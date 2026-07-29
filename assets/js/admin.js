(() => {
	'use strict';

	document.querySelectorAll('.mrncb-tool button').forEach((button) => {
		button.addEventListener('click', () => {
			button.disabled = true;
			button.textContent = 'در حال اجرا…';
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
})();
