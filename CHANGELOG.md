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

### Added

- **An audited admin override for a sponsor set wrong:** `Mlm::correctSponsor($memberId, $toSponsorId, $reason, $actorId)`, with `ReferralProgram::correctSponsor()` underneath. It is a separate method, not a flag. `assignSponsor()`, attribution, the `Member` model and raw SQL stay exactly as locked as 0.4.0. It requires a reason (non-empty after trimming, at most 1000 UTF-8 bytes) and an actor id. It still refuses self-referral, cycles, unknown members, a member with no sponsor yet, and the sponsor already set.
- **Authorization is yours, and the default is deny.** Define the `fancy-mlm.correct-sponsor` Gate (`GateCorrectionAuthorizer::ABILITY`). Until you do, every correction throws `CorrectionUnauthorized`, even if a `Gate::before()` hook allows everything else. Plain-PHP hosts pass an authorizer closure as `ReferralProgram`'s sixth constructor argument. Only a strict `true` authorizes.
- **`mlm_sponsor_corrections`: an append-only audit table** recording who, when, member, from-sponsor, to-sponsor and reason. The package has no update or delete for it. With `mlm.attribution.database_guard` on, triggers refuse `UPDATE` and `DELETE` (and `TRUNCATE` on PostgreSQL). `Mlm::sponsorCorrections($memberId)` reads the history.
- **The sponsor trigger admits only the audited change.** A new migration replaces the 0.4.0 trigger with one that refuses any change to a set `sponsor_id` except the one the member's latest audit row names (exact from, exact to). An unaudited raw `UPDATE` is still refused, and so is a replay of an older row. Rolling the migration back restores the 0.4.0 trigger exactly. SQLite is tested. MySQL/MariaDB and PostgreSQL are written to the same rule but are not run by this suite.
- `FancyMlm\Laravel\Events\SponsorCorrected`, dispatched after commit.
- `FancyMlm\Contracts\SponsorCorrectionStore`, a separate optional interface implemented by both shipped stores. Your own `AttributionStore` keeps compiling unchanged; `correctSponsor()` refuses with a `LogicException` until it implements this interface too.
- A shared fixture, `tests/fixtures/sponsor-correction.json` (23 rows), carried byte-identically by `@particle-academy/fancy-mlm`. The Eloquent store runs the same rows against SQLite with the triggers installed.

  **What you must do:** nothing, unless you want the override. `php artisan migrate` adds the table and swaps the trigger. If you published the migrations (`mlm-migrations`), publish again to pick up the two new ones. If you published the config, add `tables.sponsor_corrections`; the default applies until you do. No existing behaviour changed.

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
