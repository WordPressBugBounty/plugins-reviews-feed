<?php

/**
 * Class RemoteRequest
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common;

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;

/**
 * Summary of RemoteRequest
 */
class RemoteRequest
{
	public const BASE_URL = SBR_RELAY_BASE_URL;

	private $provider;

	private $args;

	private $endpoint;

	/**
	 * Summary of __construct
	 * @param mixed $provider
	 * @param mixed $args
	 * @param mixed $endpoint
	 */
	public function __construct($provider, $args, $endpoint = 'reviews')
	{
		$this->provider = $provider;
		$this->args     = $args;
		$this->endpoint = $endpoint;
	}

	/**
	 * Summary of fetch
	 * @return array|string
	 */
	public function fetch()
	{
		if (empty($this->args['business'])) {
			return '';
		}

		$business = $this->args['business'];

		// Build request args - always include place_id for fallback
		// If source_id is available, include it too (preferred - encoding-immune)
		// Relay middleware will use source_id first, fall back to place_id if needed
		$args = [
			'place_id' => $business,
		];

		if (!empty($this->args['info']['relay_source_id'])) {
			$args['source_id'] = (int) $this->args['info']['relay_source_id'];
		}

		// Add additional parameters
		$args = array_merge($args, $this->buildBaseArgs());

		$settings = new SettingsManagerService();
		$relay = new SBRelay($settings);

		return $relay->call($this->endpoint . '/' . $this->provider, $args, 'GET', true);
	}

	/**
	 * Build base arguments that are common to all requests
	 *
	 * @return array
	 */
	private function buildBaseArgs()
	{
		$args = [];

		if ($this->provider === 'wordpress.org') {
			$wordpressorg_args = SBR_Feed_Saver_Manager::get_place_id_wordpressorg($this->args['info']['url']);
			$args['type'] = $wordpressorg_args['type'];
			$args['slug'] = $wordpressorg_args['slug'];
		}

		if ($this->provider !== 'facebook') {
			$api_keys = get_option('sbr_apikeys', []);
			if (!empty($api_keys[$this->provider])) {
				$args['api_key'] = $api_keys[$this->provider];
			}
		} else {
			$args['api_key'] = !empty($this->args['access_token']) ? $this->args['access_token'] : '';
		}

		if (!empty($this->args['language']) && $this->args['language'] !== 'default') {
			$args['language'] = $this->args['language'];
		}

		if (!empty($this->args['starsFilter']) && $this->args['starsFilter'] !== '') {
			$args['starsFilter'] = $this->args['starsFilter'];
		}

		return $args;
	}

}
