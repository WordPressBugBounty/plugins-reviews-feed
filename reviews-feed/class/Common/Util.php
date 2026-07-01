<?php

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// Note: Table name interpolation is safe (uses internal constants, not user input).

namespace SmashBalloon\Reviews\Common;

use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Helpers\SBR_Error_Handler;
use SmashBalloon\Reviews\Pro\Integrations\Providers\EDD;

class Util
{
	public static function isProduction()
	{
		return SBR_PRODUCTION;
	}

	public static function UTMCampaign()
	{
		return '';
	}

	/**
	 * Get feature flags from config file
	 *
	 * @return array
	 */
	public static function get_feature_flags()
	{
		$config_file = SBR_PLUGIN_DIR . 'config/feature-flags.php';
		if (file_exists($config_file)) {
			return include $config_file;
		}
		return [];
	}

	/**
	 * Check if a provider is disabled via feature flags
	 *
	 * @param string $provider_type Provider type to check
	 * @return bool
	 */
	public static function is_provider_disabled($provider_type)
	{
		$flags = self::get_feature_flags();
		$disabled = isset($flags['disabled_providers']) ? $flags['disabled_providers'] : [];
		return in_array($provider_type, $disabled, true);
	}

	/**
	 * Resolve EDD's pluginRequired / pluginRequiredMessage for the customizer
	 * provider tile. Strict: in Pro the gate requires both EDD core AND the
	 * EDD Reviews extension — otherwise the modal would let users add a source
	 * the integration can't actually capture new reviews for.
	 *
	 * Free builds may not ship class/Pro/Integrations/Providers/EDD.php, so
	 * fall back to the prior core-only check there. Free never reaches this
	 * tile in the disabled-from-extension state anyway (EDD shows as an
	 * upsell, not a regular provider), but the fallback keeps parity with
	 * pre-existing behavior.
	 *
	 * @return array Two-key map: pluginRequired (bool), pluginRequiredMessage (string)
	 */
	private static function get_edd_provider_status()
	{
		if (class_exists(EDD::class)) {
			return [
				'pluginRequired'        => ! EDD::is_active_static(),
				'pluginRequiredMessage' => EDD::plugin_required_message(),
			];
		}

		return [
			'pluginRequired'        => ! ( class_exists('Easy_Digital_Downloads') || defined('EDD_VERSION') ),
			'pluginRequiredMessage' => __('Enable Easy Digital Downloads plugin to use it as a source', 'reviews-feed'),
		];
	}

	public static function get_providers()
	{
		$campaign = self::sbr_is_pro() ? 'reviews-pro' : 'reviews-free';

		$providers = [
			[
				'type'    => 'google',
				'name'    => 'Google',
				'heading' => __('Place ID', 'reviews-feed'),
				'placeholder' => __('Enter Place ID', 'reviews-feed'),
				'docLink' => 'https://smashballoon.com/doc/creating-a-google-api-key/?utm_campaign=' . $campaign . '&utm_source=settings&utm_medium=docs'
			],
			[
				'type' => 'facebook',
				'name' => 'Facebook'
			],
			[
				'type' => 'tripadvisor',
				'name' => 'TripAdvisor',
				'heading' => __('Page URL', 'reviews-feed'),
				'placeholder' => __('https://tripadvisor.com/...', 'reviews-feed'),
				'apiKey' => true,
				'mandatoryApiKey'    => true,
				'docLink' => 'https://smashballoon.com/doc/creating-a-tripadvisor-api-key/?utm_campaign=' . $campaign . '&utm_source=settings&utm_medium=docs'
			],
			[
				'type' => 'yelp',
				'name' => 'Yelp',
				'heading' => __('Page URL', 'reviews-feed'),
				'placeholder' => __('https://yelp.com/...', 'reviews-feed'),
				'docLink' => 'https://smashballoon.com/doc/creating-a-yelp-api-key/?utm_campaign=' . $campaign . '&utm_source=settings&utm_medium=docs'
			],
			[
				'type' => 'trustpilot',
				'name' => 'TrustPilot',
				'heading' => __('Page URL', 'reviews-feed'),
				'placeholder' => __('https://trustpilot.com/review/...', 'reviews-feed'),
				'onlyDetails' => true
			],
			[
				'type' => 'wordpress.org',
				'name' => 'WordPress.org',
				'heading' => __('Page URL', 'reviews-feed'),
				'placeholder' => __('https://wordpress.org/...', 'reviews-feed'),
				'onlyDetails' => true
			],
			[
				'type' => 'woocommerce',
				'name' => 'WooCommerce',
				'heading' => __('Product', 'reviews-feed'),
				'placeholder' => __('Select a product', 'reviews-feed'),
				'onlyDetails' => true,
				'isLocal' => true,
				'pluginRequired' => ! ( class_exists('WooCommerce') || function_exists('WC') ),
				'pluginRequiredMessage' => __('Enable WooCommerce plugin to use it as a source', 'reviews-feed'),
			],
			array_merge(
				[
					'type' => 'edd',
					'name' => 'Easy Digital Downloads',
					'heading' => __('Download', 'reviews-feed'),
					'placeholder' => __('Select a download', 'reviews-feed'),
					'onlyDetails' => true,
					'isLocal' => true,
				],
				self::get_edd_provider_status()
			),
			[
				'type' => 'airbnb',
				'name' => 'Airbnb',
				'heading' => __('Listing URL', 'reviews-feed'),
				'placeholder' => __('https://www.airbnb.com/rooms/...', 'reviews-feed'),
				'onlyDetails' => true
			],
			[
				'type' => 'booking',
				'name' => 'Booking.com',
				'heading' => __('Hotel URL', 'reviews-feed'),
				'placeholder' => __('https://www.booking.com/hotel/...', 'reviews-feed'),
				'onlyDetails' => true
			],
			[
				'type' => 'aliexpress',
				'name' => 'AliExpress',
				'heading' => __('Product URL', 'reviews-feed'),
				'placeholder' => __('https://www.aliexpress.com/item/...', 'reviews-feed'),
				'onlyDetails' => true
			]
		];

		// Filter out disabled providers
		$providers = array_filter($providers, function ($provider) {
			return ! self::is_provider_disabled($provider['type']);
		});

		return array_values($providers);
	}

	/**
	* Used as a listener for the account connection process. If
	* data is returned from the account connection processed it's used
	* to generate the list of possible sources to chose from.
	*
	* @return array|bool
	*
	* @since 1.0
	*/
	public static function maybe_source_connection_data()
	{
		$nonce = !empty($_GET['cff_con']) ? sanitize_key($_GET['cff_con']) : '';
		if (!wp_verify_nonce($nonce, 'cff_con')) {
			return false;
		}

		if (
			isset($_GET['cff_access_token'])
			&& isset($_GET['cff_final_response'])
			&& $_GET['cff_final_response'] == 'true'
		) {
			$access_token = sanitize_text_field(wp_unslash($_GET['cff_access_token']));
			$return = Util::retrieve_available_pages($access_token);

			if ($return) {
				return $return;
			} else {
				return array('error' => __('Unable to connect to Facebook to retrieve account information.', 'reviews-feed'));
			}
		}
		return false;
	}

	/**
	 * Uses the Facebook API to retrieve a list of pages for the
	 * access token
	 *
	 * @param string $access_token
	 *
	 * @return array|bool
	 *
	 * @since 1.0
	 */
	public static function retrieve_available_pages($access_token)
	{
		//Get User Info
		$user_url = 'https://graph.facebook.com/me?fields=name,id,picture&access_token=' . urlencode($access_token);
		$user_id_data = wp_remote_retrieve_body(wp_remote_get($user_url));
		$user_id_data_arr = json_decode($user_id_data, true);

		$url = 'https://graph.facebook.com/me/accounts?fields=access_token,name,id,overall_star_rating,rating_count&limit=500&access_token=' . urlencode($access_token);
		$pages_data = wp_remote_retrieve_body(wp_remote_get($url));
		$pages_data_arr = json_decode($pages_data, true);


		if (isset($pages_data_arr['data'])) {
			$return = [
				'user' => $user_id_data_arr,
				'pages' => $pages_data_arr['data'],
			];

			return $return;
		} elseif (isset($pages_data_arr['error'])) {
			return $pages_data_arr;
		} else {
			return [
				'error' => [
					'code' => 'HTTP Request',
					'message' => __('Your server could not complete a remote request to Facebook\'s API. Your host may be blocking access or
                    there may be a problem with your server.', 'reviews-feed')
				]
			];
		}
	}

	public static function currentPageIs($page)
	{
		$current_screen = get_current_screen();
		return $current_screen !== null && !empty($current_screen) && strpos($current_screen->id, $page) !== false;
	}

	/**
	 * Check Notices for no Provider API KEY
	 *
	 *
	 * @return boolean
	 *
	 * @since 1.0
	 */
	public static function check_notice_provider_nokey($api_limits)
	{
		$noapi_providers = ['wordpress.org', 'trustpilot'];
		$is_true = $api_limits == $noapi_providers ? false : true;

		foreach ($noapi_providers as $provider) {
			if ($api_limits == [$provider]) {
				$is_true = false;
			}
		}
		return $is_true;
	}

	/**
	 * Returns a list of notices to be displayed in the SB Reviews Pages
	 *
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function get_plugin_notices()
	{
		$notices = [];

		$api_limits = get_option('sbr_apikeys_limit', []);
		$should_show_notice = is_array($api_limits) && sizeof($api_limits) > 0 && Util::check_notice_provider_nokey($api_limits);
		if ($should_show_notice) {
			array_push($notices, [
				'type' => 'error',
				'heading' => __('New reviews will not display until an API key is entered for your sources.', 'reviews-feed'),
				'description' => __('Due to API limitations your feeds will not show new reviews until you enter an API key on the settings page.', 'reviews-feed'),
				'actions' => [
					[
						'type' => 'primary',
						'text' => __('How to create an API key', 'reviews-feed'),
						'link' => admin_url('admin.php?page=sbr-settings')
					],
					[
						'type' => 'secondary',
						'text' => __('Go to Settings page', 'reviews-feed'),
						'link' => admin_url('admin.php?page=sbr-settings')
					]
				]
			]);
		}

		$noAPIKeyProviders = ['trustpilot', 'wordpress.org'];
		$noAPIProviderxists = count(array_intersect($noAPIKeyProviders, $api_limits)) ? true : false;

		if ($noAPIProviderxists) {
			array_push($notices, [
				'type' => 'error',
				'heading' => __('You have reached the number of allowed sources.', 'reviews-feed'),
				'description' => __('Due to API limitations your feeds will not show new reviews because you have reached the number of allowed sources.', 'reviews-feed'),
			]);
		}

		return $notices;
	}

	/**
	 * Get Smahballoon Plugins Info
	 *
	 * @since 1.0
	 */
	public static function get_plugins_info()
	{
		$installed_plugins = get_plugins();
		$campaign = self::sbr_is_pro() ? 'reviews-pro' : 'reviews-free';

		$plugins_list = [
			'facebook' => [
				'free' => 'custom-facebook-feed/custom-facebook-feed.php',
				'pro' => 'custom-facebook-feed-pro/custom-facebook-feed.php',
				'link' => 'https://smashballoon.com/custom-facebook-feed/?utm_campaign=' . $campaign . '&utm_source=about-us&utm_medium=marketing'
			],
			'instagram' => [
				'free' => 'instagram-feed/instagram-feed.php',
				'pro' => 'instagram-feed-pro/instagram-feed.php',
				'link' => 'https://smashballoon.com/instagram-feed/?utm_campaign=' . $campaign . '&utm_source=about-us&utm_medium=marketing'
			],
			'twitter' => [
				'free' => 'custom-twitter-feeds/custom-twitter-feed.php',
				'pro' => 'custom-twitter-feeds-pro/custom-twitter-feed.php',
				'link' => 'https://smashballoon.com/custom-twitter-feeds/?utm_campaign=' . $campaign . '&utm_source=about-us&utm_medium=marketing'
			],
			'youtube' => [
				'free' => 'feeds-for-youtube/youtube-feed.php',
				'pro' => 'youtube-feed-pro/youtube-feed.php',
				'link' => 'https://smashballoon.com/youtube-feed/?utm_campaign=' . $campaign . '&utm_source=about-us&utm_medium=marketing'
			]
		];

		foreach ($plugins_list as $name => $plugin) {
			$type = 'none';
			$activated = 'none';
			if (isset($installed_plugins[$plugin['free']])) {
				$type = 'free';
				$activated = is_plugin_active($plugin['free']);
			}
			if (isset($installed_plugins[$plugin['pro']])) {
				$type = 'pro';
				$activated = is_plugin_active($plugin['pro']);
			}
			$plugins_list[$name]['activated'] = $activated;
			$plugins_list[$name]['type'] = $type;
		}

		return [
			'facebook' => [
				'plugin' => $plugins_list['facebook']['pro'],
				'link' => $plugins_list['facebook']['link'],
				'download_plugin' => 'https://downloads.wordpress.org/plugin/custom-facebook-feed.zip',
				'title' => __('Custom Facebook Feed', 'reviews-feed'),
				'description' => __('Add Facebook posts from your timeline, albums and much more.', 'reviews-feed'),
				'icon' => 'fb-icon.svg',
				'activated' => $plugins_list['facebook']['activated'],
				'type' => $plugins_list['facebook']['type'],
			],
			'instagram' => [
				'plugin' => $plugins_list['instagram']['pro'],
				'link' => $plugins_list['instagram']['link'],
				'download_plugin' => 'https://downloads.wordpress.org/plugin/instagram-feed.zip',
				'title' => __('Instagram Feed', 'reviews-feed'),
				'description' => __('A quick and elegant way to add your Instagram posts to your website. ', 'reviews-feed'),
				'icon' => 'insta-icon.svg',
				'activated' => $plugins_list['instagram']['activated'],
				'type' => $plugins_list['instagram']['type'],
			],
			'twitter' => [
				'plugin' => $plugins_list['twitter']['pro'],
				'link' => $plugins_list['twitter']['link'],
				'download_plugin' => 'https://downloads.wordpress.org/plugin/custom-twitter-feeds.zip',
				'title' => __('Custom Twitter Feeds', 'reviews-feed'),
				'description' => __('A customizable way to display tweets from your Twitter account. ', 'reviews-feed'),
				'icon' => 'twitter-icon.svg',
				'activated' => $plugins_list['twitter']['activated'],
				'type' => $plugins_list['twitter']['type'],
			],
			'youtube' => [
				'plugin' => $plugins_list['youtube']['pro'],
				'link' => $plugins_list['youtube']['link'],
				'download_plugin' => 'https://downloads.wordpress.org/plugin/feeds-for-youtube.zip',
				'title' => __('Feeds for YouTube', 'reviews-feed'),
				'description' => __('A simple yet powerful way to display videos from YouTube. ', 'reviews-feed'),
				'icon' => 'youtube-icon.svg',
				'activated' => $plugins_list['youtube']['activated'],
				'type' => $plugins_list['youtube']['type'],
			]
		];
	}

	/**
	 * Get Smahballoon Recommended Plugins Info
	 *
	 * @since 1.0
	 */
	public static function get_smashballoon_recommended_plugins_info()
	{
		$installed_plugins = get_plugins();
		return [
				'wpforms'         => [
					'plugin'          => 'wpforms-lite/wpforms.php',
					'download_plugin' => 'https://downloads.wordpress.org/plugin/wpforms-lite.zip',
					'title'           => __('WPForms', 'reviews-feed'),
					'description'     => __('The most beginner friendly drag & drop WordPress forms plugin allowing you to create beautiful contact forms, subscription forms, payment forms, and more in minutes, not hours!', 'reviews-feed'),
					'icon'            => 'plugin-wpforms.png',
					'installed'       => isset($installed_plugins['wpforms-lite/wpforms.php']),
					'activated'       => is_plugin_active('wpforms-lite/wpforms.php'),
				],
				'monsterinsights' => [
					'plugin'          => 'google-analytics-for-wordpress/googleanalytics.php',
					'download_plugin' => 'https://downloads.wordpress.org/plugin/google-analytics-for-wordpress.zip',
					'title'           => __('MonsterInsights', 'reviews-feed'),
					'description'     => __('MonsterInsights makes it “effortless” to properly connect your WordPress site with Google Analytics, so you can start making data-driven decisions to grow your business.', 'reviews-feed'),
					'icon'            => 'plugin-mi.png',
					'installed'       => isset($installed_plugins['google-analytics-for-wordpress/googleanalytics.php']),
					'activated'       => is_plugin_active('google-analytics-for-wordpress/googleanalytics.php'),
				],
				'optinmonster'    => [
					'plugin'          => 'optinmonster/optin-monster-wp-api.php',
					'download_plugin' => 'https://downloads.wordpress.org/plugin/optinmonster.zip',
					'title'           => __('OptinMonster', 'reviews-feed'),
					'description'     => __('Our high-converting optin forms like Exit-Intent® popups, Fullscreen Welcome Mats, and Scroll boxes help you dramatically boost conversions and get more email subscribers.', 'reviews-feed'),
					'icon'            => 'plugin-om.png',
					'installed'       => isset($installed_plugins['optinmonster/optin-monster-wp-api.php']),
					'activated'       => is_plugin_active('optinmonster/optin-monster-wp-api.php'),
				],
				'wp_mail_smtp'    => [
					'plugin'          => 'wp-mail-smtp/wp_mail_smtp.php',
					'download_plugin' => 'https://downloads.wordpress.org/plugin/wp-mail-smtp.zip',
					'title'           => __('WP Mail SMTP', 'reviews-feed'),
					'description'     => __('Make sure your website\'s emails reach the inbox. Our goal is to make email deliverability easy and reliable. Trusted by over 1 million websites.', 'reviews-feed'),
					'icon'            => 'plugin-smtp.png',
					'installed'       => isset($installed_plugins['wp-mail-smtp/wp_mail_smtp.php']),
					'activated'       => is_plugin_active('wp-mail-smtp/wp_mail_smtp.php'),
				],
				'rafflepress'     => [
					'plugin'          => 'rafflepress/rafflepress.php',
					'download_plugin' => 'https://downloads.wordpress.org/plugin/rafflepress.zip',
					'title'           => __('RafflePress', 'reviews-feed'),
					'description'     => __('Turn your visitors into brand ambassadors! Easily grow your email list, website traffic, and social media followers with powerful viral giveaways & contests.', 'reviews-feed'),
					'icon'            => 'plugin-rp.png',
					'installed'       => isset($installed_plugins['rafflepress/rafflepress.php']),
					'activated'       => is_plugin_active('rafflepress/rafflepress.php'),
				],
				'aioseo'          => [
					'plugin'          => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
					'download_plugin' => 'https://downloads.wordpress.org/plugin/all-in-one-seo-pack.zip',
					'title'           => __('All in One SEO Pack', 'reviews-feed'),
					'description'     => __('Out-of-the-box SEO for WordPress. Features like XML Sitemaps, SEO for custom post types, SEO for blogs, business sites, or ecommerce sites, and much more.', 'reviews-feed'),
					'icon'            => 'plugin-seo.png',
					'installed'       => isset($installed_plugins['all-in-one-seo-pack/all_in_one_seo_pack.php']),
					'activated'       => is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php'),
				]
				];
	}
	/**
	 * Get other active plugins of Smash Balloon
	 *
	 * @since 4.4.0
	 */
	public static function get_sb_active_plugins_info()
	{
		// get the WordPress's core list of installed plugins
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed_plugins = get_plugins();

		$is_facebook_installed = false;
		$facebook_plugin = 'custom-facebook-feed/custom-facebook-feed.php';
		if (isset($installed_plugins['custom-facebook-feed-pro/custom-facebook-feed.php'])) {
			$is_facebook_installed = true;
			$facebook_plugin = 'custom-facebook-feed-pro/custom-facebook-feed.php';
		} elseif (isset($installed_plugins['custom-facebook-feed/custom-facebook-feed.php'])) {
			$is_facebook_installed = true;
		}

		$is_instagram_installed = false;
		$instagram_plugin = 'instagram-feed/instagram-feed.php';
		if (isset($installed_plugins['instagram-feed-pro/instagram-feed.php'])) {
			$is_instagram_installed = true;
			$instagram_plugin = 'instagram-feed-pro/instagram-feed.php';
		} elseif (isset($installed_plugins['instagram-feed/instagram-feed.php'])) {
			$is_instagram_installed = true;
		}

		$is_twitter_installed = false;
		$twitter_plugin = 'custom-twitter-feeds/custom-twitter-feed.php';
		if (isset($installed_plugins['custom-twitter-feeds-pro/custom-twitter-feed.php'])) {
			$is_twitter_installed = true;
			$twitter_plugin = 'custom-twitter-feeds-pro/custom-twitter-feed.php';
		} elseif (isset($installed_plugins['custom-twitter-feeds/custom-twitter-feed.php'])) {
			$is_twitter_installed = true;
		}

		$is_youtube_installed = false;
		$youtube_plugin = 'feeds-for-youtube/youtube-feed.php';
		if (isset($installed_plugins['youtube-feed-pro/youtube-feed-pro.php'])) {
			$is_youtube_installed = true;
			$youtube_plugin = 'youtube-feed-pro/youtube-feed-pro.php';
		} elseif (isset($installed_plugins['feeds-for-youtube/youtube-feed.php'])) {
			$is_youtube_installed = true;
		}

		$is_social_wall_installed = isset($installed_plugins['social-wall/social-wall.php']) ? true : false;
		$social_wall_plugin = 'social-wall/social-wall.php';


		return array(
			'is_facebook_installed' => $is_facebook_installed,
			'is_instagram_installed' => $is_instagram_installed,
			'is_twitter_installed' => $is_twitter_installed,
			'is_youtube_installed' => $is_youtube_installed,
			'is_social_wall_installed' => $is_social_wall_installed,
			'facebook_plugin' => $facebook_plugin,
			'instagram_plugin' => $instagram_plugin,
			'twitter_plugin' => $twitter_plugin,
			'youtube_plugin' => $youtube_plugin,
			'social_wall_plugin' => $social_wall_plugin,
			'installed_plugins' => $installed_plugins
		);
	}

	/**
	 * SBR Get Whitespace
	 *
	 * @since 1.0
	 *
	 * @param int $times
	 *
	 * @return string
	 */
	public static function get_whitespace($times)
	{
		return str_repeat('&nbsp;', $times);
	}

	/**
	 * Get Site and Server Info
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_site_n_server_info()
	{
		$allow_url_fopen = ini_get('allow_url_fopen') ? "Yes" : "No";
		$php_curl = is_callable('curl_init') ? "Yes" : "No";
		$php_json_decode = function_exists("json_decode") ? "Yes" : "No";
		$php_ssl = in_array('https', stream_get_wrappers()) ? "Yes" : "No";

		$output = '## SITE/SERVER INFO: ##' . "</br>";
		$output .= 'Plugin Version:' . self::get_whitespace(11) . SBR_MENU_SLUG . "</br>";
		$output .= 'Site URL:' . self::get_whitespace(17) . site_url() . "</br>";
		$output .= 'Home URL:' . self::get_whitespace(17) . home_url() . "</br>";
		$output .= 'WordPress Version:' . self::get_whitespace(8) . get_bloginfo('version') . "</br>";
		$output .= 'PHP Version:' . self::get_whitespace(14) . PHP_VERSION . "</br>";
		$output .= 'Web Server Info:' . self::get_whitespace(10) . esc_html($_SERVER['SERVER_SOFTWARE']) . "</br>";
		$output .= 'PHP allow_url_fopen:' . self::get_whitespace(6) . $allow_url_fopen . "</br>";
		$output .= 'PHP cURL:' . self::get_whitespace(17) . $php_curl . "</br>";
		$output .= 'JSON:' . self::get_whitespace(21) . $php_json_decode . "</br>";
		$output .= 'SSL Stream:' . self::get_whitespace(15) . $php_ssl . "</br>";
		$output .= "</br>";

		return $output;
	}

	/**
	 * Get Active Plugins
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_active_plugins_info()
	{
		$plugins = get_plugins();
		$active_plugins = get_option('active_plugins');
		$output = "## ACTIVE PLUGINS: ## </br>";

		foreach ($plugins as $plugin_path => $plugin) {
			if (in_array($plugin_path, $active_plugins)) {
				$output .= $plugin['Name'] . ': ' . $plugin['Version'] . "</br>";
			}
		}

		$output .= "</br>";

		return $output;
	}


	/**
	 * Get Global Settings
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_global_settings_info()
	{
		$output = '## GLOBAL SETTINGS: ## </br>';
		$sbr_settings = get_option('sbr_settings', array());
		if (! is_array($sbr_settings)) {
			$sbr_settings = array();
		}

		$plugin_status = new AuthorizationStatusCheck();

		$statuses = $plugin_status->get_statuses();

		if (Util::sbr_is_pro()) {
			$output .= 'License key: ';
			if (isset($sbr_settings['license_key'])) {
				$output .= esc_html($sbr_settings['license_key']);
			} else {
				$output .= ' Not added';
			}

			$output .= '</br>';
			$output .= 'License Tier: ';
			if (isset($statuses['license_tier'])) {
				$output .= esc_html($statuses['license_tier']);
			} else {
				$output .= ' Not Set';
			}
			$output .= '</br>';
			$output .= 'License status: ';
			if (isset($sbr_settings['license_status'])) {
				$output .= $sbr_settings['license_status'];
			} else {
				$output .= ' Inactive';
			}
			$output .= '</br>';
		}
		$output .= 'Preserve settings if plugin is removed: ';
		$output .= isset($sbr_settings['preserve_settings']) && ($sbr_settings['preserve_settings']) ? 'Yes' : 'No';
		$output .= '</br>';
		$output .= 'Caching: ';
		$output .= $statuses['license_tier'] === 3 ? 'Twice daily' : 'daily';

		$output .= '</br>';
		$output .= 'GDPR: ';
		$output .= isset($sbr_settings['gdpr']) ? $sbr_settings['gdpr'] : ' Not setup';
		$output .= '</br>';
		$output .= 'Optimize Images: ';
		$output .= isset($sbr_settings['optimize_images']) && $sbr_settings['optimize_images'] === true ? 'Enabled' : 'Disabled';
		$output .= '</br>';
		$output .= 'Usage Tracking: ';
		$output .= isset($sbr_settings['usagetracking']) && $sbr_settings['usagetracking'] === true ? 'Enabled' : 'Disabled';
		$output .= '</br>';
		$output .= 'Enqueue in Head: ';
		$output .= isset($sbr_settings['enqueue_js_in_header']) && $sbr_settings['enqueue_js_in_header'] === true ? 'Enabled' : 'Disabled';
		$output .= '</br>';
		$output .= 'Admin Error Notice: ';
		$output .= isset($sbr_settings['admin_error_notices']) && $sbr_settings['admin_error_notices'] === true ? 'Enabled' : 'Disabled';
		$output .= '</br>';
		$output .= 'Feed Issue Email Reports: ';
		$output .= isset($sbr_settings['feed_issue_reports']) && $sbr_settings['feed_issue_reports'] === true ? 'Enabled' : 'Disabled';
		$output .= '</br>';
		$output .= '</br>';
		return $output;
	}

	/**
	 * Get Feeds Settings
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_sources_settings_info()
	{
		$output = '## SOURCES: ## </br>';
		$source_list = SBR_Sources::get_sources_list();
		foreach ($source_list as $feed) {
			$output .= $feed['name'] . ' ( ' . strtoupper($feed['provider']) . ' => ' . $feed['account_id'] . ' )';
			$output .= '</br>';
		}
		$output .= '</br>';

		return $output;
	}

	/**
	 * Get Feeds Settings
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_api_settings_info()
	{
		$output = '## API KEYS: ## </br>';
		$api_keys = get_option('sbr_apikeys', []);

		foreach ($api_keys as $id => $api) {
			$output .= ucfirst($id) . ' => ' . $api;
			$output .= '</br>';
		}
		$output .= '</br>';

		return $output;
	}



	/**
	 * Get Feeds Settings
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_feeds_settings_info()
	{
		$output = '## FEEDS: ## </br>';

		$feeds_list = DB::get_feeds_list();

		$i = 0;
		foreach ($feeds_list as $feed) {
			if ($i >= 25) {
				break;
			}
			$output .= $feed['feed_name'];
			if (isset($feed['settings'])) {
				$output .= '</br>';
				if (!empty($feed['sourcesList'])) {
					foreach ($feed['sourcesList'] as $id => $source) {
						$output .= esc_html($source['name']);
						$output .= ' (' . esc_html(ucfirst($source['name'])) . ' => ' . esc_html($source['account_id']) . ')';
						$output .= '</br>';
					}
				}
			}
			$output .= '</br>';
			if (isset($feed['location_summary']) && count($feed['location_summary']) > 0) {
				$first_feed = $feed['location_summary'][0];
				if (!empty($first_feed['link'])) {
					$output .= esc_html($first_feed['link']) . '?sb_debug';
					$output .= '</br>';
				}
			}

			if ($i < (count($feeds_list) - 1)) {
				$output .= '</br>';
			}
			$i++;
		}
		$output .= '</br>';

		return $output;
	}

	/**
	 * Get Posts Table Info
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_posts_table_info()
	{
		$output = '## POSTS: ## </br>';

		global $wpdb;
		$table_name = $wpdb->prefix . 'sbr_posts';
		$feeds_posts_table_name = $wpdb->prefix . 'sbr_reviews_posts';

		if ($wpdb->get_var("show tables like '$feeds_posts_table_name'") !== $feeds_posts_table_name) {
			$output .= 'no feeds posts table</br>';
		} else {
			$last_result = $wpdb->get_results("SELECT * FROM $feeds_posts_table_name ORDER BY id DESC LIMIT 1;");
			if (is_array($last_result) && isset($last_result[0])) {
				$output .= '## FEEDS POSTS TABLE ##</br>';
				foreach ($last_result as $column) {
					foreach ($column as $key => $value) {
						$output .= esc_html($key) . ': ' . esc_html($value) . '</br>';
					}
				}
			} else {
				$output .= 'feeds posts has no rows';
				$output .= '</br>';
			}
		}
		$output .= '</br>';
		if ($wpdb->get_var("show tables like '$table_name'") !== $table_name) {
			$output .= 'no posts table</br>';
		} else {
			$last_result = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 1;");
			if (is_array($last_result) && isset($last_result[0])) {
				$output .= '## POSTS TABLE ##';
				$output .= '</br>';
				foreach ($last_result as $column) {
					foreach ($column as $key => $value) {
						$output .= esc_html($key) . ': ' . esc_html($value) . '</br>';
					}
				}
			} else {
				$output .= 'posts has no rows</br>';
			}
		}
		$output .= '</br>';

		return $output;
	}

	/**
	 * List of possible languages/translations
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function get_translation_languages($include_default = false)
	{
		$languages = [
			'default' => 'Default',
			'' => 'No Translation',
			'en' =>	'English',
			'af' =>	'Afrikaans',
			'am' =>	'Amharic',
			'ar' =>	'Arabic',
			'az' =>	'Azerbaijani',
			'be' =>	'Belarusian',
			'bg' =>	'Bulgarian',
			'bn' =>	'Bengali',
			'bs' =>	'Bosnian',
			'ca' =>	'Catalan',
			'cs' =>	'Czech',
			'da' =>	'Danish',
			'de' =>	'German',
			'el' =>	'Greek',
			'en-AU' =>	'English (Australian)',
			'en-GB' =>	'English (Great Britain)',
			'es' =>	'Spanish',
			'es-419' =>	'Spanish (Latin America)',
			'et' =>	'Estonian',
			'eu' =>	'Basque',
			'fa' =>	'Farsi',
			'fi' =>	'Finnish',
			'fil' => 'Filipino',
			'fr' =>	'French',
			'fr-CA' =>	'French (Canada)',
			'gl' =>	'Galician',
			'gu' =>	'Gujarati',
			'hi' =>	'Hindi',
			'hr' =>	'Croatian',
			'hu' =>	'Hungarian',
			'hy' =>	'Armenian',
			'id' =>	'Indonesian',
			'is' =>	'Icelandic',
			'it' =>	'Italian	',
			'iw' =>	'Hebrew',
			'ja' =>	'Japanese',
			'ka' =>	'Georgian',
			'kk' =>	'Kazakh',
			'km' =>	'Khmer',
			'kn' =>	'Kannada',
			'ko' =>	'Korean',
			'ky' =>	'Kyrgyz',
			'lo' =>	'Lao',
			'lt' =>	'Lithuanian',
			'lv' =>	'Latvian',
			'mk' =>	'Macedonian',
			'ml' =>	'Malayalam',
			'mn' =>	'Mongolian',
			'mr' =>	'Marathi',
			'ms' =>	'Malay',
			'my' =>	'Burmese',
			'ne' =>	'Nepali',
			'nl' =>	'Dutch',
			'no' =>	'Norwegian',
			'pa' =>	'Punjabi',
			'pl' =>	'Polish',
			'pt' =>	'Portuguese',
			'pt-BR' => 'Portuguese (Brazil)',
			'pt-PT' => 'Portuguese (Portugal)',
			'ro' => 'Romanian',
			'ru' => 'Russian',
			'si' => 'Sinhalese',
			'sk' => 'Slovak',
			'sl' => 'Slovenian',
			'sq' => 'Albanian',
			'sr' => 'Serbian',
			'sv' => 'Swedish',
			'sw' => 'Swahili',
			'ta' => 'Tamil',
			'te' => 'Telugu',
			'th' => 'Thai',
			'tr' => 'Turkish',
			'uk' => 'Ukrainian',
			'ur' => 'Urdu',
			'uz' => 'Uzbek',
			'vi' => 'Vietnamese',
			'zh' => 'Chinese',
			'zh-CN' => 'Chinese (Simplified)',
			'zh-HK' => 'Chinese (Hong Kong)',
			'zh-TW' => 'Chinese (Traditional)',
			'zu' => 'Zulu'
		];
		if ($include_default === false) {
			array_shift($languages);
		}

		//Detect if WPMl is active then add the option
		if (defined('ICL_SITEPRESS_VERSION') && Util::sbr_is_pro()) {
			$position = $include_default ? 2 : 1;
			$languages = array_merge(
				array_slice($languages, 0, $position),
				['wpml' => 'Automatically by WPML'],
				array_slice($languages, $position)
			);
		}

		return $languages;
	}

	/**
	 * Get Language for API call
	 *
	 * @since 1.0
	 *
	 * @return string
	 */

	public static function get_settings_language($settings)
	{
		$args = isset($settings['localization']) && $settings['localization'] !== 'default'
				? $settings
				: wp_parse_args(get_option('sbr_settings', []), sbr_plugin_settings_defaults());

		return $args['localization'];
	}

	/**
	 * Get Language for API call
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_api_call_language($settings)
	{
		$language = Util::sbr_is_pro()
			? \SmashBalloon\Reviews\Pro\Helpers\SBR_WPML::get_current_language(Util::get_settings_language($settings))
			: Util::get_settings_language($settings);

		/**
		 * Filter the language code sent to the provider API (e.g. Google Places).
		 * Lets a site map an unsupported locale to a supported one, e.g. a WPML
		 * `es-mx` site forcing `es-419` for Latin American Spanish. SMASH-1617.
		 * Read the current WPML language inside the callback (apply_filters
		 * 'wpml_current_language') if you need per-page context.
		 *
		 * @since 2.6.6
		 * @param string $language Resolved language code (or 'default').
		 */
		return apply_filters('sbr_google_api_language', $language);
	}

	/**
	 * Map a locale / WPML language code to a code the Google Places API accepts.
	 *
	 * Google only honours codes from its supported list (get_translation_languages);
	 * an unsupported value (e.g. WPML's `es-mx`) makes Google return reviews
	 * untranslated. This normalises common regional codes to a supported one.
	 * Spanish is special-cased: Spain dialects -> `es`, any other Spanish variant
	 * (es-mx, es-ar, …) -> `es-419` (Latin America) — region-stripping alone would
	 * wrongly land on Spain Spanish. Returns null when nothing supported matches,
	 * so callers can fall back (never send a bogus code). SMASH-1617.
	 *
	 * @since 2.6.6
	 * @param string|null $code Raw locale / WPML code (e.g. 'es-mx', 'pt-br', 'es_MX').
	 * @return string|null A Google-supported code, or null if unmappable.
	 */
	public static function map_wpml_to_google_language($code)
	{
		if (! is_string($code) || $code === '') {
			return null;
		}

		$allowed = self::get_translation_languages();

		// Already a supported code (exact match) — keep existing behaviour.
		if (isset($allowed[$code]) && $code !== 'default' && $code !== '') {
			return $code;
		}

		$norm = strtolower(str_replace('_', '-', $code));

		// Spanish: Spain -> es; every other variant (es-mx, es-ar, …) -> es-419.
		if ($norm === 'es' || $norm === 'es-es') {
			return 'es';
		}
		if (strpos($norm, 'es-') === 0) {
			return 'es-419';
		}

		// Case-insensitive match for region variants Google does list (pt-br -> pt-BR).
		foreach (array_keys($allowed) as $supported) {
			if (strtolower($supported) === $norm) {
				return $supported;
			}
		}

		// Strip the region and fall back to the base language if supported (fr-fr -> fr).
		$base = strtok($norm, '-');
		if ($base !== false && isset($allowed[$base])) {
			return $base;
		}

		return null;
	}

	/**
	 * Is Plugin Pro
	 *
	 * @since 1.0
	 *
	 * @return boolean
	 */
	public static function sbr_is_pro()
	{
		return defined('SBR_PRO') && SBR_PRO === true;
	}

	/**
	 * Check if user has Pro Plus tier
	 *
	 * @since 2.5.0
	 *
	 * @return boolean
	 */
	public static function sbr_is_pro_plus()
	{
		if (!self::sbr_is_pro()) {
			return false;
		}

		// Read from sbr_statuses where license_tier is written during license activation
		// This is consistent with AuthorizationStatusCheck::get_license_tier()
		$statuses = get_option('sbr_statuses', []);
		$license_tier = $statuses['license_tier'] ?? 0;

		// Handle All Access Bundle special case (same as AuthorizationStatusCheck)
		if (
			isset($statuses['license_info']['item_name'])
			&& $statuses['license_info']['item_name'] === 'All Access Bundle - All Plugins Unlimited'
		) {
			$license_tier = 3;
		}

		// Tiers: 1 = Pro Basic, 2 = Pro Plus, 3 = Elite
		// Pro Plus and Elite (tiers 2 and 3) have full access
		return (int) $license_tier >= 2;
	}


	/**
	 * Get List of Upsell Modal Content
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function upsell_modal_content()
	{
		// Pro users (including Pro Plus) don't need provider upsell modals
		// Note: Review Alert Pro Plus upsells are handled separately in SBR_ReviewAlert_Builder
		if (Util::sbr_is_pro()) {
			return [];
		}

		// Free users get all upsell modals
		return [
			'facebookProvider' => [
				'heading' => __('Upgrade to Pro to display Facebook reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Plus" tier to display reviews from the well known social media platform.', 'reviews-feed'),
				'image' => 'upsell-facebook.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=facebook-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=facebook-modal&utm_content=Upgrade',
				# 'demo' => 'https://smashballoon.com/reviews-feed/demo/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=responsive-modal&utm_content=ViewDemo'
				],

				'includeContent' => true
			],
			'trustpilotProvider' => [
				'heading' => __('Upgrade to Pro to display TrustPilot reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Plus" tier to display reviews from the well known business review site.', 'reviews-feed'),
				'image' => 'upsell-trustpilot.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=trustpilot-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=trustpilot-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'tripadvisorProvider' => [
				'heading' => __('Upgrade to Pro to display TripAdvisor reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Elite" tier to display reviews from the well known travel advice site.', 'reviews-feed'),
				'image' => 'upsell-tripadvisor.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=tripadvisor-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=tripadvisor-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'wordpress.orgProvider' => [
				'heading' => __('Upgrade to Pro to display WordPress.org reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Elite" tier to display reviews for plugins and themes.', 'reviews-feed'),
				'image' => 'upsell-wordpress.org.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=wordpressorg-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=wordpressorg-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'woocommerceProvider' => [
				'heading' => __('Upgrade to Pro to display WooCommerce reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Plus" tier to display product reviews from your WooCommerce store.', 'reviews-feed'),
				'image' => 'upsell-woocommerce.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=woocommerce-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=woocommerce-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'eddProvider' => [
				'heading' => __('Upgrade to Pro to display Easy Digital Downloads reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Plus" tier to display download reviews from your EDD store.', 'reviews-feed'),
				'image' => 'upsell-woocommerce.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=edd-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=edd-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'airbnbProvider' => [
				'heading' => __('Upgrade to Pro to display Airbnb reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Plus" tier to display reviews from the popular accommodation platform.', 'reviews-feed'),
				'image' => 'upsell-airbnb.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=airbnb-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=airbnb-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'bookingProvider' => [
				'heading' => __('Upgrade to Pro to display Booking.com reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Plus" tier to display reviews from the leading travel booking site.', 'reviews-feed'),
				'image' => 'upsell-booking.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=booking-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=booking-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'aliexpressProvider' => [
				'heading' => __('Upgrade to Pro to display AliExpress reviews', 'reviews-feed'),
				'description' => __('Upgrade to our "Plus" tier to display product reviews from the global marketplace.', 'reviews-feed'),
				'image' => 'upsell-aliexpress.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=aliexpress-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=aliexpress-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'carouselModal' => [
				'heading' => __('Upgrade to Pro to get Carousel layout', 'reviews-feed'),
				'description' => __('An eye-catching rotating slider of your videos to add extra content in minimal space on your website.', 'reviews-feed'),
				'image' => 'upsell-carousel.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=carousel-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=carousel-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'moreReviewsModal' => [
				'heading' => __('Upgrade to Pro to display more reviews', 'reviews-feed'),
				'description' => __('More layout settings to customize the look and feel of your reviews even more.', 'reviews-feed'),
				'image' => 'upsell-morereviews.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=num-reviews-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=num-reviews-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'averageRatingModal' => [
				'heading' => __('Upgrade to Pro to display average rating', 'reviews-feed'),
				'description' => __('Boost social proof to make more sales conversions with the number of ratings and an average rating.', 'reviews-feed'),
				'image' => 'upsell-averagerating.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=average-rating-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=average-rating-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'loadMoreModal' => [
				'heading' => __('Upgrade to Pro to add load more functionality', 'reviews-feed'),
				'description' => __('Overwhelm (in a good way) your visitors with additional reviews loaded on the page with a click.', 'reviews-feed'),
				'image' => 'upsell-loadmore.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=load-more-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=load-more-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewsMediaModal' => [
				'heading' => __('Upgrade to Pro to add images', 'reviews-feed'),
				'description' => __('Display images from Yelp and Tripadvisor reviews.', 'reviews-feed'),
				'image' => 'upsell-reviewsmedia.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=lite-upgrade-footer-coupon&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=template-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'authorImageModal' => [
				'heading' => __('Upgrade to Pro to display author images', 'reviews-feed'),
				'description' => __('Build brand trust with positive reviews from real customers.', 'reviews-feed'),
				'image' => 'upsell-authorimage.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=author-avatar-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=author-avatar-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'filtersModal' => [
				'heading' => __('Upgrade to Pro to filter your reviews', 'reviews-feed'),
				'description' => __('Show only the most positive reviews and build brand trust with review filtering.', 'reviews-feed'),
				'image' => 'upsell-filters.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=star-filter-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=star-filter-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'moderationModal' => [
				'heading' => __('Upgrade to Pro to moderate your reviews', 'reviews-feed'),
				'description' => __('Take complete control of what reviews show in the feed using keyword filters and a visual moderation system.', 'reviews-feed'),
				'image' => 'upsell-moderation.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=moderation-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=moderation-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'templateModal' => [
				'heading' => __('Upgrade to Pro to get one-click templates!', 'reviews-feed'),
				'description' => __('Quickly create and preview new feeds with pre-configured options based on popular feed types.', 'reviews-feed'),
				'image' => 'upsell-template.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=template-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=template-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'responsiveModal' => [
				'heading' => __('Upgrade to Pro for responsive layouts', 'reviews-feed'),
				'description' => __('Take control of your feed layouts by customizing number of reviews & columns', 'reviews-feed'),
				'image' => 'upsell-responsive.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=all-feeds&utm_medium=responsive-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?utm_campaign=reviews-free&utm_source=customizer&utm_medium=responsive-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			// Review Alert Upsell Modals
			'reviewAlertFeature' => [
				'heading' => __('Upgrade to Pro for Review Alerts', 'reviews-feed'),
				'description' => __('Display eye-catching review popups that cycle through your best reviews to boost social proof and conversions.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-feature-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-feature-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewAlertRecentReviews' => [
				'heading' => __('Upgrade to Pro for Recent Reviews popup', 'reviews-feed'),
				'description' => __('Cycle through your individual reviews one by one to showcase real customer feedback and build trust.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-recent-reviews-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-recent-reviews-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewAlertVariations' => [
				'heading' => __('Upgrade to Pro for more popup styles', 'reviews-feed'),
				'description' => __('Unlock V2 and V3 style variations for your notification popups with unique layouts and designs.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-variations-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-variations-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewAlertDarkTheme' => [
				'heading' => __('Upgrade to Pro for dark theme popups', 'reviews-feed'),
				'description' => __('Match your site\'s dark mode with elegant dark-themed notification popups.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-dark-theme-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-dark-theme-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewAlertCustomColor' => [
				'heading' => __('Upgrade to Pro for custom popup colors', 'reviews-feed'),
				'description' => __('Customize your popup accent color to perfectly match your brand identity.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-custom-color-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-custom-color-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewAlertMultiple' => [
				'heading' => __('Upgrade to Pro Plus for multiple popups', 'reviews-feed'),
				'description' => __('Create unlimited notification popups and display different popups on different pages.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-multiple-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-multiple-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewAlertTargeting' => [
				'heading' => __('Upgrade to Pro for page targeting', 'reviews-feed'),
				'description' => __('Control exactly where your notification popups appear with specific page targeting and exclusions.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-targeting-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-targeting-modal&utm_content=Upgrade'
				],
				'includeContent' => true
			],
			'reviewAlertBranding' => [
				'heading' => __('Remove Smash Balloon Branding', 'reviews-feed'),
				'description' => __('Present a clean, professional look by removing the \'Powered by\' badge from your notification popups.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'lite' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-branding-modal&utm_content=LiteUsers50OFF',
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-branding-modal&utm_content=Upgrade',
					'learnMore' => 'https://smashballoon.com/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=popup-branding-modal&utm_content=LearnMore'
				],
				'includeContent' => true
			]
		];
	}

	/**
	 * Get Pro Plus upsell content for Pro users
	 *
	 * Returns upsell modals for features that require Pro Plus tier.
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	public static function get_pro_plus_upsell_content()
	{
		return [
			'reviewAlertMultiple' => [
				'heading' => __('Upgrade to Pro Plus for multiple popups', 'reviews-feed'),
				'description' => __('Create unlimited notification popups and display different popups on different pages.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-pro&utm_source=review-alert&utm_medium=popup-multiple-modal&utm_content=UpgradeToProPlus'
				],
				'includeContent' => false
			],
			'reviewAlertTargeting' => [
				'heading' => __('Upgrade to Pro Plus for page targeting', 'reviews-feed'),
				'description' => __('Control exactly where your notification popups appear with specific page targeting and exclusions.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-pro&utm_source=review-alert&utm_medium=popup-targeting-modal&utm_content=UpgradeToProPlus'
				],
				'includeContent' => false
			],
			'reviewAlertBranding' => [
				'heading' => __('Remove Smash Balloon Branding', 'reviews-feed'),
				'description' => __('Present a clean, professional look by removing the \'Powered by\' badge from your notification popups.', 'reviews-feed'),
				'image' => 'upsell-review-alert.png',
				'buttons' => [
					'upgrade' => 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-pro&utm_source=review-alert&utm_medium=popup-branding-modal&utm_content=UpgradeToProPlus',
					'learnMore' => 'https://smashballoon.com/reviews-feed/?utm_campaign=reviews-pro&utm_source=review-alert&utm_medium=popup-branding-modal&utm_content=LearnMore'
				],
				'includeContent' => true
			]
		];
	}

	/**
	 * Get List of Upsell Sidebar Cards
	 *
	 * @since 1.1
	 *
	 * @return array
	 */
	public static function sidebar_upsell_cards()
	{
		if (Util::sbr_is_pro()) {
			return [];
		}
		return [
			[
				'heading' => __('Display images', 'reviews-feed'),
				'description' => __('With the Pro version enable images like avatars and review  photos', 'reviews-feed'),
				'image'     => 'upswidget-images.png',
				'modal' => 'authorImageModal'
			],
			[
				'heading' => __('Get carousel layout', 'reviews-feed'),
				'description' => __('Show your reviews in a neat carousel with Review Feed Pro', 'reviews-feed'),
				'image'     => 'upswidget-carousel.png',
				'modal' => 'carouselModal'
			],
			[
				'heading' => __('Change your feed style with one-click templates!', 'reviews-feed'),
				'description' => __('Over 12 templates to choose from', 'reviews-feed'),
				'image'     => 'upswidget-templates.png',
				'modal' => 'templateModal'
			],
			[
				'heading' => __('Only show selected reviews with Moderation mode', 'reviews-feed'),
				'description' => __('Hide or show only certain reviews', 'reviews-feed'),
				'image'     => 'upswidget-moderation.png',
				'modal' => 'moderationModal'
			]
		];
	}


	/**
	 * Coerce a review's `provider`, `reviewer` and `source` into the array shapes
	 * every reader expects ($review['provider']['name'], $review['reviewer']['name'],
	 * $review['source']['id'], …). The relay can emit these as scalars (e.g.
	 * `provider` => 'google', or an error-shaped payload yielding a scalar
	 * `reviewer`/`source`), and PHP 7-era caches stored them that way in
	 * `json_data`. On PHP 8.0+ a direct nested read on a scalar fatals with
	 * "Cannot access offset of type string on string".
	 *
	 * This is the single source of truth used by SinglePostCache (both
	 * constructors) and every raw/DB-decoded read site: the dedup key build in
	 * PostAggregator::remove_duplicated_posts_list (front-end render, Feed.php:172)
	 * reads source['id'] + reviewer['name'] + provider['name'] off the raw post,
	 * parse_single_review reads reviewer/provider, and
	 * SBR_Feed_Saver_Manager::duplicate_collection reads reviewer/source.
	 *
	 * SMASH-1578 guarded whole-review + source shapes at the cache loops, SMASH-1587
	 * added provider; this also covers a scalar reviewer/source that the
	 * is_array($single_review)-only cache guard lets through to store()/dedup
	 * (reachable on WPSA-63160's associative-keyed payload). Healthy array data is
	 * left intact — missing keys are filled with empty strings (via array union),
	 * so the dedup key degrades to an empty segment instead of crashing.
	 *
	 * @param  mixed  $review
	 * @return mixed  Untouched when not an array (callers' is_array guards handle that).
	 */
	public static function normalize_review_shape($review)
	{
		if (!is_array($review)) {
			return $review;
		}

		if (isset($review['provider']) && is_string($review['provider'])) {
			$review['provider'] = ['name' => $review['provider']];
		} elseif (!isset($review['provider']) || !is_array($review['provider'])) {
			$review['provider'] = ['name' => ''];
		}
		if (!isset($review['reviewer']) || !is_array($review['reviewer'])) {
			$review['reviewer'] = [];
		}
		if (!isset($review['source']) || !is_array($review['source'])) {
			$review['source'] = [];
		}
		// Image containers iterated by resize_images() + add_local_image_urls().
		// A scalar here would fatal the foreach on PHP 8. Coerce a present
		// non-array to [] (leave absent untouched — they're optional). Element
		// shape is guarded at the loop sites (a flat URL-string element).
		foreach (['media', 'reviews_photos'] as $image_key) {
			if (isset($review[$image_key]) && !is_array($review[$image_key])) {
				$review[$image_key] = [];
			}
		}

		// Guarantee the exact keys every reader assumes are present and string,
		// so a scalar/partial provider, reviewer or source degrades to empty
		// segments instead of "Cannot access offset…" fatals or "Undefined array
		// key" notices in the dedup key build / parse_single_review / preview
		// backfill (SMASH-1587 + PR #484 Copilot review + WPSA-63160 follow-up).
		$review['provider'] += ['name' => ''];
		$review['reviewer'] += ['name' => '', 'avatar' => ''];
		$review['source']   += ['id' => '', 'url' => ''];
		if (!is_string($review['provider']['name'])) {
			$review['provider']['name'] = '';
		}
		if (!is_string($review['reviewer']['name'])) {
			$review['reviewer']['name'] = '';
		}
		if (!is_string($review['source']['id'])) {
			// Preserve a numeric id (cast), but never (string)-cast an array/object —
			// that would emit an "Array to string conversion" notice (PR #482 Copilot).
			$review['source']['id'] = is_scalar($review['source']['id'])
				? (string) $review['source']['id']
				: '';
		}

		return $review;
	}

	 /**
	 * Transform Single Review for storing purposes
	 *
	 * @since 1.4
	 *
	 * @return array
	 */
	public static function parse_single_review($review, $provider_id, $review_id)
	{
		// Provider can arrive as a scalar slug (SMASH-1587); normalize before the
		// $review['provider']['name'] read below so it can't fatal on PHP 8.
		$review = self::normalize_review_shape($review);
		$name = $review['reviewer']['name'];
		$name_array = explode(' ', $name);
		$first_name = isset($review['reviewer']['first_name']) ? $review['reviewer']['first_name'] : $name_array[0];
		$last_name = isset($review['reviewer']['last_name']) ? $review['reviewer']['last_name'] : (isset($name_array[1]) ? $name_array[1] : '');

		$sanitized_review = [
			'time' 			=> $review['time'],
			'rating' 		=> $review['rating'],
			'provider_id' 	=> $provider_id,
			'review_id'		=> $review_id,
			'text' 			=> $review['text'],
			'title' 		=> isset($review['title']) ? $review['title'] : substr($review['text'], 0, 40),
			'reviewer'		=> [
				'name' 			=> $name,
				'first_name' 	=> $first_name,
				'last_name' 	=> $last_name,
				'avatar' 		=> $review['reviewer']['avatar']
			],
			'provider'		=> [
				'name' 	=> $review['provider']['name']
			],
			'source'		=> [
				'id' 	=> $provider_id,
				'url' 	=> ''
			]
		];
		return $sanitized_review;
	}


	public static function is_facebook_collection_post($post)
	{
		return ( isset($post['provider']) && isset($post['provider']['name']) && $post['provider']['name'] === 'facebook' &&  isset($post['provider_id']) && strpos($post['provider_id'], 'collection') !== false ) === true;
	}

	/**
	 * Convert Object to Array
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function object_to_array($data)
	{
		if (is_object($data)) {
			$data = json_decode(json_encode($data), true);
		}
		return $data;
	}

	/**
	 * Convert Object to Array
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function get_free_retriever_data()
	{
		if (Util::sbr_is_pro()) {
			$retriever = new \SmashBalloon\Reviews\Pro\Utils\FreeRetriever();
		} else {
			$retriever = new \SmashBalloon\Reviews\Common\Utils\FreeRetriever();
		}
		return $retriever->get_settings();
	}

	/**
	 * Get Feeds Settings
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_settings_page_errors()
	{
		$output = '## ERROR LOGS: ## </br></br>';
		$errors = SBR_Error_Handler::get_errors();
		$errors = array_reverse($errors);
		foreach ($errors as $error) {
			$output .= json_encode($error);
			$output .= '</br></br>';
		}
		$output .= '</br>';

		return $output;
	}

	/**
	 * Get Local Images/Avatar
	 * Upload folder name
	 *
	 * @since 2.0
	 *
	 * @return string
	 */
	public static function get_upload_folder_name()
	{
		$upload = wp_upload_dir();
		$folder = trailingslashit($upload['basedir']) . trailingslashit('sbr-feed-images');
		return $folder;
	}

	public static function should_store_local_images()
	{
		$settings = get_option('sbr_settings', sbr_plugin_settings_defaults());
		if (! is_array($settings)) {
			$settings = sbr_plugin_settings_defaults();
		}
		return !empty($settings['optimize_images']) ? $settings['optimize_images'] : true;
	}

}
