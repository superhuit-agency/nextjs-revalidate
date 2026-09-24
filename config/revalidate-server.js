const express = require('express');
const app = express();
const port = 8083;

const secret = 'my-super-secret';

const startGreen = '\x1b[1;32m';
const endGreen = '\x1b[0m';

// v1's request: one path, the secret in a query arg. The revalidation queue
// still sends it until the rest of v2 lands.
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

// v2's request: a site's pending changes, all at once, the secret as a bearer
// token (ADR 0033, ADR 0034). One line per request, naming every change it
// carried, so "exactly one request" is something the console can show. A real
// Next.js app maps each change onto the cache tags it expires here.
app.post('/revalidate', express.json(), (req, res) => {
	if (req.get('Authorization') !== `Bearer ${secret}`) {
		res.status(401).json({ message: 'Invalid token' });
	} else if (!req.body || req.body.version !== 2 || !Array.isArray(req.body.changes)) {
		res.status(400).json({ message: 'Not a version 2 request' });
	} else {
		const changes = req.body.changes.map((change) => JSON.stringify(change)).join(', ');
		console.log(`= Revalidating (v2): ${startGreen}${changes}${endGreen}`);
		res.status(204).end();
	}
});

app.listen(port, () => {
	console.log(`Revalidate dev server is running on port ${port}`);
});
