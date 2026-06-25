<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade;

use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\AddUniqueReviewIndexRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\BackfillWebsiteUrlRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\LanguageCacheUpgradeRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\RegisterWebsiteRoutine;
use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\Reviews\Common\Container;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\UpgradeRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\V1Routine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\ClearReviewsDuplicateRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\NewUserRatingRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\ReconcileMigratedLicenseRoutine;

class RoutineManagerService extends ServiceProvider{
	/**
	 * a list of upgrade routines to be executed,
	 * keep the correct order, newer is always at the end of the list.
	 * @var UpgradeRoutine[]
	 */
	private $routines = [
		V1Routine::class,
		RegisterWebsiteRoutine::class,
		LanguageCacheUpgradeRoutine::class,
		ClearReviewsDuplicateRoutine::class,
		NewUserRatingRoutine::class,
		AddUniqueReviewIndexRoutine::class,
		// SMASH-1281 — backfill website_url for installs that registered before
		// this URL was tracked, so proactive migration detection works for the
		// entire install base, not just future registrations.
		BackfillWebsiteUrlRoutine::class,
		// SMASH-1585 — one-shot recovery for sites silently wedged by a
		// pre-2.6.3 migration fork (local license_status 'valid' but the relay
		// has no active license for the new URL). Runs after the backfill so
		// website_url is settled first. One ping, flips to Activate on a
		// confirmed mismatch; never auto-reactivates.
		ReconcileMigratedLicenseRoutine::class,
	];

	public function register()
	{
		$container = Container::get_instance();

		foreach ($this->routines as $routine) {
			$container->get($routine)->register();
		}
	}
}
