(() => {
	'use strict';

	document.querySelectorAll('.mrncb-tool').forEach((form) => {
		form.addEventListener('submit', (event) => {
			if (form.dataset.mrncbConfirm === 'flush-queue') {
				const message = window.mrncbAdminI18n?.flushQueue || 'Delete every queued job and cancel active workflows?';
				if (!window.confirm(message)) {
					event.preventDefault();
					return;
				}
			}
			if (form.dataset.mrncbConfirm === 'emergency-stop') {
				const message = window.mrncbAdminI18n?.stopProcessing || 'Stop processing, pause sources, and flush the queue?';
				if (!window.confirm(message)) {
					event.preventDefault();
					return;
				}
			}
			const button = form.querySelector('button[type="submit"], button:not([type])');
			if (!button) {
				return;
			}
			button.disabled = true;
			button.textContent = window.mrncbAdminI18n?.running || 'Running…';
		});
	});

	document.querySelectorAll('.mrncb-form input[type="password"]').forEach((field) => {
		field.addEventListener('focus', () => {
			if (field.value.includes('•')) {
				field.value = '';
			}
		});
	});

	document.querySelectorAll('[data-mrncb-confirm="delete-source"]').forEach((form) => {
		form.addEventListener('submit', (event) => {
			const message = window.mrncbAdminI18n?.deleteSource || 'Delete this source permanently?';
			if (!window.confirm(message)) {
				event.preventDefault();
			}
		});
	});

	document.querySelectorAll('[data-mrncb-source-form]').forEach((form) => {
		const platform = form.querySelector('[data-mrncb-source-platform]');
		const rssFields = form.querySelectorAll('[data-mrncb-rss-field]');
		const instagramFields = form.querySelectorAll('[data-mrncb-instagram-field]');
		const externalFields = form.querySelectorAll('[data-mrncb-external-field]');
		const feedUrl = form.querySelector('input[name="feed_url"]');
		const instagramToken = form.querySelector('input[name="instagram_access_token"]');
		const instagramMode = form.querySelector('[data-mrncb-instagram-mode]');
		const instagramUsername = form.querySelector('[data-mrncb-instagram-username]');
		const botFields = form.querySelectorAll('[data-mrncb-bot-field]');
		const token = form.querySelector('input[name="token"]');
		const confirmField = form.querySelector('[data-mrncb-confirm-field]');
		const confirm = confirmField?.querySelector('input[name="confirm_inbound"]');
		let previousPlatform = platform.value;

		const updateSourceFields = () => {
			const isRss = platform.value === 'rss';
			const isInstagram = platform.value === 'instagram';
			const isExternal = isRss || isInstagram;
			rssFields.forEach((field) => {
				field.hidden = !isRss;
			});
			instagramFields.forEach((field) => {
				field.hidden = !isInstagram;
			});
			externalFields.forEach((field) => {
				field.hidden = !isExternal;
			});
			feedUrl.required = isRss;
			instagramToken.required = isInstagram && instagramMode.value === 'api';
			instagramUsername.required = isInstagram && instagramMode.value !== 'api';
			botFields.forEach((field) => {
				field.hidden = isExternal;
			});
			token.required = !isExternal;
			if ( isExternal && !['rss', 'instagram'].includes(previousPlatform) ) {
				confirm.checked = false;
			}
			confirmField.hidden = false;
			confirm.disabled = false;
			previousPlatform = platform.value;
		};

		platform.addEventListener('change', updateSourceFields);
		instagramMode.addEventListener('change', updateSourceFields);
		updateSourceFields();
	});
})();
