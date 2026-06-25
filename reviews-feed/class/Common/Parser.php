<?php

namespace SmashBalloon\Reviews\Common;

class Parser {
	public function __construct()
	{
	}

	public function get_id($post)
	{
		if (! empty($post['review_id'])) {
			return (string) $post['review_id'];
		}
		if (! empty($post['id'])) {
			return (string) $post['id'];
		}
		return '';
	}

	public function get_text($post)
	{
		if (! empty($post['text'])) {
			// Decode HTML entities (fixes Danish characters like æ, ø, å and other special chars)
			return html_entity_decode((string) $post['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return '';
	}


	public function get_rating($post)
	{
		if (!empty($post['rating'])) {
			if ($post['rating'] === 'positive') {
				return 5;
			} elseif ($post['rating'] === 'negative') {
				return 1;
			} else {
				return (int) $post['rating'];
			}
		}
		return 1;
	}

	public function get_time($post)
	{
		if (! empty($post['time'])) {
			return $post['time'];
		}
		return 0;
	}

	public function get_reviewer_name($post)
	{
		if (! empty($post['reviewer']['name'])) {
			// Decode HTML entities (fixes Danish characters like æ, ø, å and other special chars)
			return html_entity_decode($post['reviewer']['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return '';
	}



	public function get_provider_name($post_or_business)
	{
		if (! empty($post_or_business['provider']['name'])) {
			return $post_or_business['provider']['name'];
		}
		return '';
	}

	public function get_business_id($post_or_business)
	{
		if (! empty($post_or_business['business']['id'])) {
			return $post_or_business['business']['id'];
		} elseif (! empty($post_or_business['id'])) {
			return $post_or_business['id'];
		}
		return '';
	}

	public function get_business_name($post_or_business)
	{
		if (! empty($post_or_business['business']['name'])) {
			return $post_or_business['business']['name'];
		} elseif (! empty($post_or_business['name']) && is_string($post_or_business['name'])) {
			return $post_or_business['name'];
		}
		return '';
	}

	public function get_average_rating($businesses)
	{
		if (is_array($businesses)) {
			// SMASH-1412: prefer the plugin-computed dedup'd aggregate when
			// present. EDD / WooCommerce multi-source feeds stamp this on every
			// source.info so the first one wins. Older feeds without the field
			// fall through to the per-source weighted mean below.
			foreach ($businesses as $business) {
				if (! empty($business['info']['feed_aggregated'])) {
					return round(floatval($business['info']['feed_average_rating'] ?? 0), 1);
				}
			}

			// SMASH-1583: weight each source's rating by its review count so a
			// 3-review source can't swing the headline average as hard as a
			// 219-review one. A multi-source feed (e.g. Google 219@4.7 +
			// Facebook 3@5.0) must report sum(rating*count)/sum(count) = 4.7,
			// not the unweighted mean round((4.7+5.0)/2, 1) = 4.9.
			//
			// Falls back to the legacy unweighted mean when no source reports a
			// usable count (manual feeds, providers without a count) — this
			// preserves the pre-1583 number and avoids a divide-by-zero / 0.0
			// regression on feeds whose counts are all empty.
			$weighted_sum = 0.0;
			$weighted_n   = 0;
			$simple_sum   = 0.0;
			$simple_n     = 0;
			foreach ($businesses as $business) {
				// Check for rating first, then fall back to average_rating for WooCommerce multi-product sources
				$rating = $business['info']['rating'] ?? $business['info']['average_rating'] ?? 0;
				if (empty($rating)) {
					continue;
				}
				$count         = intval($business['info']['total_rating'] ?? $business['info']['review_count'] ?? 0);
				$weighted_sum += floatval($rating) * $count;
				$weighted_n   += $count;
				$simple_sum   += floatval($rating);
				$simple_n     += 1;
			}

			if ($weighted_n > 0) {
				return round($weighted_sum / $weighted_n, 1);
			}

			$simple_n = $simple_n === 0 ? 1 : $simple_n;
			return round($simple_sum / $simple_n, 1);
		}
		return '';
	}

	/**
	 * Backfill missing per-source review counts from the actual cached reviews.
	 *
	 * Some providers don't expose a reliable count in the source header. Facebook
	 * is the canonical case (SMASH-1583): modern Pages use recommendations, so
	 * FB Graph's legacy `rating_count` comes back 0, omitted, OR a stale low
	 * value (e.g. 1 while 2 recommendations are actually cached and displayed).
	 * The header then under-reports the combined count and the count-weighted
	 * average mistreats the Facebook source.
	 *
	 * For each header source we set the count to max(reported, cached-reviews),
	 * keyed by each post's `source.id`. Sources that report a true total (Google,
	 * Yelp, Trustpilot, …) always report >= what's cached, so they're left
	 * untouched — counting cached posts would UNDER-report them. Only a stale or
	 * empty reported count (Facebook) gets corrected up to the real cached number.
	 *
	 * @param array $businesses Header data (one entry per source, each with info.id).
	 * @param array $posts      Full normalized cached reviews (each with source.id).
	 * @return array Header data with info.total_rating corrected where the cached count is higher.
	 */
	public function backfill_review_counts($businesses, $posts)
	{
		if (! is_array($businesses)) {
			return [];
		}
		if (empty($businesses)) {
			return $businesses; // Nothing to backfill (e.g. single-manual-review header).
		}
		if (empty($posts) || ! is_array($posts)) {
			return $businesses;
		}

		$counts_by_source = $this->tally_reviews_by_source($posts);
		if (empty($counts_by_source)) {
			return $businesses;
		}

		foreach ($businesses as $key => $business) {
			$info = $business['info'] ?? null;
			if (! is_array($info)) {
				continue;
			}
			$id = isset($info['id']) ? (string) $info['id'] : '';
			if ($id === '' || empty($counts_by_source[$id])) {
				continue;
			}
			// Use the LARGER of the provider-reported count and the actual cached
			// reviews. Providers that report a true total (Google / Yelp /
			// Trustpilot / TripAdvisor / WP.org) always report >= what's cached,
			// so they're left untouched — their real totals routinely exceed the
			// cached page and must never be down-counted. Facebook is the problem
			// case: its deprecated rating_count persists 0 OR a stale low value
			// (e.g. 1 while 2 recommendations are cached and displayed), so the
			// cached count is the honest floor and wins.
			$reported = (int) ($info['total_rating'] ?? $info['review_count'] ?? 0);
			$cached   = (int) $counts_by_source[$id];
			if ($cached > $reported) {
				$businesses[$key]['info']['total_rating'] = $cached;
			}
		}
		return $businesses;
	}

	/**
	 * Tally cached reviews per source id (keyed by each post's source.id).
	 *
	 * Shared by backfill_review_counts() and the customizer-preview enrichment
	 * so the count rule lives in exactly one place.
	 *
	 * @param array $posts Normalized cached reviews.
	 * @return array<string,int> source id => review count
	 */
	public function tally_reviews_by_source($posts)
	{
		$counts_by_source = [];
		if (! is_array($posts)) {
			return $counts_by_source;
		}
		foreach ($posts as $post) {
			if (! is_array($post)) {
				continue;
			}
			$source = $post['source'] ?? null;
			if (! is_array($source)) {
				continue;
			}
			$source_id = (string) ($source['id'] ?? '');
			if ($source_id === '') {
				continue;
			}
			$counts_by_source[$source_id] = ($counts_by_source[$source_id] ?? 0) + 1;
		}
		return $counts_by_source;
	}

	public function get_num_ratings($businesses)
	{
		if (is_array($businesses)) {
			// SMASH-1412: see get_average_rating() — prefer feed-level dedup.
			foreach ($businesses as $business) {
				if (! empty($business['info']['feed_aggregated'])) {
					return intval($business['info']['feed_total_review_count'] ?? 0);
				}
			}

			$total_rating = 0;
			foreach ($businesses as $business) {
				// Check for total_rating first, then fall back to review_count for WooCommerce multi-product sources
				$count = $business['info']['total_rating'] ?? $business['info']['review_count'] ?? 0;
				if (! empty($count)) {
					$total_rating += intval($count);
				}
			}
			return $total_rating;
		}
		return '';
	}

	public function get_max_rating($business)
	{
		if (! empty($business['max'])) {
			return $business['max'];
		}
		return '';
	}

	public function get_rating_type($business)
	{
		if (! empty($business['type'])) {
			return $business['type'];
		}
		return '';
	}

	public function get_business_image($business)
	{
		if (! empty($business['avatar'])) {
			return $business['avatar'];
		}
		return '';
	}

	public function get_review_url($business, $source)
	{

		if (! empty($business['review_url'])) {
			return $business['review_url'];
		}
		if (! empty($business['info']['url'])) {
			if (strpos($business['info']['url'], 'https://www.facebook.com') === 0) {
				return $this->convert_to_fb_review_url($business['info']['url']);
			} elseif (strpos($business['info']['url'], 'https://www.yelp.com') === 0) {
				return $this->convert_to_yelp_review_url($business['info']['url']);
			} elseif (strpos($business['info']['url'], 'https://www.tripadvisor.com') === 0) {
				return $this->convert_to_tripadvisor_review_url($business['info']['url']);
			} elseif (isset($source['provider']) && $source['provider'] === 'woocommerce') {
				// WooCommerce single product - add #reviews anchor
				return $this->convert_to_woocommerce_review_url($business['info']['url']);
			}
			return $business['info']['url'];
		} else {
			if (isset($source['provider']) && $source['provider'] === 'google') {
				return $this->convert_to_google_review_url($source['account_id']);
			}

			// Handle WooCommerce multi-product sources - link to first product's review section
			if (isset($source['provider']) && $source['provider'] === 'woocommerce') {
				return $this->get_woocommerce_review_url($source);
			}
		}

		return '';
	}

	/**
	 * Get the review URL for WooCommerce sources.
	 *
	 * For single product sources, returns the product URL with #reviews anchor.
	 * For multi-product sources, returns the first product's URL with #reviews anchor.
	 *
	 * @since 2.4.0
	 * @param array $source The source data.
	 * @return string The review URL or empty string if not available.
	 */
	public function get_woocommerce_review_url($source)
	{
		// Decode info if it's a JSON string
		$info = $source['info'] ?? [];
		if (is_string($info)) {
			$info = json_decode($info, true);
			if (! is_array($info)) {
				$info = [];
			}
		}

		// Check for single product source URL first
		if (! empty($info['url'])) {
			return $this->convert_to_woocommerce_review_url($info['url']);
		}

		// For multi-product sources, use the first product's URL from direct_products (has URLs)
		// or products array as fallback
		if (! empty($info['direct_products']) && is_array($info['direct_products'])) {
			$first_product = $info['direct_products'][0] ?? [];
			if (! empty($first_product['url'])) {
				return $this->convert_to_woocommerce_review_url($first_product['url']);
			}
		}

		// Fallback to products array
		if (! empty($info['products']) && is_array($info['products'])) {
			$first_product = $info['products'][0] ?? [];
			if (! empty($first_product['url'])) {
				return $this->convert_to_woocommerce_review_url($first_product['url']);
			}
		}

		// Fallback: try source URL directly
		if (! empty($source['url'])) {
			return $this->convert_to_woocommerce_review_url($source['url']);
		}

		return '';
	}

	/**
	 * Convert a WooCommerce product URL to a review URL by adding #reviews anchor.
	 *
	 * @since 2.4.0
	 * @param string $url The product URL.
	 * @return string The URL with #reviews anchor.
	 */
	public function convert_to_woocommerce_review_url($url)
	{
		// Remove any existing fragment
		$url = preg_replace('/#.*$/', '', $url);

		// Add #reviews anchor (standard WooCommerce review tab anchor)
		return trailingslashit($url) . '#reviews';
	}

	public function convert_to_google_review_url($account_id)
	{
		return "https://search.google.com/local/writereview?placeid=" .  $account_id;
	}

	public function convert_to_fb_review_url($url)
	{
		if (strpos($url, 'reviews') === false) {
			return trailingslashit($url) . 'reviews';
		}

		return $url;
	}

	public function convert_to_yelp_review_url($url)
	{
		if (strpos($url, 'writeareview') === false) {
			return str_replace('biz/', 'writeareview/biz/', $url);
		}

		return $url;
	}

	public function convert_to_tripadvisor_review_url($url)
	{
		if (strpos($url, 'UserReview') === false) {
			$url_parts = explode('/', $url);

			$last_url_part = end($url_parts);

			$dashes_parts = explode('-', $last_url_part);

			if (! empty($dashes_parts)) {
				return str_replace($dashes_parts[0], 'UserReviewEdit', $url);
			}
		}

		return $url;
	}

	public function get_location_url($business)
	{
		if (! empty($business['location_url'])) {
			return $business['location_url'];
		}
		return '';
	}
}
