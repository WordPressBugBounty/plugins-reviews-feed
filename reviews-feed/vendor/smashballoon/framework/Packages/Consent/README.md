# Consent

Shared WP.org-compliance consent package for Smash Balloon free plugins.

Provides a single source of truth for two top-level options that gate
remote calls + in-plugin notifications, plus the supporting cron, AJAX
endpoint, re-prompt modal, and onboarding checkbox.

## Usage

In your plugin's `plugins_loaded` handler:

```php
\Smashballoon\Framework\Packages\Consent\ConsentManager::init([
    'plugin_slug' => 'instagram-feed',
    'plugin_name' => 'Instagram Feed',
]);

// To react to user toggles, hook the public action — payload reflects
// the FINAL stored state (post DSC→NOTIF coupling), not the raw input.
add_action('sbc_consent_changed', function ($flags) {
    // $flags = ['dsc' => bool, 'notif' => bool]
});

// Pro editions opt in via this filter. Must be attached BEFORE
// ConsentManager::init() — the result is cached per request.
add_filter('sbc_consent_is_pro', '__return_true');
```

## Public API

```php
ConsentManager::flags();                  // ['dsc' => bool, 'notif' => bool]
ConsentManager::is_dsc_enabled();         // bool
ConsentManager::is_notif_enabled();       // bool
ConsentManager::is_modal_dismissed();     // bool
ConsentManager::notification_source();    // 'remote' | 'local' | 'none'
ConsentManager::update($dsc, $notif, $dismiss_modal_or_null);
ConsentManager::reconcile_cron();         // idempotent
```

## Canonical names (framework-owned)

| What                     | Name                                |
| ------------------------ | ----------------------------------- |
| Option (DSC)             | `sbc_data_sharing_consent`          |
| Option (notifications)   | `sbc_in_plugin_notifications`       |
| Option (modal dismiss)   | `sbc_consent_modal_dismissed`       |
| AJAX action              | `wp_ajax_sbc_save_consent_choice`   |
| Nonce action             | `sbc-consent`                       |
| Cron hook                | `sbc_notification_update`           |
| Cron schedule slug       | `sbcweekly`                         |
| SBNotices group          | `sbc_marketing`                     |
| Public action            | `do_action('sbc_consent_changed')`  |

## Frontend contract

| What                          | ID / class                        |
| ----------------------------- | --------------------------------- |
| Modal overlay                 | `#sbc-consent-reprompt-overlay`   |
| Modal close                   | `#sbc-consent-reprompt-close`     |
| Modal skip                    | `#sbc-consent-reprompt-skip`      |
| Modal accept                  | `#sbc-consent-reprompt-accept`    |
| Onboarding checkbox           | `#sbc-consent-onboarding-checkbox`|
| CSS prefix                    | `.sbc-consent-*`                  |
| JS global                     | `window.sbcConsent`               |

## URL filters

```php
add_filter('sbc_consent_permissions_url', fn () => 'https://...');
add_filter('sbc_consent_terms_url',       fn () => 'https://...');
add_filter('sbc_consent_privacy_url',     fn () => 'https://...');
add_filter('sbc_consent_modal_icon_url',  fn () => 'https://...');
```

## Templates

- `templates/consent-reprompt-popup.php` — auto-rendered in admin_footer.
- `templates/onboarding-consent-checkbox.php` — `include` from a wizard.
- `templates/debug-tab.php` — `include` from a non-Vue settings tab.
