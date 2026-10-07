<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Clear_Cache;

/**
 * SMASH-1033 / #558 — the Optimize Images switch is live now, so the two places that depend on
 * it have to cope with "off": the feed pass still marks rows done (or every view re-runs it),
 * and switching it back on re-queues those rows for resizing.
 */
final class Smash1033OptimizeImagesSwitchTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			/** @var string */
			public $prefix = 'wp_';
			/** @var list<string> */
			public $queries = [];
			/** @param string $sql */
			public function query($sql)
			{
				$this->queries[] = $sql;
				return 0;
			}
		};
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	/** @return list<string> */
	private function queriesAfter(array $old, array $new): array
	{
		(new Clear_Cache())->reset_images_when_optimize_enabled($old, $new);
		return $GLOBALS['wpdb']->queries;
	}

	public function test_switching_it_back_on_requeues_every_row(): void
	{
		$queries = $this->queriesAfter(['optimize_images' => false], ['optimize_images' => true]);

		$this->assertSame(['UPDATE wp_sbr_reviews_posts SET images_done = 0'], $queries);
	}

	/** @return array<string, array{0: array<string,mixed>, 1: array<string,mixed>}> */
	public static function noRequeue(): array
	{
		return [
			'on to off'                 => [['optimize_images' => true], ['optimize_images' => false]],
			'stays on'                  => [['optimize_images' => true], ['optimize_images' => true]],
			'stays off'                 => [['optimize_images' => false], ['optimize_images' => false]],
			'absent (on) to on'         => [[], ['optimize_images' => true]],
			'unrelated settings change' => [['optimize_images' => true, 'gdpr' => 'no'], ['optimize_images' => true, 'gdpr' => 'yes']],
		];
	}

	/**
	 * @dataProvider noRequeue
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $new
	 */
	public function test_no_requeue_unless_it_turns_on($old, $new): void
	{
		$this->assertSame([], $this->queriesAfter($old, $new));
	}

	public function test_the_reset_is_hooked_to_settings_updates(): void
	{
		$GLOBALS['sbr_test_actions'] = [];
		(new Clear_Cache())->register();

		$hooked = array_filter(
			$GLOBALS['sbr_test_actions']['update_option_sbr_settings'] ?? [],
			static function ($a) {
				return is_array($a['callback']) && $a['callback'][1] === 'reset_images_when_optimize_enabled';
			}
		);
		$this->assertCount(1, $hooked, 'update_option_sbr_settings must call reset_images_when_optimize_enabled');
		unset($GLOBALS['sbr_test_actions']);
	}

	/** System Info reads the switch through the same helper, so it can't report a different state. */
	public function test_system_info_reads_the_switch_through_the_helper(): void
	{
		$file = (string) file_get_contents((string) (new \ReflectionClass(\SmashBalloon\Reviews\Common\Util::class))->getFileName());
		$this->assertMatchesRegularExpression("/'Optimize Images: ';\\s*\\\$output \\.= self::optimize_images_enabled\\(\\\$sbr_settings\\)/", $file);
	}

	/** The feed pass marks a row done with the switch off too; only the resize is gated. */
	public function test_feed_pass_marks_rows_done_whether_or_not_it_resizes(): void
	{
		$m    = new \ReflectionMethod(\SmashBalloon\Reviews\Pro\Feed::class, 'find_and_resize_media');
		$file = (string) file_get_contents((string) $m->getFileName());
		$body = implode("\n", array_slice(explode("\n", $file), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

		$gate = strpos($body, 'if (Util::should_store_local_images()) {');
		$this->assertNotFalse($gate);
		$gate_end = strpos($body, "\n\t\t\t}", $gate);
		$this->assertNotFalse($gate_end);
		$this->assertStringContainsString('resize_images(', substr($body, $gate, $gate_end - $gate), 'the resize stays behind the switch');
		$this->assertStringContainsString("['images_done', 1, '%d']", substr($body, $gate_end), 'images_done = 1 is written after the gate, unconditionally');
	}
}
