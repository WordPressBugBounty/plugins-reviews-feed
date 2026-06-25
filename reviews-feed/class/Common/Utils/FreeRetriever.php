<?php

namespace SmashBalloon\Reviews\Common\Utils;

use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * Summary of FreeRetriever
 */
class FreeRetriever	extends ServiceProvider
{
	/**
	 * Free Sources/Reviews Retriever option name
	 * @var string
	 */
	public static $opt_name = 'sbr_free_retriever';


	/**
	 * Free Retriever Providers
	 * @var array
	 */
	public static $providers = ['google', 'yelp'];

	/**
	 * Transient flag that lets keyless Google/Yelp sources refetch within a
	 * short window even though they already fetched this week/ever. Set by
	 * SBR_Feed_Saver_Manager on an explicit "Clear All Caches" (which also
	 * resets the relay weekly window), so the immediate refresh can refetch
	 * instead of waiting up to 7 days. TTL-bounded (it is NOT consumed per
	 * call) — the relay's weekly window is the real cap and re-closes per
	 * source after the first fetch, which bounds the cost.
	 *
	 * Named with the `sbreviews_` prefix on purpose: clear_plugin_cache() purges
	 * `_transient_sbr_%`, and a `sbr_`-prefixed name would be swept immediately.
	 *
	 * @var string
	 */
	public const FORCE_REFETCH_FLAG = 'sbreviews_force_keyless_refetch';

	/**
	 * Summary of api_keys
	 * @var array
	 */
	public $api_keys;

	/**
	 * Stored Google/Yelp Sources
	 * @var array
	 */
	public $sources;

	/**
	 * Settings
	 * @var array
	 */
	public $settings;

	/**
	 * Set Initial Values
	 */
	public function __construct()
	{
		$this->api_keys = get_option('sbr_apikeys', []);
		$this->sources 	= SBR_Sources::sources_by_providers(self::$providers);
		$this->settings = $this->get_settings();
	}

	/**
	 * Whether an explicit Clear All Caches has opened the keyless-refetch
	 * window. Honoured by limit_review_api_call() (Common + Pro) to bypass the
	 * already-fetched belt while the flag is set — the relay's weekly window is
	 * the real cap and is reset alongside the flag. Non-destructive: cached
	 * reviews stay put, so a failed refetch leaves the existing feed intact.
	 *
	 * @return bool
	 */
	protected static function should_force_refetch(): bool
	{
		return (bool) get_transient(self::FORCE_REFETCH_FLAG);
	}

	/**
	 * Build settings for the App
	 *
	 * @return array
	 */
	public function get_settings()
	{
		$settings = [
			'providers'			=> self::$providers,
			'providerInfo' 		=> $this->check_possible_free_retrieving(),
			'emailVerification'	=> EmailVerification::get_email_verification_settings(),
			'isEmailVerified'    => EmailVerification::check_verified()
		];

		return $settings;
	}


	/**
	 * Check for Free API Retrieving
	 *
	 * @return array|boolean
	 */
	public function check_possible_free_retrieving()
	{
		$result = [];
		foreach (self::$providers as $provider) {
			//If API Key is empty
			if (empty($this->api_keys[$provider])) {
				$result[$provider] = [
					'sourcesNumber' => $this->check_provider_souces_number($provider)
				];
			}
		}

		return $result;
	}


	/**
	 * Check for Free API Retrieving
	 *
	 * @return integer
	 */
	public function check_provider_souces_number($provider)
	{
		return empty($this->sources)
			? 0
			: count($this->filter_source_provider($provider));
	}

	/**
	 * Check for Free API Retrieving
	 *
	 * @return array
	 */
	public function filter_source_provider($provider)
	{
		return array_filter(
			$this->sources,
			function ($db_provider) use ($provider) {
				return $db_provider['provider'] === $provider;
			}
		);
	}


	/**
	 * Should Make API Call
	 * Return true in case we can make API Call
	 * Logic 1 : Current Provider has API Key
	 * Logic 2 : NO API Key + First time retrieving Reviews
	 *
	 * @return boolean
	 */
	public function check_api_call($provider, $provider_id)
	{
		//Return True if its Not Google/Yelp
		if (!in_array($provider, self::$providers)) {
			return true;
		}

		//Return true Provider has API Key
		if (!empty($this->api_keys[$provider])) {
			return true;
		}
		$email_verified = EmailVerification::check_verified();
		//Email is Not Verified
		if (!$email_verified) {
			return false;
		}


		//Check only one update
		$should_limit = $this->limit_review_api_call($provider, $provider_id);
		return $should_limit === false;
	}

	/**
	 * Should Make API Call
	 *
	 * @return boolean
	*/
	public function limit_review_api_call($provider, $provider_id)
	{
		$other_provider = $provider === 'google'
			? 'yelp'
			: 'google';

		$other_api_key = empty($this->settings['providerInfo'][$other_provider]);

		//Other Provider Sources Count
		$other_sources = !empty($this->settings['providerInfo'][$other_provider]['sourcesNumber'])
			? $this->settings['providerInfo'][$other_provider]['sourcesNumber']
			: 0;

		//Means No API Key + Already Added Sources & Reviews in other Provider
		$limit_other = 	!$other_api_key && $other_sources > 0;
		if ($limit_other) {
			return true;
		}

		//Current Provider Sources Count
		$current_sources = !empty($this->settings['providerInfo'][$provider]['sourcesNumber'])
			? $this->settings['providerInfo'][$provider]['sourcesNumber']
			: 0;

		//Reviews Already Fetched for this Source — but an explicit Clear All
		//Caches opens a short refetch window (relay weekly window reset alongside).
		$limit_current = SBR_Sources::already_fetched($provider, $provider_id);
		if ($limit_current && ! self::should_force_refetch()) {
			return true;
		}

		return false;
	}
}
