<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

/*
 * The upgrade routines call wp_cache_delete() unqualified, so PHP resolves this namespaced double before the global
 * one. It records each key for HideCachedProviderFailuresRoutineTest.
 */
if (!function_exists(__NAMESPACE__ . '\\wp_cache_delete')) {
	/**
	 * @param string $key
	 * @return bool
	 */
	function wp_cache_delete($key)
	{
		\SmashBalloon\Reviews\Tests\Unit\HideCachedProviderFailuresRoutineTest::$deleted[] = $key;

		return true;
	}
}
