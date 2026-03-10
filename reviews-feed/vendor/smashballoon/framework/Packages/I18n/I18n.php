<?php

/**
 * Internationalization handler for sb-common.
 *
 * @package Smashballoon\Framework\I18n
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\I18n;

/**
 * Handles loading translations for the sb-common library.
 */
class I18n
{
    /**
     * Whether translations have been loaded.
     *
     * @var bool
     */
    private static bool $loaded = \false;
    /**
     * Load sb-common translations.
     *
     * Call this from each plugin that uses sb-common, typically on 'init' or 'plugins_loaded'.
     * Safe to call multiple times - will only load once.
     *
     * @return void
     */
    public static function load_textdomain(): void
    {
        if (self::$loaded) {
            return;
        }
        load_textdomain('sb-common', dirname(__DIR__, 2) . '/languages/sb-common-' . determine_locale() . '.mo');
        self::$loaded = \true;
    }
    /**
     * Check if translations have been loaded.
     *
     * @return bool
     */
    public static function is_loaded(): bool
    {
        return self::$loaded;
    }
}
