(function () {
	var config = window.nuem8SafetyPassUpdates || {};
	var label = config.updating || 'Updating';
	var forms = document.querySelectorAll('form.safety-pass__update');
	var storageKey = 'nuem8SafetyPassBackupNotice';

	if (!forms.length || !config.ajaxUrl || !config.updatesNonce) {
		return;
	}

	function buttons() {
		return document.querySelectorAll('form.safety-pass__update button[type="submit"]');
	}

	function alreadyWarned() {
		try {
			return window.sessionStorage.getItem(storageKey) === '1';
		} catch (error) {
			return false;
		}
	}

	function rememberWarning() {
		try {
			window.sessionStorage.setItem(storageKey, '1');
		} catch (error) {
			return;
		}
	}

	function field(form, name) {
		var input = form.querySelector('input[name="' + name + '"]');
		return input ? input.value : '';
	}

	function queueFrom(form) {
		if (form.querySelector('input[name="update_all"]')) {
			var queued = [];

			Array.prototype.forEach.call(forms, function (row) {
				var plugin = field(row, 'plugin');

				if (plugin) {
					queued.push({
						plugin: plugin,
						slug: field(row, 'slug')
					});
				}
			});

			return queued;
		}

		var plugin = field(form, 'plugin');

		if (!plugin) {
			return [];
		}

		return [{
			plugin: plugin,
			slug: field(form, 'slug')
		}];
	}

	function installOne(item) {
		var body = new window.FormData();

		body.append('action', 'update-plugin');
		body.append('_ajax_nonce', config.updatesNonce);
		body.append('plugin', item.plugin);
		body.append('slug', item.slug);

		return window.fetch(config.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			return !!(payload && payload.success);
		}).catch(function () {
			return false;
		});
	}

	function finish(updated, failed) {
		if (!updated) {
			window.alert(config.failed || 'The update did not install. Nothing was changed.');
			Array.prototype.forEach.call(buttons(), function (button) {
				button.disabled = false;
				var original = button.getAttribute('data-label');

				if (original) {
					button.textContent = original;
				}
			});
			return;
		}

		var next = new window.URL(config.tabUrl, window.location.href);
		next.searchParams.set('plugins_updated', String(updated));

		if (failed) {
			next.searchParams.set('plugins_failed', String(failed));
		}

		window.location.assign(next.toString());
	}

	function runQueue(items) {
		var updated = 0;
		var failed = 0;
		var index = 0;

		function step() {
			if (index >= items.length) {
				finish(updated, failed);
				return;
			}

			installOne(items[index]).then(function (ok) {
				if (ok) {
					updated += 1;
				} else {
					failed += 1;
				}

				index += 1;
				step();
			});
		}

		step();
	}

	Array.prototype.forEach.call(forms, function (form) {
		form.addEventListener('submit', function (event) {
			var submitter = event.submitter || form.querySelector('button[type="submit"]');
			var warning = config.backupWarning || '';

			event.preventDefault();

			if (warning && !alreadyWarned()) {
				if (!window.confirm(warning)) {
					return;
				}

				rememberWarning();
			}

			Array.prototype.forEach.call(buttons(), function (button) {
				button.disabled = true;
			});

			if (submitter) {
				submitter.textContent = label;
			}

			runQueue(queueFrom(form));
		});
	});
})();
