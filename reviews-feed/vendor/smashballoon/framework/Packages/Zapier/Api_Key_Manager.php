<?php

/**
 * Zapier API key management (shared engine).
 *
 * Keys are generated with a CSPRNG, stored as an unsalted SHA-256 hash
 * (the key is a 256-bit random value, not a password), shown exactly
 * once and compared in constant time.
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Api_Key_Manager
 *
 * @since 1.1
 */
class Api_Key_Manager
{
    /**
     * Header the key is expected in.
     *
     * @var string
     */
    const HEADER = 'X-Api-Key';
    /**
     * Engine config.
     *
     * @var Zapier_Config
     */
    private $config;
    /**
     * Constructor.
     *
     * @param Zapier_Config $config Engine config.
     */
    public function __construct(Zapier_Config $config)
    {
        $this->config = $config;
    }
    /**
     * Option name for the stored key hash.
     *
     * @return string
     */
    public function option_name()
    {
        return $this->config->prefix . '_zapier_api_key';
    }
    /**
     * Generate a new API key, replacing any existing one.
     *
     * @return string The plain text key (never stored).
     */
    public function generate()
    {
        $plain = $this->config->prefix . '_' . bin2hex(random_bytes(32));
        update_option($this->option_name(), array('hash' => hash('sha256', $plain), 'last4' => substr($plain, -4), 'created_on' => gmdate('Y-m-d H:i:s'), 'created_by' => get_current_user_id()), \false);
        return $plain;
    }
    /**
     * Revoke the current key.
     *
     * @return void
     */
    public function revoke()
    {
        delete_option($this->option_name());
    }
    /**
     * Whether a key exists.
     *
     * @return bool
     */
    public function exists()
    {
        $stored = get_option($this->option_name(), array());
        return !empty($stored['hash']);
    }
    /**
     * Non-secret display metadata.
     *
     * @return array|null
     */
    public function get_display_data()
    {
        $stored = get_option($this->option_name(), array());
        if (empty($stored['hash'])) {
            return null;
        }
        return array('last4' => isset($stored['last4']) ? $stored['last4'] : '', 'created_on' => isset($stored['created_on']) ? $stored['created_on'] : '');
    }
    /**
     * Verify a presented key in constant time.
     *
     * @param string $presented_key Key from the client.
     *
     * @return bool
     */
    public function verify($presented_key)
    {
        if (!is_string($presented_key) || $presented_key === '') {
            return \false;
        }
        $stored = get_option($this->option_name(), array());
        if (empty($stored['hash'])) {
            return \false;
        }
        return hash_equals($stored['hash'], hash('sha256', $presented_key));
    }
    /**
     * Extract the presented key from a REST request.
     *
     * Accepts the X-Api-Key header or Authorization: Bearer. Never a
     * query param (keys must not land in access logs).
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return string
     */
    public function from_request($request)
    {
        $key = $request->get_header(self::HEADER);
        if (!empty($key)) {
            return trim($key);
        }
        $auth = $request->get_header('Authorization');
        if (!empty($auth) && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }
}
