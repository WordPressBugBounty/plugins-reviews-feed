<?php

/**
 * Summary of namespace SmashBalloon\Reviews\Common\Helpers
 */

namespace SmashBalloon\Reviews\Common\Helpers;

/**
 * Summary of SBR_Error_Handler
 */
class SBR_Error_Handler
{
	/**
	 * Errors Options
	 *
	 * @var string
	 */
	private static $errors_opt = 'sbr_errors';

	/**
	 * Get All Errors
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function get_errors()
	{
		$errors = get_option(self::$errors_opt, []);

		// get_option() returns the stored value whenever the option row exists,
		// even when that value is falsy (false / '' from a corrupted, legacy or
		// third-party write) — the [] default only applies when the row is
		// absent. Coerce any non-array to [] so the foreach() in check_error(),
		// the array_push() in log_error() and the array_merge() in
		// update_errors() never receive a bool and fatal on PHP 8. SMASH-1544.
		return is_array($errors) ? $errors : [];
	}

	/**
	 * Clear All Logs
	 *
	 * @return void
	 *
	 * @since 1.0
	 */
	public static function clear_all_errors()
	{
		update_option(self::$errors_opt, []);
	}

	/**
	 * Update Reviews Feed Errors
	 *
	 * @param array $error Error to update
	 *
	 * @return boolean
	 *
	 * @since 1.0
	 */
	public static function update_errors($errors, $type = 'merge')
	{
		$current_errors = self::get_errors();
		$updated_errors = $type === 'merge'
			? array_merge($current_errors, $errors)
			: $errors;

		$updated_errors = self::truncate_errors($updated_errors);

		return update_option(self::$errors_opt, $updated_errors);
	}

	/**
	 * Check if Error Exists
	 *
	 * @param array $error
	 *
	 * @return int|boolean
	 *
	 * @since 1.0
	 */
	public static function check_error($error)
	{
		$current_errors = self::get_errors();
		$exists = 'not_defined';
		foreach ($current_errors as $key => $error_elm) {
			// Skip malformed error entries
			if (! isset($error_elm['type'], $error_elm['id'], $error['type'], $error['id'])) {
				continue;
			}

			if (
				empty($error_elm['type']) ||
				empty($error_elm['id']) ||
				empty($error['type']) ||
				empty($error['id']) ||
				$error_elm['type'] === $error['type'] &&
				$error_elm['id'] === $error['id'] &&
				(
					(
						! empty($error_elm['provider']) &&
						$error_elm['provider'] === $error['provider']
					) || empty($error_elm['provider'])
				)
			) {
				$exists = $key;
				break;
			}
		}
		return $exists;
	}

	/**
	 * Log API Error
	 *
	 * @param array $erros Error to add
	 *
	 * @return void
	 *
	 * @since 1.0
	 */
	public static function log_error($error)
	{
		$current_errors = self::get_errors();
		$error_index = self::check_error($error);

		if ($error_index !== 'not_defined') {
			$current_errors[$error_index] = $error;
		} else {
			array_push(
				$current_errors,
				$error
			);
		}

		self::update_errors($current_errors, 'no_merge');
	}

	/**
	 * Log Only the Latest 20 error
	 *
	 * @param array $erros Error
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function truncate_errors($errors)
	{
		return array_slice($errors, -20);
	}

	/**
	 * Clear All Logs Ajax
	 *
	 * @return void
	 *
	 * @since 1.0
	 */
	public static function clear_all_error_ajax()
	{
		check_ajax_referer('sbr-admin', 'nonce');
		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error();
		}
		self::clear_all_errors();
		wp_send_json_success();
	}
}
