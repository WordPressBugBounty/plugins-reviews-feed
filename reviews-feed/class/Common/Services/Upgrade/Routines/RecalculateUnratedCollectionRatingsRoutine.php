<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

/**
 * SMASH-2468: until 2.16.0 a review with no rating counted as 0 stars in its collection's average
 * (none, 4, 5 saved 3 instead of 4.5). Same one-shot recalculation as SMASH-1685, under its own flag,
 * because sites on 2.15.1 have already spent that one.
 * SMASH-2477 re-keyed the flag: the same pass now stores rated_count, so a site that ran it without that runs it again.
 */
class RecalculateUnratedCollectionRatingsRoutine extends RecalculateCollectionRatingsRoutine
{
	public const DONE_OPTION = 'sbr_collection_rating_2477_done';
}
