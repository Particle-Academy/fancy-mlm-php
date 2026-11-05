# Changelog

Notable changes to `particle-academy/fancy-mlm`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

> Entries below **1.0** were reconstructed from git history when this file was
> introduced, so they summarise commit subjects rather than consumer impact.
> Everything from the next release onward is written by hand, in the same commit
> as the change.

---

## [Unreleased]

## [0.4.0] - 2026-11-04

### Added

- **Referral codes, scan logging, attribution and stats.** `Mlm::issueCode()`, `revokeCode()`, `recordScan()` / `recordScanFromRequest()`, `attributeSignup()` / `attributeSignupFromRequest()`, `assignSponsor()` and `stats()`, backed by a framework-free `FancyMlm\Attribution\ReferralProgram` over an `AttributionStore` port (Eloquent and in-memory implementations ship). Codes are 80-bit CSPRNG strings with a host-defined scope (an opaque string or a tuple), status and optional expiry. Scans store an HMAC-SHA256 session hash and only the context you pass: **never an IP, a user agent or a raw session id**. Attribution is first touch by default over a 30-day window, refuses self-referral and cycles, and ignores revoked or expired codes. Stats per code, scope or member over a range in two queries.
- Three tables: `mlm_referral_codes`, `mlm_referral_scans`, `mlm_attributions`. Run `php artisan migrate`.
- `php artisan mlm:prune-scans`, driven by `mlm.attribution.retention_days` (default 90). Schedule it.
- `config/mlm.php` gains `attribution` and three `tables` entries. If you published the config, copy the new keys across; the defaults apply until you do.
- A shared fixture, `tests/fixtures/referral-attribution.json`, carried byte-identically by `@particle-academy/fancy-mlm` and proposed for fancy-conformance.

### Changed

- **BREAKING — `mlm_members.sponsor_id` is now permanent.** Once set, changing or clearing it throws: `SponsorImmutable` from `Mlm::assignSponsor()` and from the `Member` model, and a `QueryException` from the database trigger the new migration installs (SQLite, MySQL/MariaDB, PostgreSQL). Setting it to the member's own id is refused the same way.

  **What you must do:** if nothing in your app ever re-parents a member, nothing. If something does (an admin "move member" tool, a seeder that rewrites sponsors, a data fix), it will now fail; there is deliberately no bypass. Fix data before migrating, or remove the code path. If your database user cannot create triggers, set `mlm.attribution.database_guard` to `false` before `php artisan migrate`; the code guards still apply.

## 0.3.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


## 0.2.0 — 2026-07-02

### Added

- configurable downline trees (unilevel / binary / matrix)

## 0.1.0 — 2026-06-29

### Added

- Fancy MLM PHP MVP — agnostic referral engine + Laravel bridge
