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
