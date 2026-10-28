// The portal's script: copy buttons, the slug suggested from the title, the game frames.
import './styles/app.css';

document.addEventListener('click', (event) => {
	const button = event.target.closest('[data-copy]');
	if (!button) return;
	const text = document.querySelector(button.dataset.copy)?.textContent || '';
	navigator.clipboard?.writeText(text.trim()).then(() => {
		const label = button.textContent;
		button.textContent = 'Copié !';
		setTimeout(() => { button.textContent = label; }, 1500);
	});
});

// new project: the URL name follows the title until it is edited
const title = document.querySelector('[data-slug-source]');
const slug = document.querySelector('[data-slug-target]');
if (title && slug) {
	let touched = slug.value !== '';
	slug.addEventListener('input', () => { touched = true; });
	title.addEventListener('input', () => {
		if (touched) return;
		slug.value = title.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
			.replace(/[^a-z0-9]+/g, '-').replace(/^[-0-9]+|-+$/g, '').slice(0, 40);
	});
}

// a game in a frame of the games origin: fullscreen button, "quit" goes back to the game's page
const frame = document.querySelector('iframe[data-game-frame]');
if (frame) {
	const origin = new URL(frame.src).origin;
	document.querySelector('[data-fullscreen]')?.addEventListener('click', () => {
		(frame.requestFullscreen || frame.webkitRequestFullscreen)?.call(frame);
	});
	addEventListener('message', (event) => {
		if (event.origin !== origin || !event.data || typeof event.data !== 'object') return;
		if (event.data.type === 'jumphp:quit' && frame.dataset.quit) location.href = frame.dataset.quit;
	});
	frame.focus();
}

// a game's mark: the stars of the game bar (templates/game/_rate.html.twig, RatingController)
for (const box of document.querySelectorAll('[data-rate]')) {
	const paint = (mine) => box.querySelectorAll('[data-score]').forEach((b) => b.classList.toggle('on', Number(b.dataset.score) <= mine));
	box.addEventListener('click', async (event) => {
		const button = event.target.closest('[data-score]');
		if (!button) return;
		const score = Number(button.dataset.score);
		paint(score);
		const response = await fetch(box.dataset.rate, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ score }), credentials: 'same-origin' });
		if (!response.ok) return;
		const data = await response.json();
		const avg = box.querySelector('[data-avg]');
		avg.textContent = String(data.rating).replace('.', ',') + ' ';
		const count = document.createElement('small');
		count.textContent = `(${data.count})`;
		avg.append(count);
		box.title = 'Merci ! Votre note : ' + score + ' / 5';
	});
}

// share a game (templates/game/_share.html.twig): on a phone its share sheet, on a computer a menu of networks
for (const box of document.querySelectorAll('[data-share]')) {
	const button = box.querySelector('button');
	const menu = box.querySelector('.share-menu');
	const close = () => { menu.hidden = true; button.setAttribute('aria-expanded', 'false'); };
	button.addEventListener('click', async (event) => {
		event.stopPropagation();
		const data = { title: box.dataset.title, text: box.dataset.text, url: box.dataset.url };
		if (matchMedia('(pointer: coarse)').matches && navigator.share) {
			try { await navigator.share(data); return; } catch (e) { if (e.name === 'AbortError') return; }
		}
		menu.hidden = !menu.hidden;
		button.setAttribute('aria-expanded', String(!menu.hidden));
	});
	box.querySelector('[data-share-copy]').addEventListener('click', async (event) => {
		await navigator.clipboard?.writeText(box.dataset.url).catch(() => {});
		event.target.textContent = 'Lien copié !';
		setTimeout(() => { event.target.textContent = 'Copier le lien'; close(); }, 1200);
	});
	menu.addEventListener('click', (event) => { if (event.target.closest('a')) close(); });
	document.addEventListener('click', (event) => { if (!box.contains(event.target)) close(); });
	document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
}

// install a game as an app (templates/game/_install.html.twig, PwaController): Chrome / Edge / Android show their
// prompt; Safari on iOS has none, so the button says how; nothing once installed (the app runs standalone)
const install = document.querySelector('[data-install]');
const standalone = matchMedia('(display-mode: standalone), (display-mode: fullscreen)').matches || navigator.standalone;
if (install && !standalone && 'serviceWorker' in navigator) {
	navigator.serviceWorker.register(install.dataset.install, { scope: install.dataset.scope }).catch(() => {});
	const button = install.querySelector('button');
	const help = install.querySelector('.install-help');
	let prompt = null;
	addEventListener('beforeinstallprompt', (event) => { event.preventDefault(); prompt = event; install.hidden = false; });
	addEventListener('appinstalled', () => { install.hidden = true; });
	if (/iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) install.hidden = false;
	button.addEventListener('click', async (event) => {
		event.stopPropagation();
		if (prompt) {
			prompt.prompt();
			const choice = await prompt.userChoice.catch(() => null);
			if (choice?.outcome === 'accepted') install.hidden = true;
			prompt = null;
			return;
		}
		help.hidden = !help.hidden;
	});
	document.addEventListener('click', (event) => { if (!install.contains(event.target)) help.hidden = true; });
}
