<?php

/**
 * Minimal WP_Error for the plugin unit suite.
 *
 * Enough of it for a permission_callback to be asserted on: the code, the
 * message, and the `status` carried in $data. Its own file because phpcs
 * enforces one class per file (PSR-1), same as tests/Unit/Doubles.
 */

if (! class_exists('WP_Error')) {
	class WP_Error
	{
		/** @var string */
		public $code;

		/** @var string */
		public $message;

		/** @var mixed */
		public $data;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct($code = '', $message = '', $data = '')
		{
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}

		/** @return string */
		public function get_error_code()
		{
			return $this->code;
		}

		/** @return string */
		public function get_error_message()
		{
			return $this->message;
		}

		/** @return mixed */
		public function get_error_data()
		{
			return $this->data;
		}
	}
}
