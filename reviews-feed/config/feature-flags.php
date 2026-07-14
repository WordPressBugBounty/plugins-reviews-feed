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
	 *
	 * SMASH-782 2026-05-26: airbnb / booking / aliexpress enabled for
	 * Phase-1 E2E QA on the current backbone. Phase-2 design integration
	 * (per-provider element toggles, Regular/Boxed card style, new display
	 * elements) lands separately under T-6b once the designer redo per the
	 * spec-compliance ask in .claude/docs/SMASH-782-DESIGN-HANDOFF.md is in.
	 */
	'disabled_providers' => [
		'airbnb',
//		'booking',
		'aliexpress',
	],
];
