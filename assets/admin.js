/* global avpvhAudioTranscriptie */
(() => {
	'use strict';

	const config = avpvhAudioTranscriptie;
	const defaults = config.defaults || {};
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
					<h2>Transcripties</h2>
					<div class="avpvh-at-jobs">
						${jobs.map(jobRow).join('') || '<p>Nog geen transcripties.</p>'}
					</div>
				</section>
				${config.canEdit ? `<details class="avpvh-at-card avpvh-at-new-panel">
					<summary><strong>Nieuwe transcriptie</strong></summary>
					<div class="avpvh-at-new">
						<label>Titel <input id="avpvh-at-title" type="text"></label>
						<label>Transcriptie-engine <input id="avpvh-at-provider" type="text" value="${escapeHtml(defaults.provider || 'faster-whisper')}" list="avpvh-at-providers"><datalist id="avpvh-at-providers"><option value="faster-whisper"><option value="openai"><option value="custom"></datalist></label>
						<label>Model <input id="avpvh-at-model" type="text" value="${escapeHtml(defaults.default_model || 'medium')}" list="avpvh-at-models"><datalist id="avpvh-at-models"><option value="small"><option value="medium"><option value="large-v3-turbo"></datalist></label>
						<label>Taal <input id="avpvh-at-language" type="text" value="${escapeHtml(defaults.default_language || 'nl')}" size="6"></label>
						<label>Blokgrootte (minuten) <input id="avpvh-at-chunk" type="number" min="1" max="60" value="${escapeHtml(defaults.chunk_minutes || 10)}"></label>
						<label>Audio <input id="avpvh-at-audio" type="file" accept="audio/*"></label>
						<button id="avpvh-at-start" type="button" class="button button-primary">Start transcriptie</button>
					</div>
					<details class="avpvh-at-transcription-options" open>
						<summary>Prompt en glossary voor deze transcriptie</summary>
						<label>Prompt <textarea id="avpvh-at-prompt" rows="5">${escapeHtml(defaults.default_prompt || '')}</textarea></label>
						<label>Glossary <textarea id="avpvh-at-glossary" rows="5" placeholder="Eén term per regel of komma-gescheiden">${escapeHtml(defaults.default_glossary || '')}</textarea></label>
					</details>
					<progress id="avpvh-at-upload" max="100" value="0" hidden></progress>
				</details>` : ''}`;
			view.querySelector('#avpvh-at-start')?.addEventListener('click', startJob);
			view.querySelectorAll('.avpvh-at-job').forEach((button) => button.addEventListener('click', () => openJob(Number(button.dataset.id))));
		} catch (error) {
			view.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
		}
	}

	function jobRow(job) {
		return `<button type="button" class="avpvh-at-job" data-id="${job.id}">
			<strong>${escapeHtml(job.title)}</strong>
			<span>Open transcriptie · ${escapeHtml(job.status)} · ${Math.round(job.progress)}%</span>
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
					<button id="avpvh-at-copy-shortcode" type="button" class="button">Shortcode kopiëren</button>
					<button id="avpvh-at-export-html" type="button" class="button">Exporteer HTML</button>
					${config.canPublish ? '<button id="avpvh-at-publish-page" type="button" class="button button-primary">Publiceer als pagina</button>' : ''}
					${config.canEdit ? '<button id="avpvh-at-insert" type="button" class="button">Zin toevoegen</button>' : ''}
					${config.canEdit ? '<button id="avpvh-at-delete" type="button" class="button">Zin verwijderen</button>' : ''}
					<button id="avpvh-at-history" type="button" class="button">Geschiedenis</button>
					${config.canEdit ? '<button id="avpvh-at-summary" type="button" class="button button-primary">Samenvatting</button>' : ''}
				</div>
			</header>
			<div class="avpvh-at-summary" ${current.summary ? '' : 'hidden'}><h3>Samenvatting</h3><div>${current.summary || ''}</div></div>
			<datalist id="avpvh-at-speakers">${speakers.map((value) => `<option value="${escapeHtml(value)}">`).join('')}</datalist>
			<datalist id="avpvh-at-parties">${parties.map((value) => `<option value="${escapeHtml(value)}">`).join('')}</datalist>
			<div class="avpvh-at-filter"><label>Zoeken <input id="avpvh-at-search" type="search"></label><span id="avpvh-at-count"></span></div>
			<div id="avpvh-at-publish-panel" class="avpvh-at-card" hidden></div>
			<div id="avpvh-at-history-panel" class="avpvh-at-card" hidden></div>
			<div id="avpvh-at-segments">${current.segments.map(segmentRow).join('')}</div>`;

		view.querySelector('#avpvh-at-back').addEventListener('click', async () => {
			await flushSave();
			clearTimeout(pollTimer);
			renderJobList();
		});
		view.querySelector('#avpvh-at-insert')?.addEventListener('click', insertSegment);
		view.querySelector('#avpvh-at-delete')?.addEventListener('click', deleteSegment);
		view.querySelector('#avpvh-at-export-html').addEventListener('click', exportHtml);
		view.querySelector('#avpvh-at-publish-page')?.addEventListener('click', renderPublishPanel);
		view.querySelector('#avpvh-at-copy-shortcode').addEventListener('click', async () => {
			const shortcode = `[avpvh_audio_transcriptie id="${current.id}"]`;
			try {
				await navigator.clipboard.writeText(shortcode);
				setSaveState('Shortcode gekopieerd');
			} catch (error) {
				window.prompt('Kopieer deze shortcode:', shortcode);
			}
		});
		view.querySelector('#avpvh-at-history').addEventListener('click', renderHistory);
		view.querySelector('#avpvh-at-summary')?.addEventListener('click', createSummary);
		view.querySelector('#avpvh-at-search').addEventListener('input', filterRows);
		bindSegmentRows();
		bindAudioSync();
		setFocus(current.segments[0]?.id || null, false);
	}

	function uniqueValues(segments, field) {
		return [...new Set(segments.map((segment) => segment[field]).filter(Boolean))].sort((left, right) => left.localeCompare(right));
	}

	function segmentRow(segment) {
		return `<article class="avpvh-at-segment" data-id="${escapeHtml(segment.id)}" tabindex="0">
			<button type="button" class="avpvh-at-time" title="Speel vanaf dit fragment">${clock(segment.start)}</button>
			<label><span>Partij</span><input class="avpvh-at-party" type="text" list="avpvh-at-parties" value="${escapeHtml(segment.party)}" ${config.canEdit ? '' : 'disabled'}></label>
			<label><span>Spreker</span><input class="avpvh-at-speaker" type="text" list="avpvh-at-speakers" value="${escapeHtml(segment.speaker)}" ${config.canEdit ? '' : 'disabled'}></label>
			<label class="avpvh-at-text"><span>Transcriptie</span><textarea ${config.canEdit ? '' : 'readonly'}>${escapeHtml(segment.text)}</textarea></label>
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
			}
			row.addEventListener('mousedown', () => setFocus(id, false));
			row.addEventListener('focusin', () => setFocus(id, false));
			row.querySelector('.avpvh-at-time').addEventListener('click', () => playSegment(id));
		});
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

	function deleteSegment() {
		const index = current.segments.findIndex((segment) => segment.id === focusedId);
		if (index < 0) {
			notice('Kies eerst de zin die je wilt verwijderen.', 'warning');
			return;
		}
		if (!window.confirm('Deze zin verwijderen? Je kunt dit later via Geschiedenis terugzetten.')) {
			return;
		}
		const nextFocus = current.segments[index + 1]?.id || current.segments[index - 1]?.id || null;
		current.segments.splice(index, 1);
		app.querySelector(`.avpvh-at-segment[data-id="${cssEscape(focusedId)}"]`)?.remove();
		dirty.add('__structure__');
		setFocus(nextFocus, true);
		scheduleSave();
	}

	function exportHtml() {
		const title = escapeHtml(current.title || 'Transcriptie');
		const summary = current.summary ? `<section class="summary"><h2>Samenvatting</h2>${current.summary}</section>` : '';
		const audio = current.audio_url ? `<audio id="player" controls preload="metadata" src="${escapeHtml(current.audio_url)}"></audio>` : '';
		const rows = current.segments.map((segment) => `<article class="segment" data-start="${Number(segment.start) || 0}">
			<button type="button" class="time">${clock(segment.start)}</button>
			<div><div class="labels"><strong>${escapeHtml(segment.speaker || 'Spreker onbekend')}</strong>${segment.party ? ` <span>${escapeHtml(segment.party)}</span>` : ''}</div><p>${escapeHtml(segment.text).replace(/\n/g, '<br>')}</p></div>
		</article>`).join('');
		const documentHtml = `<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title><style>
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

	function filterRows(event) {
		const query = event.target.value.trim().toLocaleLowerCase();
		const visibleRows = [];
		app.querySelectorAll('.avpvh-at-segment').forEach((row) => {
			const segment = current.segments.find((item) => item.id === row.dataset.id);
			row.hidden = Boolean(query) && !`${segment.text} ${segment.speaker} ${segment.party}`.toLocaleLowerCase().includes(query);
			if (!row.hidden) {
				visibleRows.push(row);
			}
		});
		app.querySelector('#avpvh-at-count').textContent = query ? (visibleRows.length ? `${visibleRows.length} resultaat/resultaten` : 'Geen resultaten') : '';
		if (query) {
			setFocus(visibleRows[0]?.dataset.id || null, true);
		}
	}

	async function renderPublishPanel() {
		const panel = app.querySelector('#avpvh-at-publish-panel');
		if (!panel.hidden) {
			panel.hidden = true;
			return;
		}
		panel.hidden = false;
		panel.innerHTML = '<p class="avpvh-at-loading">Publicatieopties laden<span></span></p>';
		try {
			const options = await api(`publishing-options?job_id=${current.id}`);
			const existing = options.existing_page;
			panel.innerHTML = `<h3>${existing ? 'Transcriptiepagina bijwerken' : 'Transcriptiepagina maken'}</h3>
				<p>De pagina gebruikt de actuele transcriptie uit de editor; latere correcties verschijnen dus automatisch.</p>
				<label>Paginatitel <input id="avpvh-at-page-title" type="text" value="${escapeHtml(existing?.title || current.title)}"></label>
				<label>Status <select id="avpvh-at-page-status"><option value="draft" ${'publish' !== existing?.status ? 'selected' : ''}>Concept</option><option value="publish" ${'publish' === existing?.status ? 'selected' : ''}>Gepubliceerd</option></select></label>
				<label>WordPress-menu <select id="avpvh-at-page-menu"><option value="0">Niet toevoegen / huidige menuplaats behouden</option>${options.menus.map((menu) => `<option value="${menu.id}" ${menu.id === existing?.menu_id ? 'selected' : ''}>${escapeHtml(menu.name)}</option>`).join('')}</select></label>
				<label>Onder bestaand menu-item <select id="avpvh-at-page-parent"><option value="0">Op hoofdniveau</option></select></label>
				<p class="description">Kies Gepubliceerd om de pagina voor bezoekers bereikbaar te maken. Menuplaatsing vereist het WordPress-recht om menu’s te wijzigen.</p>
				<button id="avpvh-at-page-create" type="button" class="button button-primary">${existing ? 'Pagina bijwerken' : 'Pagina maken'}</button>
				<span id="avpvh-at-page-result" aria-live="polite"></span>`;

			const menu = panel.querySelector('#avpvh-at-page-menu');
			const parent = panel.querySelector('#avpvh-at-page-parent');
			const status = panel.querySelector('#avpvh-at-page-status');
			const updateParents = () => {
				const selected = options.menus.find((item) => item.id === Number(menu.value));
				parent.innerHTML = '<option value="0">Op hoofdniveau</option>' + (selected?.items || []).filter((item) => item.id !== existing?.menu_item).map((item) => `<option value="${item.id}" ${item.id === existing?.parent_item ? 'selected' : ''}>${escapeHtml(item.title)}</option>`).join('');
				const menuAllowed = 'publish' === status.value && options.can_manage_menus;
				menu.disabled = !menuAllowed;
				parent.disabled = !menuAllowed || '0' === menu.value;
			};
			menu.addEventListener('change', updateParents);
			status.addEventListener('change', updateParents);
			updateParents();

			panel.querySelector('#avpvh-at-page-create').addEventListener('click', async () => {
				const button = panel.querySelector('#avpvh-at-page-create');
				const resultText = panel.querySelector('#avpvh-at-page-result');
				button.disabled = true;
				resultText.textContent = ' Pagina opslaan…';
				try {
					const result = await api(`jobs/${current.id}/publish`, {
						method: 'POST',
						body: JSON.stringify({
							title: panel.querySelector('#avpvh-at-page-title').value,
							status: status.value,
							menu_id: 'publish' === status.value ? Number(menu.value) : 0,
							parent_item_id: 'publish' === status.value ? Number(parent.value) : 0
						})
					});
					resultText.innerHTML = ` Opgeslagen. <a href="${escapeHtml(result.url)}" target="_blank" rel="noopener">Bekijk pagina</a> · <a href="${escapeHtml(result.edit_url)}">Bewerk in WordPress</a>`;
				} catch (error) {
					resultText.textContent = ` ${error.message}`;
					button.disabled = false;
				}
			});
		} catch (error) {
			panel.innerHTML = `<p>${escapeHtml(error.message)}</p>`;
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
			panel.innerHTML = `<h3>Correctiegeschiedenis</h3>${revisions.map((revision) => config.canEdit ? `<button type="button" class="button avpvh-at-restore" data-revision="${revision.revision}">Versie ${revision.revision} · ${escapeHtml(revision.created_at)}</button>` : `<p>Versie ${revision.revision} · ${escapeHtml(revision.created_at)}</p>`).join('') || '<p>Nog geen eerdere versies.</p>'}`;
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
		const prompt = window.prompt('Instructie voor de samenvatting:', defaults.summary_prompt || 'Maak een beknopt verslag met kernpunten, besluiten, acties en openstaande punten.');
		if (null === prompt) {
			return;
		}
		try {
			const result = await api(`jobs/${current.id}/summary`, {
				method: 'POST',
				body: JSON.stringify({ prompt })
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
				<p>De service-URL bepaalt met welke API de plugin praat. De gekozen engine, modelversie, prompt, glossary en blokgrootte worden bij iedere nieuwe opdracht aan die API meegestuurd.</p>
				<label>Service-URL <input id="avpvh-at-url" type="url" value="${escapeHtml(settings.service_url)}" placeholder="https://service.example"></label>
				<label>Servicesleutel <input id="avpvh-at-key" type="password" placeholder="${escapeHtml(settings.license_hint || 'Servicesleutel')}"></label>
				<h3>Standaard transcriptie-instellingen</h3>
				<label>Transcriptie-engine/provider <input id="avpvh-at-provider-setting" type="text" value="${escapeHtml(settings.provider)}" list="avpvh-at-setting-providers"><datalist id="avpvh-at-setting-providers"><option value="faster-whisper"><option value="openai"><option value="custom"></datalist></label>
				<label>Modelversie <input id="avpvh-at-model-setting" type="text" value="${escapeHtml(settings.default_model)}" list="avpvh-at-setting-models"><datalist id="avpvh-at-setting-models"><option value="small"><option value="medium"><option value="large-v3-turbo"></datalist></label>
				<label>Taalcode <input id="avpvh-at-language-setting" type="text" value="${escapeHtml(settings.default_language)}"></label>
				<label>Blokgrootte in minuten <input id="avpvh-at-chunk-setting" type="number" min="1" max="60" value="${escapeHtml(settings.chunk_minutes)}"></label>
				<p class="description">Advies: 10 minuten is een goed uitgangspunt voor lange gesprekken. Gebruik 5 minuten bij weinig geheugen of snelle tussentijdse resultaten; 15–20 minuten kan bij ruime capaciteit en levert meer context per blok.</p>
				<label>Standaardprompt <textarea id="avpvh-at-prompt-setting" rows="7">${escapeHtml(settings.default_prompt)}</textarea></label>
				<label>Standaardglossary <textarea id="avpvh-at-glossary-setting" rows="7" placeholder="Eén term per regel of komma-gescheiden">${escapeHtml(settings.default_glossary)}</textarea></label>
				<p>Status: <strong>${settings.license_registered ? 'sleutel geregistreerd' : 'niet ingesteld'}</strong></p>
				<h3>Samenvatting (OpenAI-compatibele LLM-API)</h3>
				<p>Gebruik hier de volledige chat-completions-URL en de API-key van de gekozen LLM-provider. Deze sleutel staat los van de transcriptieservicesleutel.</p>
				<label>LLM API-URL <input id="avpvh-at-summary-url" type="url" value="${escapeHtml(settings.summary_api_url)}" placeholder="https://provider.example/v1/chat/completions"></label>
				<label>LLM API-key <input id="avpvh-at-summary-key" type="password" placeholder="${escapeHtml(settings.summary_key_hint || 'API-key; leeg laten om de bestaande te behouden')}"></label>
				<label>LLM-model <input id="avpvh-at-summary-model" type="text" value="${escapeHtml(settings.summary_model)}" placeholder="Modelnaam van de provider"></label>
				<label>Standaardinstructie <textarea id="avpvh-at-summary-prompt" rows="5">${escapeHtml(settings.summary_prompt)}</textarea></label>
				<p>Status LLM-key: <strong>${settings.summary_registered ? 'sleutel geregistreerd' : 'niet ingesteld of niet vereist'}</strong></p>
				<button id="avpvh-at-save-settings" type="button" class="button button-primary">Instellingen opslaan</button>
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
							summary_prompt: view.querySelector('#avpvh-at-summary-prompt').value
						})
					});
					Object.assign(defaults, updated);
					if (updated.service_url && updated.license_registered) {
						const result = await api('license/validate', { method: 'POST', body: '{}' });
						view.querySelector('#avpvh-at-license-result').textContent = result.valid ? ' Instellingen opgeslagen; transcriptiesleutel geldig ✓' : ' Instellingen opgeslagen; transcriptiesleutel ongeldig';
					} else {
						view.querySelector('#avpvh-at-license-result').textContent = ' Instellingen opgeslagen';
					}
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
