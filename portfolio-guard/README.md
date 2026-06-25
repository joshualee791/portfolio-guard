# MSP Portfolio Guard

`MSP Portfolio Guard` is a MainWP-deployable WordPress plugin that installs an MU-loader and safely remediates the analyzed fake-plugin malware family.

## What v1.5.6 does

- Detects this malware family from the filesystem, not the WordPress plugin inventory.
- Blocks the known GET backdoor parameter pairs and known malicious REST namespaces early via MU-loader.
- Classifies detections into:
  - `tier1`: exact IOC matches eligible for remediation
  - `tier2`: heuristic findings, report only
  - `tier3`: interesting uncertain findings, report only
- Defaults to safe mode via `PORTFOLIO_GUARD_SAFE_MODE`, which prevents deactivation, quarantine, and deletion while still allowing reporting and metadata-first evidence collection.
- Supports `PORTFOLIO_GUARD_ALLOW_TIER1_REMEDIATION` so confirmed Tier 1 malware can still be auto-remediated without disabling global safe mode.
- Supports dry-run scans that report `WOULD_*` actions without changing the filesystem or plugin state.
- Separates reports into confirmed malware, heuristic findings, and interesting findings.

## Important behavior

- False positives are prioritized as the main risk; uncertain detections do not change the site.
- Only Tier 1 exact matches are eligible for automatic remediation, and safe mode disables that by default.
- Evidence retention now defaults to `metadata_only`, which keeps `evidence.json` and reports without leaving raw malware files under `.msp-remediation`.
- Protected plugins are never auto-quarantined by heuristic detections.
- The plugin does not attempt to identify the original infection vector.
- Tier 1 override now falls back to a WordPress option, so managed hosts without `wp-config.php` access can still enable confirmed-malware remediation.
- The built-in Tier 1 registry now includes `uniserviceist-multiinfrastructure`, `miniapplicationing-protypescriptic`, and `these-middleware`.
- The built-in Tier 1 registry now also includes `macrolayer-macroflag`.
- Exact built-in matches now report `Source: Built-In Signature Registry`.
- Tier 1 live remediation now preserves and verifies evidence before quarantine/removal.
- Tier 1 and Tier 2 detections generate `evidence.json`; Tier 3 findings stay report-only and do not create artifact bundles.
- `compressed_archive` mode stores a single ZIP without extracting malware files into remediation folders; `full_artifact_retention` remains available for research/debugging only.
- Tier 3 findings are report-only and do not generate artifact bundles or artifact-location entries.
- Plain-text reports use ASCII-only formatting; HTML reports keep the visual status badges without depending on emoji encoding.

## v1.5.6 release notes

- Adds Tier 1 exact-match support for `macrolayer-macroflag` using researched hashes, domains, REST namespace, and IOC strings.
- Promotes the Portfolio Guard source tree to `analysis/portfolio-guard/` for cleaner long-term development and Git history.
- Reorganizes malware specimens, research, releases, and documentation into dedicated top-level repository areas.

## Artifact layout

- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/report.json`
- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/report.md`
- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/report.txt`
- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/artifacts/<plugin>/evidence.json`
- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/artifacts/<plugin>/report.json`
- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/artifacts/<plugin>/report.md`
- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/artifacts/<plugin>/report.txt`
- `wp-content/uploads/.msp-remediation/<site>/<timestamp>/artifacts/<plugin>/artifact.zip` (only in `compressed_archive` or `full_artifact_retention`)

## Evidence retention modes

- `metadata_only` (default): stores `evidence.json` and reports only; no raw malware files remain in `.msp-remediation`.
- `compressed_archive`: stores `evidence.json`, reports, and a single ZIP archive; archive contents are never extracted into remediation folders.
- `full_artifact_retention`: preserves the legacy snapshot/quarantine layout for research/debugging only.

## Configuration hooks

- `msp_pg_report_recipient`
- `msp_pg_artifact_base_dir`
- `msp_pg_scan_interval`
- `msp_pg_delete_tier1_enabled`
- `msp_pg_default_dry_run`
- `msp_pg_signature_version`
- `msp_pg_heuristic_version`
- `msp_pg_max_scan_file_bytes`
- `msp_pg_scan_extensions`
- `msp_pg_protected_plugin_slugs`
- `msp_pg_evidence_retention_mode`
- `msp_pg_temporary_quarantine_base_dir`
