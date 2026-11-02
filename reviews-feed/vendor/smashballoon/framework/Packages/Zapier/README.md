# Zapier Engine (shared)

Generic, plugin-agnostic engine for exposing plugin events as Zapier
triggers. First shipped in-plugin by Instagram Feed Pro
(`inc/Integrations/Zapier/`) and Reviews Feed Pro
(`class/Pro/Integrations/Zapier/`); this package is the canonical
shared version every feed plugin adopts once it ships in a framework
release.

## What a plugin provides (thin adapter, declarative config)

1. A **config** (prefix, REST namespace, event list, availability callback
   for license gating, polling collection callables).
2. **Event listeners** on its own business hooks (e.g. `sbi_new_post_stored`,
   `sbr_new_review_stored`) that shape a flat payload and dispatch it.

`Integration_Bootstrap` collapses all the wiring — service construction, cron
+ tables, REST routes, admin AJAX, the license/key gate — into one call, so the
plugin's integration class reads as config plus payload shaping:

```php
use Smashballoon\Framework\Packages\Zapier\Integration_Bootstrap;
use Smashballoon\Framework\Packages\Zapier\Event_Dispatcher;

$zapier = Integration_Bootstrap::from_config([
    'prefix' => 'sbi',
    'rest_namespace' => 'sbi/v1',
    'plugin_slug' => 'instagram-feed-pro',
    'plugin_version' => SBIVER,
    'text_domain' => 'instagram-feed',
    'events' => ['new_post', 'new_reel', 'new_carousel', 'feed_created', 'account_connected', 'feed_refreshed'],
    'availability_callback' => fn () => sbi_builder_pro()->license_tier->is_feature_available('zapier'),
    'collections' => [
        'feeds' => [$providers, 'feeds'],
        'posts' => [$providers, 'posts'],
    ],
]);

// boot() always registers REST + admin AJAX; the closure runs ONLY when the
// integration can dispatch (licensed AND an API key exists), so a site that
// has not set Zapier up does no per-event work.
$zapier->boot(function ($service) {
    $events = new Event_Dispatcher($service);
    add_action('sbi_new_post_stored', function ($post, $feed_ids) use ($events) {
        $events->dispatch('new_post', $payload_builder($post, $feed_ids), ['feed_id' => $feed_ids[0]], $post['id']);
    }, 10, 2);
});
```

Everything else — REST routes, auth, subscriptions, queueing, delivery,
admin AJAX — is engine code.

## What the engine provides

- `Integration_Bootstrap` — the composable adapter skeleton. Builds the
  service from a config, wires cron + tables, REST and admin AJAX, and gates
  event-listener attachment on `can_dispatch()` (licensed AND keyed). Exposes
  `service()`, `is_available()`, `can_dispatch()` (memoized per request — the
  single cheap early bail), `key_display()`, and a static `uninstall($prefix)`.
  Composed, not subclassed, so a plugin whose integration already extends
  another base class (e.g. a ServiceProvider) can still adopt it.
- `Event_Dispatcher` — a thin helper a plugin's event layer composes:
  `dispatch(event, payload, context, idempotency_key)`, `dispatch_once()` for
  the per-request de-dup a feed triggers when one refresh fires its hook per
  cache record, and the static `iso8601()` / `now()` / `site_url()` payload
  primitives every builder repeats. Holds no plugin business logic — payload
  *shape* stays in the plugin.
- `Known_Sources_Store` — a generic, option-backed "first seen" set of source
  ids so a connect-style event fires once per genuinely new source and never
  retro-fires. `seed()` a baseline when Zapier becomes usable; `remember()` on
  connect (new id → true → dispatch; known id → false → dedupe); `forget()` on
  removal/revoke so a reconnect fires again. Read once per request and cached
  in memory (no N+1), written through on change. Namespaced `{prefix}_zapier_
  {suffix}` (Instagram Feed passes suffix `known_accounts` to keep its baseline
  across the cutover).
- `Api_Key_Manager` — CSPRNG key, SHA-256 hash stored (shown once),
  constant-time verify, header extraction (`X-Api-Key` / Bearer).
- `Subscription_Store` — `{prefix}_zapier_hooks` table, target URL
  allow-list (`https://hooks.zapier.com/` only), failure counting with
  auto-disable after 5 consecutive failures.
- `Delivery_Queue` — `{prefix}_zapier_queue` table + WP-Cron single
  event scheduling (`{prefix}_zapier_process_queue`), plus the
  `{prefix}_zapier_idem` idempotency ledger backing duplicate-delivery
  collapse (see "Idempotency" below).
- `Delivery` — async `wp_safe_remote_post` delivery implementing the
  Zapier REST hook contract: 2xx = delivered, **410 = prune the
  subscription**, anything else retries once after 5 minutes.
- `Zapier_Service` — orchestrator: table creation (dbDelta, versioned
  option `{prefix}_zapier_db_version`), `dispatch(event, payload,
  context, matcher, idempotency_key)` with filter matching (exact match
  per filter key; `min_rating` compares `>=` against
  `context['rating']`) and optional delivery idempotency.

## Adding Zapier to a new feed plugin (TikTok, YouTube, Facebook, Threads…)

Five steps. Everything generic is engine code; the plugin supplies only the
declarative config and its own payload shaping.

1. **Require the framework** (`smashballoon/framework`) so the
   `Smashballoon\Framework\Packages\Zapier` classes autoload. No files are
   copied into the plugin.

2. **Write one integration class** that builds an `Integration_Bootstrap` from
   a config array and boots it. The config is pure declaration:

   ```php
   Integration_Bootstrap::from_config([
       'prefix'                => 'sbtt',            // drives every option/table/hook/filter name
       'rest_namespace'        => 'sbtt/v1',         // GET sbtt/v1/zapier/me, /hooks, …
       'plugin_slug'           => 'tiktok-feeds',    // reported by the auth-test endpoint
       'plugin_version'        => SBTTVER,
       'text_domain'           => 'tiktok-feeds',    // your loaded domain; translates REST errors
       'events'                => ['new_post', 'feed_created', 'feed_deleted',
                                   'source_connected', 'source_error', 'feed_refreshed'],
       'availability_callback' => fn () => /* your license-tier check */,
       'collections'           => ['feeds' => [$providers, 'feeds'],   // GET …/zapier/feeds
                                   'posts' => [$providers, 'posts']],   // shaped like the webhook payload
       // 'filter_keys'        => ['feed_id'],       // optional: lock down subscribe filters
   ]);
   ```

3. **Attach listeners in the `boot()` closure.** It runs only when the
   integration can dispatch, so no per-event work happens on an unset-up site.
   Compose an `Event_Dispatcher` and, in each handler, build a **flat**
   payload and dispatch it with a **stable external id** as the idempotency
   key (a post id, review id, feed id — never a payload hash):

   ```php
   $zapier->boot(function ($service) {
       $events = new Event_Dispatcher($service);
       add_action('sbtt_new_post_stored', function ($post, $feed_id) use ($events) {
           $events->dispatch('new_post', [
               'id'         => (string) $post['id'],
               'caption'    => $post['caption'] ?? '',
               'permalink'  => $post['permalink'] ?? '',
               'timestamp'  => Event_Dispatcher::iso8601($post['created_time'] ?? ''),
               'feed_id'    => (string) $feed_id,
               'site_url'   => Event_Dispatcher::site_url(),
           ], ['feed_id' => (string) $feed_id], (string) $post['id']);
       }, 10, 2);
       // feed_refreshed fires once per cache record → dispatch_once() collapses it:
       add_action('sbtt_after_cron_refresh', function ($feed_id) use ($events) {
           $events->dispatch_once('feed_refreshed', (string) $feed_id, [/* … */], ['feed_id' => (string) $feed_id]);
       });
   });
   ```

4. **Expose the settings tab** to the shared settings surface, declaring the
   connection/triggers/upsell rows and a flat list of translated trigger
   labels, and reading key metadata through the bootstrap
   (`$zapier->key_display()`). (Surface markup/JS/CSS is engine-owned.)

5. **Call `uninstall()`** from the plugin's uninstall path, gated on its
   "delete all data" preference:

   ```php
   Integration_Bootstrap::uninstall('sbtt');   // prefix only — an uninstall request has no plugin constants
   ```

### What stays in the plugin (and only this)

| Plugin supplies | Engine owns |
|---|---|
| The config array (prefix, namespace, event keys, collections) | Service construction, REST routes, auth, subscriptions, queue, delivery, admin AJAX |
| The **license-gate callback** (`availability_callback`) | Applying the `{prefix}_zapier_enabled` filter + the memoized `can_dispatch()` gate |
| **Hook names** it listens on and their **payload field mapping** | Idempotency, per-request de-dup (`dispatch_once`), the `iso8601`/`site_url` primitives |
| Polling **collection callables** (rows shaped like the webhook payload) | Route registration, arg coercion, error contract |
| **Strings + text domain**, the **upsell URL**, trigger **labels** | The connection surface markup/JS/CSS |
| *(Opt-in)* seeding `Known_Sources_Store` for connect-once semantics | The store itself (seed/remember/forget, option-backed, cached) |

### Optional: connect-once semantics via `Known_Sources_Store`

If a plugin's "source/account connected" hook also fires on token refresh (so
a naive listener would re-fire for the same source), gate it on a
`Known_Sources_Store`: `seed()` the baseline of already-connected ids inside
the `boot()` closure (once Zapier is usable), `remember($id)` on connect and
dispatch only when it returns `true`, and `forget($id)` on removal/revoke so a
reconnect fires again. A plugin whose connect hook is already de-duplicated at
source does not need it.

## dispatch() signature

```php
$service->dispatch(
    string        $event,            // event key
    array         $payload,          // JSON body delivered to the Zap
    array         $context = [],     // matched against each subscription's filters
    callable|null $matcher = null,   // optional custom fn($filters, $context): bool
    string        $idempotency_key = '' // optional stable external id (see below)
);
```

The first three arguments are the common case; `$matcher` and
`$idempotency_key` are optional trailing arguments, so existing 3-arg
callers are unchanged.

## Idempotency (SMASH-1965)

The engine collapses duplicate deliveries of one business event when — and
only when — the caller supplies a stable `$idempotency_key`. With no key
(the empty-string default) behaviour is exactly as before: every dispatch
enqueues.

- **Grain.** Dedup is per `(subscription id, event, idempotency_key)`
  triple. Two different subscriptions to the same event each still receive
  exactly one delivery; the event name is part of the triple, so a fan-out
  to sibling events (e.g. Instagram `new_post` + `new_reel` for one post)
  still delivers each event once.
- **Key = a stable external id, never a payload hash.** The duplicate rows
  this guards against carry *different* internal DB ids (and so different
  payloads); only a stable external id (a review id, an Instagram media id,
  a feed/account id) collapses them. The consumer plugin supplies it at the
  dispatch site.
- **Window.** Duplicates within a rolling window (default 300s, filter
  `{prefix}_zapier_idempotency_window`; non-positive disables the guard)
  collapse to one delivery. The window is quantised into buckets folded
  into the stored hash, so the guard is a single race-safe `INSERT IGNORE`
  against a `UNIQUE(key_hash)` index on the `{prefix}_zapier_idem` ledger —
  no read-then-write gap. The ledger row outlives the queue row (which is
  deleted on delivery success), so a duplicate arriving *after* the first
  delivery is still suppressed.
- **Trade-off.** Two dispatches straddling a bucket boundary land in
  different buckets and both deliver. The window is short and this edge is
  rare; for once-only events it cannot occur, and for repeated-edit events a
  missed collapse is a duplicate, never a lost delivery. The guard also
  fails open on any DB error (delivers rather than silently dropping).
- **Retention.** Ledger rows are pruned once past their window (two windows
  or an hour, whichever is larger) on every processing batch, alongside the
  queue prune.
- **Schema.** The ledger table is created by `maybe_create_tables()` and
  bumps `DB_VERSION` to `1.1`; dbDelta adds it to existing installs on the
  next admin request. `uninstall()` drops it with the other engine tables.

## Design rules

- One business event = one plugin hook, fired only on confirmed DB
  inserts (never updates), so Zapier receives exactly one event per
  new item. Where a plugin has paths that can fire the same business
  event twice (divergent storage keys, multi-source fan-out), the
  dispatch-site `idempotency_key` is the engine-level backstop that keeps
  it one delivery (see "Idempotency").
- Everything is additive: nothing runs unless a key exists and a Zap
  subscribed.
- Delivery is queued, never inline with cron/page loads.
- Keys never appear in URLs or logs; only hashes are stored.

## Data retention & uninstall

- **Queue retention** — `Delivery` prunes queued deliveries older than 7
  days on every run (a queued payload that has not gone out in a week
  never will, and it may carry PII). Filterable per plugin via
  `{prefix}_zapier_queue_retention_days`.
- **Unsubscribe cleanup** — deleting a subscription (Zapier
  `performUnsubscribe`, or a `410` prune) also drops that hook's queued
  payload rows, so no payload is orphaned in the queue.
- **Uninstall** — the engine ships no `uninstall.php` of its own (it is a
  vendored library). A consumer plugin calls
  `Zapier_Service::uninstall()` from its own uninstall path
  (`register_uninstall_hook` or `uninstall.php`), typically gated on the
  plugin's "delete all data on uninstall" preference. It drops both
  tables, deletes the API-key / db-version / lock options and the
  auth-failure transients, and unschedules the delivery cron:

  ```php
  // uninstall.php (consumer plugin), when "delete all data" is enabled:
  $service = /* build the same Zapier_Service the plugin boots with */;
  $service->uninstall();
  ```

## Zapier-side contract (per plugin app)

- `performSubscribe` → `POST /zapier/hooks` `{url, event, ...filters}` → `201 {id}`
- `performUnsubscribe` → `DELETE /zapier/hooks/{id}` → `204`
- `performList` (sample data) → `GET /zapier/<collection>` returning
  arrays shaped **identically** to webhook payloads
- Auth test → `GET /zapier/me`
