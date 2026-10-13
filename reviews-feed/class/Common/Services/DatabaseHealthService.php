<?php

namespace SmashBalloon\Reviews\Common\Services;

use SmashBalloon\Reviews\Common\Container;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\AddUniqueReviewIndexRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\LanguageCacheUpgradeRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\V1Routine;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Pro\Services\Upgrade\Routines\FormSubmissionsRoutine;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * SMASH-1606: the sbr_* tables are created once, by the version-gated V1Routine, and never checked again.
 * When a host tool drops them, feeds and sources vanish and Add Source "succeeds" without saving anything.
 * On admin load this rebuilds any missing table through the same create routines (each is
 * SHOW TABLES-guarded, so a healthy site creates nothing). A healthy result is cached for an hour.
 */
class DatabaseHealthService extends ServiceProvider
{
	public const HEALTH_TRANSIENT = 'sbr_db_health_ok';

	/** @return void */
	public function register()
	{
		add_action('admin_init', [ $this, 'maybe_repair_tables' ]);
	}

	/** @return void */
	public function maybe_repair_tables()
	{
		// admin_init also fires for anonymous/subscriber admin-ajax.php and profile.php requests.
		if (wp_doing_ajax() || ! current_user_can('manage_options')) {
			return;
		}
		if (get_transient(self::HEALTH_TRANSIENT)) {
			return;
		}

		$missing = $this->missing_tables();
		if (empty($missing)) {
			set_transient(self::HEALTH_TRANSIENT, 1, HOUR_IN_SECONDS);
			return;
		}

		$this->recreate_tables($missing);

		// Cached as healthy only once the tables exist, so a failed CREATE (no privilege) is retried.
		if (empty($this->missing_tables())) {
			set_transient(self::HEALTH_TRANSIENT, 1, HOUR_IN_SECONDS);
		}
	}

	/**
	 * @param list<string> $missing full table names that were missing
	 * @return void
	 */
	protected function recreate_tables(array $missing)
	{
		global $wpdb;
		$container = Container::get_instance();
		$v1 = $container->get(V1Routine::class);
		if ($v1 instanceof V1Routine) {
			$v1->run();
		}

		// V1Routine creates reviews_posts with its 1.0 schema. The later schema routines are gated on
		// sbr_db_version, so a rebuilt table would lack idx_unique_review (dedup relies on it). Both are
		// idempotent and run in version order: lang (1.1) before the index that includes it (1.3).
		if (in_array($wpdb->prefix . SBR_POSTS_TABLE, $missing, true)) {
			foreach ([ LanguageCacheUpgradeRoutine::class, AddUniqueReviewIndexRoutine::class ] as $routine) {
				$instance = $container->get($routine);
				if ($instance instanceof LanguageCacheUpgradeRoutine || $instance instanceof AddUniqueReviewIndexRoutine) {
					$instance->run();
				}
			}
		}

		if (Util::sbr_is_pro() && class_exists(FormSubmissionsRoutine::class)) {
			$forms = $container->get(FormSubmissionsRoutine::class);
			if ($forms instanceof FormSubmissionsRoutine) {
				$forms->run();
			}
		}
	}

	/**
	 * Required tables that don't exist, by full name.
	 *
	 * @return list<string>
	 */
	public function missing_tables()
	{
		global $wpdb;

		$required = [ SBR_FEEDS_TABLE, SBR_FEED_CACHES_TABLE, SBR_SOURCES_TABLE, SBR_POSTS_TABLE, SBR_FEED_LOCATOR ];
		if (Util::sbr_is_pro() && defined('SBR_FORM_SUBMISSIONS')) {
			$required[] = SBR_FORM_SUBMISSIONS;
		}

		$missing = [];
		foreach ($required as $table) {
			$name = $wpdb->prefix . $table;
			if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) !== $name) {
				$missing[] = $name;
			}
		}

		return $missing;
	}
}
