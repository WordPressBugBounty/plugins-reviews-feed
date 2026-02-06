<?php

/**
 * Feature Flags Configuration
 *
 * Use this file to enable/disable features across the plugin.
 * To enable a provider, remove it from the 'disabled_providers' array.
 *
 * @package SmashBalloon\Reviews
 */

return [
	/**
	 * Providers that are disabled and hidden from the Add Source modal.
	 * Remove a provider from this array to enable it.
	 */
	'disabled_providers' => [
		// Hidden until ready for release. Upgrade modals tested and working.
		'airbnb',
		'booking',
		'aliexpress',
	],
];
