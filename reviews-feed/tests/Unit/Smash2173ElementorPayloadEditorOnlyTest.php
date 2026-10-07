<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Integrations\Elementor\SBR_Elementor_Base;

/**
 * SMASH-2173 — sbrElementorData (full feed rows, admin URL, a live nonce) was localized on
 * every Elementor front-end page. Its only reader, sb-elementor-editor.js, renders only in
 * edit/preview mode and reads feeds[].id and feeds[].feed_name.
 */
final class Smash2173ElementorPayloadEditorOnlyTest extends TestCase
{
	public function test_feed_rows_are_reduced_to_id_and_name(): void
	{
		$rows = array(
			array(
				'id'          => 7,
				'feed_name'   => 'Homepage',
				'settings'    => array('sources' => array('ChIJ1')),
				'sourcesList' => array(array('account_id' => 'ChIJ1')),
				'feed_style'  => '.sb-feed{color:red}',
				'author'      => 1,
			),
			array('id' => '8'),
		);

		$this->assertSame(
			array(
				array('id' => 7, 'feed_name' => 'Homepage'),
				array('id' => '8', 'feed_name' => ''),
			),
			SBR_Elementor_Base::editor_feeds($rows)
		);
	}

	/**
	 * @dataProvider unusableRows
	 *
	 * @param mixed $rows
	 */
	public function test_unusable_rows_yield_an_empty_list($rows): void
	{
		$this->assertSame(array(), SBR_Elementor_Base::editor_feeds($rows));
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusableRows(): array
	{
		return array(
			'null'           => array(null),
			'false'          => array(false),
			'row without id' => array(array(array('feed_name' => 'x'))),
			'scalar row'     => array(array('7')),
		);
	}

	/** Without Elementor loaded there is no editor, so nothing is localized. */
	public function test_no_elementor_means_not_an_editor_request(): void
	{
		$this->assertFalse(class_exists('\Elementor\Plugin', false));
		$this->assertFalse(SBR_Elementor_Base::is_elementor_editor_request());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider modes
	 */
	public function test_gate_follows_elementor_edit_and_preview_modes(bool $edit, bool $preview, bool $expected): void
	{
		if (! function_exists('is_admin')) {
			eval('function is_admin() { return false; }');
		}
		eval('namespace Elementor; class Plugin { public static $instance; public $editor; public $preview; }');
		$plugin          = new \Elementor\Plugin();
		$plugin->editor  = new class ($edit)
		{
			/** @var bool */
			private $mode;

			public function __construct(bool $mode)
			{
				$this->mode = $mode;
			}

			public function is_edit_mode(): bool
			{
				return $this->mode;
			}
		};
		$plugin->preview = new class ($preview)
		{
			/** @var bool */
			private $mode;

			public function __construct(bool $mode)
			{
				$this->mode = $mode;
			}

			public function is_preview_mode(): bool
			{
				return $this->mode;
			}
		};
		\Elementor\Plugin::$instance = $plugin;

		$this->assertSame($expected, SBR_Elementor_Base::is_elementor_editor_request());
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: bool}>
	 */
	public static function modes(): array
	{
		return array(
			'public page'  => array(false, false, false),
			'editor'       => array(true, false, true),
			'preview'      => array(false, true, true),
		);
	}

	/**
	 * BC: the keys sb-elementor-editor.js reads (feeds, feed_url, is_pro_active) plus nonce, as before.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_editor_data_keeps_feeds_feed_url_is_pro_active_nonce_keys(): void
	{
		if (! function_exists('admin_url')) {
			eval('function admin_url($path = "") { return "https://example.test/wp-admin/" . $path; }');
		}
		if (! function_exists('wp_create_nonce')) {
			eval('function wp_create_nonce($action = -1) { return "nonce-" . $action; }');
		}

		$data = SBR_Elementor_Base::editor_data(
			array(array('id' => 7, 'feed_name' => 'Homepage', 'feed_style' => '.x{}'))
		);

		$this->assertSame(array('feeds', 'feed_url', 'is_pro_active', 'nonce'), array_keys($data));
		$this->assertSame(array(array('id' => 7, 'feed_name' => 'Homepage')), $data['feeds']);
		$this->assertSame(admin_url('admin.php?page=sbr'), $data['feed_url']);
		$this->assertIsBool($data['is_pro_active']);
		$this->assertSame(wp_create_nonce('sbr-blocks'), $data['nonce']);
	}

	/** The localize call sits behind the gate, so a public page never gets the payload. */
	public function test_localize_is_gated(): void
	{
		$src = (string) file_get_contents(__DIR__ . '/../../class/Common/Integrations/Elementor/SBR_Elementor_Base.php');

		$this->assertMatchesRegularExpression(
			"/if \\(self::is_elementor_editor_request\\(\\)\\) \\{\\s*wp_localize_script\\('sbr_scripts', 'sbrElementorData', self::editor_data\\(\\)\\);\\s*\\}/",
			$src
		);
		$this->assertSame(1, substr_count($src, "'sbrElementorData'") - substr_count($src, "'globalVar'  => 'sbrElementorData'"));
	}
}
