<?php

namespace SmashBalloon\Reviews\Common\Abilities;

use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Ability;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Create_Feed;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Describe_Settings_Schema;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Get_Feed;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Get_Global_Settings;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Get_Pro_Features;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_List_Feeds;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_List_Sources;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Request_Feed_Deletion;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Set_Feed_Sources;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Set_Header_Button_Link;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Update_Feed_Settings;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Update_Global_Settings;
use Smashballoon\Stubs\Services\ServiceProvider;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WordPress Abilities API integration entry point.
 *
 * Exposes Reviews Feed *settings* to AI agents (MCP clients, WPVibe) through
 * the core Abilities API (WP 6.9+). Silent no-op on older WordPress. Follows
 * the cross-AM pattern established by WPForms and ported to Instagram Feed
 * Pro / Facebook Feed Pro / Twitter Feed Pro (AgDR-0503): category on
 * `wp_abilities_api_categories_init`, abilities on `wp_abilities_api_init`,
 * capability-checked permission callbacks, writes off by default.
 *
 * Registered as a Common ServiceContainer service (class/Common/ServiceContainer.php),
 * so it runs in both Free and Pro (SBR-577, AgDR-9005). Abilities that only
 * exist in Pro are appended by the Pro build through the `sbr_abilities`
 * filter in abilities() below, so their classes stay out of the free zip.
 *
 * No `get-connect-url` ability: Reviews Feed has no OAuth handshake for an
 * agent-minted link to complete. Google/Yelp/TripAdvisor/TrustPilot/
 * WordPress.org sources are added by searching a business/place name through
 * the Smash Balloon relay (an API-key-and-search flow, not a redirect); a
 * Facebook source is added by pasting a page/group access token retrieved
 * from Meta directly. Neither is a browser-completable connect link — see
 * the hard rule in the port brief ("if the plugin's connect flow can't be
 * completed from a minted link… SKIP get-connect-url").
 *
 * @since SBR-499
 */
class SBR_Abilities extends ServiceProvider
{
	public function register(): void
	{
		// Always track MCP Write Access disables (gate epoch), even if abilities are unavailable.
		SBR_Write_Gate::register_hooks();
		if (!self::is_available()) {
			return;
		}
		add_action('wp_abilities_api_categories_init', array($this, 'register_category'));
		add_action('wp_abilities_api_init', array($this, 'register_abilities'));
		// Discovery hides write abilities while MCP Write Access is off; see
		// hide_writes_from_discovery() for the two-path (core 7.1 hook / rebuild) design.
		add_filter('rest_request_before_callbacks', array($this, 'begin_discovery'), 10, 3);
		add_filter('rest_request_after_callbacks', array($this, 'end_discovery'), 10, 1);
		add_filter('wp_get_abilities_item_include', array($this, 'filter_discovery_item'), 10, 2);
		add_filter('rest_post_dispatch', array($this, 'hide_writes_from_discovery'), 10, 3);

		// Browser-side half of the deletion-confirmation flow.
		if (is_admin()) {
			new SBR_Delete_Confirmation();
		}
	}

	/**
	 * @return bool
	 */
	public static function is_available()
	{
		return function_exists('wp_register_ability') && function_exists('wp_register_ability_category');
	}

	/**
	 * @return void
	 */
	public function register_category()
	{
		wp_register_ability_category(
			SBR_Ability::CATEGORY,
			array(
				'label'       => __('Reviews Feed', 'reviews-feed'),
				'description' => __('Read and update Reviews Feed settings.', 'reviews-feed'),
			)
		);
	}

	/**
	 * @return void
	 */
	public function register_abilities()
	{
		foreach ($this->abilities() as $ability) {
			$ability->register();
		}
	}

	/**
	 * Discovery route served by core's WP_REST_Abilities_V1_List_Controller.
	 */
	const DISCOVERY_ROUTE = '/wp-abilities/v1/abilities';

	/**
	 * True while the current request is a gated-off discovery list request.
	 *
	 * @var bool
	 */
	private $discovery_request = false;

	/**
	 * True once core's own pre-pagination filter (WP 7.1+) has run for the
	 * current discovery request, i.e. core already paginated the filtered set.
	 *
	 * @var bool
	 */
	private $core_filtered = false;

	/**
	 * Guards against re-entry while the fallback issues internal sub-requests.
	 *
	 * @var bool
	 */
	private $rebuilding = false;

	/**
	 * @param \WP_REST_Request $request
	 * @return bool Whether this is the abilities list route.
	 */
	private function is_discovery_route($request)
	{
		return $request instanceof \WP_REST_Request
			&& in_array($request->get_method(), array('GET', 'HEAD'), true)
			&& untrailingslashit($request->get_route()) === self::DISCOVERY_ROUTE;
	}

	/**
	 * `rest_request_before_callbacks`: remember that this is a discovery request
	 * while MCP Write Access is off, so the include filter below only ever acts
	 * inside this request's controller callback. The flag is cleared by
	 * end_discovery() on `rest_request_after_callbacks`; both fire inside
	 * WP_REST_Server::dispatch() on every path (HTTP, rest_do_request(), batch),
	 * unlike rest_post_dispatch, so the flag can never outlive its request or
	 * leak into unrelated wp_get_abilities() calls.
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed Untouched.
	 */
	public function begin_discovery($response, $handler, $request)
	{
		if ($this->rebuilding) {
			return $response;
		}
		$this->core_filtered     = false;
		$this->discovery_request = $this->is_discovery_route($request) && !SBR_Write_Gate::is_enabled();
		return $response;
	}

	/**
	 * `rest_request_after_callbacks`: always closes the discovery window.
	 * core_filtered deliberately survives (hide_writes_from_discovery() reads it).
	 *
	 * @param mixed $response
	 * @return mixed Untouched.
	 */
	public function end_discovery($response)
	{
		$this->discovery_request = false;
		return $response;
	}

	/**
	 * `wp_get_abilities_item_include` (WP 7.1+): drops our write abilities from
	 * the registry query BEFORE core paginates, so core itself computes correct
	 * X-WP-Total / X-WP-TotalPages / Link headers. Scoped to the discovery
	 * request flagged above; every other wp_get_abilities() caller is untouched.
	 *
	 * @param bool                 $include
	 * @param \WP_Ability|object   $ability
	 * @return bool
	 */
	public function filter_discovery_item($include, $ability)
	{
		if (!$this->discovery_request) {
			return $include;
		}
		$this->core_filtered = true;
		if ($include && is_object($ability) && method_exists($ability, 'get_name')) {
			$name = (string) $ability->get_name();
			if (strpos($name, SBR_Ability::NAMESPACE_PREFIX . '/') === 0 && $this->is_write_ability($name)) {
				return false;
			}
		}
		return $include;
	}

	/**
	 * Hides this plugin's write abilities from the core abilities list while MCP
	 * Write Access is off.
	 *
	 * Agents (WPVibe) discover tools from GET /wp-abilities/v1/abilities, and
	 * should not be shown a tool they cannot use. Only the list is filtered: the
	 * abilities stay registered and runnable, so a direct call still gets the
	 * 403 writes_disabled explaining how to turn writes on, which is the real
	 * gate (SBR_Write_Gate::check()).
	 *
	 * HOW, and why two paths. Core paginates inside the list controller and
	 * computes X-WP-Total / X-WP-TotalPages / Link from its own result, so
	 * filtering the finished response only fixes the current page: pages come
	 * back short and the headers lie (SBR-499 QA).
	 *   1. WP 7.1+ has `wp_get_abilities_item_include`, which runs inside the
	 *      registry query before pagination. filter_discovery_item() uses it and
	 *      core's output is already right; this method then does nothing.
	 *   2. Older cores (6.9, 7.0) have no such hook, so the response is rebuilt
	 *      here: gather the UNPAGINATED list through internal sub-requests (same
	 *      query params, per_page=100, so category / namespace / _fields /
	 *      context all apply exactly as the caller sent them), drop the writes,
	 *      re-slice the caller's requested page from the filtered set, and
	 *      recompute X-WP-Total, X-WP-TotalPages and the Link header from it.
	 * Behaviour is identical when the gate is on or the route does not match.
	 *
	 * @param \WP_HTTP_Response $response
	 * @param \WP_REST_Server   $server
	 * @param \WP_REST_Request  $request
	 * @return \WP_HTTP_Response
	 */
	public function hide_writes_from_discovery($response, $server, $request)
	{
		if (
			$this->rebuilding
			|| !$response instanceof \WP_HTTP_Response
			|| !$this->is_discovery_route($request)
			|| SBR_Write_Gate::is_enabled()
		) {
			return $response;
		}

		$core_filtered       = $this->core_filtered;
		$this->core_filtered = false;
		if ($core_filtered) {
			return $response; // Path 1: core already filtered before paginating.
		}

		$data = $response->get_data();
		if (!is_array($data)) {
			return $response;
		}
		$headers = $response->get_headers();
		$total   = isset($headers['X-WP-Total']) ? (int) $headers['X-WP-Total'] : -1;
		if ($total < 0) {
			return $this->strip_page($response, $data);
		}
		$page_param     = $request->get_param('page');
		$per_page_param = $request->get_param('per_page');
		$page           = max(1, is_numeric($page_param) ? (int) $page_param : 1);
		$per_page       = max(1, is_numeric($per_page_param) ? (int) $per_page_param : 10);
		if ($per_page > 100) {
			$per_page = 100;
		}

		// Everything core would list, unpaginated. The caller's own page already
		// holds all of it when it was the only page (the common unpaged case).
		$all = $data;
		if (!($page === 1 && count($data) === $total)) {
			$all = $this->collect_all_items($request, $total);
			if ($all === null) {
				// Sub-request failed: fail closed. Hide the writes on this page and
				// leave core's paging headers as they are.
				return $this->strip_page($response, $data);
			}
		}

		$kept = $this->without_writes($all);
		if (count($kept) === count($all) && $page === 1 && count($data) === $total) {
			return $response; // Nothing hidden and nothing to recompute.
		}

		$paged = self::paginate($kept, $page, $per_page);
		$response->set_data($paged['items']);

		// Rebuild paging headers (and Link) from the filtered total.
		unset($headers['Link']);
		$headers['X-WP-Total']      = (string) $paged['total'];
		$headers['X-WP-TotalPages'] = (string) $paged['total_pages'];
		$response->set_headers($headers);
		$base = add_query_arg(
			urlencode_deep($request->get_query_params()),
			rest_url(ltrim(self::DISCOVERY_ROUTE, '/'))
		);
		if ($response instanceof \WP_REST_Response) { // link_header() lives on WP_REST_Response only.
			foreach (self::link_pages($page, $paged['total_pages']) as $rel => $target) {
				$response->link_header($rel, add_query_arg('page', $target, $base));
			}
		}
		return $response;
	}

	/**
	 * Fail-closed fallback: drop this plugin's writes from the current page only.
	 *
	 * @param \WP_HTTP_Response $response
	 * @param array             $data Current page items.
	 * @return \WP_HTTP_Response
	 */
	private function strip_page($response, array $data)
	{
		$response->set_data($this->without_writes($data));
		return $response;
	}

	/**
	 * Pure paging math: slice page $page of $per_page from $items and report the
	 * totals core would report for exactly that set.
	 *
	 * @param array $items    The full (already filtered) list.
	 * @param int   $page     1-based page.
	 * @param int   $per_page Items per page, >= 1.
	 * @return array{items: array, total: int, total_pages: int}
	 */
	public static function paginate(array $items, $page, $per_page)
	{
		$per_page = max(1, (int) $per_page);
		$page     = max(1, (int) $page);
		$items    = array_values($items);
		$total    = count($items);
		return array(
			'items'       => array_slice($items, ($page - 1) * $per_page, $per_page),
			'total'       => $total,
			'total_pages' => (int) ceil($total / $per_page),
		);
	}

	/**
	 * Pure: which prev / next pages core's Link header advertises.
	 *
	 * @param int $page
	 * @param int $total_pages
	 * @return array<string,int> rel => page number.
	 */
	public static function link_pages($page, $total_pages)
	{
		$links = array();
		if ($page > 1) {
			$links['prev'] = $page - 1;
		}
		if ($page < $total_pages) {
			$links['next'] = $page + 1;
		}
		return $links;
	}

	/**
	 * Fetches every item of the discovery list (all pages) with the caller's own
	 * query params, via internal requests.
	 *
	 * @param \WP_REST_Request $request
	 * @param int              $total Core's unfiltered X-WP-Total.
	 * @return array|null Items, or null if any sub-request failed.
	 */
	private function collect_all_items($request, $total)
	{
		$items            = array();
		$this->rebuilding = true;
		try {
			$pages = max(1, (int) ceil($total / 100));
			for ($n = 1; $n <= $pages; $n++) {
				$sub = new \WP_REST_Request('GET', self::DISCOVERY_ROUTE);
				$sub->set_query_params(array_merge($request->get_query_params(), array('page' => $n, 'per_page' => 100)));
				$res = rest_do_request($sub);
				if ($res->is_error() || !is_array($res->get_data())) {
					return null;
				}
				$items = array_merge($items, $res->get_data());
			}
		} finally {
			$this->rebuilding = false;
		}
		return $items;
	}

	/**
	 * @param array $items Response items.
	 * @return array Items minus this plugin's write abilities.
	 */
	private function without_writes(array $items)
	{
		$prefix = SBR_Ability::NAMESPACE_PREFIX . '/';
		$kept   = array();
		foreach ($items as $item) {
			$name = is_array($item) && isset($item['name']) ? (string) $item['name'] : '';
			if (strpos($name, $prefix) === 0 && $this->is_write_ability($name)) {
				continue;
			}
			$kept[] = $item;
		}
		return $kept;
	}

	/**
	 * Reads the annotation from the registry rather than the response item, which
	 * a `_fields` query or another filter may have trimmed.
	 *
	 * @param string $name
	 * @return bool
	 */
	protected function is_write_ability($name)
	{
		if (!function_exists('wp_get_ability')) {
			return false;
		}
		$ability = wp_get_ability($name);
		if (!is_object($ability) || !method_exists($ability, 'get_meta')) {
			return false;
		}
		$meta = $ability->get_meta();
		return isset($meta['annotations']['readonly']) && $meta['annotations']['readonly'] === false;
	}

	/**
	 * @return SBR_Ability[]
	 */
	protected function abilities()
	{
		$abilities = array(
			new SBR_Get_Global_Settings(),
			new SBR_List_Feeds(),
			new SBR_Get_Feed(),
			new SBR_Describe_Settings_Schema(),
			new SBR_Update_Global_Settings(),
			new SBR_Update_Feed_Settings(),
			new SBR_List_Sources(),
			new SBR_Create_Feed(),
			new SBR_Set_Feed_Sources(),
			new SBR_Set_Header_Button_Link(),
			new SBR_Request_Feed_Deletion(),
		);

		// Free edition only (AgDR-9006): lets an agent explain what Pro adds.
		if (SBR_Pro_Upsell::is_free()) {
			$abilities[] = new SBR_Get_Pro_Features();
		}

		/**
		 * Filters the abilities registered by Reviews Feed.
		 *
		 * @param SBR_Ability[] $abilities
		 */
		return apply_filters('sbr_abilities', $abilities);
	}
}
