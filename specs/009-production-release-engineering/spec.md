# Specification 009 — Production Release Engineering

**Status:** Approved  
**Covers:** Milestone 4.4 — the final PC1 milestone  
**Depends on:** Spec 001 (approved), Spec 007 (approved), Spec 008 (approved)  
**Does not cover:** Detection capabilities, scanning behavior, remediation logic, signature
registry update mechanism (Spec 007), diagnostics page (Spec 008)

---

## 1. Purpose

This specification defines the engineering requirements for Portfolio Guard to be
considered a Production Candidate suitable for professional deployment on new WordPress
installations and long-term MSP fleet maintenance.

Milestone 4.4 adds no new detection or operational capability. It closes the gap between
a functionally complete plugin and one that can be professionally packaged, deployed
through standard WordPress channels, maintained across software releases, and cleanly
removed when no longer needed.

Every requirement in this document is a necessary condition for PC1. None are optional.

---

## 2. Objectives

1. Establish a stable, consistent version identity for the PC1 release.
2. Complete the plugin uninstall lifecycle so no Portfolio Guard resources are left
   behind after removal.
3. Introduce native WordPress plugin update participation so MSP engineers are notified
   of new plugin code releases through the standard WordPress admin interface.
4. Define and implement a reproducible build process that produces a clean, deployable
   release artifact from source.
5. Validate the release artifact directly — not merely the source tree.
6. Align all documentation and metadata with the 2.0.0 release.

---

## 3. Scope

- Version bump to `2.0.0` across all locations where version is declared
- `readme.txt` changelog and metadata updates for the 2.0.0 release
- Uninstall completeness: all missing option, transient, scheduled event, and file
  cleanup
- `MSP_PG_PluginUpdater`: native WordPress plugin update participation (separate from
  Spec 007 registry updates)
- `build-release.ps1`: build orchestration wrapper with exclusion list and verification
- `ReleasePackageTest`: ZIP artifact validation (separate from development gate)
- `UninstallTest`: uninstall completeness validation (added to development gate)
- `MSP_PG_Config::plugin_update_url()`: accessor for the plugin update endpoint

---

## 4. Non-Goals

- Any change to detection, classification, or remediation behavior
- Any change to the Spec 007 signature registry update mechanism
- Any change to the Spec 008 diagnostics page
- WordPress.org plugin directory submission
- Multisite support
- User-facing configuration UI
- Evidence artifact cleanup on uninstall (intentionally excluded — see §9.5)
- Automated deployment or remote fleet management

---

## 5. Version Identity

### 5.1 Release Version

The PC1 release version is `2.0.0`.

This version number appears in exactly four locations. All four must agree before the
build can proceed.

| Location | Field |
|---|---|
| `portfolio-guard/portfolio-guard.php` | Plugin header `Version:` field |
| `portfolio-guard/portfolio-guard.php` | `define('MSP_PG_VERSION', ...)` constant |
| `portfolio-guard/readme.txt` | `Stable tag:` field |
| `portfolio-guard/readme.txt` | Changelog entry `= 2.0.0 =` |

The build orchestration wrapper (§11) enforces this consistency mechanically. A version
mismatch in any of these locations causes the build to fail before a ZIP is produced.

### 5.2 Plugin Header

The plugin file header is updated to:

```
Plugin Name: MSP Portfolio Guard
Description: Family-specific WordPress malware detection and remediation for MSP fleet deployment.
Version: 2.0.0
Author: My Social Practice
Requires at least: 5.0
Requires PHP: 7.4
```

No additional header fields are introduced. The `MSP_PG_VERSION` constant immediately
below the header is updated to `'2.0.0'`.

### 5.3 readme.txt

The `Stable tag:` line is updated to `2.0.0`.

The `Tested up to:` line is updated to reflect the current WordPress version at the time
of the 2.0.0 release.

A `= 2.0.0 =` changelog entry is added as the first entry in the `== Changelog ==`
section. The entry covers the Phase 2, 3, and 4 milestones added since 1.5.6:

- Behavior classifier with five named profiles (Persistence, Command & Control, Payload
  Delivery, Operator Access, Stealth)
- Profile-based Tier 2 classification with signal-level explainability
- Native signature registry update infrastructure with HMAC-authenticated manifests and
  SHA-256 registry integrity
- Engineering diagnostics page for MSP operators
- Complete uninstall lifecycle

An `= 2.0.0 =` entry is added to the `== Upgrade Notice ==` section.

---

## 6. Uninstall Lifecycle

### 6.1 Policy

When a site administrator uninstalls Portfolio Guard, the WordPress installation must be
left in the same state as if the plugin had never been installed. No Portfolio Guard
resource may remain after uninstall completes, with one explicit exception: evidence
artifacts in `{uploads}/.msp-remediation/` are preserved by design (§9.5).

### 6.2 Complete Resource Inventory

The following table enumerates every resource Portfolio Guard owns and its required
uninstall handling.

**Options**

| Option key | Introduced | Remove on uninstall |
|---|---|---|
| `msp_pg_state` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_pending_activation_scan` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_setup_notice` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_allow_tier1_remediation` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_version` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_report_recipient` | Pre-2.0.0 | Yes — operator configuration, remove on uninstall |
| `msp_pg_last_update_checked` | Spec 007 | Yes — **missing from current uninstall** |
| `msp_pg_last_update_applied` | Spec 007 | Yes — **missing from current uninstall** |
| `msp_pg_max_registry_version` | Spec 007 | Yes — **missing from current uninstall** |
| `msp_pg_update_consecutive_failures` | Spec 007 | Yes — **missing from current uninstall** |
| `msp_pg_plugin_update_last_checked` | Spec 009 | Yes |
| `msp_pg_plugin_update_cache` | Spec 009 | Yes |

**Transients**

| Transient key | Introduced | Remove on uninstall |
|---|---|---|
| `msp_pg_scan_lock` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_catchup_lock` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_update_notice` | Spec 007 | Yes — **missing from current uninstall** |

**Scheduled Events**

| Hook name | Introduced | Remove on uninstall |
|---|---|---|
| `msp_pg_run_scan` | Pre-2.0.0 | Yes — current implementation ✓ |
| `msp_pg_run_update_check` | Spec 007 | Yes — **missing from current uninstall** |

**Generated Files**

| Path | Introduced | Remove on uninstall |
|---|---|---|
| `{WPMU_PLUGIN_DIR}/portfolio-guard-loader.php` | Pre-2.0.0 | Yes — current implementation ✓ |
| `{uploads}/portfolio-guard/signatures.json` | Spec 007 | Yes — **missing from current uninstall** |
| `{uploads}/portfolio-guard/signatures.json.tmp` | Spec 007 | Yes — **missing from current uninstall** |

### 6.3 Implementation

`MSP_PG_Plugin::uninstall()` is extended to handle all missing items identified in §6.2.

The `msp_pg_run_update_check` scheduled event must be cleared unconditionally in
`uninstall()`. It must not rely on `MSP_PG_UpdateScheduler::deactivate()` having
previously run. Deactivation and uninstall are independent lifecycle events in WordPress.

The applied registry directory (`{uploads}/portfolio-guard/`) and all its contents must
be removed. If the directory does not exist, this is a silent no-op. The implementation
uses recursive deletion limited to this specific subdirectory. It must not attempt to
remove the uploads directory itself or any other path.

### 6.4 Deactivation Is Unchanged

The current `MSP_PG_Plugin::deactivate()` and `MSP_PG_UpdateScheduler::deactivate()`
behavior is correct and is not changed by this specification. Deactivation removes
scheduled events and the MU-loader, allowing the plugin to be cleanly reactivated.

### 6.5 Evidence Artifacts Preserved

Evidence artifacts in `{uploads}/.msp-remediation/` are forensic records produced during
active plugin operation. They are not owned exclusively by Portfolio Guard — they are site
records that belong to the site operator. These artifacts are intentionally preserved on
uninstall. The operator may remove them independently through standard filesystem tools.

---

## 7. Native Plugin Update System

### 7.1 Distinction from Spec 007

The Spec 007 signature registry update system keeps detection rules current by
downloading signed `signatures.json` updates. It concerns detection data.

This section defines how the plugin PHP code itself participates in the WordPress native
update mechanism. It concerns plugin code versions. The two systems are completely
independent: separate endpoints, separate authentication models, separate installation
paths, separate options.

### 7.2 Class: `MSP_PG_PluginUpdater`

A new class `MSP_PG_PluginUpdater` is introduced in
`includes/class-msp-pg-plugin-updater.php`.

Responsibility: Check a designated JSON endpoint for newer plugin releases and surface
update availability through the standard WordPress admin plugin update interface.

Public interface:

```
MSP_PG_PluginUpdater::register()     — hook admin_init to trigger periodic check;
                                        hook pre_set_site_transient_update_plugins;
                                        hook plugins_api
MSP_PG_PluginUpdater::check()        — fetch and cache the update endpoint response
MSP_PG_PluginUpdater::inject($t)     — filter callback: inject update info if newer
MSP_PG_PluginUpdater::api($r, $a, $s) — filter callback: return plugin info for modal
```

### 7.3 Update Endpoint

The update endpoint URL is provided by `MSP_PG_Config::plugin_update_url()`. The URL
must be HTTPS. The method returns a configurable value; the default is the designated
production endpoint for Portfolio Guard plugin releases.

The endpoint serves a JSON document with the following structure:

```json
{
  "version": "2.0.1",
  "download_url": "https://releases.example.com/portfolio-guard-2.0.1.zip",
  "requires": "5.0",
  "requires_php": "7.4",
  "tested": "6.6.1",
  "changelog": "..."
}
```

The provisioning of the endpoint and its hosting are out of scope for the plugin
implementation. The plugin must be designed to function correctly when the endpoint is
unreachable (network error, misconfiguration, or empty response) — failing silently
without affecting site operation.

### 7.4 Check Interval

The plugin must not fetch the update endpoint on every page load.

`MSP_PG_PluginUpdater` stores `msp_pg_plugin_update_last_checked` (Unix timestamp) and
`msp_pg_plugin_update_cache` (the most recent endpoint response) in WordPress options.

An update check is performed when:
- `admin_init` fires, AND
- `time() - msp_pg_plugin_update_last_checked > 12 * HOUR_IN_SECONDS`

When a check fires, the endpoint is fetched via `wp_remote_get()` with HTTPS enforcement
and a reasonable timeout. On a successful response, the cache option is updated and
`msp_pg_plugin_update_last_checked` is set to the current time. On any error (network
failure, non-200 response, malformed JSON), the cached value is left unchanged and no
error is surfaced to the admin.

### 7.5 WordPress Transient Injection

The `pre_set_site_transient_update_plugins` filter receives the current update transient.
`MSP_PG_PluginUpdater::inject()` reads the cached endpoint response and:

- If `version` in the cache is strictly greater than `MSP_PG_VERSION`, adds an entry
  to `$transient->response` keyed by `plugin_basename()`. This causes WordPress to
  display the update available notice in the Plugins screen.
- If `version` is equal to or less than `MSP_PG_VERSION`, adds an entry to
  `$transient->no_update` to inform WordPress the plugin is current.

Version comparison uses `version_compare()`. The `download_url` from the cached response
is placed in the `package` field of the injected record.

### 7.6 Plugin Information Modal

The `plugins_api` filter is hooked to return plugin information when WordPress displays
the "View version details" modal in the Plugins screen.

`MSP_PG_PluginUpdater::api()` checks that `$args->slug === 'portfolio-guard'`. If
matched, it returns a `stdClass` object with `name`, `slug`, `version` (from cache),
`download_link`, and a `sections` array containing at minimum a `changelog` key. If the
cached response contains a `changelog` value, it is used; otherwise a minimal string is
returned.

If `$args->slug` does not match, the filter returns its `$result` input unchanged.

### 7.7 Registration

`MSP_PG_PluginUpdater::register()` is called directly from `portfolio-guard.php` at
plugin load time, consistent with the pattern established for `MSP_PG_DiagnosticsPage`.

`MSP_PG_PluginUpdater` must not call `wp_remote_get()` at class load time. All HTTP
activity is deferred to the `admin_init` hook.

### 7.8 Security

The plugin update ZIP is downloaded and installed by WordPress's built-in plugin
installer over HTTPS. Portfolio Guard does not implement custom integrity verification
for the plugin update package — this is handled by WordPress.

The `download_url` value from the endpoint is passed directly to WordPress as the
`package` field. It must be HTTPS. The implementation must reject non-HTTPS `download_url`
values before injecting them into the transient.

---

## 8. Build Process

### 8.1 Production File Structure

The production release ZIP contains exactly the following:

```
portfolio-guard/
├── portfolio-guard.php
├── readme.txt
├── data/
│   └── signatures.json
└── includes/
    ├── class-msp-pg-behavior-classifier.php
    ├── class-msp-pg-config.php
    ├── class-msp-pg-detector.php
    ├── class-msp-pg-diagnostics-page.php
    ├── class-msp-pg-diagnostics.php
    ├── class-msp-pg-feature-extractor.php
    ├── class-msp-pg-plugin-updater.php
    ├── class-msp-pg-plugin.php
    ├── class-msp-pg-remediator.php
    ├── class-msp-pg-runtime.php
    ├── class-msp-pg-signatures.php
    ├── class-msp-pg-update-scheduler.php
    ├── class-msp-pg-update-verifier.php
    ├── class-msp-pg-updater.php
    └── class-msp-pg-utils.php
```

The following paths are present in the source tree but must be excluded from the
production ZIP:

| Excluded path | Reason |
|---|---|
| `tests/` | Test infrastructure — not for deployment |
| `scripts/` | Build tooling — not for deployment |
| `README.md` | Developer documentation — `readme.txt` serves the production role |

If additional development-only directories are introduced in future work (e.g., `docs/`,
`benchmarks/`), they must be added to the exclusion list before the next release build.
The exclusion list is the canonical boundary between development and production content.

### 8.2 Build Orchestration: `scripts/build-release.ps1`

A new script `scripts/build-release.ps1` wraps the existing
`scripts/build-wordpress-plugin-zip.ps1` and orchestrates the complete release pipeline.

**Parameters:**

```
-Version    string    (required) The release version, e.g. "2.0.0"
-PhpPath    string    (optional) Path to php.exe; defaults to "php" on PATH
```

**Pipeline:**

```
Step 1 — Development gate
    Run: php validation/gate.php
    Fail: non-zero exit code

Step 2 — Version verification
    Assert: plugin header Version: in portfolio-guard.php equals $Version
    Assert: MSP_PG_VERSION constant in portfolio-guard.php equals $Version
    Assert: Stable tag: in readme.txt equals $Version
    Assert: changelog entry = $Version = exists in readme.txt
    Fail: any assertion fails

Step 3 — Build release ZIP
    Invoke: build-wordpress-plugin-zip.ps1
        -SourceDir: portfolio-guard/
        -DestinationZip: releases/portfolio-guard/portfolio-guard-{$Version}.zip
        -ExcludePaths: tests/, scripts/, README.md
    Fail: build script exits non-zero or ZIP is not produced

Step 4 — Release package validation
    Run: php validation/release-package-test.php
        --zip releases/portfolio-guard/portfolio-guard-{$Version}.zip
        --version $Version
    Fail: non-zero exit code

Step 5 — SHA-256
    Compute SHA-256 of the ZIP
    Write: releases/portfolio-guard/portfolio-guard-{$Version}.sha256
           (single line: <hex> *portfolio-guard-{$Version}.zip)

Step 6 — Summary
    Print: artifact path, file size, SHA-256
    Exit: 0
```

If any step fails, the build exits non-zero and no artifact is written beyond what was
already produced by that step. On failure before step 5, no `.sha256` file is written.

### 8.3 Exclusion List in the Build Script

The existing `build-wordpress-plugin-zip.ps1` is extended to accept an optional
`-ExcludePaths` parameter (an array of relative path prefixes). Any file or directory
whose path under the source root begins with an excluded prefix is omitted from the ZIP.

Exclusion is prefix-based: `tests/` excludes `tests/bootstrap.php`,
`tests/SignatureRegistryTest.php`, and all other files under `tests/`. The comparison
is case-insensitive to match Windows filesystem semantics.

### 8.4 Release Artifact Layout

```
releases/
└── portfolio-guard/
    ├── portfolio-guard-2.0.0.zip
    └── portfolio-guard-2.0.0.sha256
```

Historical releases remain in their existing locations under `releases/historical/`.

---

## 9. Release Package Validation

### 9.1 `validation/release-package-test.php`

A new standalone validation script `validation/release-package-test.php` validates the
production ZIP artifact. It does not use the bootstrap and does not run as part of the
development gate. It is invoked by `build-release.ps1` in step 4.

**Invocation:**

```
php validation/release-package-test.php --zip <path> --version <version>
```

Exit codes:
- `0` — all checks passed
- `1` — one or more checks failed
- `2` — ZIP not found or could not be opened

**Required presence checks** (file or directory must exist in ZIP):

| Path in ZIP |
|---|
| `portfolio-guard/portfolio-guard.php` |
| `portfolio-guard/readme.txt` |
| `portfolio-guard/data/signatures.json` |
| `portfolio-guard/includes/class-msp-pg-plugin.php` |
| `portfolio-guard/includes/class-msp-pg-plugin-updater.php` |

**Required absence checks** (no file under these prefixes may exist in ZIP):

| Prefix |
|---|
| `portfolio-guard/tests/` |
| `portfolio-guard/scripts/` |
| `portfolio-guard/README.md` |

**Version consistency checks:**

- The plugin header `Version:` in `portfolio-guard/portfolio-guard.php` within the ZIP
  must equal the `--version` argument.
- The `Stable tag:` in `portfolio-guard/readme.txt` within the ZIP must equal the
  `--version` argument.

**Artifact quality checks:**

- No file in the ZIP has an extension of `.tmp`.
- The ZIP root contains exactly one top-level directory (`portfolio-guard/`).

### 9.2 Output Format

The script prints one line per check:

```
[PASS] ReleasePackageTest: portfolio-guard/portfolio-guard.php present
[PASS] ReleasePackageTest: portfolio-guard/tests/ absent
[FAIL] ReleasePackageTest: Version mismatch — header says "1.5.6", expected "2.0.0"
```

A summary line follows:

```
--- Release Package Validation ---
Checks: 12 / 12 passed
RESULT: PASS
```

---

## 10. Uninstall Validation

### 10.1 `UninstallTest` (Development Gate, Blocking)

A new gate runner `validation/runner/UninstallTest.php` is added to the development
gate as a blocking suite. It verifies that `MSP_PG_Plugin::uninstall()` correctly
removes every resource Portfolio Guard owns.

The test establishes a known pre-uninstall state by writing specific values for all
`msp_pg_*` options and transients, scheduling the cron events, and writing the applied
registry file and directory. It then calls `MSP_PG_Plugin::uninstall()` directly and
asserts the absence of all resources.

**Required test cases:**

1. All `msp_pg_*` options are absent after uninstall (one assertion per option key)
2. `msp_pg_scan_lock` transient is absent
3. `msp_pg_catchup_lock` transient is absent
4. `msp_pg_update_notice` transient is absent
5. `msp_pg_run_scan` is absent from scheduled events
6. `msp_pg_run_update_check` is absent from scheduled events
7. The applied registry directory does not exist after uninstall
8. The MU-loader file does not exist after uninstall
9. No `msp_pg_*` option remains in the option store

Test case 9 is a catch-all: it scans all keys in `$GLOBALS['msp_pg_test_options']` after
uninstall and fails if any key beginning with `msp_pg_` is present. This prevents future
option additions from silently escaping the uninstall method.

### 10.2 Gate Integration

`UninstallTest` is added to `validation/gate.php` as a blocking suite before the
`SyntheticBehaviorTest`. The gate total rises from 47 to 47 + N where N is the count of
test cases implemented. The gate summary line is:

```
Uninstall:             N / N passed
```

---

## 11. Upgrade Behavior

The current upgrade behavior implemented by `MSP_PG_Plugin::maybe_complete_setup()` and
`MSP_PG_Plugin::maybe_sync_mu_loader()` is correct and complete. No changes are required.

When WordPress upgrades Portfolio Guard through the native plugin update mechanism:
1. WordPress replaces the plugin directory with the new version.
2. On the next page load, `plugins_loaded` fires and `maybe_sync_mu_loader()` detects the
   version change, regenerating the MU-loader with the new version string.
3. On the `init` hook, `maybe_complete_setup()` detects the version change, reschedules
   the daily scan, and performs any necessary setup.

The applied registry at `{uploads}/portfolio-guard/signatures.json` is not in the plugin
directory and is not affected by plugin code upgrades. The Spec 007 update scheduler
continues operating normally across plugin upgrades.

No option schema migration is required for the 2.0.0 release. All options written
by pre-2.0.0 releases are compatible with the 2.0.0 implementation.

`MSP_PG_PluginUpdater` options (`msp_pg_plugin_update_last_checked`,
`msp_pg_plugin_update_cache`) are absent on sites upgrading from pre-2.0.0. The absence
of each is handled gracefully: a missing `msp_pg_plugin_update_last_checked` is treated
as 0 (triggering an immediate check on next `admin_init`), and a missing
`msp_pg_plugin_update_cache` is treated as no cached data (no update injected until a
check completes).

---

## 12. Acceptance Criteria

Milestone 4.4 is complete when all of the following are true:

**Version Identity**

1. `portfolio-guard.php` plugin header declares `Version: 2.0.0`.
2. `MSP_PG_VERSION` constant in `portfolio-guard.php` is `'2.0.0'`.
3. `readme.txt` `Stable tag:` is `2.0.0`.
4. `readme.txt` contains a `= 2.0.0 =` changelog entry covering Phase 2–4 milestones.

**Uninstall**

5. `MSP_PG_Plugin::uninstall()` removes every option listed in §6.2.
6. `MSP_PG_Plugin::uninstall()` removes every transient listed in §6.2.
7. `MSP_PG_Plugin::uninstall()` clears both scheduled events listed in §6.2.
8. `MSP_PG_Plugin::uninstall()` removes the applied registry directory.
9. `UninstallTest` passes all test cases (blocking in development gate).
10. The catch-all test (AC 10.1 case 9) confirms no `msp_pg_*` option remains after
    uninstall.

**Native Plugin Update**

11. `MSP_PG_PluginUpdater` is included in `portfolio-guard.php` and registered via
    `MSP_PG_PluginUpdater::register()`.
12. The `pre_set_site_transient_update_plugins` filter is registered and injects a
    correct update record when a newer version is available in the cache.
13. The `plugins_api` filter is registered and returns plugin information for the
    `portfolio-guard` slug.
14. `MSP_PG_PluginUpdater` makes no HTTP requests at plugin load time; all HTTP activity
    occurs on `admin_init`.
15. A non-HTTPS `download_url` in the endpoint response is rejected — it is not injected
    into the update transient.
16. Network failure during an update check does not produce an admin error or exception.
17. `MSP_PG_Config::plugin_update_url()` exists and returns the designated endpoint URL.

**Build Process**

18. `scripts/build-release.ps1` exists and accepts `-Version` and `-PhpPath` parameters.
19. `scripts/build-wordpress-plugin-zip.ps1` accepts and applies the `-ExcludePaths`
    parameter.
20. A build invoked with `-Version 2.0.0` on the current `main` produces a ZIP at
    `releases/portfolio-guard/portfolio-guard-2.0.0.zip` and exits 0.
21. The build fails (non-zero exit, no ZIP) if the development gate does not pass.
22. The build fails if any of the four version declarations do not match `-Version`.

**Release Package Validation**

23. `validation/release-package-test.php` exists, accepts `--zip` and `--version` arguments,
    and exits 0 for a correctly built 2.0.0 ZIP.
24. The script exits 1 if `portfolio-guard/tests/` is present in the ZIP.
25. The script exits 1 if `portfolio-guard/README.md` is present in the ZIP.
26. The script exits 1 if the plugin header version within the ZIP does not match
    `--version`.
27. The script is invoked automatically by `build-release.ps1` as step 4.

**Regression**

28. `php validation/gate.php` passes 47+ / 47+ tests (exit 0) after all 4.4 changes.
29. Tier 1 and Tier 2 detection behavior is unchanged. No existing test suite changes
    its pass/fail status as a result of 4.4 work.
30. Deactivation behavior is unchanged. Reactivation after deactivation restores all
    scheduled events correctly.

---

## 13. Risks

**Risk 1 — Update endpoint not yet provisioned.**  
`MSP_PG_PluginUpdater` depends on an external HTTPS endpoint to serve version
information. If the endpoint is not provisioned before the plugin ships, the native
update feature will degrade gracefully (no update notices shown, no errors surfaced), but
operators will not receive automatic notifications of new releases. The endpoint must be
provisioned before the 2.0.0 release is distributed. The implementation must not require
the endpoint to be reachable for normal plugin operation.

**Risk 2 — Option name collisions in future releases.**  
The catch-all uninstall test (§10.1 case 9) requires that every future option added by
Portfolio Guard also be added to `uninstall()`. This is a process requirement, not a
code requirement. Any new option added in a future milestone must be audited for uninstall
coverage before merge.

**Risk 3 — ZIP exclusion list divergence.**  
If development-only directories are added to the source tree without updating
`-ExcludePaths` in `build-release.ps1`, the production ZIP will contain dev artifacts.
The build process does not fail on unexpected extra files — it only enforces absence of
files in the declared exclusion list. Future milestones that add directories must
explicitly categorize them as production or development and update the exclusion list
accordingly.

**Risk 4 — WordPress version change between spec and release.**  
The `Tested up to:` field must be updated at release time to match the current WordPress
version. If the field is populated from the spec rather than verified at build time, it
may lag. The build orchestration wrapper does not verify this field — it is a manual
step. This risk is accepted; the build wrapper's version checks cover the critical
consistency requirement (plugin header = constant = stable tag), not the `Tested up to:`
field.

**Risk 5 — Plugin slug collision.**  
The `plugins_api` filter checks `$args->slug === 'portfolio-guard'`. If the plugin is
installed under a different directory name, the slug will not match. Portfolio Guard is
not a WordPress.org plugin and its slug is stable by convention, not by registry. This
is an accepted constraint of the current deployment model.

---

## 14. Implementation Order

The following order minimizes integration risk:

1. Version bump (`portfolio-guard.php` + `readme.txt`)
2. Uninstall completeness (`MSP_PG_Plugin::uninstall()`)
3. `UninstallTest` (development gate addition)
4. `MSP_PG_PluginUpdater` (new class + `MSP_PG_Config::plugin_update_url()` + wiring)
5. `build-wordpress-plugin-zip.ps1` exclusion list extension
6. `scripts/build-release.ps1` (new orchestration wrapper)
7. `validation/release-package-test.php` (new standalone validator)
8. Run `php validation/gate.php` — confirm no regression
9. Run `scripts/build-release.ps1 -Version 2.0.0` — confirm clean build and passing
   release package validation
