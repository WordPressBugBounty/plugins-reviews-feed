/**
 * Smash Usage Tracking: reports admin session duration on page unload.
 *
 * @package SmashBalloon\Reviews
 */
(function ($) {
	'use strict';

	if (typeof window.sbrSmashUsageSession === 'undefined') {
		return;
	}

	var config = window.sbrSmashUsageSession;
	var sessionStart = Date.now();
	var sessionSent = false;

	function sendSessionDuration() {
		var durationSeconds = Math.round((Date.now() - sessionStart) / 1000);
		if (sessionSent || durationSeconds < 3) {
			return;
		}
		sessionSent = true;
		if (navigator.sendBeacon) {
			var data = new FormData();
			data.append('action', 'sbr_smash_usage_record_session');
			data.append('nonce', config.nonce);
			data.append('duration_seconds', durationSeconds);
			navigator.sendBeacon(config.ajax_url, data);
		} else {
			$.post(config.ajax_url, {
				action: 'sbr_smash_usage_record_session',
				nonce: config.nonce,
				duration_seconds: durationSeconds
			});
		}
	}

	// pagehide is the reliable unload signal (beforeunload doesn't fire for
	// beacons on mobile Safari); the sessionSent flag guards the fallback.
	var unloadEvent = 'onpagehide' in window ? 'pagehide' : 'beforeunload';
	$(window).on(unloadEvent, function () {
		sendSessionDuration();
	});
})(jQuery);
