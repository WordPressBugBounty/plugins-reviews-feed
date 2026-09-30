<?php

/**
 * Reviews Feed Saver
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\Settings;

use Smashballoon\Customizer\V2\Settings_Builder;
use SmashBalloon\Reviews\Common\AuthorizationStatusCheck;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Services\SBR_Upgrader;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Common\Utils\EmailVerification;

class SBR_Settings_Builder extends Settings_Builder {
	/**
	 *  Settings Menu Info
	 * @since 1.0
	 */
	protected $menu;

	/**
	 *  Settings Tabs Path
	 * @since 1.0
	 */
	protected $settingspage_tabs_path;

	/**
	 *  Settings Tabs Name Space
	 * @since 1.0
	 */
	protected $settingspage_tabs_namespace;
	protected $tabs_order;
	protected $add_to_menu;

	protected $db;
	protected $plugin_status;


	public function __construct()
	{
		$this->menu = [
			'parent_menu_slug'  => "sbr",
			'page_title'        => "Settings",
			'menu_title'        => "Settings",
			'menu_slug'        => "sbr-settings",
		];
		$this->settingspage_tabs_path       = SBR_SETTINGSPAGE_TABS_PATH;
		$this->settingspage_tabs_namespace = SBR_SETTINGSPAGE_TABS_NAMESPACE;
		$this->tabs_order                   = [ 'sb-general-tab', 'sb-feeds-tab', 'sb-data-controls-tab', 'sb-advanced-tab', 'sb-translation-tab' ];

		$this->db = new DB();
		$this->add_to_menu = !Util::sbr_is_pro() ? true : check_license_valid();
		$this->plugin_status = new AuthorizationStatusCheck();
	}

	/**
	 * Build the settings-page tab data, then drop the Data Sharing tab when any
	 * Smash Balloon Pro plugin is active. Pro forces data-sharing consent +
	 * in-plugin notifications on and governs them centrally, so the tab is hidden.
	 *
	 * The parent builder auto-instantiates every tab class on disk and keys each
	 * by its tabs_order position, so we filter the assembled result here rather
	 * than via tabs_order (where a missing id would make array_search() return
	 * false and collapse onto index 0). The React app reads this list with
	 * Object.values(), which returns values in ascending key order and tolerates a
	 * key gap, so removing the entry preserves the remaining tab order.
	 *
	 * @return array
	 */
	public function settingspage_builder_data()
	{
		$data = parent::settingspage_builder_data();

		if ( class_exists( '\SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager' )
			&& \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::is_locked_by_pro() ) {
			foreach ( $data as $key => $tab ) {
				if ( is_array( $tab ) && isset( $tab['id'] ) && 'sb-data-controls-tab' === $tab['id'] ) {
					unset( $data[ $key ] );
				}
			}
		}

		return $data;
	}

	public function custom_settings_data()
	{
		$verification_result = EmailVerification::catch_email_verification();

		$settings_data = [
			'nonce' => wp_create_nonce('sbr-admin'),
			'apiKeys' => get_option('sbr_apikeys', []),
			'apiKeyLimits' => get_option('sbr_apikeys_limit', []),
			'pluginSettings' => sbr_recursive_parse_args(get_option('sbr_settings', []), sbr_plugin_settings_defaults()),
			'currentTab' => 'sb-general-tab',
			'sourcesList' => SBR_Sources::get_sources_list(),
			'sourcesCount' => SBR_Sources::get_sources_count(),
			'providers' => Util::get_providers(),
			'feedsList' => DB::get_feeds_list(),
			'feedsCount' => DB::feeds_list_count(),
			'connectFBUrls' => sbr_get_fb_connection_urls(true),
			'pluginNotices' => Util::get_plugin_notices(),
			'pluginStatus' => $this->plugin_status->get_statuses(),
			'isPro' => Util::sbr_is_pro(),
			'assetsURL' => SB_COMMON_ASSETS,
			'upsellContent' => Util::upsell_modal_content(),
			'adminNoticeContent' => apply_filters('sbr_admin_notices_filter', 1),
			'collectionsPageUrl' => admin_url('admin.php?page=sbr-collections'),
			'aboutPageUrl' => admin_url('admin.php?page=sbr-about'),
			'isDevUrl' => SBR_Upgrader::is_dev_url(home_url()),
			'builderUrl'           => admin_url('admin.php?page=sbr'),
			'collectionsList' => DB::get_collections_list(),
			'bulkHistorySources'    => get_option('sbr_bulk_sources', []),
			'freeRetrieverData'     => Util::get_free_retriever_data(),
			'emailVerificationURL'    => EmailVerification::build_email_verification_url(),
			// Try recovery before checking verified status (handles stuck loop case)
			'isEmailVerified'    => EmailVerification::check_and_recover_verification()
		];

		// Check if verification was attempted but failed
		if (!empty($_GET['sbr_email_token']) && !empty($_GET['verified_email'])) {
			if ($verification_result === true) {
				$settings_data['openSourceModal'] = true;
			} else {
				// Verification failed - pass error to frontend
				$settings_data['emailVerificationError'] = EmailVerification::get_verification_error_message();
			}
		}
		if (isset($_GET['manualsource']) && $_GET['manualsource'] == true) {
			$settings_data['manualSourcePopupInit'] = true;
		}
		$newly_retrieved_source_connection_data = Util::maybe_source_connection_data();
		if ($newly_retrieved_source_connection_data) {
			$settings_data['newSourceData'] = $newly_retrieved_source_connection_data;
		}
		return $settings_data;
	}

}
