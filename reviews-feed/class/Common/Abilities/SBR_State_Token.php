<?php

namespace SmashBalloon\Reviews\Common\Abilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Short-lived, single-use, user-bound state tokens for ability flows that
 * finish in the browser (feed-deletion confirmation).
 *
 * WordPress nonces are bound to the session token, so a nonce minted during an
 * Application-Password REST request never verifies in the admin's browser
 * session. These tokens bridge that gap: minted by an ability, consumed once
 * by the SAME user in a browser context, expired after 15 minutes.
 *
 * Mirrors InstagramFeed\Abilities\SBI_State_Token (AgDR-0503).
 *
 * @since SBR-499
 */
class SBR_State_Token
{
	const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Transient name prefix. DELIBERATELY does not start with `sbr_`: the plugin's
	 * Clear Cache path (SBR_Feed_Saver_Manager) deletes every option matching
	 * `%\_transient\_sbr\_%` (and the timeout twin), so an `sbr_`-style name made
	 * a cache clear silently kill every pending deletion link. Nothing a cache wipe
	 * matches may ever be added here; the Smash1994 abilities test pins that.
	 * Uninstall removes these rows explicitly (see uninstall.php).
	 *
	 * Tokens minted under the previous `sbr_abilities_state_` prefix are simply
	 * never found again and expire with their 15-minute TTL; no migration needed.
	 */
	const PREFIX = 'sb_abilities_sbr_state_';

	/**
	 * @param string $action Namespaced action, e.g. `delete_feed_2`.
	 * @return string|\WP_Error The token, or an error if no CSPRNG is available.
	 */
	public static function create($action)
	{
		try {
			// Lowercase hex: invariant under sanitize_key(), so the storage key and
			// the lookup key can never diverge (case-sensitive object caches!).
			$token = bin2hex(random_bytes(16));
		} catch (\Exception $e) {
			// random_bytes() throws \Exception when the platform has no CSPRNG
			// source — rare, but a fatal here would take down the whole ability
			// instead of surfacing a normal WP_Error to the caller.
			return new \WP_Error(
				'sbr_token_generation_failed',
				__('Could not generate a secure token on this server.', 'reviews-feed'),
				array('status' => 500)
			);
		}
		set_transient(
			self::key($token),
			array(
				'action' => (string) $action,
				'user'   => get_current_user_id(),
				// Same clock as SBR_Write_Gate::disabled_since(); see peek().
				'created' => round(microtime(true), 6),
			),
			self::TTL
		);
		return $token;
	}

	/**
	 * Transient key for a token. Stored under the token's SHA-256 so a
	 * database read of wp_options cannot replay a live token.
	 *
	 * @param string $token
	 * @return string
	 */
	private static function key($token)
	{
		return self::PREFIX . hash('sha256', sanitize_key($token));
	}

	/**
	 * Slot transient name holding the current user's pending token for a group.
	 *
	 * @param string $group
	 * @return string
	 */
	private static function slot_key($group)
	{
		return self::PREFIX . 'slot_' . sanitize_key($group) . '_' . get_current_user_id();
	}

	/**
	 * Create a token that is EXCLUSIVE within a group: minting a new one
	 * invalidates the group's previous pending token. Used for feed deletion
	 * so a user can only ever have ONE confirmable deletion at a time.
	 *
	 * @param string $action Namespaced action, e.g. `delete_feed_2`.
	 * @param string $group  Slot name, e.g. `delete`.
	 * @return string|\WP_Error The token, or an error if no CSPRNG is available
	 *                          or a concurrent mint for the same user won the slot.
	 */
	public static function create_exclusive($action, $group)
	{
		// Mint FIRST, before touching the slot: if create() fails (no CSPRNG),
		// propagate the WP_Error untouched — the previous pending token (if any)
		// must stay valid rather than being invalidated for a mint that never
		// happened.
		$token = self::create($action);
		if (is_wp_error($token)) {
			return $token;
		}

		$slot = self::slot_key($group);
		$prev = get_transient($slot);
		if (is_string($prev) && $prev !== '') {
			// $prev is the previous token's STORAGE key (never the raw token).
			delete_transient($prev);
		}
		set_transient($slot, self::key($token), self::TTL);

		// Read the slot back: two concurrent mints for the same user both write it
		// and the last write wins. If the slot does not hold OUR token we lost that
		// race, so kill our own token (keeping the "one pending deletion per user"
		// invariant) and tell the caller to retry; the winner's token stays valid.
		if (get_transient($slot) !== self::key($token)) {
			delete_transient(self::key($token));
			return new \WP_Error(
				'sbr_token_conflict',
				__('Another deletion request was created at the same time. Please try again.', 'reviews-feed'),
				array('status' => 409)
			);
		}
		return $token;
	}

	/**
	 * Validate without consuming (e.g. to render a confirmation screen).
	 *
	 * @param string $token
	 * @param string $action
	 * @return bool
	 */
	public static function peek($token, $action)
	{
		$data = get_transient(self::key($token));
		return is_array($data)
			&& isset($data['action'], $data['user'], $data['created'])
			// Minted at or before the last MCP Write Access disable: revoked for good.
			// Strict `>` so a same-instant token is refused (fails closed). Tokens
			// without `created` never validate.
			&& (float) $data['created'] > SBR_Write_Gate::disabled_since()
			&& hash_equals((string) $data['action'], (string) $action)
			&& (int) $data['user'] === get_current_user_id();
	}

	/**
	 * Validate AND invalidate (single use).
	 *
	 * @param string $token
	 * @param string $action
	 * @return bool
	 */
	public static function consume($token, $action)
	{
		if (!self::peek($token, $action)) {
			return false;
		}
		// The delete is the deciding step. peek() and the delete are not atomic, so
		// two concurrent requests can both pass peek(); only the one whose
		// delete_transient() actually removed the row (true) may proceed. The loser
		// sees false (row already gone) and is refused, so a token runs exactly once.
		return (bool) delete_transient(self::key($token));
	}

	/**
	 * Invalidate the CURRENT user's pending token for an exclusive group, plus
	 * the slot itself. Used when the write gate refuses a flow: the link must be
	 * dead for good, not merely suspended until the gate is re-enabled. Scoped to
	 * the current user by construction (the slot name embeds the user id), so it
	 * can never burn another user's token.
	 *
	 * @param string $group Slot name, e.g. `delete`.
	 * @return void
	 */
	public static function revoke_group($group)
	{
		$slot = self::slot_key($group);
		$prev = get_transient($slot);
		if (is_string($prev) && $prev !== '') {
			delete_transient($prev);
		}
		delete_transient($slot);
	}
}
