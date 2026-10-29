// The portal's script: copy buttons, the slug suggested from the title, the game frames.
import './styles/app.css';

// the few words written from here, in the page's language (<html lang>)
const T = document.documentElement.lang === 'en' ? {
	copied: 'Copied!', linkCopied: 'Link copied!', copyLink: 'Copy link',
	thanks: (score) => `Thanks! Your rating: ${score} / 5`,
	sending: (n, total, name) => `Uploading ${n} / ${total}: ${name}…`,
	error: (status, name) => `Error ${status} on ${name}`,
	sent: (n) => `${n} file(s) uploaded.`,
} : {
	copied: 'Copié !', linkCopied: 'Lien copié !', copyLink: 'Copier le lien',
	thanks: (score) => `Merci ! Votre note : ${score} / 5`,
	sending: (n, total, name) => `Envoi ${n} / ${total} : ${name}…`,
	error: (status, name) => `Erreur ${status} sur ${name}`,
	sent: (n) => `${n} fichier(s) envoyé(s).`,
};

document.addEventListener('click', (event) => {
	const button = event.target.closest('[data-copy]');
	if (!button) return;
	const text = document.querySelector(button.dataset.copy)?.textContent || '';
	navigator.clipboard?.writeText(text.trim()).then(() => {
		const label = button.textContent;
		button.textContent = T.copied;
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

// the kind of a new game (Studio): its explanation, and a remake goes in the "remakes" category
const kind = document.querySelector('[data-kind]');
if (kind) {
	const brief = document.getElementById('brief');
	const defaultPlaceholder = brief?.placeholder;
	kind.addEventListener('change', () => {
		for (const hint of document.querySelectorAll('[data-kind-hint]')) hint.hidden = hint.dataset.kindHint !== kind.value;
		const category = document.getElementById('category');
		if (kind.value === 'cpc' && category && !category.value) category.value = 'remake';
		if (brief) brief.placeholder = kind.value === 'cpc' ? brief.dataset.placeholderCpc : defaultPlaceholder;
	});
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
		box.title = T.thanks(score);
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
		event.target.textContent = T.linkCopied;
		setTimeout(() => { event.target.textContent = T.copyLink; close(); }, 1200);
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

// the private files of a game (Studio, StudioController::privateAdd): sent one by one, then the form (removals)
const privateForm = document.querySelector('[data-private]');
if (privateForm) {
	privateForm.addEventListener('submit', async (event) => {
		const files = [...privateForm.querySelector('[data-private-files]').files];
		if (!files.length || privateForm.dataset.sent) return;
		event.preventDefault();
		const status = privateForm.querySelector('[data-private-status]');
		const dir = privateForm.querySelector('[data-private-dir]').value.trim().replace(/^\/+|\/+$/g, '');
		const token = privateForm.querySelector('[name=_token]').value;
		privateForm.querySelector('button[type=submit]').disabled = true;
		let done = 0;
		for (const file of files) {
			status.textContent = T.sending(done + 1, files.length, file.name);
			const body = new FormData();
			body.append('_token', token);
			body.append('path', (dir ? dir + '/' : '') + (file.webkitRelativePath || file.name));
			body.append('file', file);
			const response = await fetch(privateForm.dataset.private, { method: 'POST', body, credentials: 'same-origin' });
			if (!response.ok) {
				status.textContent = (await response.json().catch(() => ({}))).error || T.error(response.status, file.name);
				privateForm.querySelector('button[type=submit]').disabled = false;
				return;
			}
			done++;
		}
		status.textContent = T.sent(done);
		privateForm.dataset.sent = '1';
		privateForm.submit();
	});
}
