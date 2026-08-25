const express = require('express');
const app = express();
const port = 8083;

const startGreen = '\x1b[1;32m';
const endGreen = '\x1b[0m';

app.get('/revalidate', (req, res) => {
	const secret = req.query.secret;
	const path = req.query.path;

	if (secret !== 'my-super-secret') {
		res.status(401).json({ message: 'Invalid token' });
	} else if (path) {
		console.log(`= Revalidating: ${startGreen}${path}${endGreen}`);
		res.status(200).json({ revalidated: true });
	} else {
		res.status(500).send('Error revalidating');
	}
});

// The FSE endpoint, at the path the plugin composes when the setting is left
// empty — so a seeded site reaches it without configuring anything. It takes no
// `path`: an FSE change invalidates the whole template snapshot, and the pages
// holding it rebuild lazily. A real Next.js app invalidates a cache tag here.
app.get('/api/revalidate-fse', (req, res) => {
	const secret = req.query.secret;

	if (secret !== 'my-super-secret') {
		res.status(401).json({ message: 'Invalid token' });
	} else {
		console.log(`= Invalidating: ${startGreen}the FSE snapshot${endGreen}`);
		res.status(200).json({ revalidated: true });
	}
});

app.listen(port, () => {
	console.log(`Revalidate dev server is running on port ${port}`);
});
