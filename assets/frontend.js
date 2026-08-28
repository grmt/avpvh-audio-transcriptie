(() => {
	'use strict';

	document.querySelectorAll('.avpvh-at-public').forEach((transcript) => {
		const audio = transcript.querySelector('.avpvh-at-public-player');
		const search = transcript.querySelector('.avpvh-at-public-search');
		const speaker = transcript.querySelector('.avpvh-at-public-speaker');
		const party = transcript.querySelector('.avpvh-at-public-party');
		const rows = [...transcript.querySelectorAll('.avpvh-at-public-segment')];

		const filter = () => {
			const query = search.value.trim().toLocaleLowerCase();
			rows.forEach((row) => {
				row.hidden = Boolean(
					(query && !row.dataset.search.toLocaleLowerCase().includes(query)) ||
					(speaker.value && row.dataset.speaker !== speaker.value) ||
					(party.value && row.dataset.party !== party.value)
				);
			});
		};

		search.addEventListener('input', filter);
		speaker.addEventListener('change', filter);
		party.addEventListener('change', filter);
		rows.forEach((row) => {
			row.querySelector('.avpvh-at-public-time').addEventListener('click', () => {
				if (!audio) {
					return;
				}
				audio.currentTime = Number(row.dataset.start) || 0;
				audio.play();
			});
		});
	});
})();
