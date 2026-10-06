const express = require('express');
const app = express();
const port = 8083;

const secret = 'my-super-secret';

const startGreen = '\x1b[1;32m';
const endGreen = '\x1b[0m';

// v1's request: one path, the secret in a query arg. Nothing in 2.x sends it;
// it stays for a 1.x site, which the upgraded stack of the extended pass raises
// and upgrades.
app.get('/revalidate', (req, res) => {
	const path = req.query.path;

	if (req.query.secret !== secret) {
		res.status(401).json({ message: 'Invalid token' });
	} else if (path) {
		console.log(`= Revalidating: ${startGreen}${path}${endGreen}`);
		res.status(200).json({ revalidated: true });
	} else {
		res.status(500).send('Error revalidating');
	}
});

// v2's request: a site's pending changes, all at once (ADR 0033, ADR 0034). The
// secret is read from its own header when the request has it, and as a bearer
// token otherwise, as the README's contract says (ADR 0042). One line per
// request, naming every change it carried, so "exactly one request" is
// something the console can show. A real Next.js app maps each change onto the
// cache tags it expires here.
const revalidateV2 = (req, res) => {
	const own = req.get('X-Nextjs-Revalidate-Secret');
	const authorised = own !== undefined ? own === secret : req.get('Authorization') === `Bearer ${secret}`;

	if (!authorised) {
		res.status(401).json({ message: 'Invalid token' });
	} else if (!req.body || req.body.version !== 2 || !Array.isArray(req.body.changes)) {
		res.status(400).json({ message: 'Not a version 2 request' });
	} else {
		const changes = req.body.changes.map((change) => JSON.stringify(change)).join(', ');
		console.log(`= Revalidating (v2): ${startGreen}${changes}${endGreen}`);
		res.status(204).end();
	}
};

app.post('/revalidate', express.json(), revalidateV2);

// A front-end behind basic auth, as a password-protected staging host is: the
// credentials are `runbook` and `p@ss`, so a revalidate domain carries them as
// `http://runbook:p%40ss@…` and the plugin has to decode them. Without them it
// answers 401 before the secret is looked at.
const basicAuth = `Basic ${Buffer.from('runbook:p@ss').toString('base64')}`;

app.post('/behind-basic-auth', express.json(), (req, res) => {
	if (req.get('Authorization') !== basicAuth) {
		res.set('WWW-Authenticate', 'Basic realm="staging"').status(401).json({ message: 'Basic auth required' });
		return;
	}

	revalidateV2(req, res);
});

// A front-end whose route moved, as a Next.js app with `trailingSlash: true`
// answers the bare path: a 308 keeps the request and is followed, a 301 does
// not and is a failure (ADR 0039). Both name a path, as Next.js does.
app.post('/trailing-slash', (req, res) => res.redirect(308, '/revalidate/'));
app.post('/moved-permanently', (req, res) => res.redirect(301, '/revalidate/'));

app.listen(port, () => {
	console.log(`Revalidate dev server is running on port ${port}`);
});
