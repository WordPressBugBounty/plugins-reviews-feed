<?php

/**
 * URL Normalization Trait
 *
 * @package SmashBalloon\Reviews\Common\Support
 */

namespace SmashBalloon\Reviews\Common\Support;

/**
 * Byte-stable URL normalization used to compare URLs that may differ only in
 * casing, scheme, or trailing slash. Mirrors the server-side trait at
 * `SmashBalloon\Backoffice\Support\UrlNormalization` in sb-relay so that
 * the plugin's view of a URL matches whatever the relay has on file.
 *
 * See SMASH-1274 (server-side) and SMASH-1281 (this plugin-side companion).
 */
trait UrlNormalization
{
	/**
	 * Normalize a URL to a byte-stable form.
	 *
	 * - Lowercases scheme and host
	 * - Preserves any non-default port
	 * - Strips a trailing slash on the path
	 * - Preserves query string verbatim
	 * - Leaves `www.` untouched
	 * - Returns the original string unchanged if the URL is malformed
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public function normalize_url($url)
	{
		if (!is_string($url) || $url === '') {
			return '';
		}
		$parts = parse_url($url);
		if ($parts === false || empty($parts['host'])) {
			return $url;
		}
		$scheme = strtolower($parts['scheme'] ?? 'https');
		$host   = strtolower($parts['host']);
		$port   = isset($parts['port']) ? ':' . $parts['port'] : '';
		$path   = rtrim($parts['path'] ?? '', '/');
		$query  = isset($parts['query']) ? '?' . $parts['query'] : '';
		return "{$scheme}://{$host}{$port}{$path}{$query}";
	}

	/**
	 * Normalized form with the scheme stripped, so http and https variants
	 * of the same URL compare equal. Used by detect_site_migration.
	 */
	public function normalize_url_scheme_agnostic($url)
	{
		$normalized = $this->normalize_url($url);
		$stripped   = preg_replace('#^https?://#i', '', $normalized);
		return is_string($stripped) ? $stripped : $normalized;
	}

	/**
	 * Host-only normalized form: scheme stripped, path/query dropped.
	 * Used by detect_site_migration so WPML/Polylang language path variants
	 * (`/pt-br/`, `/en/`, `/de/`) on the same WordPress install don't
	 * register as a migration. Multisite subsites stay distinct because
	 * each subsite has its own hostname or its own sbr_settings store.
	 */
	public function normalize_url_host_only($url)
	{
		if (!is_string($url) || $url === '') {
			return '';
		}
		$parts = parse_url($url);
		if ($parts === false || empty($parts['host'])) {
			return $url;
		}
		$host = strtolower($parts['host']);
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		return $host . $port;
	}
}
