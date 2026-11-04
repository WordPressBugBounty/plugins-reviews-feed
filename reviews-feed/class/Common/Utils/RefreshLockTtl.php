<?php

namespace SmashBalloon\Reviews\Common\Utils;

use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Pro\Integrations\Providers\Facebook;

/**
 * How long a feed refresh may hold its single-flight lock before another worker treats it as stale (SMASH-2434).
 * Feed::api_request() calls each source in turn, so the TTL is the sum of their own call timeouts: never below the
 * old fixed 75s, and capped so a crashed worker can't keep a large feed from refreshing for hours.
 */
final class RefreshLockTtl
{
	/** The fixed TTL both locks used before; a feed with no outbound call keeps it. */
	public const FLOOR = 75;

	/** While the lock is held the other worker serves stored reviews, so a long wait costs freshness, not content. */
	public const CAP = 1800;

	/** Room for the work around the calls: parsing, caching, the relay's own overhead. */
	private const MARGIN = 15;

	/** Read from the site's own database by Feed::api_request(): no outbound call. */
	private const LOCAL_PROVIDERS = ['woocommerce', 'edd', 'collection'];

	/**
	 * @param array<int, mixed> $sources The feed's sources, each with a `provider`.
	 * @param string            $type    The api_request() type the lock guards: 'reviews' or 'sources'.
	 */
	public static function for_sources(array $sources, string $type): int
	{
		$seconds = 0;
		foreach ($sources as $source) {
			$provider = is_array($source) && is_string($source['provider'] ?? null) ? $source['provider'] : '';
			$seconds += self::source_seconds($provider, $type);
		}

		return min(self::CAP, max(self::FLOOR, $seconds + self::MARGIN));
	}

	private static function source_seconds(string $provider, string $type): int
	{
		if (in_array($provider, self::LOCAL_PROVIDERS, true)) {
			return 0;
		}
		if ($provider === 'facebook') {
			// Graph is called straight from the plugin; without Pro the source returns an error with no call.
			return class_exists(Facebook::class) ? Facebook::TIMEOUT : 0;
		}

		return SBRelay::timeout_for($type . '/' . $provider);
	}
}
