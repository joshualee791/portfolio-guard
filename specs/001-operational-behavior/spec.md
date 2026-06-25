# Specification 001 — Operational Behavior

**Status:** Approved  
**Covers:** Milestones 1.1a, 1.1b (and the migration contract that 1.2 and 1.3 depend on)  
**Does not cover:** Scheduling algorithm (Spec 002), Tier 2 reporting content (Spec 003)

---

## 1. Purpose

This specification defines the operational contract for Portfolio Guard PC1. It replaces
the v1.5.6 behavioral model where the two diverge. Every implementation decision in
Milestones 1.1a and 1.1b must be traceable to a statement in this document.

---

## 2. Outcome Model

PC1 exposes exactly three outcomes to operators. No other outcomes exist.

| Outcome | Trigger | System Action |
|---|---|---|
| **Healthy** | No detections in scan | No action. |
| **Confirmed Malware** | Tier 1 exact match | Deactivate, preserve evidence, remove, notify MSP. |
| **Review Required** | Tier 2 behavioral match | Preserve evidence, notify MSP. No site changes. |

**Tier 3 removal:** Any plugin that would have scored Tier 3 (score >= 20, score < 100)
under the v1.5.6 model returns `null` from `detect()` and is not reported. This is a
detection scope change. No backfill of prior Tier 3 findings is performed.

**Tier labels in code:** The internal variables `tier1` and `tier2` remain. These are
detection engine internals, not operator-visible labels. Operator-visible strings are
`Confirmed Malware` and `Review Required`.

---

## 3. Remediation Model

### 3.1 Confirmed Malware (Tier 1)

Tier 1 detections are auto-remediated by default with no operator toggle.

**Remediation sequence:**
1. Deactivate the plugin if active.
2. Create evidence bundle (evidence.json, report files).
3. Verify evidence bundle.
4. If verification passes and deletion gate passes: move live plugin to temporary
   quarantine, delete temporary quarantine copy.
5. Notify MSP by email.

**Deletion gate (unchanged from v1.5.6):** Deletion requires ALL of the following:
- `msp_pg_delete_tier1_enabled` filter returns `true` (default: `true`)
- Evidence bundle verification passed
- `canDeleteOriginal` passes: `known_hash` is in `exact_match_types` OR
  `count(exact_match_types) >= 2`

The deletion gate is a safety control, not an operator mode. It remains intact.

**Dry run:** The `msp_pg_default_dry_run` filter and `PORTFOLIO_GUARD_DRY_RUN` constant
are preserved. Dry run suppresses all filesystem changes and records `WOULD_*` action
codes. This is an engineering/testing tool, not an operator mode.

### 3.2 Review Required (Tier 2)

Review Required detections are report-only. No site changes are made. Evidence is
preserved. MSP is notified. Content of the Review Required notification is defined in
Spec 003.

### 3.3 Removed: Safe Mode and Tier 1 Override

The following are removed entirely in PC1:

**Config methods removed:**
- `MSP_PG_Config::safe_mode()`
- `MSP_PG_Config::allow_tier1_remediation()`
- `MSP_PG_Config::tier1_override_option_name()`

**Constants ignored (silently):**
- `PORTFOLIO_GUARD_SAFE_MODE`
- `PORTFOLIO_GUARD_ALLOW_TIER1_REMEDIATION`

These constants may exist in MSP engineer wp-config.php files. After upgrade, they are
harmless but have no effect. This is a silent behavior change. No deprecation warning
is issued (there is no logging surface).

**`$shouldModify` simplification:**

Current v1.5.6:
```php
$shouldModify = !$reportOnly && !$dryRun && (!$safeMode || $allowTier1Remediation);
```

PC1:
```php
$shouldModify = !$reportOnly && !$dryRun;
```

**Action codes removed:**
- `SAFE_MODE_ENABLED`
- `TIER1_OVERRIDE_ENABLED`

**Action codes retained:** All others, including `WOULD_*` codes (used by dry run).

---

## 4. Plugin Lifecycle

### 4.1 Activation (fresh install)

On `register_activation_hook`:

1. Write `msp_pg_pending_activation_scan` = current timestamp (ISO 8601).
2. Write `msp_pg_version` = `MSP_PG_VERSION`.
3. **Do not seed `msp_pg_allow_tier1_remediation`.** This option is not created by PC1.

On next `init` hook (`maybe_complete_setup`):

1. If `msp_pg_version` does not match `MSP_PG_VERSION`, write `msp_pg_version`.
2. If setup is not complete (MU-loader does not exist):
   a. Create MU-loader.
   b. Register scan schedule (per Spec 002).
   c. If either fails, write error to `msp_pg_setup_notice` and return.
   d. If both succeed, delete `msp_pg_setup_notice`.

**Setup completion is defined as:** MU-loader file exists at
`MSP_PG_Config::mu_loader_path()`. This definition is unchanged from v1.5.6.

On next `admin_init` hook (`maybe_run_catchup_scan`):

1. If `msp_pg_pending_activation_scan` exists: run scan with trigger
   `activation-catchup`, delete the pending option, return.
2. Otherwise: apply normal catch-up logic (per Spec 002).

### 4.2 Activation (upgrade from v1.5.6)

On `register_activation_hook` (upgrade path — WordPress calls this on manual
re-activation):

Same as fresh install. If `msp_pg_allow_tier1_remediation` exists in the database from
v1.5.6, it is left in place as an orphaned option. It is no longer read.

On `init` (`maybe_complete_setup`):

1. Compare `msp_pg_version` to `MSP_PG_VERSION`. If different, write new version.
2. If MU-loader exists and schedule is registered, setup is complete — return.
3. If MU-loader is stale or missing, regenerate it.
4. If schedule is missing, re-register it.

**No database migration is performed on upgrade.** The `msp_pg_allow_tier1_remediation`
option remains as orphaned data until uninstall. No rename, no value conversion, no
explicit cleanup step.

On `plugins_loaded` (`maybe_sync_mu_loader`):

1. If installed version in database does not match `MSP_PG_VERSION`, or MU-loader is
   missing: regenerate MU-loader, update `msp_pg_version`.

This ensures upgrades via WordPress admin or MainWP (which do not re-trigger the
activation hook) still regenerate the MU-loader.

### 4.3 Deactivation

On `register_deactivation_hook`:

1. Clear scan schedule (all registered instances of the cron hook) using
   `wp_clear_scheduled_hook()`.
2. Remove MU-loader file.

No options are deleted on deactivation. State is preserved for re-activation.

### 4.4 Uninstall

On `register_uninstall_hook`:

1. Clear scan schedule.
2. Remove MU-loader file.
3. Delete options:
   - `msp_pg_state`
   - `msp_pg_version`
   - `msp_pg_pending_activation_scan`
   - `msp_pg_setup_notice`
   - `msp_pg_allow_tier1_remediation` ← orphaned v1.5.6 option, cleaned up here
4. Delete transients:
   - `msp_pg_scan_lock`
   - `msp_pg_catchup_lock`

Artifact files in `.msp-remediation/` are **not** deleted on uninstall. Evidence is
retained for MSP review.

### 4.5 MU-Loader

The inline MU-loader written by `ensure_mu_loader()` is the authoritative boot path.
`boot/mu-bootstrap.php` is dead code and is deleted in Milestone 1.1a.

---

## 5. Stored Option Lifecycle

| Option | Created | Updated | Deleted |
|---|---|---|---|
| `msp_pg_version` | Activation | `maybe_complete_setup` on version bump | Uninstall |
| `msp_pg_state` | First scan | Every scan completion | Uninstall |
| `msp_pg_pending_activation_scan` | Activation hook | — | After activation-catchup scan runs |
| `msp_pg_setup_notice` | Setup failure | Setup success (deleted), next failure (overwritten) | Uninstall |
| `msp_pg_allow_tier1_remediation` | Never created by PC1 | Never written by PC1 | Uninstall (cleanup only) |

| Transient | Created | Deleted |
|---|---|---|
| `msp_pg_scan_lock` | Scan start | Scan completion, deactivation |
| `msp_pg_catchup_lock` | Catch-up scan start | Catch-up scan completion, deactivation |

---

## 6. Scan Lifecycle

### 6.1 Trigger sources

| Trigger label | Source |
|---|---|
| `cron` | WP-Cron scheduled event |
| `activation-catchup` | First admin page load after activation |
| `admin-catchup` | Admin page load when scan is overdue |
| `manual` | Direct call to `MSP_PG_Remediator::run_scan()` (testing/tooling) |

### 6.2 Execution sequence

1. Acquire `msp_pg_scan_lock` transient. If already set, return `null`.
2. Run artifact retention cleanup.
3. Enumerate top-level directories in `WP_PLUGIN_DIR`.
4. For each directory (excluding `portfolio-guard` and `.msp-*` prefixed directories):
   a. Call `MSP_PG_Detector::detect()`.
   b. If `null`, skip (Healthy for this plugin).
   c. If not `null`, call `remediate_detection()`.
5. Aggregate results into scan report.
6. Write `msp_pg_state`.
7. Write scan report files and send email. (Email behavior on Healthy scans is
   addressed in Milestone 1.3.)
8. Release `msp_pg_scan_lock`.
9. Return scan report array.

### 6.3 Scan report structure changes (Milestone 1.1a)

**Removed fields:**
- `safe_mode`
- `allow_tier1_remediation`

**Fields unchanged:** all others. (Tier 3 / heuristic renaming is Milestone 1.1b.)

### 6.4 Evidence manifest changes (Milestone 1.1a)

**Removed fields:**
- `safe_mode`
- `allow_tier1_remediation`

All other fields unchanged.

---

## 7. Detection Model

The detection engine (`MSP_PG_Detector`) is **not modified in Phase 1**. The tier
classification logic, scoring weights, and signature matching are unchanged.

The threshold for Tier 2 remains: score >= 100.
The Tier 3 threshold (score >= 20) is removed from the remediator dispatch in Milestone
1.1b, not from the detector. `detect()` still calculates a score; the remediator
discards anything below Tier 2 in 1.1b.

**Rationale:** Keeping the detector unchanged in Phase 1 isolates regression risk.
Detection regressions cannot be introduced by remediation model changes.

---

## 8. State Transitions

```
[Not Installed]
      │ wp activate
      ▼
[Active]
      │ wp deactivate         │ wp uninstall
      ▼                       ▼
[Inactive]              [Not Installed]
      │ wp activate
      ▼
[Active]
```

**Active state invariants:**
- MU-loader exists at `MSP_PG_Config::mu_loader_path()`
- Scan schedule is registered
- `msp_pg_version` matches `MSP_PG_VERSION`
- `MSP_PG_Runtime::boot()` runs on every request via MU-loader

**Inactive state:**
- MU-loader does not exist
- Schedule is not registered
- Options are preserved (`msp_pg_state`, `msp_pg_version`, etc.)
- No early blocking or active plugin filtering occurs

---

## 9. Engineering Invariant — Evidence Before Deletion

**Every automatic remediation must remain explainable through retained evidence.**

This invariant is enforced by the existing deletion gate and is not relaxed in PC1:

- `LIVE_PLUGIN_REMOVED` MUST NOT appear in detection action codes unless `BUNDLE_VERIFIED`
  also appears.
- `BUNDLE_VERIFIED` MUST NOT be recorded unless `evidence.json` exists and is readable at
  `$manifestPath`.
- The deletion code path is unreachable unless `$preservationVerified === true`.

This invariant is structural: the code path from detection to deletion passes through
`$preservationVerified` unconditionally. Removing or bypassing this check is a defect,
not a simplification.

The test suite must assert this invariant for every confirmed malware detection: if
`LIVE_PLUGIN_REMOVED` is present, `BUNDLE_VERIFIED` must also be present, and
`evidence.json` must exist on disk.

---

## 10. Operator-Facing Acceptance Criteria

These criteria confirm correct behavior from the operator's perspective after deployment.

### Fresh Install

1. After plugin activation on a clean site with no malware: no remediation actions occur,
   scan state is written, MU-loader is present.
2. After plugin activation on a site containing a known malware slug: on the first admin
   page load, the malware plugin is deactivated, evidence is preserved, the live plugin
   directory is removed, and an email is sent to the report recipient.
3. The MSP email for a confirmed malware detection contains: site URL, plugin slug,
   detection confidence (`Exact Match`), evidence artifact path, and the action codes
   taken.
4. After activation, the `msp_pg_allow_tier1_remediation` option does not exist in the
   WordPress options table.

### Upgrade from v1.5.6

5. After upgrade on a site running v1.5.6 with default settings (safe mode on, Tier 1
   override on via seeded option): remediation behavior is identical to before. Confirmed
   malware is still auto-remediated.
6. After upgrade on a site running v1.5.6 with `PORTFOLIO_GUARD_SAFE_MODE = false`
   explicitly set in wp-config.php: the constant is silently ignored. Confirmed malware
   is still auto-remediated (behavior unchanged).
7. After upgrade on a site running v1.5.6 with `PORTFOLIO_GUARD_SAFE_MODE = true` and
   `PORTFOLIO_GUARD_ALLOW_TIER1_REMEDIATION = false` (i.e., remediation explicitly
   suppressed): **remediation now occurs.** This is a deliberate behavior change. MSP
   engineers using this configuration must be notified before upgrade.
8. After upgrade, `msp_pg_allow_tier1_remediation` remains in the options table as an
   orphaned value. It is not read. It is cleaned up on uninstall.

### Evidence Invariant

9. For every confirmed malware detection that results in `LIVE_PLUGIN_REMOVED`:
   `evidence.json` exists in the artifact directory and contains the plugin slug,
   detection tier, matched indicators, and remediation timestamp.

---

## 11. Resolved Questions

The following items that were open in v1.5.6 are resolved by this specification:

- **Default remediation behavior:** Tier 1 always auto-remediates. No operator toggle.
- **Safe mode:** Removed.
- **Tier 1 override:** Removed.
- **`msp_pg_allow_tier1_remediation` migration:** Orphaned; not read; deleted on uninstall only.
- **`wp_clear_scheduled_hook()`:** Replaces `wp_unschedule_event()` in `clear_scan_schedule()`.
- **`boot/mu-bootstrap.php`:** Deleted in Milestone 1.1a.
