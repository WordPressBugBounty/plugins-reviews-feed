<?php

// phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded,Generic.Metrics.CyclomaticComplexity.TooHigh
// Note: Legacy file with complex feed rendering logic. Refactoring planned.

/**
 * Class Feed
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common;

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Helpers\Data_Encryption;

class Feed
{
	protected $posts = array();

	protected $header_data = array();

	/**
	 * @var FeedCache
	 */
	protected $feed_cache;

	protected $statuses = array();

	protected $settings;

	protected $feed_id;
	private $feed_style;

	private $flag_media_check;
	private $providers_languages;

	/**
	 * Data_Encryption
	 */
	private $encryption;

	/**
	 * @var array|string[]
	 */
	protected $providers_no_media = [];


	public function __construct($settings, $feed_id, FeedCache $feed_cache)
	{
		$this->feed_cache = $feed_cache;
		$this->feed_id = $feed_id;
		// Ensure settings is an array to prevent PHP 8.1+ deprecation warning
		$this->settings = is_array($settings) ? $settings : [];
		$this->settings['apiCallLanguage'] = Util::get_api_call_language($this->settings);
		$this->feed_style = is_array($settings) && isset($settings['feed_style']) ? $settings['feed_style'] : '';
		$this->statuses = array(
			'from_cache' => false,
			'post_found_before_filter' => false,
			'errors' => array()
		);

		$this->flag_media_check = false;

		$this->providers_languages = [
			'facebook',
			'google'
		];

		$this->providers_no_media = sbr_get_no_media_providers();
		$this->encryption = new Data_Encryption();
	}

	public function init()
	{
		if (empty($this->settings)) {
			$this->add_error(sprintf(__('No feed with the ID %d found.', 'reviews-feed'), $this->feed_id), sprintf(__('Please go to the %sReviews Feed%s settings page to create a feed.', 'reviews-feed'), '<a href="' . esc_url(admin_url('admin.php?page=sbr')) . '" target="_blank" rel="noopener noreferrer">', '</a>'));
			return;
		}
		if (empty($this->settings['sources']) && ! $this->is_single_manual_review()) {
			$this->add_error(sprintf(__('No sources available for this feed.', 'reviews-feed'), $this->feed_id), sprintf(__('Please go to the %sReviews Feed%s settings page add sources for this feed to use.', 'reviews-feed'), '<a href="' . esc_url(admin_url('admin.php?page=sbr')) . '" target="_blank" rel="noopener noreferrer">', '</a>'));
			return;
		}
		if (! $this->is_single_manual_review()) {
			$this->hydrate_sources();
		}
	}

	public function get_settings()
	{
		return $this->settings;
	}

	public function get_errors()
	{
		return $this->statuses['errors'];
	}

	public function set_errors($errors_array)
	{
		$this->statuses['errors'] = $errors_array;
	}

	public function add_error($message, $instructions)
	{
		$this->statuses['errors'][] = array(
			'message' => $message,
			'directions' => $instructions
		);
	}

	public function get_feed_id()
	{
		return $this->feed_id;
	}

	public function get_feed_style()
	{
		return $this->feed_style;
	}

	public function set_posts($posts)
	{
		$this->posts = $posts;
	}

	public function get_posts()
	{
		return $this->posts;
	}

	public function should_check_media()
	{
		return $this->flag_media_check;
	}

	public function set_header_data($header_data)
	{
		$this->header_data = $header_data;
	}

	public function get_header_data()
	{
		return $this->header_data;
	}

	public function is_single_manual_review()
	{
		return isset($this->settings['singleManualReview']) && $this->settings['singleManualReview'] === true;
	}

	public function get_set_cache()
	{
		if (! $this->is_single_manual_review()) {
			$this->feed_cache->retrieve_and_set();

			if ($this->feed_cache->is_expired()) {
				$posts = $this->update_posts_cache();
				$header_data = $this->update_header_cache();
			} else {
				$this->statuses['from_cache'] = true;
				$posts = json_decode($this->feed_cache->get('posts'), true);
				$header_data = $this->feed_cache->get('header') !== null ? json_decode($this->feed_cache->get('header'), true) : $this->update_header_cache_from_source();
				$error_cache = $this->feed_cache->get('errors');
				if (is_string($error_cache)) {
					$error_cache = json_decode($error_cache, true);
				}
				$this->set_errors($error_cache);
			}

			$posts = PostAggregator::remove_duplicated_posts_list($posts, 'json');

			if (empty($header_data)) {
				$header_data = $this->update_header_cache_from_source();
			}


			$this->set_posts($posts);
			$this->set_header_data($header_data);
		}
	}

	/**
	 * Acquire a per-feed single-flight refresh lock using `add_option` for
	 * MySQL-level atomicity (UNIQUE constraint on `option_name` makes the
	 * underlying INSERT a CAS — only one concurrent worker wins, the rest
	 * see false). Stores `time()` as the lock value so a crashed worker's
	 * orphaned lock can be detected and re-taken after the TTL elapses.
	 *
	 * @param string $lock_key  Unique key per feed_id + cache_type.
	 * @param int    $ttl       Seconds after which a held lock is considered stale.
	 * @return bool             True if the caller now owns the lock.
	 *
	 * @since 2.5.6
	 */
	private function acquire_refresh_lock(string $lock_key, int $ttl): bool
	{
		if (add_option($lock_key, time(), '', 'no')) {
			return true;
		}
		// Option already exists. Check whether the lock is stale.
		$held_since = (int) get_option($lock_key, 0);
		if ($held_since > 0 && (time() - $held_since) < $ttl) {
			return false;
		}
		// Stale lock — likely a crashed prior worker. Take it over.
		update_option($lock_key, time(), false);
		return true;
	}

	/**
	 * Release the single-flight refresh lock acquired by acquire_refresh_lock().
	 *
	 * @param string $lock_key
	 *
	 * @since 2.5.6
	 */
	private function release_refresh_lock(string $lock_key): void
	{
		delete_option($lock_key);
	}

	public function update_posts_cache()
	{
		$settings = $this->get_settings();

		if (empty($settings['sources'])) {
			return array();
		}

		// Single-flight: if another worker (cron or another visitor render)
		// is already fetching upstream for this feed, skip the duplicate HTTP
		// round-trip and return whatever's locally available. The lock holder
		// will populate the cache and the next render will see warm data.
		//
		// TTL is 75s — sits just above the relay's reviews fetch timeout (now 60s
		// on the RapidAPI Google/Yelp review calls) plus a little relay overhead,
		// so a slow-but-alive lock holder isn't mistaken for stale and double-
		// fetched. On lock-held, returns posts_from_db() which reads
		// `wp_sbr_reviews_posts` (review rows preserved across clear_plugin_cache,
		// only the images_done flag is reset, so the lock-loser still serves real
		// review text/ratings).
		$lock_key = 'sbr_refresh_lock_posts_' . $this->feed_id;
		if (! $this->acquire_refresh_lock($lock_key, 75)) {
			return $this->posts_from_db();
		}

		try {
			$remote_posts = $this->get_remote_posts($settings);

			foreach ($remote_posts as $provider_remote_posts) {
				// Only dispatch when the reviews payload is actually a list. An
				// error-shaped relay response can leave 'reviews' as a scalar,
				// which would otherwise foreach-warn / fatal downstream (SMASH-1578).
				if (isset($provider_remote_posts['data']['reviews']) && is_array($provider_remote_posts['data']['reviews'])) {
					$this->cache_single_posts_from_set($provider_remote_posts['data']['reviews'], $provider_remote_posts['provider_id']);
				}
			}

			$posts = $this->posts_from_db();
			if (empty($posts)) {
				$no_posts_found = __('No Posts Found.', 'reviews-feed');
				if ($this->statuses['post_found_before_filter']) {
					$this->add_error($no_posts_found, sprintf(__('There were no posts that fit your filters. Try modifying the filters set or add more sources with reviews that fit the filter by %sediting your feed%s', 'reviews-feed'), '<a href="' . esc_url(admin_url('admin.php?page=sbr')) . '" target="_blank" rel="noopener noreferrer">', '</a>'));
				} else {
					$this->add_error($no_posts_found, sprintf(__('There were no posts found for the sources selected. Make sure reviews are available for this source or change the source by %sediting your feed%s', 'reviews-feed'), '<a href="' . esc_url(admin_url('admin.php?page=sbr')) . '" target="_blank" rel="noopener noreferrer">', '</a>'));
				}
			}

			$posts = $this->maybe_encrypt_cached_posts($posts);
			$this->update_cache($posts);

			return $posts;
		} finally {
			$this->release_refresh_lock($lock_key);
		}
	}


	/**
	 * Used to filter Posts and check Facebook that should be encrypted
	 *
	 * @param $posts posts list
	 *
	 *  @return array
	 *
	 */
	public function maybe_encrypt_cached_posts($posts)
	{
		foreach ($posts as $key => $s_post) {
			if (isset($s_post['provider']['name']) && $s_post['provider']['name'] === 'facebook') {
				$posts[$key] = $this->encryption->maybe_encrypt(wp_json_encode($s_post));
			}
		}
		return $posts;
	}

	public function posts_from_db()
	{
		$settings = $this->get_settings();
		$aggregator = new PostAggregator();
		// Pass limit from settings (default 150 for backward compatibility)
		$limit = isset($settings['numPostDesktop']) ? max(150, (int) $settings['numPostDesktop']) : 150;
		$posts = $aggregator->db_post_set($settings['sources'], $this->settings['apiCallLanguage'], $limit);
		$posts = $aggregator->normalize_db_post_set($posts);
		if ($aggregator->missing_media_found()) {
			$this->flag_media_check = true;
		}

		$aggregator->update_last_requested($settings['sources']);

		if (! empty($posts)) {
			$this->statuses['post_found_before_filter'] = true;
		}

		return $this->filter_posts($posts, $settings, true);
	}

	public function update_cache($posts)
	{
		$this->feed_cache->update_or_insert('posts', json_encode($posts));
		$this->feed_cache->clear('errors');
		$this->feed_cache->update_or_insert('errors', json_encode($this->get_errors()));
	}

	public function update_header_cache()
	{
		$settings = $this->get_settings();
		if (empty($settings['sources'])) {
			return array();
		}

		// Single-flight: see update_posts_cache() for rationale (75s TTL).
		$lock_key = 'sbr_refresh_lock_header_' . $this->feed_id;
		if (! $this->acquire_refresh_lock($lock_key, 75)) {
			return $this->update_header_cache_from_source();
		}

		try {
			$remote_header_data = $this->get_remote_header_data($settings);

			// SMASH-1412: same dedup'd feed aggregate as update_header_cache_from_source().
			// Both paths persist the header cache, so both must stamp the aggregate.
			$feed_aggregate = $this->compute_feed_level_aggregate($settings['sources']);
			if ($feed_aggregate !== null && !empty($remote_header_data)) {
				foreach ($remote_header_data as $key => $entry) {
					$remote_header_data[$key]['info']['feed_total_review_count'] = $feed_aggregate['count'];
					$remote_header_data[$key]['info']['feed_average_rating']     = $feed_aggregate['rating'];
					$remote_header_data[$key]['info']['feed_aggregated']         = true;
				}
			}

			if (!empty($remote_header_data) && isset($remote_header_data[0]) && isset($remote_header_data[0]['info']) && isset($remote_header_data[0]['info']['id'])) {
				// Use get_provider_for_source to match the correct provider for the first header entry
				$first_header_id = $remote_header_data[0]['info']['id'];
				$first_header_provider = $this->get_provider_for_source($first_header_id, $settings['sources']);
				$persistent_business_data_cache = new BusinessDataCache();
				$persistent_business_data_cache->update_data($first_header_provider ?: ($settings['sources'][0]['provider'] ?? ''), $first_header_id, $remote_header_data);
				$this->feed_cache->update_or_insert('header', json_encode($remote_header_data));

				// Update ALL sources in DB, not just the first one
				foreach ($remote_header_data as $index => $source_data) {
					if (empty($source_data['info']['id'])) {
						continue;
					}
					$provider = $this->get_provider_for_source($source_data['info']['id'], $settings['sources']);
					// Fall back to index-based provider when ID lookup fails (e.g., type mismatch)
					if (empty($provider) && isset($settings['sources'][$index]['provider'])) {
						$provider = $settings['sources'][$index]['provider'];
					}
					if (empty($provider)) {
						continue;
					}
					$source_to_update = [
						'id'           => $source_data['info']['id'],
						'provider'     => $provider,
						'last_updated' => date('Y-m-d H:i:s'),
						'info'         => json_encode($source_data['info'])
					];
					SBR_Sources::update($source_to_update);
				}
			}
			return $remote_header_data;
		} finally {
			$this->release_refresh_lock($lock_key);
		}
	}

	/**
	 * Get the provider name for a source ID from the sources settings array
	 *
	 * @param string $source_id
	 * @param array  $sources
	 *
	 * @return string
	 */
	private function get_provider_for_source($source_id, $sources)
	{
		foreach ($sources as $source) {
			$info = $source['info'] ?? [];
			if (is_string($info)) {
				$info = json_decode($info, true) ?: [];
			}
			$info_id = $info['id'] ?? $source['account_id'] ?? '';
			if ($info_id === $source_id || ($source['account_id'] ?? '') === $source_id) {
				return $source['provider'] ?? '';
			}
		}
		return '';
	}

	public function update_header_cache_from_source()
	{
		$settings = $this->get_settings();

		if (empty($settings['sources'])) {
			return array();
		}

		// Decode info field if it's a JSON string
		foreach ($settings['sources'] as $key => $source) {
			if (isset($source['info']) && is_string($source['info'])) {
				$decoded = json_decode($source['info'], true);
				// Handle malformed JSON by setting empty array to prevent null access errors
				$settings['sources'][$key]['info'] = is_array($decoded) ? $decoded : [];
			}
		}

		// Build per-source header data so Parser can iterate each source correctly
		$remote_header_data = [];
		foreach ($settings['sources'] as $s_source) {
			$source_info = $s_source['info'] ?? [];
			if (empty($source_info)) {
				continue;
			}
			$remote_header_data[] = [
				'info' => [
					'id'           => $source_info['id'] ?? $s_source['account_id'] ?? '',
					'name'         => $source_info['name'] ?? $source_info['source_name'] ?? $s_source['name'] ?? 'Unknown',
					'rating'       => $source_info['rating'] ?? $source_info['average_rating'] ?? 0,
					'total_rating' => $source_info['total_rating'] ?? $source_info['review_count'] ?? 0,
					'url'          => $source_info['url'] ?? ''
				]
			];
		}

		// SMASH-1412: per-source counts double-count when two EDD (or Woo) sources
		// overlap on the same underlying download/product. Compute a dedup'd feed
		// aggregate here so the customizer reads one correct number instead of
		// summing per-source. For providers without overlap semantics (Yelp,
		// Google, Trustpilot, TripAdvisor, WP.org) the helper returns null and
		// the customizer falls back to summing — which is correct because each
		// source represents an independent business.
		$feed_aggregate = $this->compute_feed_level_aggregate($settings['sources']);
		if ($feed_aggregate !== null && !empty($remote_header_data)) {
			foreach ($remote_header_data as $key => $entry) {
				$remote_header_data[$key]['info']['feed_total_review_count'] = $feed_aggregate['count'];
				$remote_header_data[$key]['info']['feed_average_rating']     = $feed_aggregate['rating'];
				$remote_header_data[$key]['info']['feed_aggregated']         = true;
			}
		}

		if (!empty($remote_header_data)) {
			$first_source = $remote_header_data[0]['info'] ?? [];
			$first_source_id = $first_source['id'] ?? '';
			$first_provider = !empty($first_source_id)
				? $this->get_provider_for_source($first_source_id, $settings['sources'])
				: '';
			if (empty($first_provider)) {
				$first_provider = $settings['sources'][0]['provider'] ?? '';
			}
			$persistent_business_data_cache = new BusinessDataCache();
			$persistent_business_data_cache->update_data($first_provider, $first_source_id, $remote_header_data);
			$this->feed_cache->update_or_insert('header', json_encode($remote_header_data));
		}

		return $remote_header_data;
	}

	/**
	 * Compute a deduplicated feed-level review-count + weighted average rating
	 * across all sources in the feed. Groups sources by provider so each
	 * provider's class can dedup its own entity space (EDD = downloads,
	 * Woo = products). Sums totals across provider groups at the end since
	 * different providers represent independent business surfaces.
	 *
	 * Returns null when there's nothing to aggregate (no sources / no
	 * recognised providers / no review data) — the caller treats null as
	 * "let the customizer fall back to its existing per-source sum".
	 *
	 * @since SMASH-1412
	 * @param array $sources Sources array from feed settings
	 * @return array{count:int,rating:float}|null
	 */
	private function compute_feed_level_aggregate(array $sources)
	{
		if (empty($sources)) {
			return null;
		}

		$by_provider = [];
		foreach ($sources as $src) {
			$provider = $src['provider'] ?? '';
			if (empty($provider)) {
				continue;
			}
			$by_provider[$provider][] = $src;
		}
		if (empty($by_provider)) {
			return null;
		}

		$total_count = 0;
		$weighted_sum = 0.0;
		$any_dedup = false;

		foreach ($by_provider as $provider => $provider_sources) {
			$agg = $this->compute_provider_aggregate($provider, $provider_sources);
			if ($agg === null) {
				// No dedup available — sum per-source (correct for independent
				// businesses: Yelp, Google, Trustpilot, TripAdvisor, WP.org).
				foreach ($provider_sources as $src) {
					$info = $src['info'] ?? [];
					if (is_string($info)) {
						$info = json_decode($info, true) ?: [];
					}
					$count  = (int) ($info['review_count'] ?? $info['total_rating'] ?? 0);
					$rating = (float) ($info['rating'] ?? $info['average_rating'] ?? 0);
					$total_count  += $count;
					$weighted_sum += $rating * $count;
				}
			} else {
				$any_dedup = true;
				$total_count  += $agg['count'];
				$weighted_sum += $agg['rating'] * $agg['count'];
			}
		}

		// Only return an aggregate when at least one provider actually deduped
		// something. Otherwise let the customizer use its existing per-source
		// sum so we don't pay the BC cost on non-EDD/Woo feeds.
		if (! $any_dedup) {
			return null;
		}

		return [
			'count'  => $total_count,
			'rating' => $total_count > 0 ? round($weighted_sum / $total_count, 1) : 0.0,
		];
	}

	/**
	 * Provider-specific deduplicated aggregate. Returns null when the provider
	 * doesn't expose a dedup hook (treated as "use per-source sum" by the
	 * caller). EDD + WooCommerce dedup over the union of download / product
	 * IDs respectively. Other providers (Google / Yelp / Trustpilot /
	 * TripAdvisor / WP.org) return null on purpose because each source is its
	 * own business — summing is mathematically correct there.
	 *
	 * @since SMASH-1412
	 * @param string $provider Provider name (edd, woocommerce, …)
	 * @param array  $sources  Subset of feed sources matching this provider
	 * @return array{count:int,rating:float}|null
	 */
	private function compute_provider_aggregate(string $provider, array $sources)
	{
		if ($provider === 'edd') {
			$download_ids = [];
			foreach ($sources as $src) {
				$info = $src['info'] ?? [];
				if (is_string($info)) {
					$info = json_decode($info, true) ?: [];
				}
				$downloads = $info['downloads'] ?? $info['direct_downloads'] ?? [];
				foreach ($downloads as $d) {
					if (! empty($d['id'])) {
						$download_ids[(int) $d['id']] = true;
					}
				}
			}
			$download_ids = array_keys($download_ids);
			if (empty($download_ids)) {
				return null;
			}
			$class = '\\SmashBalloon\\Reviews\\Pro\\Integrations\\Providers\\EDD';
			if (! class_exists($class)) {
				return null;
			}
			$edd = new $class();
			if (! method_exists($edd, 'get_multi_source_info')) {
				return null;
			}
			$info = $edd->get_multi_source_info($download_ids, 'feed_aggregate', []);
			return [
				'count'  => (int) ($info['review_count'] ?? 0),
				'rating' => (float) ($info['average_rating'] ?? $info['rating'] ?? 0),
			];
		}

		if ($provider === 'woocommerce') {
			$product_ids = [];
			foreach ($sources as $src) {
				$info = $src['info'] ?? [];
				if (is_string($info)) {
					$info = json_decode($info, true) ?: [];
				}
				$products = $info['products'] ?? $info['direct_products'] ?? $info['downloads'] ?? [];
				foreach ($products as $p) {
					if (! empty($p['id'])) {
						$product_ids[(int) $p['id']] = true;
					}
				}
			}
			$product_ids = array_keys($product_ids);
			if (empty($product_ids)) {
				return null;
			}
			$class = '\\SmashBalloon\\Reviews\\Pro\\Integrations\\Providers\\WooCommerce';
			if (! class_exists($class)) {
				return null;
			}
			$woo = new $class();
			if (! method_exists($woo, 'get_multi_source_info')) {
				return null;
			}
			$info = $woo->get_multi_source_info($product_ids, 'feed_aggregate', []);
			return [
				'count'  => (int) ($info['review_count'] ?? 0),
				'rating' => (float) ($info['average_rating'] ?? $info['rating'] ?? 0),
			];
		}

		return null;
	}


	public function get_remote_posts($settings)
	{
		if (empty($settings['sources'])) {
			return array();
		}
		return $this->api_request($settings['sources']);
	}

	public function get_remote_header_data_old($settings)
	{
		if (empty($settings['sources'])) {
			return array();
		}
		$needed = array($settings['sources'][0]);
		return $this->api_request($needed, 'sources');
	}

	public function get_remote_header_data($settings)
	{
		if (empty($settings['sources'])) {
			return array();
		}
		$needed = $settings['sources'];
		return $this->api_request($needed, 'sources');
	}

	public function cache_single_posts_from_set($posts, $provider_id)
	{
		foreach ($posts as $single_review) {
			// Skip scalar entries from a malformed upstream payload (SMASH-1578);
			// downstream caching assumes an associative review array.
			if (! is_array($single_review)) {
				continue;
			}
			$single_post_cache = new SinglePostCache($single_review);
			$single_post_cache->set_provider_id($provider_id);

			$single_post_cache->set_lang($this->get_db_lang($provider_id));

			if (Util::sbr_is_pro() && method_exists($single_post_cache, 'check_api_media')) {
				$single_post_cache->check_api_media();
			}

			if (! $single_post_cache->db_record_exists()) {
				$single_post_cache->resize_avatar(150);
				if (in_array($this->provider_for_provider_id($provider_id), $this->providers_no_media, true)) {
					$single_post_cache->set_storage_data('images_done', 1);
				}
				$single_post_cache->store();
			} else {
				$single_post_cache->update_single();
			}
		}
	}



	/**
	 * Push a source's stored `info` into the header results so the source is
	 * still counted when its fresh remote fetch is skipped (API key limit or
	 * free-tier per-provider call cap). Without this, a rate-limited source
	 * vanishes from the multi-source header total — the front-end then
	 * under-reports the combined review count versus the Feed Builder preview,
	 * which always aggregates every source's stored info (SMASH-1583 parity).
	 *
	 * No-op for review requests and for sources with no stored info.
	 *
	 * @param array  $data    Results accumulator (by reference).
	 * @param mixed  $request The hydrated source request.
	 * @param string $type    'sources' or 'reviews'.
	 * @return void
	 */
	private function push_stored_source_info(&$data, $request, $type)
	{
		if ($type !== 'sources' || empty($request['info'])) {
			return;
		}
		$info = is_string($request['info']) ? json_decode($request['info'], true) : $request['info'];
		if (!empty($info) && is_array($info)) {
			$data[] = ['info' => $info];
		}
	}

	public function api_request($requests_needed, $type = 'reviews')
	{
		$data = array();

		foreach ($requests_needed as $request) {
			// Handle collections separately
			if ($request['provider'] === 'collection') {
				if ($type === 'sources') {
					$collection = SBR_Sources::update_collection_ratings($request['account_id']);
					$info = isset($collection['info']) ? json_decode($collection['info'], true) : [];
					$data[] = [
						'info' => $info
					];
				}
				continue;
			}

			// Apply Trustpilot-specific settings
			if ($type === 'reviews' && $request['provider'] === 'trustpilot') {
				$request['language'] = !empty($this->settings['trustpilotLanguage'])
					? $this->settings['trustpilotLanguage']
					: 'default';
				$request['starsFilter'] = !empty($this->settings['includedStarFilters'])
					? implode(',', $this->settings['includedStarFilters'])
					: '';
			}

			// Skip if API limit reached for this provider. For header (sources)
			// requests still count the source via its stored info so a
			// rate-limited source isn't dropped from the multi-source header
			// count — the admin preview always aggregates every source, so the
			// front-end must too (SMASH-1583 front-end parity).
			if (SBR_Feed_Saver_Manager::check_api_limit($request['provider'])) {
				$this->push_stored_source_info($data, $request, $type);
				continue;
			}

			// Skip if provider call limit reached — same stored-info fallback,
			// otherwise a free-tier per-provider call cap silently removes the
			// source from the header total (SMASH-1583).
			if (SBR_Feed_Saver_Manager::limit_provider_api_calls($request['provider'], $request['account_id'])) {
				$this->push_stored_source_info($data, $request, $type);
				continue;
			}

			// Apply language settings if applicable
			if (in_array($request['provider'], $this->providers_languages)) {
				$request['language'] = Util::get_api_call_language($this->settings);
			}

			// Fetch data from the appropriate provider
			$new_data = null;
			if ($request['provider'] === 'facebook') {
				// Check if Pro version is active (Facebook provider is Pro-only)
				if (!Util::sbr_is_pro() || !class_exists('\SmashBalloon\Reviews\Pro\Integrations\Providers\Facebook')) {
					$new_data = [
						'data' => [
							'error' => __('Facebook sources require Reviews Feed Pro.', 'reviews-feed')
						],
						'message' => __('Please upgrade to Reviews Feed Pro to display Facebook reviews.', 'reviews-feed')
					];
				} else {
					$new_data = \SmashBalloon\Reviews\Pro\Integrations\Providers\Facebook::get_facebook_info($type, $request);
				}
			} elseif ($request['provider'] === 'woocommerce') {
				// Check if Pro version is active (WooCommerce provider is Pro-only)
				if (!Util::sbr_is_pro() || !class_exists('\SmashBalloon\Reviews\Pro\Integrations\Providers\WooCommerce')) {
					$new_data = [
						'data' => [
							'error' => __('WooCommerce sources require Reviews Feed Pro.', 'reviews-feed')
						],
						'message' => __('Please upgrade to Reviews Feed Pro to display WooCommerce reviews.', 'reviews-feed')
					];
				} elseif (!function_exists('wc_get_product')) {
					// Check if WooCommerce plugin is active
					$new_data = [
						'data' => [
							'error' => __('WooCommerce plugin is not active.', 'reviews-feed')
						],
						'message' => __('Please activate WooCommerce to display reviews from this source.', 'reviews-feed')
					];
				} else {
					// WooCommerce is a local provider, fetch reviews directly from database
					$woocommerce = new \SmashBalloon\Reviews\Pro\Integrations\Providers\WooCommerce();
					$is_multi_product = strpos($request['account_id'], 'wc_multi_') === 0;

					if ($type === 'reviews') {
						if ($is_multi_product) {
							// Multi-product source: extract product IDs from info
							$product_ids = [];
							if (!empty($request['info']['products']) && is_array($request['info']['products'])) {
								foreach ($request['info']['products'] as $product_info) {
									if (!empty($product_info['id'])) {
										$product_ids[] = absint($product_info['id']);
									}
								}
							}

							if (!empty($product_ids)) {
								$reviews = $woocommerce->fetch_reviews_multi($product_ids);
								$normalized_reviews = $woocommerce->normalize_reviews_multi($reviews);
								$new_data = [
								'data' => [
									'reviews' => $normalized_reviews
								]
								];
							} else {
								$new_data = [
								'data' => [
									'error' => __('No valid products found in WooCommerce multi-product source.', 'reviews-feed')
								],
								'message' => __('The WooCommerce source has no valid products configured.', 'reviews-feed')
								];
							}
						} else {
							// Single product source
							$product = wc_get_product($request['account_id']);
							if ($product) {
								$reviews = $woocommerce->fetch_reviews($request['account_id']);
								$normalized_reviews = $woocommerce->normalize_reviews($reviews, $product);
								$new_data = [
								'data' => [
									'reviews' => $normalized_reviews
								]
								];
							} else {
								// Product not found or deleted
								$new_data = [
								'data' => [
									'error' => __('WooCommerce product not found or has been deleted.', 'reviews-feed')
								],
								'message' => sprintf(
									/* translators: %s: product ID */
									__('The WooCommerce product (ID: %s) no longer exists. Please update or remove this source.', 'reviews-feed'),
									esc_html($request['account_id'])
								)
								];
							}
						}
					} elseif ($type === 'sources') {
						if ($is_multi_product) {
							// Multi-product source: aggregate info from all products
							$product_ids = [];
							if (!empty($request['info']['products']) && is_array($request['info']['products'])) {
								foreach ($request['info']['products'] as $product_info) {
									if (!empty($product_info['id'])) {
										$product_ids[] = absint($product_info['id']);
									}
								}
							}

							if (!empty($product_ids)) {
								$new_data = [
								'data' => [
									'info' => $woocommerce->get_multi_source_info($product_ids, $request['account_id'], $request['info'])
								]
								];
							}
						} else {
							// Single product source
							$product = wc_get_product($request['account_id']);
							if ($product) {
								$new_data = [
								'data' => [
									'info' => $woocommerce->get_source_info($product)
								]
								];
							}
						}
					}
				}
			} elseif ($request['provider'] === 'edd') {
				// Check if Pro version is active (EDD provider is Pro-only)
				if (!Util::sbr_is_pro() || !class_exists('\SmashBalloon\Reviews\Pro\Integrations\Providers\EDD')) {
					$new_data = [
						'data' => [
							'error' => __('EDD sources require Reviews Feed Pro.', 'reviews-feed')
						],
						'message' => __('Please upgrade to Reviews Feed Pro to display EDD reviews.', 'reviews-feed')
					];
				} else {
					// EDD is a local provider, fetch reviews directly from database
					$edd_provider = new \SmashBalloon\Reviews\Pro\Integrations\Providers\EDD();

					// Check if EDD with reviews capability is active
					if (!$edd_provider->is_edd_active()) {
						// Provide specific error based on what's missing
						if ($edd_provider->is_edd_core_only_active()) {
							// EDD core is active but Reviews extension is missing
							$new_data = [
								'data' => [
									'error' => __('EDD Reviews extension is not active.', 'reviews-feed')
								],
								'message' => __('Please install and activate the EDD Reviews extension to display download reviews.', 'reviews-feed')
							];
						} else {
							// EDD core is not active
							$new_data = [
								'data' => [
									'error' => __('Easy Digital Downloads plugin is not active.', 'reviews-feed')
								],
								'message' => __('Please activate Easy Digital Downloads to display reviews from this source.', 'reviews-feed')
							];
						}
					} else {
						// EDD is fully active - fetch reviews
						$is_multi_download = strpos($request['account_id'], 'edd_multi_') === 0;

						if ($type === 'reviews') {
							if ($is_multi_download) {
								// Multi-download source: extract download IDs from info
								$download_ids = [];
								if (!empty($request['info']['downloads']) && is_array($request['info']['downloads'])) {
									foreach ($request['info']['downloads'] as $download_info) {
										if (!empty($download_info['id'])) {
											$download_ids[] = absint($download_info['id']);
										}
									}
								}

								if (!empty($download_ids)) {
									$reviews = $edd_provider->fetch_reviews_multi($download_ids);
									$normalized_reviews = $edd_provider->normalize_reviews_multi($reviews);
									$new_data = [
										'data' => [
											'reviews' => $normalized_reviews
										]
									];
								} else {
									$new_data = [
										'data' => [
											'error' => __('No valid downloads found in EDD multi-download source.', 'reviews-feed')
										],
										'message' => __('The EDD source has no valid downloads configured.', 'reviews-feed')
									];
								}
							} else {
								// Single download source
								$download = get_post($request['account_id']);
								if ($download && $download->post_type === 'download') {
									$reviews = $edd_provider->fetch_reviews($request['account_id']);
									$normalized_reviews = $edd_provider->normalize_reviews($reviews, $download);
									$new_data = [
										'data' => [
											'reviews' => $normalized_reviews
										]
									];
								} else {
									// Download not found or deleted
									$new_data = [
										'data' => [
											'error' => __('EDD download not found or has been deleted.', 'reviews-feed')
										],
										'message' => sprintf(
											/* translators: %s: download ID */
											__('The EDD download (ID: %s) no longer exists. Please update or remove this source.', 'reviews-feed'),
											esc_html($request['account_id'])
										)
									];
								}
							}
						} elseif ($type === 'sources') {
							if ($is_multi_download) {
								// Multi-download source: aggregate info from all downloads
								$download_ids = [];
								if (!empty($request['info']['downloads']) && is_array($request['info']['downloads'])) {
									foreach ($request['info']['downloads'] as $download_info) {
										if (!empty($download_info['id'])) {
											$download_ids[] = absint($download_info['id']);
										}
									}
								}

								if (!empty($download_ids)) {
									$new_data = [
										'data' => [
											'info' => $edd_provider->get_multi_source_info($download_ids, $request['account_id'], $request['info'])
										]
									];
								}
							} else {
								// Single download source
								$download = get_post($request['account_id']);
								if ($download && $download->post_type === 'download') {
									$new_data = [
										'data' => [
											'info' => $edd_provider->get_source_info($download)
										]
									];
								}
							}
						}
					}
				}
			} else {
				$remote_request = new RemoteRequest($request['provider'], $request, $type);
				$new_data = $remote_request->fetch();
			}

			// If no data was returned, fall back to stored source info for header requests
			if (!isset($new_data['data'])) {
				if ($type === 'sources' && !empty($request['info'])) {
					$fallback_info = is_string($request['info']) ? json_decode($request['info'], true) : $request['info'];
					if (!empty($fallback_info) && is_array($fallback_info)) {
						array_push($data, ['info' => $fallback_info]);
					}
				}
				continue;
			}

			// Handle errors — for source requests, fall back to stored info
			if (! empty($new_data['data']['error'])) {
				$used_fallback = false;
				if ($type === 'sources' && !empty($request['info'])) {
					$fallback_info = is_string($request['info']) ? json_decode($request['info'], true) : $request['info'];
					if (!empty($fallback_info) && is_array($fallback_info)) {
						array_push($data, ['info' => $fallback_info]);
						$used_fallback = true;
					}
				}
				$message = ! empty(( $new_data['message'] )) ? wp_strip_all_tags($new_data['message']) : 'An error has occurred when fetching new reviews';
				if (is_array($new_data['data']['error'])) {
					$message .= '<br>';
					foreach ($new_data['data']['error'] as $key => $value) {
						$message .= '<br>' . $key . ': ' . wp_strip_all_tags($value);
					}
				}
				$message .= '<br><br>';
				$message .= sprintf(__('This is affecting the source %s for %s. New reviews will not be fetched until this is resolved.', 'reviews-feed'), wp_strip_all_tags($request['name']), wp_strip_all_tags($request['provider']));
				$message .= '<br><br>';
				$this->add_error($message, sprintf(__('Troubleshoot by visiting %serror message reference page%s.', 'reviews-feed'), '<a href="https://smashballoon.com/doc/reviews-feed-error-message-reference/?reviews&utm_campaign=reviews-pro&utm_source=feed&utm_medium=apierror&utm_content=Error%20Message%20Reference" target="_blank" rel="noopener noreferrer">', '</a>'));
				// For source requests: always skip the normal data push after error handling
				// (error structures lack 'info' key and would break update_header_cache)
				// For review requests: preserve original fall-through behavior
				if ($type === 'sources') {
					continue;
				}
			}

			$new_data = $this->add_source_to_post_set($request, $new_data);
			$to_push = $type === 'reviews' ? [
				'provider_id' => $request['account_id'],
				'data' => $new_data['data']
			] : $new_data['data'];
			array_push($data, $to_push);
		}

		return $data;
	}

	public function add_source_to_post_set($source, $post_set)
	{
		// `reviews` must be a real list before we iterate. On an error-shaped
		// payload the container can itself be a scalar (e.g. 'reviews' => 'error
		// message'); `isset($reviews[0])` alone is fooled by string-offset
		// semantics (isset($str[0]) is true), which would make the foreach below
		// emit a "foreach() argument must be of type array|object" warning. Guard
		// that it's an array first (SMASH-1578 / PR #478 review).
		if (! is_array($post_set['data']['reviews'] ?? null) || ! isset($post_set['data']['reviews'][0])) {
			return $post_set;
		}
		foreach ($post_set['data']['reviews'] as $index => $review) {
			// Skip non-array entries from a malformed/error-shaped payload
			// (SMASH-1578): the write below assigns a 'source' offset, which on a
			// scalar (string) entry is a fatal TypeError on PHP 8.0+. This runs
			// upstream of cache_single_posts_from_set, so it must guard too.
			if (! is_array($review)) {
				continue;
			}
			$post_set['data']['reviews'][ $index ]['source'] = array(
				'id' => $source['info']['id'] ?? $source['account_id'] ?? '',
				'url' => $source['info']['url'] ?? '',
			);
		}

		return $post_set;
	}

	public function get_post_set_page($page = 1)
	{
		if ($this->is_single_manual_review()) {
			return [
				$this->hydrate_single_manual_review($this->settings['singleManualReviewContent'])
			];
		}

		$posts = $this->get_posts();
		$max = $this->settings['numPostDesktop'];
		if ($this->settings['numPostTablet'] > $this->settings['numPostDesktop']) {
			$max = $this->settings['numPostTablet'];
		}
		if ($this->settings['numPostMobile'] > $this->settings['numPostTablet']) {
			$max = $this->settings['numPostMobile'];
		}

		$offset = ($page - 1) * $max;
		return is_array($posts) ? array_slice($posts, $offset, $max) : [];
	}

	public function is_last_page($page)
	{
		$posts = $this->get_posts();
		$posts_counts = count($posts);
		$posts_per_page = $this->settings['numPostDesktop'];
		if ($this->settings['numPostTablet'] > $this->settings['numPostDesktop']) {
			$posts_per_page = $this->settings['numPostTablet'];
		}
		if ($this->settings['numPostMobile'] > $this->settings['numPostTablet']) {
			$posts_per_page = $this->settings['numPostMobile'];
		}
		$posts_per_page = (int) $posts_per_page;
		return $posts_counts <= ($page * $posts_per_page);
	}

	public function hydrate_sources()
	{

		if (!is_array($this->settings['sources'])) {
			$this->settings['sources'] = explode(',', $this->settings['sources']);
		}

		$db_sources = SBR_Sources::get_sources_list([
		  'id' => $this->settings['sources']
		]);

		$hydrated_sources = array();
		foreach ($this->settings['sources'] as $single_source) {
			foreach ($db_sources as $db_source) {
				if (
					!is_array($single_source)
					&& !empty($db_source['account_id'])
					&& (string) $db_source['account_id'] === $single_source
				) {
					$final_source = $db_source;
					$final_source['business'] = $db_source['account_id'];
					if (!empty($final_source['info'])) {
						$decoded = json_decode($final_source['info'], true);
						// Handle malformed JSON by setting empty array to prevent null access errors
						$final_source['info'] = is_array($decoded) ? $decoded : [];
					} else {
						// Ensure info is always an array even when empty/falsy
						$final_source['info'] = [];
					}
					if ($final_source['provider'] === 'google') {
						$final_source['lang'] = $this->settings['apiCallLanguage'];
					}
					$hydrated_sources[] = $final_source;
				}
			}
		}

		$this->settings['sources'] = $hydrated_sources;
	}

	protected function get_db_lang($provider_id)
	{
		$settings = $this->get_settings();
		if ('google' === $this->provider_for_provider_id($provider_id)) {
			return $settings['apiCallLanguage'];
		}

		return '';
	}


	protected function provider_for_provider_id($provider_id)
	{
		foreach ($this->settings['sources'] as $single_source) {
			if ($provider_id === $single_source['account_id']) {
				return $single_source['provider'];
			}
		}

		return '';
	}

	public function filter_posts($posts, $settings, $moderatePosts = false)
	{

		$filtered_posts = [];

		$is_star_filters = isset($settings['includedStarFilters']) && sizeof($settings['includedStarFilters']) > 0 ? true : false;
		$is_includeword = isset($settings['includeWords']) && !empty($settings['includeWords']) ? true : false;
		$is_excludeword = isset($settings['excludeWords']) && !empty($settings['excludeWords']) ? true : false;

		$is_sortbydate = isset($settings['sortByDateEnabled']) && !empty($settings['sortByDateEnabled']) && $settings['sortByDateEnabled'] == true ? true : false;
		$is_sortbyrating = isset($settings['sortByRatingEnabled']) && !empty($settings['sortByRatingEnabled']) && $settings['sortByRatingEnabled'] == true ? true : false;
		$is_randomize = isset($settings['sortRandomEnabled']) && !empty($settings['sortRandomEnabled']) && $settings['sortRandomEnabled'] == true ? true : false;

		$is_minchar = isset($settings['filterCharCountMin']) && !empty($settings['filterCharCountMin']) ? true : false;
		$is_maxchar = isset($settings['filterCharCountMax']) && !empty($settings['filterCharCountMax']) ? true : false;

		$sort_by_date = $settings['sortByDate'];
		$sort_by_rating = $settings['sortByRating'];

		$includewords = $is_includeword ? explode(',', $settings['includeWords']) : [];
		$excludewords = $is_excludeword ? explode(',', $settings['excludeWords']) : [];


		foreach ($posts as $post) {
			if (!is_null($post)) {
				$keep_post = false;
				//Work Around for facebook Positive / Negative Reviews
				if (!empty($post['provider']['name']) && $post['provider']['name'] === 'facebook') {
					if (in_array($post['rating'], [ 'positive', 'negative' ])) {
						$post['rating'] = $post['rating'] === 'positive' ? 5 : 1;
					}
				}

				$passes_star_filter = !$is_star_filters || ($is_star_filters && (isset($post['rating']) && in_array($post['rating'], $settings['includedStarFilters']))) ? true : false;
				$has_includeword = false;
				$has_excludeword = false;

				$passes_word_filter = false;
				$passes_moderation = true;


				if ($is_includeword && !empty($includewords)) {
					foreach ($includewords as $includeword) {
						if (strpos(strtolower($post['text']), strtolower($includeword)) !== false) {
							$has_includeword = true;
						}
					}
				}

				if ($is_excludeword && !empty($excludewords)) {
					foreach ($excludewords as $excludeword) {
						if (strpos(strtolower($post['text']), strtolower($excludeword)) !== false) {
							$has_excludeword = true;
						}
					}
				}

				if (!empty($excludewords) && !empty($includewords)) {
					$passes_word_filter = $has_includeword && !$has_excludeword;
				} elseif (!empty($includewords)) {
					$passes_word_filter = $has_includeword;
				} else {
					$passes_word_filter = !$has_excludeword;
				}


				if ($moderatePosts === true && isset($settings['moderationEnabled']) && $settings['moderationEnabled'] === true) {
					$moderation_ids = isset($settings['moderationType']) && $settings['moderationType'] === 'allow' ? $settings['moderationAllowList'] : $settings['moderationBlockList'];
					if ($settings['moderationType'] === 'allow') {
						$passes_moderation = in_array($post['review_id'], $moderation_ids);
					}
					if ($settings['moderationType'] === 'block') {
						$passes_moderation = !in_array($post['review_id'], $moderation_ids);
					}
				}

				//Max Length and Min Length checking
				$text_length = strlen($post['text']);
				$passes_minchar_filter = ( !$is_minchar || ( $is_minchar && $text_length >= intval($settings['filterCharCountMin']) ) ) ? true : false;
				$passes_maxchar_filter = ( !$is_maxchar || ( $is_maxchar && $text_length <= intval($settings['filterCharCountMax']) ) ) ? true : false;


				if ($passes_star_filter === true && $passes_word_filter && $passes_moderation && $passes_minchar_filter && $passes_maxchar_filter) {
					$keep_post = true;
				}

				// $keep_post = apply_filters( 'sbr_passes_filter', $keep_post, $post, $settings );
				if ($keep_post) {
					$filtered_posts[] = $post;
				}
			}
		}

		if (!$is_randomize) {
			if ($is_sortbydate && !$is_sortbyrating) {
				$filtered_posts = $this->sort_array_bydate($filtered_posts, $sort_by_date);
			}

			if ($is_sortbyrating && !$is_sortbydate) {
				$filtered_posts = $this->sort_array_byrating($filtered_posts, $sort_by_rating);
			}

			if ($is_sortbyrating && $is_sortbydate) {
				$filtered_posts = $this->sort_array_byrating_and_date($filtered_posts, $sort_by_rating, $sort_by_date);
			}
		}

		return $filtered_posts;
	}


	public function sort_array_bydate($posts, $type = 'latest')
	{
		usort($posts, function ($a, $b) use ($type) {
			return $type == 'latest' ? $b['time'] <=> $a['time'] : $a['time'] <=> $b['time'];
		});
		return $posts;
	}

	public function sort_array_byrating($posts, $type = 'lowest')
	{
		usort($posts, function ($a, $b) use ($type) {
			return $type == 'highest' ? $b['rating'] <=> $a['rating'] : $a['rating'] <=> $b['rating'];
		});
		return $posts;
	}

	public function sort_array_byrating_and_date($posts, $rating_type = 'lowest', $date_type = 'latest')
	{
		usort($posts, function ($a, $b) use ($rating_type, $date_type) {
			if ($a['rating'] === $b['rating']) {
				return $date_type == 'latest' ? $b['time'] <=> $a['time'] : $a['time'] <=> $b['time'];
			}
			return $rating_type == 'highest' ? $b['rating'] <=> $a['rating'] : $a['rating'] <=> $b['rating'];
		});
		return $posts;
	}

	public function get_posts_for_moderation()
	{
		$settings = $this->get_settings();
		$aggregator = new PostAggregator();
		// Pass limit from settings (default 150 for backward compatibility)
		$limit = isset($settings['numPostDesktop']) ? max(150, (int) $settings['numPostDesktop']) : 150;
		$posts = $aggregator->db_post_set($settings['sources'], null, $limit);
		$posts = $aggregator->normalize_db_post_set($posts);
		$post_set = $this->filter_posts($posts, $settings);
		return $post_set;
	}

	public function hydrate_single_manual_review($review)
	{
		return [
			'review_id'		=> uniqid(),
			'text' 			=> $review['content'],
			'rating' 		=> $review['rating'],
			'time' 			=> $review['time'],
			'reviewer' 		=> [
					'name' 		=> $review['name'],
					'avatar' 	=> $review['avatar']
			],
			'provider'		=> [
				'name' => $review['provider']
			]
		];
	}

	public function is_init_wpml()
	{
		return false;
	}

}
