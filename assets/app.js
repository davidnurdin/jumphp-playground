// The portal's script: copy buttons, the slug suggested from the title, the game frames.
import './styles/app.css';

// the few words written from here, in the page's language (<html lang>)
const T = document.documentElement.lang === 'en' ? {
	copied: 'Copied!', linkCopied: 'Link copied!', copyLink: 'Copy link',
	thanks: (score) => `Thanks! Your rating: ${score} / 5`,
	sending: (n, total, name) => `Uploading ${n} / ${total}: ${name}…`,
	error: (status, name) => `Error ${status} on ${name}`,
	sent: (n) => `${n} file(s) uploaded.`,
	play: 'Play', pause: 'Pause', running: 'running…', open: 'Watch it full screen, frame by frame',
	prev: 'Previous frame (←)', next: 'Next frame (→)', close: 'Close (Esc)', speed: 'Speed',
} : {
	copied: 'Copié !', linkCopied: 'Lien copié !', copyLink: 'Copier le lien',
	thanks: (score) => `Merci ! Votre note : ${score} / 5`,
	sending: (n, total, name) => `Envoi ${n} / ${total} : ${name}…`,
	error: (status, name) => `Erreur ${status} sur ${name}`,
	sent: (n) => `${n} fichier(s) envoyé(s).`,
	play: 'Lire', pause: 'Pause', running: 'en cours…', open: 'Voir en plein écran, image par image',
	prev: 'Image précédente (←)', next: 'Image suivante (→)', close: 'Fermer (Échap)', speed: 'Vitesse',
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

// a test run's filmed game tests (studio/tests.html.twig): each test replayed in a loop, live while the run runs
const filmsBlock = document.querySelector('[data-films]');
if (filmsBlock) {
	const list = filmsBlock.querySelector('[data-films-list]');
	const frameUrl = (n, i) => filmsBlock.dataset.frame.replace('/000/0000.png', `/${n}/${String(i).padStart(4, '0')}.png`);
	const marks = { passed: '✔', failed: '✘', error: '✘', skipped: '○', run: '⏳' };
	const cards = new Map();

	// the full screen player of one film: frame by frame (← →, the slider), play / pause (space), speed, Esc
	const theater = (() => {
		const box = document.createElement('dialog');
		box.className = 'theater';
		box.innerHTML = '<div class="theater-head"><span class="mark"></span> <b></b><button class="btn small" type="button" data-close></button></div>'
			+ '<div class="theater-pic"><img alt=""></div>'
			+ '<div class="theater-ctrl"><button class="btn" type="button" data-first>⏮</button><button class="btn" type="button" data-prev>◀</button>'
			+ '<button class="btn primary" type="button" data-play></button><button class="btn" type="button" data-next>▶</button><button class="btn" type="button" data-last>⏭</button>'
			+ '<input type="range" min="1"><span class="count"></span>'
			+ '<label class="faint"><span></span> <select><option value="3">¼×</option><option value="6">½×</option><option value="12" selected>1×</option><option value="24">2×</option></select></label></div>';
		document.body.append(box);
		const q = (sel) => box.querySelector(sel);
		let card = null;
		let at = 1;
		let isPlaying = false;
		let timer = null;
		q('[data-close]').textContent = '✕';
		q('[data-close]').title = T.close;
		q('[data-prev]').title = T.prev;
		q('[data-next]').title = T.next;
		q('label span').textContent = T.speed;
		const frames = () => card.film.frames;
		const draw = () => {
			at = Math.min(Math.max(1, at), frames());
			q('img').src = frameUrl(card.film.n, at);
			q('input').max = frames();
			q('input').value = at;
			q('.count').textContent = `${at} / ${frames()}`;
			q('[data-play]').textContent = isPlaying ? '⏸' : '▶';
			q('[data-play]').title = isPlaying ? T.pause : T.play;
		};
		const stop = () => { isPlaying = false; clearInterval(timer); timer = null; draw(); };
		const play = () => {
			isPlaying = true;
			clearInterval(timer);
			if (at >= frames()) at = 1;
			timer = setInterval(() => { at = at >= frames() ? 1 : at + 1; draw(); }, 1000 / Number(q('select').value));
			draw();
		};
		const step = (to) => { stop(); at = to; draw(); };
		q('[data-close]').addEventListener('click', () => box.close());
		q('[data-play]').addEventListener('click', () => (isPlaying ? stop() : play()));
		q('[data-prev]').addEventListener('click', () => step(at - 1));
		q('[data-next]').addEventListener('click', () => step(at + 1));
		q('[data-first]').addEventListener('click', () => step(1));
		q('[data-last]').addEventListener('click', () => step(frames()));
		q('input').addEventListener('input', () => step(Number(q('input').value)));
		q('select').addEventListener('change', () => { if (isPlaying) play(); });
		box.addEventListener('keydown', (event) => {
			const keys = { ArrowLeft: () => step(at - 1), ArrowRight: () => step(at + 1), Home: () => step(1), End: () => step(frames()), ' ': () => (isPlaying ? stop() : play()) };
			if (keys[event.key] && event.target.tagName !== 'SELECT') { event.preventDefault(); keys[event.key](); }
		});
		box.addEventListener('close', () => {
			stop();
			if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
		});
		return {
			open(which) {
				card = which;
				at = which.at;
				box.className = `theater ${which.film.status}`;
				q('.mark').textContent = which.el.querySelector('.mark').textContent;
				q('b').textContent = which.film.label;
				box.showModal();
				box.requestFullscreen?.().catch(() => {});
				stop();
				q('[data-play]').focus();
			},
			// a film that grows (a test that runs): the player follows its length
			refresh() { if (card && box.open) draw(); },
		};
	})();

	const show = (card) => {
		card.img.src = frameUrl(card.film.n, card.at);
		card.range.value = card.at;
		card.count.textContent = `${card.at} / ${card.film.frames}`;
	};
	const draw = (films) => {
		for (const film of films) {
			let card = cards.get(film.n);
			if (!card) {
				const el = document.createElement('figure');
				el.className = 'film';
				el.innerHTML = '<a class="film-pic" target="_blank" rel="noopener"><img alt=""></a>'
					+ '<figcaption><span class="mark"></span> <b></b><small class="faint"></small></figcaption>'
					+ '<div class="film-ctrl"><button class="btn small" type="button"></button><input type="range" min="1"><span class="faint"></span></div>'
					+ '<pre class="code" hidden></pre>';
				card = { el, film, at: 1, isPlaying: true, img: el.querySelector('img'), link: el.querySelector('a'),
					range: el.querySelector('input'), count: el.querySelector('.film-ctrl span'), button: el.querySelector('button') };
				card.link.title = T.open;
				card.link.href = '#';
				card.link.addEventListener('click', (event) => { event.preventDefault(); theater.open(card); });
				card.button.addEventListener('click', () => {
					card.isPlaying = !card.isPlaying;
					card.button.textContent = card.isPlaying ? '⏸' : '▶';
					card.button.title = card.isPlaying ? T.pause : T.play;
				});
				card.range.addEventListener('input', () => {
					card.isPlaying = false;
					card.button.textContent = '▶';
					card.at = Number(card.range.value);
					show(card);
				});
				card.button.textContent = '⏸';
				cards.set(film.n, card);
				list.append(el);
			}
			card.film = film;
			card.el.className = `film ${film.status}`;
			card.el.querySelector('.mark').textContent = marks[film.status] || '';
			card.el.querySelector('b').textContent = film.label;
			card.el.querySelector('small').textContent = ' ' + film.name + (film.status === 'run' ? ' · ' + T.running : '');
			const pre = card.el.querySelector('pre');
			pre.hidden = !film.message;
			pre.textContent = film.message || '';
			card.range.max = film.frames;
			// a test filmed right now: its newest frame
			if (film.status === 'run') card.at = film.frames;
			show(card);
		}
		theater.refresh();
		filmsBlock.hidden = cards.size === 0 && filmsBlock.dataset.done === '1';
	};
	draw(JSON.parse(filmsBlock.dataset.initial || '[]'));

	// 12 frames a second (the films have one frame every 4 of the game's 60)
	setInterval(() => {
		for (const card of cards.values()) {
			if (!card.isPlaying || card.film.status === 'run' || card.film.frames < 2) continue;
			card.at = card.at >= card.film.frames ? 1 : card.at + 1;
			show(card);
		}
	}, 1000 / 12);

	if (filmsBlock.dataset.done !== '1') {
		const poll = async () => {
			const state = await fetch(filmsBlock.dataset.films, { credentials: 'same-origin' }).then((r) => r.json()).catch(() => null);
			if (state?.done) {
				location.reload();
				return;
			}
			if (state) draw(state.films);
			setTimeout(poll, 1000);
		};
		setTimeout(poll, 1000);
	}
}
