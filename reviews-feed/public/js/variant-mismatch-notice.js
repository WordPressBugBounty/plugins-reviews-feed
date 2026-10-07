/**
 * Variant-mismatch notice: stores the per-user dismissal (SMASH-1402).
 * Everything it needs is on the notice's data-* attributes.
 *
 * @package SmashBalloon\Reviews
 */
(function () {
	'use strict';

	var notice = document.querySelector('.sbr-variant-mismatch-notice');
	if (!notice) {
		return;
	}

	notice.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest('.notice-dismiss');
		if (!btn) {
			return;
		}
		var action = notice.getAttribute('data-sbr-dismiss-action');
		var nonce = notice.getAttribute('data-sbr-dismiss-nonce');
		var key = notice.getAttribute('data-sbr-dismiss-key');
		var url = notice.getAttribute('data-sbr-dismiss-url');
		if (!action || !nonce || !url) {
			return;
		}
		var data = new FormData();
		data.append('action', action);
		data.append('nonce', nonce);
		data.append('key', key);
		if (window.fetch) {
			window.fetch(url, { method: 'POST', credentials: 'same-origin', body: data });
		}
	});
})();
