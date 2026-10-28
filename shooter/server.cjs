// The playground's camera: headless Chrome that opens a game, lets it run and photographs its screen, for the
// games without a picture of their own (App\Service\Screenshots). One game at a time; internal network only.
//   GET /shot?url=<a game page of SHOOTER_ALLOW>&seconds=6&keys=Enter,Enter  ->  image/png (the game's canvas)
const http = require('http');
const puppeteer = require('puppeteer');

const ALLOW = (process.env.SHOOTER_ALLOW || '').split(',').map((s) => s.trim()).filter(Boolean);
const ARGS = ['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader',
	'--autoplay-policy=no-user-gesture-required', '--mute-audio', ...(process.env.SHOOTER_CHROME_ARGS || '').split(' ').filter(Boolean)];
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let browser = null;
let queue = Promise.resolve();

async function shoot(url, seconds, keys) {
	if (!browser || !browser.connected) browser = await puppeteer.launch({ headless: 'new', args: ARGS });
	const page = await browser.newPage();
	try {
		await page.setViewport({ width: 1280, height: 720 });
		await page.goto(url, { waitUntil: 'load', timeout: 60000 });
		await page.waitForFunction(() => {
			const loader = document.getElementById('loader');
			return loader && (getComputedStyle(loader).display === 'none' || loader.classList.contains('error'));
		}, { timeout: 120000 });
		const error = await page.evaluate(() => document.getElementById('loader').classList.contains('error') ? document.getElementById('status').textContent : null);
		if (error) throw new Error('the game did not start: ' + error.slice(0, 300));
		await page.mouse.click(640, 360);
		for (const key of keys) { await sleep(1200); await page.keyboard.press(key); }
		await sleep(seconds * 1000);
		// only the game: the page's buttons, pad and messages hidden, the whole view photographed
		await page.evaluate(() => {
			const canvas = document.querySelector('canvas');
			for (const element of document.body.querySelectorAll('*')) {
				if (element !== canvas && !element.contains(canvas)) element.style.visibility = 'hidden';
			}
		});
		return await page.screenshot({ type: 'png' });
	} finally {
		await page.close().catch(() => {});
	}
}

http.createServer((request, response) => {
	const query = new URL(request.url, 'http://shooter').searchParams;
	const url = query.get('url') || '';
	if (!request.url.startsWith('/shot') || !ALLOW.some((prefix) => url.startsWith(prefix))) {
		response.writeHead(400, { 'Content-Type': 'text/plain' }).end('GET /shot?url=<a game of ' + ALLOW.join(' ') + '>');
		return;
	}
	const seconds = Math.min(30, Math.max(0, Number(query.get('seconds') || 6)));
	const keys = (query.get('keys') || '').split(',').filter((k) => /^[A-Za-z0-9 ]{1,12}$/.test(k)).slice(0, 6);
	queue = queue.then(() => shoot(url, seconds, keys)).then(
		(png) => { response.writeHead(200, { 'Content-Type': 'image/png' }).end(png); },
		(error) => { console.error(url, error.message); response.writeHead(500, { 'Content-Type': 'text/plain' }).end(error.message); },
	);
}).listen(Number(process.env.PORT || 3000), () => console.log('shooter: ready, allowed', ALLOW.join(' ')));
