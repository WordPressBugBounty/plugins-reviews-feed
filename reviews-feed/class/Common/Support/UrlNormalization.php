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
	 * Host-only normalized form: scheme + path + query dropped, plus the
	 * round-8 (Patch B) strip set on the host itself: port (`:80`/`:443`),
	 * leading `www.`, and trailing dot. The port strip is critical for
	 * reverse-proxy environments (Cloudflare, AWS ALB, Nginx with `$host`
	 * vs `$http_host`) where the same site oscillates between `host` and
	 * `host:80` across requests; without it, `detect_site_migration`
	 * would falsely detect a migration on every port-drift request.
	 *
	 * Used by detect_site_migration so:
	 *   - WPML/Polylang language path variants (`/pt-br/`, `/en/`, `/de/`)
	 *   - reverse-proxy port drift (`:80`/`:443` injected by upstream)
	 *   - canonical drift (`www.example.com` ↔ `example.com`)
	 *   - FQDN form (`example.com.` ↔ `example.com`)
	 * on the same WordPress install don't register as a migration.
	 *
	 * Multisite subsites stay distinct because each subsite has its own
	 * hostname or its own `sbr_settings` store. Plugin-side this trait
	 * is byte-for-byte semantically identical to the relay's mirror at
	 * `app/Support/UrlNormalization::normalize_url_host_only` so plugin
	 * and relay agree on what counts as the same install.
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
		// Migration-detection compares site identity, not network endpoints.
		// Strip:
		//   - port: a reverse-proxy-injected ":80"/":443" (Cloudflare, AWS ALB,
		//     Nginx with $host vs $http_host) is not a migration. This was the
		//     residual SMASH-1274 loop driver — same site oscillating between
		//     "host" and "host:80" depending on request path.
		//   - leading "www.": canonical-URL drift between get_home_url()
		//     returning "www.example.com" on one request and "example.com" on
		//     another (non-canonical permalinks, multisite alias mapping) is
		//     not a migration.
		//   - trailing dot: "example.com." is the same WP install as
		//     "example.com" (FQDN vs short form).
		$host = strtolower($parts['host']);
		$host = preg_replace('/^www\./', '', $host);
		$host = rtrim($host, '.');
		return $host;
	}
}
