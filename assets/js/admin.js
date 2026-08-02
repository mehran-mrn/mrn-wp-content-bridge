(() => {
	'use strict';

	document.querySelectorAll('.mrncb-tool button').forEach((button) => {
		button.addEventListener('click', () => {
			button.disabled = true;
			button.textContent = window.mrncbAdminI18n?.running || 'Running…';
			button.closest('form').submit();
		});
	});

	document.querySelectorAll('.mrncb-form input[type="password"]').forEach((field) => {
		field.addEventListener('focus', () => {
			if (field.value.includes('•')) {
				field.value = '';
			}
		});
	});

	document.querySelectorAll('[data-mrncb-source-form]').forEach((form) => {
		const platform = form.querySelector('[data-mrncb-source-platform]');
		const rssField = form.querySelector('[data-mrncb-rss-field]');
		const feedUrl = rssField?.querySelector('input[name="feed_url"]');
		const botFields = form.querySelectorAll('[data-mrncb-bot-field]');
		const token = form.querySelector('input[name="token"]');
		const confirmField = form.querySelector('[data-mrncb-confirm-field]');
		const confirm = confirmField?.querySelector('input[name="confirm_inbound"]');

		const updateSourceFields = () => {
			const isRss = platform.value === 'rss';
			rssField.hidden = !isRss;
			feedUrl.required = isRss;
			botFields.forEach((field) => {
				field.hidden = isRss;
			});
			token.required = !isRss;
			confirmField.hidden = isRss;
			confirm.disabled = isRss;
		};

		platform.addEventListener('change', updateSourceFields);
		updateSourceFields();
	});
})();
