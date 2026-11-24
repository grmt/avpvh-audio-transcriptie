/* global avpvhAudioTranscriptie, wp */
(() => {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;
	const config = avpvhAudioTranscriptie;
	const defaults = config.defaults || {};
	const app = document.querySelector('#avpvh-at-app');
	let current = null;
	// Segment id -> edit counter value of its latest unsaved change.
	let dirty = new Map();
	// Segment ids removed locally and not yet saved.
	let deleted = new Set();
	let editCounter = 0;
	let focusedId = null;
	let saveTimer = null;
	let pollTimer = null;
	let saving = false;
	let savePromise = null;
	let conflicted = false;
	let conflictRetries = 0;
	// Bumped whenever a save or restore starts/ends; lets poll() detect stale reads.
	let saveSeq = 0;
	// Bumped on every navigation; async work for an older view is discarded.
	let viewGeneration = 0;

	const escapeHtml = (value) => String(value ?? '').replace(
		/[&<>"']/g,
		(character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]
	);

	// Job statuses from the service API; unknown values are shown as-is.
	const statusLabels = {
		created: __('Aangemaakt', 'avpvh-audio-transcriptie'),
		uploading: __('Uploaden', 'avpvh-audio-transcriptie'),
		queued: __('In de wachtrij', 'avpvh-audio-transcriptie'),
		converting: __('Converteren', 'avpvh-audio-transcriptie'),
		transcribing: __('Transcriberen', 'avpvh-audio-transcriptie'),
		complete: __('Klaar', 'avpvh-audio-transcriptie'),
		failed: __('Mislukt', 'avpvh-audio-transcriptie')
	};
	const statusLabel = (status) => statusLabels[status] || String(status ?? '');

	function segmentCount() {
		const count = current.segments.length;
		/* translators: %d: number of transcript segments. */
		return sprintf(_n('%d fragment', '%d fragmenten', count, 'avpvh-audio-transcriptie'), count);
	}

	async function api(path, options = {}) {
		const response = await fetch(config.root + path, {
			...options,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
				...(options.headers || {})
			}
		});
		const text = await response.text();
		let data = null;
		let parsed = true;
		try {
			data = text ? JSON.parse(text) : null;
		} catch (parseError) {
			parsed = false;
		}
		if (!response.ok || !parsed) {
			const error = new Error(data?.message || sprintf(
				/* translators: %d: HTTP status code. */
				__('De aanvraag is mislukt (HTTP %d).', 'avpvh-audio-transcriptie'),
				response.status
			));
			error.status = response.ok ? 0 : response.status;
			error.data = data;
			throw error;
		}
		return data;
	}

	function renderShell() {
		app.innerHTML = `
			<nav class="avpvh-at-tabs" aria-label="${escapeHtml(__('Pluginonderdelen', 'avpvh-audio-transcriptie'))}">
				<button type="button" data-tab="jobs" class="active">${escapeHtml(__('Transcripties', 'avpvh-audio-transcriptie'))}</button>
				${config.canManage ? `<button type="button" data-tab="settings">${escapeHtml(__('Instellingen', 'avpvh-audio-transcriptie'))}</button>` : ''}
			</nav>
			<div id="avpvh-at-notice" class="notice inline" hidden><p></p></div>
			<section id="avpvh-at-view"></section>`;

		app.querySelectorAll('[data-tab]').forEach((button) => {
			button.addEventListener('click', async () => {
				if (!(await leaveEditor())) {
					return;
				}
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

	function hasPendingChanges() {
		return dirty.size > 0 || deleted.size > 0;
	}

	function markDirty(id) {
		editCounter += 1;
		dirty.set(id, editCounter);
		scheduleSave();
	}

	function resetEditorState() {
		clearTimeout(saveTimer);
		clearTimeout(pollTimer);
		saveTimer = null;
		pollTimer = null;
		current = null;
		dirty = new Map();
		deleted = new Set();
		focusedId = null;
		conflicted = false;
	}

	// Saves pending edits and stops editor activity. Resolves false when the
	// user chooses to stay because edits could not be saved.
	async function leaveEditor() {
		if (!current) {
			viewGeneration += 1;
			return true;
		}
		clearTimeout(pollTimer);
		await flushSave();
		if (hasPendingChanges() && !window.confirm(__('Niet alle wijzigingen zijn opgeslagen. Toch de editor verlaten?', 'avpvh-audio-transcriptie'))) {
			if (current && shouldPoll()) {
				void poll();
			}
			return false;
		}
		viewGeneration += 1;
		resetEditorState();
		return true;
	}

	function notice(message, type = 'info') {
		const element = app.querySelector('#avpvh-at-notice');
		element.className = `notice notice-${type} inline`;
		element.querySelector('p').textContent = message;
		element.hidden = false;
	}

	async function renderJobList() {
		const generation = ++viewGeneration;
		const view = app.querySelector('#avpvh-at-view');
		view.innerHTML = `<p class="avpvh-at-loading">${escapeHtml(__('Transcripties laden', 'avpvh-audio-transcriptie'))}<span></span></p>`;
		try {
			const jobs = await api('jobs');
			if (generation !== viewGeneration) {
				return;
			}
			view.innerHTML = `
				<section class="avpvh-at-card">
					<h2>${escapeHtml(__('Transcripties', 'avpvh-audio-transcriptie'))}</h2>
					<div class="avpvh-at-jobs">
						${jobs.map(jobRow).join('') || `<p>${escapeHtml(__('Nog geen transcripties.', 'avpvh-audio-transcriptie'))}</p>`}
					</div>
				</section>
				${config.canEdit ? `<details class="avpvh-at-card avpvh-at-new-panel">
					<summary><strong>${escapeHtml(__('Nieuwe transcriptie', 'avpvh-audio-transcriptie'))}</strong></summary>
					<div class="avpvh-at-new">
						<label>${escapeHtml(__('Titel', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-title" type="text"></label>
						<label>${escapeHtml(__('Transcriptie-engine', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-provider" type="text" value="${escapeHtml(defaults.provider || 'faster-whisper')}" list="avpvh-at-providers"><datalist id="avpvh-at-providers"><option value="faster-whisper"><option value="openai"><option value="custom"></datalist></label>
						<label>${escapeHtml(__('Model', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-model" type="text" value="${escapeHtml(defaults.default_model || 'medium')}" list="avpvh-at-models"><datalist id="avpvh-at-models"><option value="small"><option value="medium"><option value="large-v3-turbo"></datalist></label>
						<label>${escapeHtml(__('Taal', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-language" type="text" value="${escapeHtml(defaults.default_language || 'nl')}" size="6"></label>
						<label>${escapeHtml(__('Blokgrootte (minuten)', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-chunk" type="number" min="1" max="60" value="${escapeHtml(defaults.chunk_minutes || 10)}"></label>
						<label>${escapeHtml(__('Audio', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-audio" type="file" accept="audio/*"></label>
						<button id="avpvh-at-start" type="button" class="button button-primary">${escapeHtml(__('Start transcriptie', 'avpvh-audio-transcriptie'))}</button>
					</div>
					<details class="avpvh-at-transcription-options" open>
						<summary>${escapeHtml(__('Prompt en glossary voor deze transcriptie', 'avpvh-audio-transcriptie'))}</summary>
						<label>${escapeHtml(__('Prompt', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-prompt" rows="5">${escapeHtml(defaults.default_prompt || '')}</textarea></label>
						<label>${escapeHtml(__('Glossary', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-glossary" rows="5" placeholder="${escapeHtml(__('Eén term per regel of komma-gescheiden', 'avpvh-audio-transcriptie'))}">${escapeHtml(defaults.default_glossary || '')}</textarea></label>
					</details>
					<progress id="avpvh-at-upload" max="100" value="0" hidden></progress>
				</details>` : ''}`;
			view.querySelector('#avpvh-at-start')?.addEventListener('click', startJob);
			view.querySelectorAll('.avpvh-at-job').forEach((button) => button.addEventListener('click', () => openJob(Number(button.dataset.id))));
		} catch (error) {
			if (generation === viewGeneration) {
				view.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
			}
		}
	}

	function jobRow(job) {
		return `<button type="button" class="avpvh-at-job" data-id="${job.id}">
			<strong>${escapeHtml(job.title)}</strong>
			<span>${escapeHtml(__('Open transcriptie', 'avpvh-audio-transcriptie'))} · ${escapeHtml(statusLabel(job.status))} · ${Math.round(job.progress)}%</span>
			<progress max="100" value="${job.progress}"></progress>
		</button>`;
	}

	async function startJob() {
		const file = app.querySelector('#avpvh-at-audio').files[0];
		if (!file) {
			notice(__('Kies eerst een audiobestand.', 'avpvh-audio-transcriptie'), 'warning');
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
					provider: app.querySelector('#avpvh-at-provider').value,
					model: app.querySelector('#avpvh-at-model').value,
					language: app.querySelector('#avpvh-at-language').value,
					chunk_minutes: Number(app.querySelector('#avpvh-at-chunk').value),
					prompt: app.querySelector('#avpvh-at-prompt').value,
					glossary: app.querySelector('#avpvh-at-glossary').value
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
					reject(new Error(sprintf(
						/* translators: %d: HTTP status code. */
						__('Upload mislukt (HTTP %d).', 'avpvh-audio-transcriptie'),
						request.status
					)));
				}
			});
			request.addEventListener('error', () => reject(new Error(__('De uploadverbinding is mislukt.', 'avpvh-audio-transcriptie'))));
			request.send(file);
		});
	}

	async function openJob(id) {
		resetEditorState();
		const generation = ++viewGeneration;
		const view = app.querySelector('#avpvh-at-view');
		view.innerHTML = `<p class="avpvh-at-loading">${escapeHtml(__('Transcriptie laden', 'avpvh-audio-transcriptie'))}<span></span></p>`;
		try {
			const job = await api(`jobs/${id}`);
			if (generation !== viewGeneration) {
				return;
			}
			current = job;
			renderEditor();
			if (shouldPoll()) {
				pollTimer = setTimeout(poll, 3000);
			}
		} catch (error) {
			if (generation === viewGeneration) {
				view.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
			}
		}
	}

	function renderEditor() {
		const view = app.querySelector('#avpvh-at-view');
		const speakers = uniqueValues(current.segments, 'speaker');
		const parties = uniqueValues(current.segments, 'party');
		view.innerHTML = `
			<header class="avpvh-at-editor-head">
				<button id="avpvh-at-back" type="button" class="button">← ${escapeHtml(__('Overzicht', 'avpvh-audio-transcriptie'))}</button>
				<div class="avpvh-at-editor-main">
					<h2>${config.canEdit ? `<button id="avpvh-at-rename" type="button" class="avpvh-at-title-button" title="${escapeHtml(__('Naam wijzigen', 'avpvh-audio-transcriptie'))}">${escapeHtml(current.title)}</button>` : escapeHtml(current.title)}</h2>
					<div><span id="avpvh-at-status">${escapeHtml(statusLabel(current.status))}</span> · <span id="avpvh-at-percent">${Math.round(current.progress)}%</span> <span id="avpvh-at-save-state" aria-live="polite"></span></div>
					<progress id="avpvh-at-progress" max="100" value="${current.progress}"></progress>
					${playerHtml()}
				</div>
				<div class="avpvh-at-actions">
					<details class="avpvh-at-action-menu"><summary class="button">${escapeHtml(__('Transcriptie', 'avpvh-audio-transcriptie'))}</summary><div>
						<button id="avpvh-at-storage" type="button" class="button">${escapeHtml(__('Bestanden & opslag', 'avpvh-audio-transcriptie'))}</button>
						<button id="avpvh-at-history" type="button" class="button">${escapeHtml(__('Geschiedenis', 'avpvh-audio-transcriptie'))}</button>
					</div></details>
					<details class="avpvh-at-action-menu"><summary class="button">${escapeHtml(__('Exporteren', 'avpvh-audio-transcriptie'))}</summary><div>
						<button id="avpvh-at-export-html" type="button" class="button">${escapeHtml(__('HTML-bestand', 'avpvh-audio-transcriptie'))}</button>
						<button id="avpvh-at-export-markdown" type="button" class="button">${escapeHtml(__('Markdown-tabel', 'avpvh-audio-transcriptie'))}</button>
					</div></details>
				</div>
			</header>
			<datalist id="avpvh-at-speakers">${speakers.map((value) => `<option value="${escapeHtml(value)}">`).join('')}</datalist>
			<datalist id="avpvh-at-parties">${parties.map((value) => `<option value="${escapeHtml(value)}">`).join('')}</datalist>
			<div id="avpvh-at-rename-panel" class="avpvh-at-card" hidden></div>
			<div id="avpvh-at-storage-panel" class="avpvh-at-card" hidden></div>
			<div id="avpvh-at-history-panel" class="avpvh-at-card" hidden></div>
			${config.canPublish ? `<details id="avpvh-at-publish-panel" class="avpvh-at-card avpvh-at-section">
				<summary><strong>${escapeHtml(__('Transcriptiepagina maken', 'avpvh-audio-transcriptie'))}</strong></summary>
				<div class="avpvh-at-panel-body"><p>${escapeHtml(__('Open deze sectie om de shortcode te bekijken of een WordPress-pagina te maken.', 'avpvh-audio-transcriptie'))}</p></div>
			</details>` : ''}
			<details id="avpvh-at-summary-panel" class="avpvh-at-card avpvh-at-section" ${config.canEdit || current.summary ? '' : 'hidden'}>
				<summary><strong>${escapeHtml(config.canEdit ? __('Samenvatting maken', 'avpvh-audio-transcriptie') : __('Samenvatting', 'avpvh-audio-transcriptie'))}</strong></summary>
				<div class="avpvh-at-panel-body">${current.summary ? `<div class="avpvh-at-summary-result">${current.summary}</div>` : `<p class="avpvh-at-summary-placeholder">${escapeHtml(__('Open deze sectie om een samenvatting te laten maken.', 'avpvh-audio-transcriptie'))}</p>`}</div>
			</details>
			<details class="avpvh-at-card avpvh-at-section avpvh-at-transcript-section" open>
				<summary><strong>${escapeHtml(__('Transcriptie', 'avpvh-audio-transcriptie'))}</strong> <span id="avpvh-at-segment-count">${escapeHtml(segmentCount())}</span></summary>
				<div class="avpvh-at-filter"><label>${escapeHtml(__('Zoeken', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-search" type="search"></label><span id="avpvh-at-count"></span></div>
				<div id="avpvh-at-segments">${current.segments.map(segmentRow).join('')}</div>
			</details>`;

		view.querySelector('#avpvh-at-back').addEventListener('click', async () => {
			if (await leaveEditor()) {
				renderJobList();
			}
		});
		view.querySelector('#avpvh-at-rename')?.addEventListener('click', renderRenamePanel);
		view.querySelector('#avpvh-at-storage').addEventListener('click', renderStoragePanel);
		view.querySelector('#avpvh-at-export-html').addEventListener('click', exportHtml);
		view.querySelector('#avpvh-at-export-markdown').addEventListener('click', exportMarkdown);
		view.querySelector('#avpvh-at-history').addEventListener('click', renderHistory);
		view.querySelector('#avpvh-at-publish-panel')?.addEventListener('toggle', (event) => {
			const body = event.currentTarget.querySelector('.avpvh-at-panel-body');
			if (event.currentTarget.open && 'yes' !== body.dataset.loaded && 'yes' !== body.dataset.loading) {
				body.dataset.loading = 'yes';
				renderPublishPanel().finally(() => { body.dataset.loading = ''; });
			}
		});
		view.querySelector('#avpvh-at-summary-panel').addEventListener('toggle', (event) => {
			const body = event.currentTarget.querySelector('.avpvh-at-panel-body');
			if (config.canEdit && event.currentTarget.open && 'yes' !== body.dataset.loaded) {
				renderSummaryPanel();
			}
		});
		view.querySelector('#avpvh-at-search').addEventListener('input', filterRows);
		app.querySelectorAll('.avpvh-at-segment').forEach(bindSegmentRow);
		bindAudioSync();
		setFocus(current.segments[0]?.id || null, false);
	}

	function playerHtml() {
		return current.audio_url ? `<audio id="avpvh-at-player" controls preload="metadata" src="${escapeHtml(current.audio_url)}"></audio>` : '';
	}

	function labelOptions(field) {
		return uniqueValues(current.segments, field).map((value) => `<option value="${escapeHtml(value)}">`).join('');
	}

	// Rebuilds only the segment rows, keeping the audio player, panels, search
	// filter and the caret of the field being edited.
	function renderSegments() {
		const container = app.querySelector('#avpvh-at-segments');
		if (!container) {
			return;
		}
		const active = document.activeElement;
		const activeRow = active && container.contains(active) ? active.closest('.avpvh-at-segment') : null;
		const activeId = activeRow?.dataset.id;
		const activeClass = active && activeRow ? ['textarea', '.avpvh-at-party', '.avpvh-at-speaker'].find((selector) => active.matches(selector)) : null;
		const selection = activeClass && 'selectionStart' in active ? [active.selectionStart, active.selectionEnd] : null;

		container.innerHTML = current.segments.map(segmentRow).join('');
		container.querySelectorAll('.avpvh-at-segment').forEach(bindSegmentRow);
		app.querySelector('#avpvh-at-speakers').innerHTML = labelOptions('speaker');
		app.querySelector('#avpvh-at-parties').innerHTML = labelOptions('party');
		app.querySelector('#avpvh-at-segment-count').textContent = segmentCount();
		applyFilter(false);
		const keepFocus = current.segments.some((segment) => segment.id === focusedId) ? focusedId : current.segments[0]?.id || null;
		setFocus(keepFocus, false);

		if (activeId && activeClass) {
			const field = container.querySelector(`.avpvh-at-segment[data-id="${cssEscape(activeId)}"] ${activeClass}`);
			if (field) {
				field.focus({ preventScroll: true });
				if (selection) {
					field.setSelectionRange(selection[0], selection[1]);
				}
			}
		}
	}

	function showSummary() {
		const panel = app.querySelector('#avpvh-at-summary-panel');
		if (!panel || !current.summary) {
			return;
		}
		panel.hidden = false;
		const body = panel.querySelector('.avpvh-at-panel-body');
		let box = body.querySelector('.avpvh-at-summary-result');
		if (!box) {
			box = document.createElement('div');
			box.className = 'avpvh-at-summary-result';
			body.prepend(box);
		}
		body.querySelector('.avpvh-at-summary-placeholder')?.remove();
		box.innerHTML = `<h3>${escapeHtml(__('Samenvatting', 'avpvh-audio-transcriptie'))}</h3>${current.summary}`;
	}

	function uniqueValues(segments, field) {
		return [...new Set(segments.map((segment) => segment[field]).filter(Boolean))].sort((left, right) => left.localeCompare(right));
	}

	function revealPanel(panel) {
		panel.hidden = false;
		if ('DETAILS' === panel.tagName) {
			panel.open = true;
		}
		requestAnimationFrame(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }));
	}

	function renderRenamePanel() {
		const panel = app.querySelector('#avpvh-at-rename-panel');
		if (!panel.hidden) {
			panel.hidden = true;
			return;
		}
		revealPanel(panel);
		panel.innerHTML = `<h3>${escapeHtml(__('Naam wijzigen', 'avpvh-audio-transcriptie'))}</h3>
			<label>${escapeHtml(__('Naam transcriptie', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-new-title" type="text" value="${escapeHtml(current.title)}"></label>
			<p class="description">${escapeHtml(__('Dit wijzigt de naam in WordPress en in nieuwe exports. Het oorspronkelijke audiobestand en eerder gemaakte TXT-, SRT- en audiofragmentbestanden worden niet hernoemd: die bestanden staan buiten de beheerde transcriptiedatabase.', 'avpvh-audio-transcriptie'))}</p>
			<button id="avpvh-at-save-title" type="button" class="button button-primary">${escapeHtml(__('Naam opslaan', 'avpvh-audio-transcriptie'))}</button>
			<span id="avpvh-at-title-result" aria-live="polite"></span>`;
		panel.querySelector('#avpvh-at-save-title').addEventListener('click', async () => {
			const title = panel.querySelector('#avpvh-at-new-title').value.trim();
			const result = panel.querySelector('#avpvh-at-title-result');
			if (!title) {
				result.textContent = ` ${__('Vul een naam in.', 'avpvh-audio-transcriptie')}`;
				return;
			}
			try {
				const job = current;
				const updated = await api(`jobs/${job.id}/title`, {
					method: 'PUT',
					body: JSON.stringify({ title })
				});
				// Only take the title: the response's segments would overwrite unsaved edits.
				job.title = updated.title;
				if (job === current) {
					app.querySelector('.avpvh-at-title-button').textContent = job.title;
				}
				result.textContent = ` ${__('Naam opgeslagen.', 'avpvh-audio-transcriptie')}`;
			} catch (error) {
				result.textContent = ` ${error.message}`;
			}
		});
	}

	function renderStoragePanel() {
		const panel = app.querySelector('#avpvh-at-storage-panel');
		if (!panel.hidden) {
			panel.hidden = true;
			return;
		}
		revealPanel(panel);
		panel.innerHTML = `<h3>${escapeHtml(__('Bestanden en opslag', 'avpvh-audio-transcriptie'))}</h3>
			<dl class="avpvh-at-storage-list">
				<dt>${escapeHtml(__('Audio', 'avpvh-audio-transcriptie'))}</dt><dd>${current.audio_url ? `<a href="${escapeHtml(current.audio_url)}" target="_blank" rel="noopener">${escapeHtml(current.audio_name || current.audio_url)}</a>` : escapeHtml(current.audio_name || __('Geen audiolocatie beschikbaar', 'avpvh-audio-transcriptie'))}</dd>
				<dt>${escapeHtml(__('Transcriptie', 'avpvh-audio-transcriptie'))}</dt><dd>${escapeHtml(sprintf(
					/* translators: 1: transcription ID, 2: number of segments. */
					_n('WordPress-database, transcriptie %1$d; %2$d fragment.', 'WordPress-database, transcriptie %1$d; %2$d fragmenten.', current.segments.length, 'avpvh-audio-transcriptie'),
					current.id,
					current.segments.length
				))}</dd>
				<dt>${escapeHtml(__('Correctiehistorie', 'avpvh-audio-transcriptie'))}</dt><dd>${escapeHtml(__('WordPress-database; maximaal 50 bewaarde revisies.', 'avpvh-audio-transcriptie'))}</dd>
				<dt>${escapeHtml(__('HTML/Markdown-export', 'avpvh-audio-transcriptie'))}</dt><dd>${escapeHtml(__('Wordt door de browser in de map Downloads geplaatst.', 'avpvh-audio-transcriptie'))}</dd>
				<dt>${escapeHtml(__('WordPress-pagina', 'avpvh-audio-transcriptie'))}</dt><dd>${escapeHtml(__('Gebruik “Transcriptiepagina maken” om een live pagina te maken die altijd de actuele databaseversie toont.', 'avpvh-audio-transcriptie'))}</dd>
			</dl>`;
	}

	function segmentRow(segment) {
		return `<article class="avpvh-at-segment" data-id="${escapeHtml(segment.id)}" tabindex="0">
			<button type="button" class="avpvh-at-time" title="${escapeHtml(__('Speel vanaf dit fragment', 'avpvh-audio-transcriptie'))}">${clock(segment.start)}</button>
			<label><span>${escapeHtml(__('Partij', 'avpvh-audio-transcriptie'))}</span><input class="avpvh-at-party" type="text" list="avpvh-at-parties" value="${escapeHtml(segment.party)}" ${config.canEdit ? '' : 'disabled'}></label>
			<label><span>${escapeHtml(__('Spreker', 'avpvh-audio-transcriptie'))}</span><input class="avpvh-at-speaker" type="text" list="avpvh-at-speakers" value="${escapeHtml(segment.speaker)}" ${config.canEdit ? '' : 'disabled'}></label>
			<label class="avpvh-at-text"><span>${escapeHtml(__('Transcriptie', 'avpvh-audio-transcriptie'))}</span><textarea ${config.canEdit ? '' : 'readonly'}>${escapeHtml(segment.text)}</textarea></label>
			${config.canEdit ? `<div class="avpvh-at-row-actions"><button type="button" class="button avpvh-at-insert-row" title="${escapeHtml(__('Zin na dit fragment toevoegen', 'avpvh-audio-transcriptie'))}" aria-label="${escapeHtml(__('Zin na dit fragment toevoegen', 'avpvh-audio-transcriptie'))}">+</button><button type="button" class="button avpvh-at-delete-row" title="${escapeHtml(__('Deze zin verwijderen', 'avpvh-audio-transcriptie'))}" aria-label="${escapeHtml(__('Deze zin verwijderen', 'avpvh-audio-transcriptie'))}">−</button></div>` : ''}
		</article>`;
	}

	function bindSegmentRow(row) {
		const id = row.dataset.id;
		const text = row.querySelector('textarea');
		const speaker = row.querySelector('.avpvh-at-speaker');
		const party = row.querySelector('.avpvh-at-party');
		const change = () => {
			const segment = current.segments.find((item) => item.id === id);
			if (!segment) {
				return;
			}
			segment.text = text.value;
			segment.speaker = speaker.value;
			segment.party = party.value;
			markDirty(id);
		};
		if (config.canEdit) {
			text.addEventListener('input', change);
			speaker.addEventListener('input', () => {
				const inferred = inferredParty(speaker.value);
				if (inferred) {
					party.value = inferred;
				}
				change();
			});
			party.addEventListener('input', change);
			row.querySelector('.avpvh-at-insert-row').addEventListener('mousedown', (event) => event.preventDefault());
			row.querySelector('.avpvh-at-insert-row').addEventListener('click', () => {
				setFocus(id, false);
				insertSegment();
			});
			const deleteButton = row.querySelector('.avpvh-at-delete-row');
			deleteButton.addEventListener('click', () => {
				setFocus(id, false);
				if ('yes' !== deleteButton.dataset.confirm) {
					deleteButton.dataset.confirm = 'yes';
					deleteButton.textContent = __('Zeker?', 'avpvh-audio-transcriptie');
					deleteButton.title = __('Klik nogmaals om deze zin te verwijderen', 'avpvh-audio-transcriptie');
					setTimeout(() => {
						if (deleteButton.isConnected) {
							deleteButton.dataset.confirm = '';
							deleteButton.textContent = '−';
							deleteButton.title = __('Deze zin verwijderen', 'avpvh-audio-transcriptie');
						}
					}, 3000);
					return;
				}
				deleteSegment();
			});
		}
		row.addEventListener('mousedown', () => setFocus(id, false));
		row.addEventListener('focusin', () => setFocus(id, false));
		row.querySelector('.avpvh-at-time').addEventListener('click', () => playSegment(id));
	}

	function inferredParty(speaker) {
		const counts = new Map();
		current.segments.forEach((segment) => {
			if (segment.speaker === speaker && segment.party) {
				counts.set(segment.party, (counts.get(segment.party) || 0) + 1);
			}
		});
		return [...counts.entries()].sort((left, right) => right[1] - left[1])[0]?.[0] || '';
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

	function bindAudioSync() {
		const player = app.querySelector('#avpvh-at-player');
		if (!player) {
			return;
		}
		const sync = () => {
			const time = player.currentTime;
			let active = null;
			for (let index = 0; index < current.segments.length; index += 1) {
				const segment = current.segments[index];
				const nextStart = current.segments[index + 1]?.start ?? Math.max(segment.end, segment.start + 0.001);
				if (time >= segment.start && time < nextStart) {
					active = segment;
					break;
				}
			}
			if (active && active.id !== focusedId) {
				setFocus(active.id, true);
			}
		};
		player.addEventListener('timeupdate', sync);
		player.addEventListener('seeked', sync);
		player.addEventListener('play', sync);
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
			markDirty(source.id);
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
		const sourceRow = source ? app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(source.id)}"]`) : null;
		if (sourceRow) {
			sourceRow.insertAdjacentHTML('afterend', segmentRow(segment));
		} else {
			app.querySelector('#avpvh-at-segments').insertAdjacentHTML('afterbegin', segmentRow(segment));
		}
		const row = app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(id)}"]`);
		bindSegmentRow(row);
		app.querySelector('#avpvh-at-segment-count').textContent = segmentCount();
		setFocus(id);
		row.querySelector('textarea')?.focus();
		markDirty(id);
	}

	function deleteSegment() {
		const index = current.segments.findIndex((segment) => segment.id === focusedId);
		if (index < 0) {
			notice(__('Kies eerst de zin die je wilt verwijderen.', 'avpvh-audio-transcriptie'), 'warning');
			return;
		}
		const nextFocus = current.segments[index + 1]?.id || current.segments[index - 1]?.id || null;
		const [removed] = current.segments.splice(index, 1);
		app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(focusedId)}"]`)?.remove();
		app.querySelector('#avpvh-at-segment-count').textContent = segmentCount();
		dirty.delete(removed.id);
		deleted.add(removed.id);
		setFocus(nextFocus, true);
		scheduleSave();
	}

	function exportHtml() {
		const title = escapeHtml(current.title || __('Transcriptie', 'avpvh-audio-transcriptie'));
		const summary = current.summary ? `<section class="summary"><h2>${escapeHtml(__('Samenvatting', 'avpvh-audio-transcriptie'))}</h2>${current.summary}</section>` : '';
		const audio = current.audio_url ? `<audio id="player" controls preload="metadata" src="${escapeHtml(current.audio_url)}"></audio>` : '';
		const rows = current.segments.map((segment) => `<article class="segment" data-start="${Number(segment.start) || 0}">
			<button type="button" class="time">${clock(segment.start)}</button>
			<div><div class="labels"><strong>${escapeHtml(segment.speaker || __('Spreker onbekend', 'avpvh-audio-transcriptie'))}</strong>${segment.party ? ` <span>${escapeHtml(segment.party)}</span>` : ''}</div><p>${escapeHtml(segment.text).replace(/\n/g, '<br>')}</p></div>
		</article>`).join('');
		const documentHtml = `<!doctype html><html lang="${escapeHtml(document.documentElement.lang || 'nl')}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title><style>
		body{max-width:1100px;margin:2rem auto;padding:0 1rem;font:16px/1.55 system-ui,sans-serif;color:#1d2327}audio{width:100%;position:sticky;top:0;background:#fff}.summary,.segment{padding:1rem;border-bottom:1px solid #ddd}.segment{display:grid;grid-template-columns:7rem 1fr;gap:1rem}.time{border:0;background:none;color:#06c;cursor:pointer}.labels span{margin-left:.5rem;color:#555}p{margin:.35rem 0}.is-current{background:#eef7ff}@media(max-width:600px){.segment{grid-template-columns:1fr}}</style></head><body><h1>${title}</h1>${audio}${summary}<main>${rows}</main><script>
		document.querySelectorAll('.segment').forEach(row=>row.querySelector('.time').addEventListener('click',()=>{const p=document.querySelector('#player');if(p){p.currentTime=Number(row.dataset.start);p.play()}}));
		const p=document.querySelector('#player');if(p)p.addEventListener('timeupdate',()=>{let active;document.querySelectorAll('.segment').forEach(row=>{row.classList.remove('is-current');if(Number(row.dataset.start)<=p.currentTime)active=row});if(active){active.classList.add('is-current')}});
		<\/script></body></html>`;
		const blob = new Blob([documentHtml], { type: 'text/html;charset=utf-8' });
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = `${String(current.title || 'transcriptie').replace(/[^a-z0-9_-]+/gi, '-').replace(/^-|-$/g, '').toLowerCase() || 'transcriptie'}.html`;
		link.click();
		setTimeout(() => URL.revokeObjectURL(link.href), 1000);
	}

	function exportMarkdown() {
		const cell = (value) => String(value ?? '').replace(/\\/g, '\\\\').replace(/\|/g, '\\|').replace(/\r?\n/g, '<br>');
		const lines = [
			`# ${String(current.title || __('Transcriptie', 'avpvh-audio-transcriptie')).replace(/\r?\n/g, ' ')}`,
			'',
			`| ${cell(__('Tijd', 'avpvh-audio-transcriptie'))} | ${cell(__('Partij', 'avpvh-audio-transcriptie'))} | ${cell(__('Spreker', 'avpvh-audio-transcriptie'))} | ${cell(__('Transcriptie', 'avpvh-audio-transcriptie'))} |`,
			'| --- | --- | --- | --- |',
			...current.segments.map((segment) => `| ${clock(segment.start)} | ${cell(segment.party)} | ${cell(segment.speaker)} | ${cell(segment.text)} |`)
		];
		const blob = new Blob([`${lines.join('\n')}\n`], { type: 'text/markdown;charset=utf-8' });
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = `${String(current.title || 'transcriptie').replace(/[^a-z0-9_-]+/gi, '-').replace(/^-|-$/g, '').toLowerCase() || 'transcriptie'}.md`;
		link.click();
		setTimeout(() => URL.revokeObjectURL(link.href), 1000);
	}

	function filterRows() {
		applyFilter(true);
	}

	function applyFilter(moveToFirst) {
		const query = (app.querySelector('#avpvh-at-search')?.value || '').trim().toLocaleLowerCase();
		const visibleRows = [];
		app.querySelectorAll('.avpvh-at-segment').forEach((row) => {
			const segment = current.segments.find((item) => item.id === row.dataset.id);
			row.hidden = Boolean(query) && !`${segment?.text} ${segment?.speaker} ${segment?.party}`.toLocaleLowerCase().includes(query);
			if (!row.hidden) {
				visibleRows.push(row);
			}
		});
		let count = '';
		if (query && visibleRows.length) {
			/* translators: %d: number of matching segments. */
			count = sprintf(_n('%d resultaat', '%d resultaten', visibleRows.length, 'avpvh-audio-transcriptie'), visibleRows.length);
		} else if (query) {
			count = __('Geen resultaten', 'avpvh-audio-transcriptie');
		}
		app.querySelector('#avpvh-at-count').textContent = count;
		if (query && moveToFirst) {
			setFocus(visibleRows[0]?.dataset.id || null, true);
		}
	}

	async function renderPublishPanel() {
		const panel = app.querySelector('#avpvh-at-publish-panel');
		const body = panel.querySelector('.avpvh-at-panel-body');
		if (panel.open && 'yes' === body.dataset.loaded) {
			panel.open = false;
			return;
		}
		body.dataset.loading = 'yes';
		revealPanel(panel);
		body.innerHTML = `<p class="avpvh-at-loading">${escapeHtml(__('Publicatieopties laden', 'avpvh-audio-transcriptie'))}<span></span></p>`;
		try {
			const options = await api(`publishing-options?job_id=${current.id}`);
			const existing = options.existing_page;
			const shortcode = `[avpvh_audio_transcriptie id="${current.id}"]`;
			const menus = [...(options.menus || []), ...(options.block_menus || [])];
			panel.querySelector('summary strong').textContent = existing ? __('Transcriptiepagina bijwerken', 'avpvh-audio-transcriptie') : __('Transcriptiepagina maken', 'avpvh-audio-transcriptie');
			body.dataset.loaded = 'yes';
			body.innerHTML = `<p>${escapeHtml(__('De pagina gebruikt de actuele transcriptie uit de editor; latere correcties verschijnen dus automatisch.', 'avpvh-audio-transcriptie'))}</p>
				<label>${escapeHtml(__('Benodigde shortcode', 'avpvh-audio-transcriptie'))} <span class="avpvh-at-shortcode-field"><input id="avpvh-at-shortcode" type="text" readonly value="${escapeHtml(shortcode)}"><button id="avpvh-at-copy-shortcode" type="button" class="button">${escapeHtml(__('Kopiëren', 'avpvh-audio-transcriptie'))}</button></span></label>
				<p class="description">${escapeHtml(__('Plak deze shortcode in een shortcodeblok op een bestaande pagina. “Pagina maken” doet dit automatisch.', 'avpvh-audio-transcriptie'))}</p>
				<label>${escapeHtml(__('Paginatitel', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-page-title" type="text" value="${escapeHtml(existing?.title || current.title)}"></label>
				<label>${escapeHtml(__('Status', 'avpvh-audio-transcriptie'))} <select id="avpvh-at-page-status"><option value="draft" ${'publish' !== existing?.status ? 'selected' : ''}>${escapeHtml(__('Concept', 'avpvh-audio-transcriptie'))}</option><option value="publish" ${'publish' === existing?.status ? 'selected' : ''}>${escapeHtml(__('Gepubliceerd', 'avpvh-audio-transcriptie'))}</option></select></label>
				<label>${escapeHtml(__('WordPress-menu', 'avpvh-audio-transcriptie'))} <select id="avpvh-at-page-menu"><option value="0">${escapeHtml(__('Niet toevoegen / huidige menuplaats behouden', 'avpvh-audio-transcriptie'))}</option>${menus.map((menu) => `<option value="${escapeHtml(menu.value)}" ${menu.value === existing?.menu_ref ? 'selected' : ''}>${escapeHtml(menu.name)}</option>`).join('')}</select></label>
				<label>${escapeHtml(__('Onder bestaand menu-item', 'avpvh-audio-transcriptie'))} <select id="avpvh-at-page-parent"><option value="0">${escapeHtml(__('Op hoofdniveau', 'avpvh-audio-transcriptie'))}</option></select></label>
				<p class="description">${escapeHtml(__('Kies Gepubliceerd om de pagina voor bezoekers bereikbaar te maken. Menuplaatsing vereist het WordPress-recht om menu’s te wijzigen.', 'avpvh-audio-transcriptie'))}</p>
				<button id="avpvh-at-page-create" type="button" class="button button-primary">${escapeHtml(existing ? __('Pagina bijwerken', 'avpvh-audio-transcriptie') : __('Pagina maken', 'avpvh-audio-transcriptie'))}</button>
				<span id="avpvh-at-page-result" aria-live="polite"></span>`;

			const menu = panel.querySelector('#avpvh-at-page-menu');
			const parent = panel.querySelector('#avpvh-at-page-parent');
			const status = panel.querySelector('#avpvh-at-page-status');
			const updateParents = () => {
				const selected = menus.find((item) => item.value === menu.value);
				parent.innerHTML = `<option value="0">${escapeHtml(__('Op hoofdniveau', 'avpvh-audio-transcriptie'))}</option>` + (selected?.items || []).filter((item) => item.value !== existing?.menu_item_ref).map((item) => `<option value="${escapeHtml(item.value)}" ${item.value === existing?.parent_ref ? 'selected' : ''}>${escapeHtml(item.title)}</option>`).join('');
				menu.disabled = !options.can_manage_menus;
				parent.disabled = !options.can_manage_menus || '0' === menu.value;
			};
			menu.addEventListener('change', () => {
				if ('0' !== menu.value) {
					status.value = 'publish';
				}
				updateParents();
			});
			status.addEventListener('change', updateParents);
			updateParents();
			panel.querySelector('#avpvh-at-copy-shortcode').addEventListener('click', async () => {
				const input = panel.querySelector('#avpvh-at-shortcode');
				input.select();
				try {
					await navigator.clipboard.writeText(shortcode);
					setSaveState(__('Shortcode gekopieerd', 'avpvh-audio-transcriptie'));
				} catch (error) {
					setSaveState(__('Shortcode geselecteerd; druk op Ctrl+C', 'avpvh-audio-transcriptie'));
				}
			});

			panel.querySelector('#avpvh-at-page-create').addEventListener('click', async () => {
				const button = panel.querySelector('#avpvh-at-page-create');
				const resultText = panel.querySelector('#avpvh-at-page-result');
				button.disabled = true;
				resultText.textContent = ` ${__('Pagina opslaan…', 'avpvh-audio-transcriptie')}`;
				try {
					const result = await api(`jobs/${current.id}/publish`, {
						method: 'POST',
						body: JSON.stringify({
							title: panel.querySelector('#avpvh-at-page-title').value,
							status: status.value,
							menu_ref: 'publish' === status.value ? menu.value : '',
							parent_ref: 'publish' === status.value ? parent.value : ''
						})
					});
					resultText.innerHTML = ` ${escapeHtml(__('Opgeslagen.', 'avpvh-audio-transcriptie'))} <a href="${escapeHtml(result.url)}" target="_blank" rel="noopener">${escapeHtml(__('Bekijk pagina', 'avpvh-audio-transcriptie'))}</a> · <a href="${escapeHtml(result.edit_url)}">${escapeHtml(__('Bewerk in WordPress', 'avpvh-audio-transcriptie'))}</a>`;
					button.textContent = __('Pagina bijwerken', 'avpvh-audio-transcriptie');
					panel.querySelector('summary strong').textContent = __('Transcriptiepagina bijwerken', 'avpvh-audio-transcriptie');
				} catch (error) {
					resultText.textContent = ` ${error.message}`;
				} finally {
					button.disabled = false;
				}
			});
		} catch (error) {
			body.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
		} finally {
			body.dataset.loading = '';
		}
	}

	function scheduleSave() {
		clearTimeout(saveTimer);
		setSaveState(__('Wijzigingen…', 'avpvh-audio-transcriptie'));
		saveTimer = setTimeout(save, 1200);
	}

	async function flushSave() {
		clearTimeout(saveTimer);
		if (savePromise) {
			await savePromise;
		}
		// The finished save may have scheduled a follow-up for later edits.
		clearTimeout(saveTimer);
		if (hasPendingChanges()) {
			await save();
		}
	}

	function save() {
		if (!savePromise) {
			savePromise = runSave().finally(() => {
				savePromise = null;
			});
		}
		return savePromise;
	}

	async function runSave() {
		clearTimeout(saveTimer);
		if (!current || conflicted || !hasPendingChanges()) {
			return;
		}
		const job = current;
		const sentDirty = new Map(dirty);
		const sentDeleted = [...deleted];
		let retry = false;
		saving = true;
		saveSeq += 1;
		setSaveState(__('Opslaan…', 'avpvh-audio-transcriptie'));
		try {
			const result = await api(`jobs/${job.id}/segments`, {
				method: 'PUT',
				body: JSON.stringify({ revision: job.revision, segments: job.segments, dirty_ids: [...sentDirty.keys()], deleted_ids: sentDeleted })
			});
			job.revision = result.revision;
			conflictRetries = 0;
			if (job === current) {
				// Keep ids that were edited again while this request was in flight.
				sentDirty.forEach((version, id) => {
					if (dirty.get(id) === version) {
						dirty.delete(id);
					}
				});
				sentDeleted.forEach((id) => deleted.delete(id));
				setSaveState(hasPendingChanges() ? __('Wijzigingen…', 'avpvh-audio-transcriptie') : __('Opgeslagen', 'avpvh-audio-transcriptie'));
			}
		} catch (error) {
			if (job === current) {
				const newer = error.data?.data?.current;
				if (409 === error.status && newer && conflictRetries < 2) {
					// Take the newer server version, keep the unsaved local
					// edits, insertions and deletions on top of it, and save again.
					conflictRetries += 1;
					applyRemote(newer, false);
					notice(__('Er was een nieuwere versie; je wijzigingen zijn daarmee samengevoegd.', 'avpvh-audio-transcriptie'), 'warning');
				} else if (409 === error.status) {
					conflicted = true;
					setSaveState(__('Niet opgeslagen', 'avpvh-audio-transcriptie'));
					notice(__('Er is een nieuwere versie. Kopieer je laatste wijzigingen en herlaad de transcriptie om ze samen te voegen.', 'avpvh-audio-transcriptie'), 'error');
				} else {
					retry = true;
					setSaveState(__('Opslaan mislukt; nieuwe poging…', 'avpvh-audio-transcriptie'));
				}
			}
		} finally {
			saving = false;
			saveSeq += 1;
		}
		if (job !== current) {
			return;
		}
		if (retry) {
			saveTimer = setTimeout(save, 5000);
		} else if (!conflicted && hasPendingChanges()) {
			scheduleSave();
		}
	}

	function setSaveState(text) {
		const element = app.querySelector('#avpvh-at-save-state');
		if (element) {
			element.textContent = text ? `· ${text}` : '';
		}
	}

	function shouldPoll() {
		return Boolean(current) && (!['complete', 'failed'].includes(current.status) || Boolean(current.summary_pending));
	}

	async function poll() {
		clearTimeout(pollTimer);
		pollTimer = null;
		if (!current) {
			return;
		}
		const generation = viewGeneration;
		const job = current;
		const seq = saveSeq;
		let delay = 3000;
		try {
			const remote = await api(`jobs/${job.id}`);
			if (generation !== viewGeneration || job !== current) {
				return;
			}
			// A save that ran during this request makes the remote segments and
			// revision older than the local ones; use only the job metadata then.
			applyRemote(remote, saving || seq !== saveSeq);
		} catch (error) {
			if (generation !== viewGeneration || job !== current) {
				return;
			}
			delay = 5000;
		}
		if (shouldPoll() && !pollTimer) {
			pollTimer = setTimeout(poll, delay);
		}
	}

	function applyRemote(remote, segmentsStale) {
		const hadPlayer = Boolean(app.querySelector('#avpvh-at-player'));
		const summaryChanged = (remote.summary || '') !== (current.summary || '');
		['status', 'progress', 'duration', 'audio_url', 'summary', 'summary_pending', 'updated_at'].forEach((field) => {
			current[field] = remote[field];
		});

		if (!segmentsStale) {
			const merged = mergeRemoteSegments(remote.segments || []);
			const changed = segmentsDiffer(current.segments, merged);
			current.segments = merged;
			current.revision = remote.revision;
			if (changed) {
				renderSegments();
			}
		}

		const progress = app.querySelector('#avpvh-at-progress');
		if (progress) {
			progress.value = current.progress;
			app.querySelector('#avpvh-at-percent').textContent = `${Math.round(current.progress)}%`;
			app.querySelector('#avpvh-at-status').textContent = current.status;
		}
		// Add the player once audio becomes available; never swap the source of
		// an existing player, which would interrupt playback.
		if (!hadPlayer && current.audio_url && progress) {
			progress.insertAdjacentHTML('afterend', playerHtml());
			bindAudioSync();
		}
		if (summaryChanged) {
			showSummary();
		}
	}

	// Server segments, with unsaved local edits, insertions and deletions kept.
	function mergeRemoteSegments(remoteSegments) {
		const local = new Map(current.segments.map((segment) => [segment.id, segment]));
		const remoteIds = new Set(remoteSegments.map((segment) => segment.id));
		const merged = remoteSegments
			.filter((segment) => !deleted.has(segment.id))
			.map((segment) => (dirty.has(segment.id) && local.has(segment.id) ? local.get(segment.id) : segment));
		current.segments.forEach((segment, index) => {
			if (remoteIds.has(segment.id) || !dirty.has(segment.id)) {
				return;
			}
			// Place an unsaved insertion after the nearest preceding local segment.
			let position = 0;
			for (let back = index - 1; back >= 0; back -= 1) {
				const found = merged.findIndex((item) => item.id === current.segments[back].id);
				if (found >= 0) {
					position = found + 1;
					break;
				}
			}
			merged.splice(position, 0, segment);
		});
		return merged;
	}

	function segmentsDiffer(left, right) {
		if (left.length !== right.length) {
			return true;
		}
		return left.some((segment, index) => {
			const other = right[index];
			return segment.id !== other.id || segment.text !== other.text || segment.speaker !== other.speaker || segment.party !== other.party || segment.start !== other.start;
		});
	}

	async function renderHistory() {
		const panel = app.querySelector('#avpvh-at-history-panel');
		if (!panel.hidden) {
			panel.hidden = true;
			return;
		}
		revealPanel(panel);
		panel.innerHTML = `<p>${escapeHtml(__('Geschiedenis laden…', 'avpvh-audio-transcriptie'))}</p>`;
		try {
			const revisions = await api(`jobs/${current.id}/history`);
			const versionLabel = (revision) => escapeHtml(sprintf(
				/* translators: 1: revision number, 2: date and time of the revision. */
				__('Versie %1$d · %2$s', 'avpvh-audio-transcriptie'),
				revision.revision,
				revision.created_at
			));
			panel.innerHTML = `<h3>${escapeHtml(__('Correctiegeschiedenis', 'avpvh-audio-transcriptie'))}</h3>${revisions.map((revision) => config.canEdit ? `<button type="button" class="button avpvh-at-restore" data-revision="${revision.revision}">${versionLabel(revision)}</button>` : `<p>${versionLabel(revision)}</p>`).join('') || `<p>${escapeHtml(__('Nog geen eerdere versies.', 'avpvh-audio-transcriptie'))}</p>`}`;
			panel.querySelectorAll('.avpvh-at-restore').forEach((button) => button.addEventListener('click', async () => {
				if (!window.confirm(__('Deze versie terugzetten? De huidige versie blijft in de geschiedenis bewaard.', 'avpvh-audio-transcriptie'))) {
					return;
				}
				const job = current;
				button.disabled = true;
				try {
					// Save pending edits first so they end up in the history too.
					await flushSave();
					clearTimeout(pollTimer);
					saveSeq += 1;
					const restored = await api(`jobs/${job.id}/restore/${button.dataset.revision}`, { method: 'POST', body: '{}' });
					saveSeq += 1;
					if (job !== current) {
						return;
					}
					clearTimeout(saveTimer);
					current = restored;
					dirty = new Map();
					deleted = new Set();
					conflicted = false;
					renderEditor();
					if (shouldPoll()) {
						pollTimer = setTimeout(poll, 3000);
					}
				} catch (error) {
					saveSeq += 1;
					button.disabled = false;
					notice(error.message, 'error');
					if (job === current && shouldPoll() && !pollTimer) {
						pollTimer = setTimeout(poll, 3000);
					}
				}
			}));
		} catch (error) {
			panel.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
		}
	}

	function renderSummaryPanel() {
		const panel = app.querySelector('#avpvh-at-summary-panel');
		const body = panel.querySelector('.avpvh-at-panel-body');
		if (panel.open && 'yes' === body.dataset.loaded) {
			panel.open = false;
			return;
		}
		revealPanel(panel);
		body.dataset.loaded = 'yes';
		body.innerHTML = `${current.summary ? `<div class="avpvh-at-summary-result"><h3>${escapeHtml(__('Bestaande samenvatting', 'avpvh-audio-transcriptie'))}</h3>${current.summary}</div>` : ''}
			${config.summaryConfigured ? `<p class="description">${escapeHtml(sprintf(
				/* translators: %s: how the summary is generated, e.g. the transcription service. */
				__('Route: %s.', 'avpvh-audio-transcriptie'),
				summaryRoute()
			))}</p>` : `<p class="notice notice-warning inline"><strong>${escapeHtml(__('Samenvatting is nog niet geconfigureerd.', 'avpvh-audio-transcriptie'))}</strong> ${escapeHtml(__('Configureer een WordPress AI-connector, vul onder Instellingen de directe LLM API-URL en het model in, of stel de transcriptieservice in.', 'avpvh-audio-transcriptie'))}</p>`}
			${current.summary_pending ? `<p class="description">${escapeHtml(__('Er wordt op de achtergrond een samenvatting gemaakt; deze verschijnt hier zodra hij klaar is.', 'avpvh-audio-transcriptie'))}</p>` : ''}
			<label>${escapeHtml(__('Procedurele toelichting', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-summary-procedure" rows="7">${escapeHtml(defaults.summary_procedure_prompt || '')}</textarea></label>
			<p class="description">${escapeHtml(__('Beschrijf hier de werkwijze, structuur, zorgvuldigheid en gewenste vorm van het resultaat.', 'avpvh-audio-transcriptie'))}</p>
			<label>${escapeHtml(__('Inhoudelijke toelichting', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-summary-content" rows="7">${escapeHtml(defaults.summary_content_prompt || '')}</textarea></label>
			<p class="description">${escapeHtml(__('Beschrijf hier welke onderwerpen, vragen, partijen of accenten inhoudelijk extra aandacht moeten krijgen.', 'avpvh-audio-transcriptie'))}</p>
			<button id="avpvh-at-run-summary" type="button" class="button button-primary" ${config.summaryConfigured ? '' : 'disabled'}>${escapeHtml(__('Samenvatting maken', 'avpvh-audio-transcriptie'))}</button>
			${!config.summaryConfigured && config.canManage ? `<button id="avpvh-at-summary-settings" type="button" class="button">${escapeHtml(__('Open instellingen', 'avpvh-audio-transcriptie'))}</button>` : ''}
			<span id="avpvh-at-summary-result" aria-live="polite"></span>`;
		panel.querySelector('#avpvh-at-run-summary').addEventListener('click', createSummary);
		panel.querySelector('#avpvh-at-summary-settings')?.addEventListener('click', () => app.querySelector('[data-tab="settings"]').click());
	}

	function summaryRoute() {
		if (config.aiClientAvailable) {
			return __('centrale WordPress AI Client', 'avpvh-audio-transcriptie');
		}
		return defaults.summary_api_url ? __('direct ingestelde LLM-API', 'avpvh-audio-transcriptie') : __('transcriptieservice', 'avpvh-audio-transcriptie');
	}

	async function createSummary() {
		const panel = app.querySelector('#avpvh-at-summary-panel');
		const button = panel.querySelector('#avpvh-at-run-summary');
		const resultText = panel.querySelector('#avpvh-at-summary-result');
		const procedurePrompt = panel.querySelector('#avpvh-at-summary-procedure').value;
		const contentPrompt = panel.querySelector('#avpvh-at-summary-content').value;
		const job = current;
		button.disabled = true;
		resultText.textContent = ` ${__('Correcties opslaan en samenvatting maken…', 'avpvh-audio-transcriptie')}`;
		try {
			await flushSave();
			const result = await api(`jobs/${job.id}/summary`, {
				method: 'POST',
				body: JSON.stringify({ procedure_prompt: procedurePrompt, content_prompt: contentPrompt })
			});
			if (job !== current) {
				return;
			}
			if (result.summary) {
				current.summary = result.summary;
				current.summary_pending = false;
				showSummary();
				resultText.textContent = ` ${__('Samenvatting gemaakt.', 'avpvh-audio-transcriptie')}`;
			} else {
				current.summary_pending = true;
				resultText.textContent = ` ${__('De samenvatting is gestart en verschijnt zodra deze klaar is.', 'avpvh-audio-transcriptie')}`;
				void poll();
			}
		} catch (error) {
			resultText.textContent = ` ${error.message}`;
		} finally {
			button.disabled = false;
		}
	}

	async function renderSettings() {
		const generation = ++viewGeneration;
		const view = app.querySelector('#avpvh-at-view');
		view.innerHTML = `<p class="avpvh-at-loading">${escapeHtml(__('Instellingen laden', 'avpvh-audio-transcriptie'))}<span></span></p>`;
		try {
			const settings = await api('settings');
			if (generation !== viewGeneration) {
				return;
			}
			view.innerHTML = `<section class="avpvh-at-card avpvh-at-settings">
				<h2>${escapeHtml(__('Transcriptieservice', 'avpvh-audio-transcriptie'))}</h2>
				<p>${escapeHtml(__('De service-URL bepaalt met welke API de plugin praat. De gekozen engine, modelversie, prompt, glossary en blokgrootte worden bij iedere nieuwe opdracht aan die API meegestuurd.', 'avpvh-audio-transcriptie'))}</p>
				<label>${escapeHtml(__('Service-URL', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-url" type="url" value="${escapeHtml(settings.service_url)}" placeholder="https://service.example"></label>
				<label>${escapeHtml(__('Servicesleutel', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-key" type="password" placeholder="${escapeHtml(settings.license_hint || __('Servicesleutel', 'avpvh-audio-transcriptie'))}"></label>
				<h3>${escapeHtml(__('Standaard transcriptie-instellingen', 'avpvh-audio-transcriptie'))}</h3>
				<label>${escapeHtml(__('Transcriptie-engine/provider', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-provider-setting" type="text" value="${escapeHtml(settings.provider)}" list="avpvh-at-setting-providers"><datalist id="avpvh-at-setting-providers"><option value="faster-whisper"><option value="openai"><option value="custom"></datalist></label>
				<label>${escapeHtml(__('Modelversie', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-model-setting" type="text" value="${escapeHtml(settings.default_model)}" list="avpvh-at-setting-models"><datalist id="avpvh-at-setting-models"><option value="small"><option value="medium"><option value="large-v3-turbo"></datalist></label>
				<label>${escapeHtml(__('Taalcode', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-language-setting" type="text" value="${escapeHtml(settings.default_language)}"></label>
				<label>${escapeHtml(__('Blokgrootte in minuten', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-chunk-setting" type="number" min="1" max="60" value="${escapeHtml(settings.chunk_minutes)}"></label>
				<p class="description">${escapeHtml(__('Advies: 10 minuten is een goed uitgangspunt voor lange gesprekken. Gebruik 5 minuten bij weinig geheugen of snelle tussentijdse resultaten; 15–20 minuten kan bij ruime capaciteit en levert meer context per blok.', 'avpvh-audio-transcriptie'))}</p>
				<label>${escapeHtml(__('Standaardprompt', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-prompt-setting" rows="7">${escapeHtml(settings.default_prompt)}</textarea></label>
				<label>${escapeHtml(__('Standaardglossary', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-glossary-setting" rows="7" placeholder="${escapeHtml(__('Eén term per regel of komma-gescheiden', 'avpvh-audio-transcriptie'))}">${escapeHtml(settings.default_glossary)}</textarea></label>
				<p>${escapeHtml(__('Status:', 'avpvh-audio-transcriptie'))} <strong>${escapeHtml(settings.license_registered ? __('sleutel geregistreerd', 'avpvh-audio-transcriptie') : __('niet ingesteld', 'avpvh-audio-transcriptie'))}</strong></p>
				<h3>${escapeHtml(__('Samenvatting en AI-provider', 'avpvh-audio-transcriptie'))}</h3>
				<p>${escapeHtml(__('WordPress AI Client:', 'avpvh-audio-transcriptie'))} <strong>${escapeHtml(settings.ai_client_available ? __('provider beschikbaar; deze route krijgt voorrang', 'avpvh-audio-transcriptie') : __('nog geen geschikte connector geconfigureerd', 'avpvh-audio-transcriptie'))}</strong>.</p>
				<p>${escapeHtml(__('De onderstaande directe chat-completions-instellingen blijven beschikbaar als terugval wanneer WordPress geen AI-provider heeft.', 'avpvh-audio-transcriptie'))}</p>
				<label>${escapeHtml(__('LLM API-URL', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-summary-url" type="url" value="${escapeHtml(settings.summary_api_url)}" placeholder="https://provider.example/v1/chat/completions"></label>
				<label>${escapeHtml(__('LLM API-key', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-summary-key" type="password" placeholder="${escapeHtml(settings.summary_key_hint || __('API-key; leeg laten om de bestaande te behouden', 'avpvh-audio-transcriptie'))}"></label>
				<label>${escapeHtml(__('LLM-model', 'avpvh-audio-transcriptie'))} <input id="avpvh-at-summary-model" type="text" value="${escapeHtml(settings.summary_model)}" placeholder="${escapeHtml(__('Modelnaam van de provider', 'avpvh-audio-transcriptie'))}"></label>
				<label>${escapeHtml(__('Standaard procedurele toelichting', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-summary-procedure-setting" rows="6">${escapeHtml(settings.summary_procedure_prompt)}</textarea></label>
				<p class="description">${escapeHtml(__('Werkwijze, opbouw, voorzichtigheid en gewenste uitvoervorm.', 'avpvh-audio-transcriptie'))}</p>
				<label>${escapeHtml(__('Standaard inhoudelijke toelichting', 'avpvh-audio-transcriptie'))} <textarea id="avpvh-at-summary-content-setting" rows="6">${escapeHtml(settings.summary_content_prompt)}</textarea></label>
				<p class="description">${escapeHtml(__('Onderwerpen, vragen en accenten die inhoudelijk moeten worden behandeld.', 'avpvh-audio-transcriptie'))}</p>
				<p>${escapeHtml(__('Status LLM-key:', 'avpvh-audio-transcriptie'))} <strong>${escapeHtml(settings.summary_registered ? __('sleutel geregistreerd', 'avpvh-audio-transcriptie') : __('niet ingesteld of niet vereist', 'avpvh-audio-transcriptie'))}</strong></p>
				<button id="avpvh-at-save-settings" type="button" class="button button-primary">${escapeHtml(__('Instellingen opslaan', 'avpvh-audio-transcriptie'))}</button>
				<span id="avpvh-at-license-result" aria-live="polite"></span>
			</section>`;
			view.querySelector('#avpvh-at-save-settings').addEventListener('click', async () => {
				try {
					const updated = await api('settings', {
						method: 'PUT',
						body: JSON.stringify({
							service_url: view.querySelector('#avpvh-at-url').value,
							license_key: view.querySelector('#avpvh-at-key').value,
							provider: view.querySelector('#avpvh-at-provider-setting').value,
							default_model: view.querySelector('#avpvh-at-model-setting').value,
							default_language: view.querySelector('#avpvh-at-language-setting').value,
							chunk_minutes: Number(view.querySelector('#avpvh-at-chunk-setting').value),
							default_prompt: view.querySelector('#avpvh-at-prompt-setting').value,
							default_glossary: view.querySelector('#avpvh-at-glossary-setting').value,
							summary_api_url: view.querySelector('#avpvh-at-summary-url').value,
							summary_api_key: view.querySelector('#avpvh-at-summary-key').value,
							summary_model: view.querySelector('#avpvh-at-summary-model').value,
							summary_procedure_prompt: view.querySelector('#avpvh-at-summary-procedure-setting').value,
							summary_content_prompt: view.querySelector('#avpvh-at-summary-content-setting').value
						})
					});
					Object.assign(defaults, updated);
					config.aiClientAvailable = Boolean(updated.ai_client_available);
					config.summaryConfigured = Boolean(updated.summary_configured);
					if (updated.service_url && updated.license_registered) {
						const result = await api('license/validate', { method: 'POST', body: '{}' });
						view.querySelector('#avpvh-at-license-result').textContent = result.valid ? ` ${__('Instellingen opgeslagen; transcriptiesleutel geldig ✓', 'avpvh-audio-transcriptie')}` : ` ${__('Instellingen opgeslagen; transcriptiesleutel ongeldig', 'avpvh-audio-transcriptie')}`;
					} else {
						view.querySelector('#avpvh-at-license-result').textContent = ` ${__('Instellingen opgeslagen', 'avpvh-audio-transcriptie')}`;
					}
				} catch (error) {
					view.querySelector('#avpvh-at-license-result').textContent = ` ${error.message}`;
				}
			});
		} catch (error) {
			if (generation === viewGeneration) {
				view.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
			}
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
		// Space keeps its native meaning on form fields and other controls.
		const editing = Boolean(event.target.closest?.('input, textarea, select, button, a[href], summary, audio, video, [contenteditable]:not([contenteditable="false"]), [role="button"], [role="link"], [role="checkbox"], [role="tab"]'));
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
