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
			return (string) $post['text'];
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
			return $post['reviewer']['name'];
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
			$average_rating = 0;
			$number = 0;
			foreach ($businesses as $business) {
				// Check for rating first, then fall back to average_rating for WooCommerce multi-product sources
				$rating = $business['info']['rating'] ?? $business['info']['average_rating'] ?? 0;
				if (! empty($rating)) {
					$average_rating += floatval($rating);
					$number += 1;
				}
			}
			$number = $number === 0 ? 1 : $number;
			return round($average_rating / $number, 1);
		}
		return '';
	}

	public function get_num_ratings($businesses)
	{
		if (is_array($businesses)) {
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
