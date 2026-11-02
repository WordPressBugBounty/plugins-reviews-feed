<?php

/**
 * Zapier known-sources store (shared engine).
 *
 * A generic, option-backed "first seen" set of source ids (accounts,
 * connected providers) so a connect-style event fires once per genuinely new
 * source and never retro-fires for sources that pre-dated the Zapier setup.
 *
 * Generalised from Instagram Feed's inline known-accounts logic. The pattern:
 *
 *  - Seed a baseline the moment Zapier becomes usable (licensed + a key), so
 *    every source already connected at that point is recorded and none of them
 *    retro-fires on a later token refresh.
 *  - remember() a source on connect: a genuinely new id returns true (dispatch);
 *    a known id returns false (dedupe a refresh re-firing the same hook).
 *  - forget() a source on removal / revoked authorization, so reconnecting that
 *    same source later is treated as new and fires again.
 *
 * The set is read from its option once per request and cached in memory, then
 * written through on change, so a handler calling is_known() repeatedly costs a
 * single option read and mutations cost a single write.
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Known_Sources_Store
 *
 * @since 1.1
 */
class Known_Sources_Store
{
    /**
     * Full option name the set is stored under: {prefix}_zapier_{suffix}.
     * Not autoloaded (set only on a licensed, keyed install).
     *
     * @var string
     */
    private $option;
    /**
     * In-memory copy of the set for this request, or an empty array once
     * loaded. Paired with $loaded so an empty set is distinguished from an
     * unloaded one.
     *
     * @var array
     */
    private $cache = array();
    /**
     * Whether $cache has been populated from the option this request. Gates the
     * single option read.
     *
     * @var bool
     */
    private $loaded = \false;
    /**
     * Whether a baseline row exists in the database (distinct from a baseline
     * that exists but is empty). Resolved by the same read that fills $cache.
     *
     * @var bool
     */
    private $seeded = \false;
    /**
     * Constructor.
     *
     * @param string $prefix Plugin engine prefix (e.g. 'sbi', 'sbr'). Constrained
     *                       to [a-z0-9_] to match the engine's option naming.
     * @param string $suffix Option name suffix after '_zapier_'. Defaults to
     *                       'known_sources'; a plugin migrating an existing option
     *                       passes its own (Instagram Feed passes 'known_accounts'
     *                       to keep its baseline across the cutover).
     */
    public function __construct($prefix, $suffix = 'known_sources')
    {
        $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $prefix));
        $suffix = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $suffix));
        $this->option = $prefix . '_zapier_' . $suffix;
    }
    /**
     * Full option name the set is stored under.
     *
     * @return string
     */
    public function option_name()
    {
        return $this->option;
    }
    /**
     * Whether a baseline has been recorded (even an empty one).
     *
     * @return bool
     */
    public function is_seeded()
    {
        $this->load();
        return $this->seeded;
    }
    /**
     * Record the baseline of ids that already existed when Zapier became
     * usable — exactly once. A no-op when a baseline already exists, so a caller
     * can seed on every boot cheaply and pre-existing sources never retro-fire.
     *
     * @param array $ids Currently-connected source ids.
     *
     * @return void
     */
    public function seed(array $ids)
    {
        if ($this->is_seeded()) {
            return;
        }
        $this->cache = $this->normalize($ids);
        $this->seeded = \true;
        $this->loaded = \true;
        update_option($this->option, $this->cache, \false);
    }
    /**
     * Every known source id.
     *
     * @return array
     */
    public function all()
    {
        $this->load();
        return $this->cache;
    }
    /**
     * Whether a source id is already known.
     *
     * @param string $id Source id.
     *
     * @return bool
     */
    public function is_known($id)
    {
        $id = (string) $id;
        $this->load();
        return '' !== $id && in_array($id, $this->cache, \true);
    }
    /**
     * Append a source id and persist, returning whether it was genuinely new.
     * A new id returns true (the caller should dispatch); an already-known id
     * returns false (dedupe). The first remembered id on an unseeded store also
     * creates the baseline, so it is always treated as new.
     *
     * @param string $id Source id.
     *
     * @return bool True when the id was new and recorded.
     */
    public function remember($id)
    {
        $id = (string) $id;
        if ('' === $id) {
            return \false;
        }
        $this->load();
        if (in_array($id, $this->cache, \true)) {
            return \false;
        }
        $this->cache[] = $id;
        $this->cache = array_values($this->cache);
        $this->seeded = \true;
        update_option($this->option, $this->cache, \false);
        return \true;
    }
    /**
     * Forget a source id so a later re-appearance is treated as new. A no-op
     * when the baseline is unseeded (nothing to forget) or the id is absent, so
     * it never creates the option and never writes when nothing changed.
     *
     * @param string $id Source id.
     *
     * @return void
     */
    public function forget($id)
    {
        $id = (string) $id;
        if ('' === $id || !$this->is_seeded()) {
            return;
        }
        $filtered = array_values(array_filter($this->cache, static function ($known_id) use ($id) {
            return (string) $known_id !== $id;
        }));
        if (count($filtered) !== count($this->cache)) {
            $this->cache = $filtered;
            update_option($this->option, $filtered, \false);
        }
    }
    /**
     * Read the set from its option once per request, resolving both the cached
     * value and whether a baseline row exists. A null default distinguishes an
     * absent option (unseeded) from a stored empty array (seeded, empty).
     *
     * @return void
     */
    private function load()
    {
        if ($this->loaded) {
            return;
        }
        $stored = get_option($this->option, null);
        $this->seeded = null !== $stored;
        $this->cache = is_array($stored) ? array_values($stored) : array();
        $this->loaded = \true;
    }
    /**
     * Normalise a list of ids to a de-duplicated array of non-empty strings.
     *
     * @param array $ids Raw ids.
     *
     * @return array
     */
    private function normalize(array $ids)
    {
        $out = array();
        foreach ($ids as $id) {
            $id = (string) $id;
            if ('' !== $id) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }
}
