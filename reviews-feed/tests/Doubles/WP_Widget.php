<?php

/**
 * Minimal `WP_Widget` base, so SBR_Badge_Widget's update()/form() contracts can be
 * asserted without loading WordPress.
 *
 * Global, not namespaced: the badge widget extends the class at the root. In its own
 * file because PSR1 wants one class per file, same reason as Doubles/WP_Query.php —
 * phpcs errors on a class declared inside tests/bootstrap.php.
 */

if (! class_exists('WP_Widget')) {
	class WP_Widget
	{
		public $id_base;
		public $name;

		public function __construct($id_base = '', $name = '', $widget_options = array(), $control_options = array())
		{
			$this->id_base = $id_base;
			$this->name    = $name;
		}

		public function get_field_id($field_name)
		{
			return 'widget-' . $this->id_base . '-1-' . $field_name;
		}

		public function get_field_name($field_name)
		{
			return 'widget-' . $this->id_base . '[1][' . $field_name . ']';
		}
	}
}
