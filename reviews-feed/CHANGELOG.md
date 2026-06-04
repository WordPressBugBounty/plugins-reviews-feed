# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Star ratings in review feeds now include accessible labels (e.g. "4 out of 5 stars") for screen readers, improving accessibility for visually impaired users. (#459)
- {internal} PRs labeled "Ready for Review" now automatically queue an AI-generated changelog entry via the changelog-writer workflow. [SMASH-1497] (#466)

### Changed
- Refreshed the Easy Digital Downloads source icon to the official blue cart mark, matching EDD's current branding. [SMASH-1569] (#474, customizer #171)

### Fixed
- A PHP 8 fatal error that occurred when the `sbr_errors` database option held a falsy value (e.g. `false` or an empty string from a corrupted or legacy write) no longer crashes the feed builder or WP-Cron cache update. (#474)

## [2.6.1] - 2026-06-02

### Added
- Help Widget with quick access to documentation and feature suggestions now available in the Reviews Feed. Easily find answers and share your ideas without leaving the plugin. [SMASH-1307] (#448)

### Fixed
- EDD review titles now render above the review body on both the public feed and the customizer preview. The title was captured at submission (required field on the EDD product-page review form), persisted to comment meta, and forwarded all the way to the renderer, but the default text template + customizer canvas were silently dropping it. Fix is provider-allowlisted (only EDD today; extensible) and decodes HTML entities once so titles with `&`, `'`, `<`, accented letters render correctly. [SMASH-1553] (#469, customizer #161)
