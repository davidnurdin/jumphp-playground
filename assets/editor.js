// The online editor: a game's files with the user's drafts (saved as they type), CodeMirror, a preview of the
// game with the drafts (games origin, signed token), push to GitHub or fork. Talks to EditorApiController.
import { EditorView, basicSetup } from 'codemirror';
import { EditorState } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { oneDark } from '@codemirror/theme-one-dark';
import { php } from '@codemirror/lang-php';
import { javascript } from '@codemirror/lang-javascript';
import { html } from '@codemirror/lang-html';
import { css } from '@codemirror/lang-css';
import { json } from '@codemirror/lang-json';
import { yaml } from '@codemirror/lang-yaml';
import { markdown } from '@codemirror/lang-markdown';

const root = document.getElementById('editor');
// the editor's messages, in the page's language (<html lang>)
const T = document.documentElement.lang === 'en' ? {
	binary: (kb) => `Binary file (${kb} KB): it can't be edited here.`,
	draft: 'modified (draft)', same: 'same as the published version', saved: 'saved', error: 'error: ',
	sending: 'sending…', sent: (sha) => `sent: ${sha} (publishing)`,
	confirmDelete: (path) => `Delete ${path} (in your changes)?`,
	confirmReset: 'Erase all your changes and start again from the original game?', reset: 'Everything is back to the original.',
	making: 'making the link…', copied: 'Copied!', until: (date) => `Works until ${date}.`, forever: 'Works with no end date.',
} : {
	binary: (kb) => `Fichier binaire (${kb} Ko) : il ne s'édite pas ici.`,
	draft: 'modifié (brouillon)', same: 'identique à la version publiée', saved: 'enregistré', error: 'erreur : ',
	sending: 'envoi…', sent: (sha) => `envoyé : ${sha} (publication en cours)`,
	confirmDelete: (path) => `Supprimer ${path} (dans vos modifications) ?`,
	confirmReset: 'Effacer toutes vos modifications et repartir du jeu d\'origine ?', reset: 'Tout est revenu à l\'origine.',
	making: 'création du lien…', copied: 'Copié !', until: (date) => `Valable jusqu'au ${date}.`, forever: 'Valable sans date de fin.',
};
if (root) {
	const $ = (id) => document.getElementById(id);
	const API = root.dataset.api;
	const CSRF = root.dataset.csrf;
	const tree = $('tree');
	const code = $('code');
	const status = $('status');
	let files = [];
	let current = null;         // {path, view}
	let saveTimer = null;

	const api = async (method, path, body) => {
		const response = await fetch(API + path, {
			method,
			headers: { 'X-CSRF-Token': CSRF, ...(body ? { 'Content-Type': 'application/json' } : {}) },
			body: body ? JSON.stringify(body) : undefined,
			credentials: 'same-origin',
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || data.detail || `HTTP ${response.status}`);
		return data;
	};
	const say = (text) => { status.textContent = text; };

	const language = (path) => {
		if (/\.php$/i.test(path)) return php();
		if (/\.(m?js)$/i.test(path)) return javascript();
		if (/\.html?$/i.test(path)) return html();
		if (/\.css$/i.test(path)) return css();
		if (/\.(json|tmj|tsj|webmanifest)$/i.test(path)) return json();
		if (/\.ya?ml$/i.test(path)) return yaml();
		if (/\.md$/i.test(path)) return markdown();
		return [];
	};

	// --- the tree --------------------------------------------------------------------------------------
	function build(list) {
		const top = { dirs: {}, files: [] };
		for (const file of list) {
			const parts = file.path.split('/');
			let node = top;
			for (const dir of parts.slice(0, -1)) node = node.dirs[dir] ||= { dirs: {}, files: [] };
			node.files.push(file);
		}
		return top;
	}
	function render(node, into, prefix = '') {
		for (const name of Object.keys(node.dirs).sort()) {
			const details = document.createElement('details');
			const summary = document.createElement('summary');
			summary.textContent = name;
			details.append(summary);
			const children = document.createElement('div');
			children.className = 'children';
			render(node.dirs[name], children, prefix + name + '/');
			details.append(children);
			details.open = (current?.path || location.hash.slice(1)).startsWith(prefix + name + '/') || prefix === '' && ['src', 'config'].includes(name);
			into.append(details);
		}
		for (const file of node.files.sort((a, b) => a.path.localeCompare(b.path))) {
			const a = document.createElement('a');
			a.href = '#' + file.path;
			a.textContent = file.path.split('/').pop();
			a.title = file.path;
			a.dataset.path = file.path;
			if (file.state !== 'published') a.classList.add(file.state);
			if (current && current.path === file.path) a.classList.add('on');
			into.append(a);
		}
	}
	async function loadTree() {
		files = (await api('GET', '/tree')).files;
		tree.replaceChildren();
		render(build(files), tree);
		const count = $('drafts');    // in the welcome text, gone once a file is open
		if (count) count.textContent = files.filter((f) => f.state !== 'published').length;
	}
	tree.addEventListener('click', (event) => {
		const link = event.target.closest('a[data-path]');
		if (!link) return;
		event.preventDefault();
		open(link.dataset.path);
	});

	// --- a file ----------------------------------------------------------------------------------------
	async function open(path) {
		await flush();
		say('…');
		let data;
		try { data = await api('GET', '/file?path=' + encodeURIComponent(path)); } catch (e) { say(e.message); return; }
		history.replaceState(null, '', '#' + path);
		$('path').textContent = path;
		const state = files.find((f) => f.path === path)?.state || 'published';
		$('btn-revert').disabled = state === 'published';
		$('btn-delete').disabled = state === 'deleted';
		code.replaceChildren();
		if (!data.text) {
			current = { path, view: null };
			const box = document.createElement('div');
			box.className = 'ed-empty';
			if (/\.(png|jpe?g|gif|webp)$/i.test(path)) {
				const img = document.createElement('img');
				img.src = data.url;
				img.alt = path;
				img.style.cssText = 'image-rendering: pixelated; max-width: 100%; max-height: 100%';
				box.append(img);
			} else {
				box.textContent = T.binary(Math.round(data.size / 1024));
			}
			code.append(box);
		} else {
			const view = new EditorView({
				parent: code,
				state: EditorState.create({
					doc: data.content,
					extensions: [basicSetup, keymap.of([indentWithTab]), oneDark, language(path),
						EditorView.updateListener.of((update) => { if (update.docChanged) schedule(); })],
				}),
			});
			current = { path, view };
		}
		for (const a of tree.querySelectorAll('a.on')) a.classList.remove('on');
		tree.querySelector(`a[data-path="${CSS.escape(path)}"]`)?.classList.add('on');
		say(data.draft ? T.draft : '');
	}
	function schedule() {
		say('…');
		clearTimeout(saveTimer);
		saveTimer = setTimeout(save, 700);
	}
	async function save() {
		saveTimer = null;
		if (!current?.view) return;
		try {
			const result = await api('PUT', '/file', { path: current.path, content: current.view.state.doc.toString() });
			say(result.state === 'published' ? T.same : T.saved);
			live();
			const known = files.find((f) => f.path === current.path);
			if (!known || known.state !== result.state) await loadTree();
			$('btn-revert').disabled = result.state === 'published';
		} catch (e) { say(T.error + e.message); }
	}
	async function flush() { if (saveTimer) { clearTimeout(saveTimer); await save(); } }
	addEventListener('keydown', (event) => {
		if ((event.ctrlKey || event.metaKey) && event.key === 's') { event.preventDefault(); flush(); }
	});
	addEventListener('beforeunload', (event) => { if (saveTimer) { event.preventDefault(); } });

	// --- actions ---------------------------------------------------------------------------------------
	$('btn-new').addEventListener('click', () => {
		const dialog = $('dlg-new');
		dialog.returnValue = '';
		dialog.showModal();
		dialog.addEventListener('close', async () => {
			const path = $('new-path').value.trim().replace(/^\/+/, '');
			if (dialog.returnValue !== 'ok' || !path) return;
			try {
				await api('PUT', '/file', { path, content: '' });
				await loadTree();
				open(path);
			} catch (e) { say(e.message); }
		}, { once: true });
	});
	$('btn-revert').addEventListener('click', async () => {
		if (!current) return;
		clearTimeout(saveTimer); saveTimer = null;
		await api('POST', '/discard', { path: current.path });
		await loadTree();
		if (files.some((f) => f.path === current.path)) open(current.path);
		else { code.replaceChildren(); $('path').textContent = ''; current = null; }
	});
	$('btn-delete').addEventListener('click', async () => {
		if (!current || !confirmDelete(current.path)) return;
		clearTimeout(saveTimer); saveTimer = null;
		await api('DELETE', '/file?path=' + encodeURIComponent(current.path));
		await loadTree();
		open(current.path).catch(() => {});
	});
	function confirmDelete(path) { return window.confirm(T.confirmDelete(path)); }

	const preview = $('preview');
	async function play() {
		await flush();
		root.classList.remove('no-preview');
		preview.src = (await api('GET', '/preview')).url;
	}
	$('btn-preview').addEventListener('click', play);
	$('btn-reload').addEventListener('click', play);
	root.classList.add('no-preview');

	// live: each change saved relaunches the game on the right (the engine stays in the browser's cache: a
	// relaunch takes a fraction of a second); typing stays in the editor
	const liveBox = $('live');
	try { liveBox.checked = localStorage.getItem('jumphp-editor-live') !== '0'; } catch (e) { /* no storage */ }
	liveBox.addEventListener('change', () => { try { localStorage.setItem('jumphp-editor-live', liveBox.checked ? '1' : '0'); } catch (e) { /* no storage */ } });
	let isLiveReload = false;
	function live() {
		if (!liveBox.checked || root.classList.contains('no-preview')) return;
		isLiveReload = true;
		play().catch((e) => say(e.message));
	}
	addEventListener('message', (event) => {
		if (event.source !== preview.contentWindow || event.data?.type !== 'jumphp:ready' || !isLiveReload) return;
		isLiveReload = false;
		current?.view?.focus();
	});

	$('btn-push')?.addEventListener('click', async () => {
		await flush();
		const dialog = $('dlg-push');
		dialog.returnValue = '';
		dialog.showModal();
		dialog.addEventListener('close', async () => {
			if (dialog.returnValue !== 'ok') return;
			say(T.sending);
			try {
				const result = await api('POST', '/push', { message: $('push-message').value });
				say(T.sent(result.commit.slice(0, 7)));
				await loadTree();
				if (current) open(current.path);
			} catch (e) { say(e.message); }
		}, { once: true });
	});
	// a link to this version (SandboxShare): what is saved now, kept for the chosen time
	$('btn-share').addEventListener('click', async () => {
		await flush();
		$('dlg-share').querySelector('[data-share-form]').hidden = false;
		$('dlg-share').querySelector('[data-share-result]').hidden = true;
		$('btn-share-make').hidden = false;
		$('share-status').textContent = '';
		$('dlg-share').showModal();
	});
	$('btn-share-make').addEventListener('click', async () => {
		$('share-status').textContent = T.making;
		try {
			const result = await api('POST', '/share', { duration: $('share-duration').value, title: $('share-title').value });
			$('share-url').value = result.url;
			$('share-expires').textContent = result.expires ? T.until(new Date(result.expires).toLocaleString()) : T.forever;
			$('dlg-share').querySelector('[data-share-form]').hidden = true;
			$('dlg-share').querySelector('[data-share-result]').hidden = false;
			$('btn-share-make').hidden = true;
			$('share-status').textContent = '';
			$('share-url').select();
		} catch (e) { $('share-status').textContent = e.message; }
	});
	$('btn-share-copy').addEventListener('click', () => {
		navigator.clipboard?.writeText($('share-url').value).then(() => { $('btn-share-copy').textContent = T.copied; });
	});
	// the sandbox into one's space: what is being typed is saved first
	document.querySelector('form[data-flush]')?.addEventListener('submit', async (event) => {
		const form = event.currentTarget;
		if (form.dataset.flushed) return;
		event.preventDefault();
		await flush();
		form.dataset.flushed = '1';
		form.submit();
	});
	$('btn-fork')?.addEventListener('click', async () => { await flush(); $('dlg-fork').showModal(); });
	$('btn-reset')?.addEventListener('click', async () => {
		if (!window.confirm(T.confirmReset)) return;
		clearTimeout(saveTimer); saveTimer = null;
		await api('POST', '/discard', {});
		current = null;
		code.replaceChildren();
		$('path').textContent = '';
		await loadTree();
		say(T.reset);
	});

	loadTree().then(() => { if (location.hash.length > 1) open(decodeURIComponent(location.hash.slice(1))); })
		.catch((e) => { tree.textContent = e.message; });
}
