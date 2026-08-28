/* global avpvhAudioTranscriptie */
(() => {
	'use strict';

	const config = avpvhAudioTranscriptie;
	const app = document.querySelector('#avpvh-at-app');
	let current = null;
	let dirty = new Set();
	let focusedId = null;
	let saveTimer = null;
	let pollTimer = null;
	let saving = false;

	const escapeHtml = (value) => String(value ?? '').replace(
		/[&<>"']/g,
		(character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]
	);

	async function api(path, options = {}) {
		const response = await fetch(config.root + path, {
			...options,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
				...(options.headers || {})
			}
		});
		const data = await response.json();
		if (!response.ok) {
			const error = new Error(data.message || config.i18n.requestFailed);
			error.status = response.status;
			error.data = data;
			throw error;
		}
		return data;
	}

	function renderShell() {
		app.innerHTML = `
			<nav class="avpvh-at-tabs" aria-label="Pluginonderdelen">
				<button type="button" data-tab="jobs" class="active">Transcripties</button>
				${config.canManage ? '<button type="button" data-tab="settings">Instellingen</button>' : ''}
			</nav>
			<div id="avpvh-at-notice" class="notice inline" hidden><p></p></div>
			<section id="avpvh-at-view"></section>`;

		app.querySelectorAll('[data-tab]').forEach((button) => {
			button.addEventListener('click', () => {
				clearTimeout(pollTimer);
				app.querySelectorAll('[data-tab]').forEach((item) => item.classList.toggle('active', item === button));
				if ('settings' === button.dataset.tab) {
					renderSettings();
				} else {
					renderJobList();
				}
			});
		});
		renderJobList();
	}

	function notice(message, type = 'info') {
		const element = app.querySelector('#avpvh-at-notice');
		element.className = `notice notice-${type} inline`;
		element.querySelector('p').textContent = message;
		element.hidden = false;
	}

	async function renderJobList() {
		const view = app.querySelector('#avpvh-at-view');
		view.innerHTML = '<p class="avpvh-at-loading">Transcripties laden<span></span></p>';
		try {
			const jobs = await api('jobs');
			view.innerHTML = `
				<section class="avpvh-at-card">
					<h2>Nieuwe transcriptie</h2>
					<div class="avpvh-at-new">
						<label>Titel <input id="avpvh-at-title" type="text"></label>
						<label>Model <select id="avpvh-at-model"><option>small</option><option selected>medium</option><option>large-v3-turbo</option></select></label>
						<label>Audio <input id="avpvh-at-audio" type="file" accept="audio/*"></label>
						<button id="avpvh-at-start" type="button" class="button button-primary">Start transcriptie</button>
					</div>
					<progress id="avpvh-at-upload" max="100" value="0" hidden></progress>
				</section>
				<section class="avpvh-at-card">
					<h2>Transcripties</h2>
					<div class="avpvh-at-jobs">
						${jobs.map(jobRow).join('') || '<p>Nog geen transcripties.</p>'}
					</div>
				</section>`;
			view.querySelector('#avpvh-at-start').addEventListener('click', startJob);
			view.querySelectorAll('.avpvh-at-job').forEach((button) => button.addEventListener('click', () => openJob(Number(button.dataset.id))));
		} catch (error) {
			view.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
		}
	}

	function jobRow(job) {
		return `<button type="button" class="avpvh-at-job" data-id="${job.id}">
			<strong>${escapeHtml(job.title)}</strong>
			<span>${escapeHtml(job.status)} · ${Math.round(job.progress)}%</span>
			<progress max="100" value="${job.progress}"></progress>
		</button>`;
	}

	async function startJob() {
		const file = app.querySelector('#avpvh-at-audio').files[0];
		if (!file) {
			notice('Kies eerst een audiobestand.', 'warning');
			return;
		}

		const button = app.querySelector('#avpvh-at-start');
		const progress = app.querySelector('#avpvh-at-upload');
		button.disabled = true;
		progress.hidden = false;

		try {
			const created = await api('jobs', {
				method: 'POST',
				body: JSON.stringify({
					filename: file.name,
					size: file.size,
					content_type: file.type,
					title: app.querySelector('#avpvh-at-title').value,
					model: app.querySelector('#avpvh-at-model').value,
					language: 'nl'
				})
			});

			await uploadFile(created, file, progress);
			await api(`jobs/${created.job.id}/upload-complete`, { method: 'POST', body: '{}' });
			await openJob(created.job.id);
		} catch (error) {
			notice(error.message, 'error');
			button.disabled = false;
		}
	}

	function uploadFile(created, file, progress) {
		return new Promise((resolve, reject) => {
			const request = new XMLHttpRequest();
			request.open('PUT', created.upload_url);
			Object.entries(created.upload_headers || {}).forEach(([name, value]) => request.setRequestHeader(name, value));
			request.upload.addEventListener('progress', (event) => {
				if (event.lengthComputable) {
					progress.value = (event.loaded / event.total) * 100;
				}
			});
			request.addEventListener('load', () => {
				if (request.status >= 200 && request.status < 300) {
					resolve();
				} else {
					reject(new Error(`Upload mislukt (${request.status}).`));
				}
			});
			request.addEventListener('error', () => reject(new Error('De uploadverbinding is mislukt.')));
			request.send(file);
		});
	}

	async function openJob(id) {
		clearTimeout(pollTimer);
		const view = app.querySelector('#avpvh-at-view');
		view.innerHTML = '<p class="avpvh-at-loading">Transcriptie laden<span></span></p>';
		current = null;
		dirty = new Set();
		focusedId = null;
		try {
			current = await api(`jobs/${id}`);
			renderEditor();
			poll();
		} catch (error) {
			view.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
		}
	}

	function renderEditor() {
		const view = app.querySelector('#avpvh-at-view');
		const speakers = uniqueValues(current.segments, 'speaker');
		const parties = uniqueValues(current.segments, 'party');
		view.innerHTML = `
			<header class="avpvh-at-editor-head">
				<button id="avpvh-at-back" type="button" class="button">← Overzicht</button>
				<div>
					<h2>${escapeHtml(current.title)}</h2>
					<div><span id="avpvh-at-status">${escapeHtml(current.status)}</span> · <span id="avpvh-at-percent">${Math.round(current.progress)}%</span> <span id="avpvh-at-save-state" aria-live="polite"></span></div>
					<progress id="avpvh-at-progress" max="100" value="${current.progress}"></progress>
					${current.audio_url ? `<audio id="avpvh-at-player" controls preload="metadata" src="${escapeHtml(current.audio_url)}"></audio>` : ''}
				</div>
				<div class="avpvh-at-actions">
					<button id="avpvh-at-insert" type="button" class="button">Zin toevoegen</button>
					<button id="avpvh-at-history" type="button" class="button">Geschiedenis</button>
					<button id="avpvh-at-summary" type="button" class="button button-primary">Samenvatting</button>
				</div>
			</header>
			<div class="avpvh-at-summary" ${current.summary ? '' : 'hidden'}><h3>Samenvatting</h3><div>${current.summary || ''}</div></div>
			<datalist id="avpvh-at-speakers">${speakers.map((value) => `<option value="${escapeHtml(value)}">`).join('')}</datalist>
			<datalist id="avpvh-at-parties">${parties.map((value) => `<option value="${escapeHtml(value)}">`).join('')}</datalist>
			<div class="avpvh-at-filter"><label>Zoeken <input id="avpvh-at-search" type="search"></label><span id="avpvh-at-count"></span></div>
			<div id="avpvh-at-history-panel" class="avpvh-at-card" hidden></div>
			<div id="avpvh-at-segments">${current.segments.map(segmentRow).join('')}</div>`;

		view.querySelector('#avpvh-at-back').addEventListener('click', async () => {
			await flushSave();
			clearTimeout(pollTimer);
			renderJobList();
		});
		view.querySelector('#avpvh-at-insert').addEventListener('click', insertSegment);
		view.querySelector('#avpvh-at-history').addEventListener('click', renderHistory);
		view.querySelector('#avpvh-at-summary').addEventListener('click', createSummary);
		view.querySelector('#avpvh-at-search').addEventListener('input', filterRows);
		bindSegmentRows();
		setFocus(current.segments[0]?.id || null, false);
	}

	function uniqueValues(segments, field) {
		return [...new Set(segments.map((segment) => segment[field]).filter(Boolean))].sort((left, right) => left.localeCompare(right));
	}

	function segmentRow(segment) {
		return `<article class="avpvh-at-segment" data-id="${escapeHtml(segment.id)}" tabindex="0">
			<button type="button" class="avpvh-at-time" title="Speel vanaf dit fragment">${clock(segment.start)}</button>
			<label><span>Partij</span><input class="avpvh-at-party" type="text" list="avpvh-at-parties" value="${escapeHtml(segment.party)}"></label>
			<label><span>Spreker</span><input class="avpvh-at-speaker" type="text" list="avpvh-at-speakers" value="${escapeHtml(segment.speaker)}"></label>
			<label class="avpvh-at-text"><span>Transcriptie</span><textarea>${escapeHtml(segment.text)}</textarea></label>
		</article>`;
	}

	function bindSegmentRows() {
		app.querySelectorAll('.avpvh-at-segment').forEach((row) => {
			const id = row.dataset.id;
			const text = row.querySelector('textarea');
			const speaker = row.querySelector('.avpvh-at-speaker');
			const party = row.querySelector('.avpvh-at-party');
			const change = () => {
				const segment = current.segments.find((item) => item.id === id);
				segment.text = text.value;
				segment.speaker = speaker.value;
				segment.party = party.value;
				dirty.add(id);
				scheduleSave();
			};
			text.addEventListener('input', change);
			speaker.addEventListener('input', change);
			party.addEventListener('input', change);
			row.addEventListener('mousedown', () => setFocus(id, false));
			row.addEventListener('focusin', () => setFocus(id, false));
			row.querySelector('.avpvh-at-time').addEventListener('click', () => playSegment(id));
		});
	}

	function setFocus(id, scroll = true) {
		focusedId = id;
		app.querySelectorAll('.avpvh-at-segment').forEach((row) => row.classList.toggle('is-current', row.dataset.id === id));
		const row = id ? app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(id)}"]`) : null;
		if (scroll && row) {
			row.scrollIntoView({ block: 'nearest' });
		}
	}

	function cssEscape(value) {
		return window.CSS?.escape ? window.CSS.escape(value) : String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
	}

	function playSegment(id) {
		const player = app.querySelector('#avpvh-at-player');
		const segment = current.segments.find((item) => item.id === id);
		if (!player || !segment) {
			return;
		}
		setFocus(id);
		player.currentTime = segment.start;
		player.play();
	}

	function toggleAudio() {
		const player = app.querySelector('#avpvh-at-player');
		if (!player) {
			return;
		}
		if (player.paused) {
			const segment = current?.segments.find((item) => item.id === focusedId);
			if (segment && (player.currentTime < segment.start || player.currentTime > segment.end)) {
				player.currentTime = segment.start;
			}
			player.play();
		} else {
			player.pause();
		}
	}

	function moveFocus(direction) {
		const visible = [...app.querySelectorAll('.avpvh-at-segment:not([hidden])')];
		if (!visible.length) {
			return;
		}
		let index = visible.findIndex((row) => row.dataset.id === focusedId);
		index = Math.max(0, Math.min(visible.length - 1, index + direction));
		setFocus(visible[index].dataset.id);
	}

	function insertSegment() {
		const focusedIndex = current.segments.findIndex((segment) => segment.id === focusedId);
		const index = focusedIndex >= 0 ? focusedIndex : current.segments.length - 1;
		const source = current.segments[index];
		let text = '';
		const textarea = app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(focusedId)}"] textarea`);
		if (textarea && textarea.selectionStart !== textarea.selectionEnd) {
			text = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);
			textarea.setRangeText('', textarea.selectionStart, textarea.selectionEnd, 'end');
			source.text = textarea.value;
			dirty.add(source.id);
		}

		const next = current.segments[index + 1];
		const id = `manual-${Date.now()}-${Math.random().toString(16).slice(2)}`;
		const segment = {
			id,
			start: source?.end ?? 0,
			end: next?.start ?? source?.end ?? 0,
			text,
			speaker: next?.speaker || '',
			party: next?.party || '',
			manual: true,
			inserted: true
		};
		current.segments.splice(index + 1, 0, segment);
		dirty.add(id);
		renderEditor();
		setFocus(id);
		app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(id)}"] textarea`)?.focus();
		scheduleSave();
	}

	function filterRows(event) {
		const query = event.target.value.trim().toLocaleLowerCase();
		let visible = 0;
		app.querySelectorAll('.avpvh-at-segment').forEach((row) => {
			const segment = current.segments.find((item) => item.id === row.dataset.id);
			row.hidden = Boolean(query) && !`${segment.text} ${segment.speaker} ${segment.party}`.toLocaleLowerCase().includes(query);
			visible += row.hidden ? 0 : 1;
		});
		app.querySelector('#avpvh-at-count').textContent = query ? `${visible} resultaat/resultaten` : '';
		if (query && !app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(focusedId)}"]:not([hidden])`)) {
			setFocus(app.querySelector('.avpvh-at-segment:not([hidden])')?.dataset.id || null, false);
		}
	}

	function scheduleSave() {
		clearTimeout(saveTimer);
		setSaveState('Wijzigingen…');
		saveTimer = setTimeout(save, 1200);
	}

	async function flushSave() {
		clearTimeout(saveTimer);
		if (dirty.size) {
			await save();
		}
	}

	async function save() {
		if (!dirty.size || saving) {
			return;
		}
		saving = true;
		const dirtyIds = [...dirty];
		setSaveState('Opslaan…');
		try {
			const result = await api(`jobs/${current.id}/segments`, {
				method: 'PUT',
				body: JSON.stringify({ revision: current.revision, segments: current.segments, dirty_ids: dirtyIds })
			});
			current.revision = result.revision;
			dirtyIds.forEach((id) => dirty.delete(id));
			setSaveState('Opgeslagen');
		} catch (error) {
			if (409 === error.status) {
				notice('Er is een nieuwere versie. Herlaad de transcriptie om wijzigingen samen te voegen.', 'error');
			} else {
				setSaveState('Opslaan mislukt; nieuwe poging…');
				saveTimer = setTimeout(save, 5000);
			}
		} finally {
			saving = false;
		}
	}

	function setSaveState(text) {
		const element = app.querySelector('#avpvh-at-save-state');
		if (element) {
			element.textContent = text ? `· ${text}` : '';
		}
	}

	async function poll() {
		if (!current) {
			return;
		}
		try {
			const remote = await api(`jobs/${current.id}`);
			const local = new Map(current.segments.map((segment) => [segment.id, segment]));
			remote.segments = remote.segments.map((segment) => dirty.has(segment.id) && local.has(segment.id) ? local.get(segment.id) : segment);
			const segmentCountChanged = remote.segments.length !== current.segments.length;
			current = remote;
			const progress = app.querySelector('#avpvh-at-progress');
			if (progress) {
				progress.value = current.progress;
				app.querySelector('#avpvh-at-percent').textContent = `${Math.round(current.progress)}%`;
				app.querySelector('#avpvh-at-status').textContent = current.status;
			}
			if (segmentCountChanged && !dirty.size) {
				renderEditor();
			}
			if (!['complete', 'failed'].includes(current.status)) {
				pollTimer = setTimeout(poll, 3000);
			}
		} catch (error) {
			pollTimer = setTimeout(poll, 5000);
		}
	}

	async function renderHistory() {
		const panel = app.querySelector('#avpvh-at-history-panel');
		if (!panel.hidden) {
			panel.hidden = true;
			return;
		}
		panel.hidden = false;
		panel.innerHTML = '<p>Geschiedenis laden…</p>';
		try {
			const revisions = await api(`jobs/${current.id}/history`);
			panel.innerHTML = `<h3>Correctiegeschiedenis</h3>${revisions.map((revision) => `<button type="button" class="button avpvh-at-restore" data-revision="${revision.revision}">Versie ${revision.revision} · ${escapeHtml(revision.created_at)}</button>`).join('') || '<p>Nog geen eerdere versies.</p>'}`;
			panel.querySelectorAll('.avpvh-at-restore').forEach((button) => button.addEventListener('click', async () => {
				if (!window.confirm('Deze versie terugzetten? De huidige versie blijft in de geschiedenis bewaard.')) {
					return;
				}
				current = await api(`jobs/${current.id}/restore/${button.dataset.revision}`, { method: 'POST', body: '{}' });
				dirty.clear();
				renderEditor();
			}));
		} catch (error) {
			panel.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
		}
	}

	async function createSummary() {
		await flushSave();
		try {
			const result = await api(`jobs/${current.id}/summary`, {
				method: 'POST',
				body: JSON.stringify({ prompt: 'Maak een beknopt verslag met kernpunten, besluiten, acties en openstaande punten.' })
			});
			if (result.summary) {
				current.summary = result.summary;
				renderEditor();
			} else {
				notice('De samenvatting is gestart en verschijnt zodra deze klaar is.', 'info');
			}
		} catch (error) {
			notice(error.message, 'error');
		}
	}

	async function renderSettings() {
		const view = app.querySelector('#avpvh-at-view');
		view.innerHTML = '<p class="avpvh-at-loading">Instellingen laden<span></span></p>';
		try {
			const settings = await api('settings');
			view.innerHTML = `<section class="avpvh-at-card avpvh-at-settings">
				<h2>Transcriptieservice</h2>
				<p>De plugin verstuurt audio en transcriptietekst alleen naar de service die u hier zelf instelt.</p>
				<label>Service-URL <input id="avpvh-at-url" type="url" value="${escapeHtml(settings.service_url)}" placeholder="https://service.example"></label>
				<label>Servicesleutel <input id="avpvh-at-key" type="password" placeholder="${escapeHtml(settings.license_hint || 'Servicesleutel')}"></label>
				<p>Status: <strong>${settings.license_registered ? 'sleutel geregistreerd' : 'niet ingesteld'}</strong></p>
				<button id="avpvh-at-save-settings" type="button" class="button button-primary">Opslaan en valideren</button>
				<span id="avpvh-at-license-result" aria-live="polite"></span>
			</section>`;
			view.querySelector('#avpvh-at-save-settings').addEventListener('click', async () => {
				try {
					await api('settings', {
						method: 'PUT',
						body: JSON.stringify({ service_url: view.querySelector('#avpvh-at-url').value, license_key: view.querySelector('#avpvh-at-key').value })
					});
					const result = await api('license/validate', { method: 'POST', body: '{}' });
					view.querySelector('#avpvh-at-license-result').textContent = result.valid ? ' Sleutel geldig ✓' : ' Sleutel ongeldig';
				} catch (error) {
					view.querySelector('#avpvh-at-license-result').textContent = ` ${error.message}`;
				}
			});
		} catch (error) {
			view.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
		}
	}

	function clock(seconds) {
		const value = Number(seconds) || 0;
		return `${String(Math.floor(value / 3600)).padStart(2, '0')}:${String(Math.floor((value % 3600) / 60)).padStart(2, '0')}:${String(Math.floor(value % 60)).padStart(2, '0')}`;
	}

	document.addEventListener('keydown', (event) => {
		if (!current) {
			return;
		}
		const editing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);
		if (event.ctrlKey && 'Space' === event.code) {
			event.preventDefault();
			toggleAudio();
			return;
		}
		if (!editing && 'Space' === event.code) {
			event.preventDefault();
			toggleAudio();
		}
		if (event.ctrlKey && 'ArrowDown' === event.key) {
			event.preventDefault();
			moveFocus(1);
		}
		if (event.ctrlKey && 'ArrowUp' === event.key) {
			event.preventDefault();
			moveFocus(-1);
		}
	});

	renderShell();
})();
