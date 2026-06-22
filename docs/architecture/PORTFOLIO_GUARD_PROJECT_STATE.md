# Portfolio Guard Project State

Generated: 2026-06-22  
Scope: Current repository state under `c:\analysis\malware-corpus\lkandersondds`  
Audience: Senior AI engineering agent continuing development without direct repository access

## SECTION 1: EXECUTIVE SUMMARY

- `Portfolio Guard` is a WordPress security/remediation plugin focused on a specific malware family implemented as fake plugins. It scans `wp-content/plugins` from the filesystem, classifies findings into three tiers, blocks known operator entrypoints early through an MU-loader, and can auto-remediate Tier 1 confirmed malware while preserving evidence metadata and reports. `portfolio-guard/portfolio-guard.php:2`, `portfolio-guard/README.md:3`
- Current maturity is best described as a **family-specific v1.5.5 operational plugin**: it has working scheduling, reporting, retention controls, packaging artifacts, and one self-contained test harness, but no admin UI, no generic malware model, and no repository Git metadata. `portfolio-guard/portfolio-guard.php:5`, `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:30`
- Intended users are operators managing many WordPress sites, especially through MainWP-like fleet deployment, with managed hosting constraints. This is explicit in the README and implicit in the code path that seeds Tier 1 override via WordPress options instead of requiring `wp-config.php`. `portfolio-guard/README.md:3`, `portfolio-guard/includes/class-msp-pg-config.php:67`, `portfolio-guard/includes/class-msp-pg-plugin.php:29`
- Primary capabilities:
  - early request blocking for known backdoor parameter pairs and malicious REST namespaces
  - hiding known malicious plugins from active execution via `option_active_plugins`
  - filesystem-first plugin detection using signatures + heuristics
  - tiered remediation logic
  - evidence manifest/report generation
  - email scan reporting
  - automated retention cleanup  
  `portfolio-guard/includes/class-msp-pg-runtime.php:11`, `portfolio-guard/includes/class-msp-pg-detector.php:9`, `portfolio-guard/includes/class-msp-pg-remediator.php:9`
- Current deployment model:
  - source development tree in `portfolio-guard/`
  - generated single-file distributable in `dist-match-known-good/portfolio-guard/`
  - canonical ZIP packaging via `scripts/build-wordpress-plugin-zip.ps1:1`
  - plugin installs an MU-loader into `wp-content/mu-plugins` so the block logic runs before normal plugins  
  `portfolio-guard/includes/class-msp-pg-plugin.php:206`, `scripts/build-wordpress-plugin-zip.ps1:1`

## SECTION 2: ARCHITECTURE

### Directory Structure

- `portfolio-guard/`
  - `portfolio-guard.php` main plugin bootstrap
  - `boot/mu-bootstrap.php` alternate/legacy MU bootstrap file
  - `includes/` class files
  - `tests/` self-contained PHP test harness
  - `README.md` developer/operator summary  
  `portfolio-guard/portfolio-guard.php:2`, `portfolio-guard/boot/mu-bootstrap.php:12`
- `dist/portfolio-guard-0.1.3/` older multi-file packaged artifact
- `dist-onefile/portfolio-guard/` older bundled single-file artifact
- `dist-match-known-good/portfolio-guard/` current packaged single-file artifact plus WordPress-style readme TXT
- `scripts/build-wordpress-plugin-zip.ps1` canonical ZIP builder
- `malware-corpus/specimens/fake-plugin-ecosystem/known-families/` preserved malware specimen corpus used to derive the family model

### Plugin Bootstrap Flow

1. WordPress loads `portfolio-guard.php`.
2. The file defines version/path constants and requires all core class files.
3. Activation, deactivation, and uninstall hooks are registered.
4. `MSP_PG_Plugin::instance()` wires runtime hooks.
5. On setup, the plugin writes an MU-loader into `WPMU_PLUGIN_DIR`.
6. On future requests, the MU-loader requires the plugin main file and calls `MSP_PG_Runtime::boot()`.  
   `portfolio-guard/portfolio-guard.php:11`, `portfolio-guard/includes/class-msp-pg-plugin.php:20`, `portfolio-guard/includes/class-msp-pg-plugin.php:206`, `portfolio-guard/boot/mu-bootstrap.php:12`

### Initialization Sequence

- `MSP_PG_Plugin::__construct()` registers:
  - `init` → `maybe_complete_setup`
  - `msp_pg_run_scan` cron hook → `run_cron_scan`
  - `admin_init` → `maybe_run_catchup_scan`
  - `plugins_loaded` → `maybe_sync_mu_loader`
  - `admin_notices` → `render_setup_notice`  
  `portfolio-guard/includes/class-msp-pg-plugin.php:20`
- `MSP_PG_Runtime::boot()` is idempotent and:
  - adds `option_active_plugins` filter
  - immediately blocks known entrypoints if present in the current request  
  `portfolio-guard/includes/class-msp-pg-runtime.php:11`

### Data Flow

1. Scan trigger enters `MSP_PG_Remediator::run_scan()`.
2. It gathers site metadata, runtime config, and retention-cleanup results.
3. It enumerates top-level plugin directories under `WP_PLUGIN_DIR`.
4. Each directory is passed to `MSP_PG_Detector::detect()`.
5. Non-null analyses go to `remediate_detection()`.
6. Remediation produces:
  - detection result array
  - optional artifact directory
  - optional `evidence.json`
  - per-artifact reports
7. Scan aggregates counts and groups into `confirmed_malware`, `heuristic_findings`, `interesting_findings`.
8. State is written to `msp_pg_state`.
9. Scan report files are written and an HTML email is sent.  
   `portfolio-guard/includes/class-msp-pg-remediator.php:9`, `portfolio-guard/includes/class-msp-pg-detector.php:9`, `portfolio-guard/includes/class-msp-pg-remediator.php:453`

### Scheduled Tasks / WP-Cron

- Cron hook name: `msp_pg_run_scan`. `portfolio-guard/includes/class-msp-pg-config.php:19`
- Default recurrence: `hourly`, filterable via `msp_pg_scan_interval`. `portfolio-guard/includes/class-msp-pg-config.php:39`
- `schedule_scan()` attempts:
  1. recurring event at `time() + 60`
  2. fallback single event at `time() + 300`
  3. admin catch-up scans if cron registration fails  
  `portfolio-guard/includes/class-msp-pg-plugin.php:152`
- `maybe_run_catchup_scan()` runs from `admin_init` for admins if:
  - pending activation scan exists, or
  - last scan is stale beyond interval  
  `portfolio-guard/includes/class-msp-pg-plugin.php:62`

### MU-Plugin Interactions

- Current active MU strategy is generated inline by `ensure_mu_loader()`, not by copying `boot/mu-bootstrap.php`. `portfolio-guard/includes/class-msp-pg-plugin.php:206`
- Loader points at `WP_PLUGIN_DIR/<installed-folder>/portfolio-guard.php`.
- MU-loaded runtime:
  - blocks known backdoor GET parameter pairs
  - blocks malicious REST namespaces
  - filters known malware slugs out of `active_plugins` before normal plugin execution  
  `portfolio-guard/includes/class-msp-pg-runtime.php:19`, `portfolio-guard/includes/class-msp-pg-runtime.php:43`
- `boot/mu-bootstrap.php` exists but is not referenced by `ensure_mu_loader()`, so it appears to be an alternate or vestigial boot path. `portfolio-guard/boot/mu-bootstrap.php:7`, `portfolio-guard/includes/class-msp-pg-plugin.php:206`

### MainWP Interactions

- There is no direct MainWP API integration in the codebase.
- “MainWP-deployable” is a deployment assumption reflected in documentation, packaging, and the protected-plugin list containing `mainwp-child`. `portfolio-guard/README.md:3`, `portfolio-guard/includes/class-msp-pg-config.php:188`
- Operationally, Portfolio Guard is a normal plugin intended to be pushed to child sites; it self-installs its MU-loader locally.

### Major Classes

#### `MSP_PG_Config` — central configuration registry

- Purpose: expose all runtime knobs, defaults, option names, hooks, and artifact paths. `portfolio-guard/includes/class-msp-pg-config.php:7`
- Responsibilities:
  - reporting recipient
  - cron hook / schedule / interval
  - safe mode, dry run, Tier 1 override
  - artifact base dir and retention mode
  - MU-loader paths
  - protected plugin list
  - scoring weights and thresholds
- Key methods:
  - `safe_mode()` `portfolio-guard/includes/class-msp-pg-config.php:49`
  - `allow_tier1_remediation()` `portfolio-guard/includes/class-msp-pg-config.php:67`
  - `artifact_base_dir()` `portfolio-guard/includes/class-msp-pg-config.php:104`
  - `evidence_retention_mode()` `portfolio-guard/includes/class-msp-pg-config.php:116`
  - `protected_plugin_slugs()` `portfolio-guard/includes/class-msp-pg-config.php:178`
  - `score_weights()` `portfolio-guard/includes/class-msp-pg-config.php:194`
  - `score_thresholds()` `portfolio-guard/includes/class-msp-pg-config.php:215`
- Dependencies: WordPress option/filter APIs and path helpers.

#### `MSP_PG_Signatures` — hardcoded knowledge registry

- Purpose: define the malware family variants and shared indicators. `portfolio-guard/includes/class-msp-pg-signatures.php:7`
- Responsibilities:
  - store variant registry
  - expose known hashes, routes, backdoor param/token pairs, domains, filenames, primary plugin files
  - expose heuristic markers
- Key methods:
  - `family()` `portfolio-guard/includes/class-msp-pg-signatures.php:9`
  - `known_hashes()` `portfolio-guard/includes/class-msp-pg-signatures.php:144`
  - `route_namespaces()` `portfolio-guard/includes/class-msp-pg-signatures.php:157`
  - `backdoor_pairs()` `portfolio-guard/includes/class-msp-pg-signatures.php:165`
  - `heuristic_markers()` `portfolio-guard/includes/class-msp-pg-signatures.php:188`
  - `known_primary_plugin_files()` `portfolio-guard/includes/class-msp-pg-signatures.php:231`
  - `variant_by_slug()` `portfolio-guard/includes/class-msp-pg-signatures.php:244`
- Dependencies: `MSP_PG_Config::family_name()`.

#### `MSP_PG_Utils` — filesystem, hashing, zipping, reporting helpers

- Purpose: support detector/remediator with portable utility functions. `portfolio-guard/includes/class-msp-pg-utils.php:5`
- Responsibilities:
  - path joining and normalization
  - recursive file enumeration
  - hashing, directory metrics, fingerprints
  - directory move/copy/zip
  - random payload structure detection
  - markdown/plaintext/html report rendering
  - action code to description mapping
  - cleanup summary formatting
- Key methods:
  - `recursive_files()` `portfolio-guard/includes/class-msp-pg-utils.php:36`
  - `directory_counts()` `portfolio-guard/includes/class-msp-pg-utils.php:87`
  - `directory_fingerprint()` `portfolio-guard/includes/class-msp-pg-utils.php:117`
  - `random_payload_structure()` `portfolio-guard/includes/class-msp-pg-utils.php:218`
  - `markdown_report()` `portfolio-guard/includes/class-msp-pg-utils.php:246`
  - `plain_text_report()` `portfolio-guard/includes/class-msp-pg-utils.php:305`
  - `html_report()` `portfolio-guard/includes/class-msp-pg-utils.php:373`
  - `describe_action()` `portfolio-guard/includes/class-msp-pg-utils.php:508`
- Dependencies: WordPress path/json helpers and `ZipArchive`.

#### `MSP_PG_Detector` — per-plugin analyzer/classifier

- Purpose: inspect one plugin directory and return either `null` or a scored analysis object. `portfolio-guard/includes/class-msp-pg-detector.php:7`
- Responsibilities:
  - apply exact signatures
  - apply heuristic scoring
  - attach reasons and indicators
  - assign tier, confidence, source
  - compute `variant_hash`
- Key methods:
  - `detect()` `portfolio-guard/includes/class-msp-pg-detector.php:9`
  - `add_reason()` `portfolio-guard/includes/class-msp-pg-detector.php:220`
  - `variant_hash()` `portfolio-guard/includes/class-msp-pg-detector.php:234`
- Dependencies: `MSP_PG_Signatures`, `MSP_PG_Config`, `MSP_PG_Utils`.

#### `MSP_PG_Runtime` — early request-time blocking layer

- Purpose: stop known operator access paths before malware can run. `portfolio-guard/includes/class-msp-pg-runtime.php:7`
- Responsibilities:
  - boot once
  - filter active plugin list
  - block known GET backdoors
  - block known REST namespaces
- Key methods:
  - `boot()` `portfolio-guard/includes/class-msp-pg-runtime.php:11`
  - `filter_known_active_plugins()` `portfolio-guard/includes/class-msp-pg-runtime.php:24`
  - `block_known_entrypoints()` `portfolio-guard/includes/class-msp-pg-runtime.php:43`
  - `deny()` `portfolio-guard/includes/class-msp-pg-runtime.php:62`
- Dependencies: `MSP_PG_Signatures`, WordPress headers/hooks.

#### `MSP_PG_Remediator` — scan orchestration and evidence pipeline

- Purpose: run scans, remediate detections, preserve evidence, write/send reports. `portfolio-guard/includes/class-msp-pg-remediator.php:7`
- Responsibilities:
  - lock scan execution
  - enumerate plugins
  - invoke detector
  - invoke remediation logic
  - write reports
  - send HTML emails
  - perform retention cleanup
- Key methods:
  - `run_scan()` `portfolio-guard/includes/class-msp-pg-remediator.php:9`
  - `remediate_detection()` `portfolio-guard/includes/class-msp-pg-remediator.php:109`
  - `artifact_markdown()` `portfolio-guard/includes/class-msp-pg-remediator.php:422`
  - `write_scan_report()` `portfolio-guard/includes/class-msp-pg-remediator.php:453`
  - `send_scan_report()` `portfolio-guard/includes/class-msp-pg-remediator.php:460`
  - `cleanup_expired_artifacts()` `portfolio-guard/includes/class-msp-pg-remediator.php:527`
- Dependencies: `MSP_PG_Config`, `MSP_PG_Utils`, `MSP_PG_Detector`, WordPress plugin/email APIs.

#### `MSP_PG_Plugin` — plugin lifecycle and scheduling coordinator

- Purpose: connect plugin lifecycle to setup, loader management, and scanning. `portfolio-guard/includes/class-msp-pg-plugin.php:7`
- Responsibilities:
  - activation/deactivation/uninstall
  - setup completion
  - scheduling
  - MU-loader creation/removal
  - admin catch-up scans
  - setup warning notice
- Key methods:
  - `activate()` `portfolio-guard/includes/class-msp-pg-plugin.php:29`
  - `deactivate()` `portfolio-guard/includes/class-msp-pg-plugin.php:38`
  - `uninstall()` `portfolio-guard/includes/class-msp-pg-plugin.php:44`
  - `maybe_run_catchup_scan()` `portfolio-guard/includes/class-msp-pg-plugin.php:62`
  - `maybe_complete_setup()` `portfolio-guard/includes/class-msp-pg-plugin.php:92`
  - `maybe_sync_mu_loader()` `portfolio-guard/includes/class-msp-pg-plugin.php:127`
  - `schedule_scan()` `portfolio-guard/includes/class-msp-pg-plugin.php:152`
  - `ensure_mu_loader()` `portfolio-guard/includes/class-msp-pg-plugin.php:206`
- Dependencies: `MSP_PG_Config`, `MSP_PG_Remediator`, `MSP_PG_Utils`, WP cron/options.

## SECTION 3: DETECTION ENGINE

### Detection Methodologies Implemented

- Exact signature matching
- File hash matching
- Directory/filename matching
- IOC content matching for domains and routes
- Backdoor parameter/token triplet matching
- Structural family pattern matching
- Weighted heuristic scoring for suspicious behavior markers  
  `portfolio-guard/includes/class-msp-pg-detector.php:9`

### Signature System

- The registry is fully hardcoded in `MSP_PG_Signatures::family()`. `portfolio-guard/includes/class-msp-pg-signatures.php:9`
- Each variant can define:
  - `slug`
  - `main_file`
  - `hashes`
  - `domains`
  - `routes`
  - `backdoors`
  - `ioc_strings`  
  `portfolio-guard/includes/class-msp-pg-signatures.php:13`
- Exact signature entrypoints exposed by helper methods:
  - hashes → `known_hashes()` `portfolio-guard/includes/class-msp-pg-signatures.php:144`
  - routes → `route_namespaces()` `portfolio-guard/includes/class-msp-pg-signatures.php:157`
  - backdoor pairs → `backdoor_pairs()` `portfolio-guard/includes/class-msp-pg-signatures.php:165`
  - primary files → `known_primary_plugin_files()` `portfolio-guard/includes/class-msp-pg-signatures.php:231`
  - relative filenames → `known_relative_filenames()` `portfolio-guard/includes/class-msp-pg-signatures.php:221`

### Heuristic System

- Weight table in config: `known_hash` 100, `known_route` 75, `known_auth_cookie_impersonation_pattern` 50, `suspicious_remote_javascript` 10, etc. `portfolio-guard/includes/class-msp-pg-config.php:194`
- Thresholds:
  - `tier2` >= 100
  - `tier3` >= 20  
  `portfolio-guard/includes/class-msp-pg-config.php:215`
- Reasons are deduplicated by key and summed into `score`. `portfolio-guard/includes/class-msp-pg-detector.php:220`

### IOC System

- Exact IOC types currently used:
  - known plugin directory
  - known malware relative filename
  - known primary plugin file
  - known file hash
  - known IOC domain
  - known malware route  
  `portfolio-guard/includes/class-msp-pg-detector.php:51`, `portfolio-guard/includes/class-msp-pg-detector.php:67`, `portfolio-guard/includes/class-msp-pg-detector.php:73`, `portfolio-guard/includes/class-msp-pg-detector.php:84`, `portfolio-guard/includes/class-msp-pg-detector.php:109`, `portfolio-guard/includes/class-msp-pg-detector.php:118`
- Backdoor pair detection checks for simultaneous presence of:
  - ID param
  - token param
  - token value  
  inside scanned file contents. `portfolio-guard/includes/class-msp-pg-detector.php:123`
- Structural IOC system:
  - random 5–6 char directories
  - random 8-char PHP payload names
  - `assets/<8char>.js`  
  `portfolio-guard/includes/class-msp-pg-utils.php:218`

### Confidence Scoring / Tier Classifications

- If any exact match type exists, the plugin is immediately `tier1`. `portfolio-guard/includes/class-msp-pg-detector.php:199`
- Otherwise:
  - `score >= tier2 threshold` → `tier2`
  - `score >= tier3 threshold` → `tier3`
  - else return `null`  
  `portfolio-guard/includes/class-msp-pg-detector.php:201`
- Confidence labels:
  - `tier1` → `Exact Match`
  - `tier2` → `Strong Heuristic`
  - `tier3` → `Interesting`  
  `portfolio-guard/includes/class-msp-pg-detector.php:213`
- Detection source labels:
  - `tier1` → `Built-In Signature Registry`
  - others → `Behavioral / Heuristic Analysis`  
  `portfolio-guard/includes/class-msp-pg-detector.php:214`

### False Positive Mitigation

- Filesystem-only scanning is restricted to selected extensions and a max size of 2 MB. `portfolio-guard/includes/class-msp-pg-config.php:94`, `portfolio-guard/includes/class-msp-pg-config.php:99`
- Tier 2 and Tier 3 are non-destructive by design. `portfolio-guard/includes/class-msp-pg-remediator.php:141`, `portfolio-guard/includes/class-msp-pg-remediator.php:340`
- Protected plugin list prevents heuristic auto-remediation of critical/common plugins. `portfolio-guard/includes/class-msp-pg-config.php:178`
- Safe mode defaults to true at config level. `portfolio-guard/includes/class-msp-pg-config.php:49`
- Tier 1 deletion requires either:
  - `known_hash`, or
  - at least two exact match types  
  `portfolio-guard/includes/class-msp-pg-remediator.php:117`
- Preservation verification must succeed before live removal. `portfolio-guard/includes/class-msp-pg-remediator.php:296`, `portfolio-guard/includes/class-msp-pg-remediator.php:307`

### Detection Mechanisms: Trigger, Internals, Limitations

#### Known plugin directory

- Trigger: slug matches a registry key.
- Internals: `variant_by_slug($slug)` non-null → add exact type + 100 weight.
- Limitation: exact slug matching is narrow and family-specific.  
  `portfolio-guard/includes/class-msp-pg-detector.php:13`, `portfolio-guard/includes/class-msp-pg-detector.php:49`

#### Known relative payload filename

- Trigger: file path matches one of four hardcoded payload filenames.
- Internals: compares normalized relative path against `known_relative_filenames()`.
- Limitation: only four payload filenames are modeled.  
  `portfolio-guard/includes/class-msp-pg-detector.php:65`, `portfolio-guard/includes/class-msp-pg-signatures.php:221`

#### Known primary plugin file

- Trigger: top-level PHP filename equals a known main file.
- Internals: basename lookup against `known_primary_plugin_files()`.
- Limitation: assumes malware preserves the main file naming convention.  
  `portfolio-guard/includes/class-msp-pg-detector.php:71`

#### Known hash

- Trigger: SHA-256 of any scanned file matches registry.
- Internals: hash lookup via `known_hashes()`.
- Limitation: hashes are currently present only for the first four families, not the three newer ones.  
  `portfolio-guard/includes/class-msp-pg-detector.php:79`, `portfolio-guard/includes/class-msp-pg-signatures.php:102`

#### Known domain / route

- Trigger: file contents contain a known domain or route namespace.
- Internals: raw substring search.
- Limitation: no contextual validation; any embedded occurrence becomes an exact match.  
  `portfolio-guard/includes/class-msp-pg-detector.php:105`, `portfolio-guard/includes/class-msp-pg-detector.php:114`

#### Known auth-cookie impersonation pattern

- Trigger: a file contains the backdoor ID param, token param, and token value.
- Internals: three-string conjunction.
- Limitation: this adds heuristic weight but does not itself become an `exact_match_type`.  
  `portfolio-guard/includes/class-msp-pg-detector.php:123`

#### Known family bootstrap pattern

- Trigger: presence of `fastreactic_nanomicroserviceing`, `tridatation_quicktypescriptal`, or `data-ph-pid`.
- Internals: raw substring search, +50 weight.
- Limitation: implemented inline rather than via the `heuristic_markers()` registry.  
  `portfolio-guard/includes/class-msp-pg-detector.php:138`

#### Structural family pattern

- Trigger: random short subdir + random short PHP file.
- Internals: `random_payload_structure()` plus one reason.
- Limitation: broad enough to overlap with some benign obfuscated plugins.  
  `portfolio-guard/includes/class-msp-pg-utils.php:218`, `portfolio-guard/includes/class-msp-pg-detector.php:56`

#### Generic behavioral heuristics

- Triggers: suspicious remote JS, custom REST namespace, AJAX handlers, remote requests, cookie manipulation, dynamic script registration.
- Internals: simple substring checks, small additive weights.
- Limitation: these are generic WordPress behaviors and need exact-match absence + thresholds to stay non-destructive.  
  `portfolio-guard/includes/class-msp-pg-detector.php:147`, `portfolio-guard/includes/class-msp-pg-detector.php:154`, `portfolio-guard/includes/class-msp-pg-detector.php:157`, `portfolio-guard/includes/class-msp-pg-detector.php:161`, `portfolio-guard/includes/class-msp-pg-detector.php:169`, `portfolio-guard/includes/class-msp-pg-detector.php:177`

### Important Implementation Gaps in Detection

- `MSP_PG_Signatures::exact_ioc_strings()` exists but is not used by the detector. `portfolio-guard/includes/class-msp-pg-signatures.php:178`
- `$heuristics = MSP_PG_Signatures::heuristic_markers();` is assigned in `detect()` but not used; the actual heuristic checks are hardcoded later in the method. `portfolio-guard/includes/class-msp-pg-detector.php:15`

## SECTION 4: REMEDIATION ENGINE

### Report-Only Behavior

- `tier2` and `tier3` set `reportOnly = true` because `reportOnly` is defined as any tier other than `tier1`. `portfolio-guard/includes/class-msp-pg-remediator.php:114`
- Tier 2:
  - generates evidence/report artifacts
  - never changes site state
  - action codes include `HEURISTIC_FINDING_IDENTIFIED`, `REPORT_ONLY_NO_CHANGES`, `HEURISTIC_REPORT_ONLY`  
  `portfolio-guard/includes/class-msp-pg-remediator.php:141`, `portfolio-guard/includes/class-msp-pg-remediator.php:340`
- Tier 3:
  - report-only
  - not bundle-eligible
  - no artifact directory generation
  - action code `INTERESTING_REPORT_ONLY`  
  `portfolio-guard/includes/class-msp-pg-remediator.php:116`, `portfolio-guard/includes/class-msp-pg-remediator.php:344`

### Quarantine Behavior

- In `full_artifact_retention`, the live plugin directory is moved into the artifact tree under `artifacts/<slug>/quarantine/<slug>`. `portfolio-guard/includes/class-msp-pg-remediator.php:313`
- In `metadata_only` and `compressed_archive`, the live plugin directory is moved into a temporary system directory under `sys_get_temp_dir()/msp-portfolio-guard-quarantine/...`, then immediately deleted. `portfolio-guard/includes/class-msp-pg-config.php:124`, `portfolio-guard/includes/class-msp-pg-remediator.php:321`
- Action code `QUARANTINE_COMPLETED` is used for both persistent and temporary quarantine. `portfolio-guard/includes/class-msp-pg-remediator.php:316`, `portfolio-guard/includes/class-msp-pg-remediator.php:329`

### Removal Behavior

- Live removal only occurs for Tier 1 when all are true:
  - `shouldModify` is true
  - `msp_pg_delete_tier1_enabled` is true
  - preservation verified
  - delete gate passes (`known_hash` or 2+ exact matches)  
  `portfolio-guard/includes/class-msp-pg-remediator.php:311`
- Live removal is recorded by action `LIVE_PLUGIN_REMOVED`. `portfolio-guard/includes/class-msp-pg-remediator.php:317`, `portfolio-guard/includes/class-msp-pg-remediator.php:331`

### File Handling Logic

- Scan ignores:
  - the `portfolio-guard` plugin itself
  - directories beginning `.msp-`  
  `portfolio-guard/includes/class-msp-pg-remediator.php:31`
- Evidence bundle eligibility:
  - Tier 1 and Tier 2 only. `portfolio-guard/includes/class-msp-pg-remediator.php:116`
- Evidence modes:
  - `metadata_only`: manifest + reports only
  - `compressed_archive`: manifest + reports + ZIP from live directory
  - `full_artifact_retention`: snapshot copy + ZIP + retained quarantine  
  `portfolio-guard/includes/class-msp-pg-remediator.php:267`, `portfolio-guard/includes/class-msp-pg-remediator.php:274`
- Verification checks manifest/report existence and, if applicable, readable ZIP. `portfolio-guard/includes/class-msp-pg-remediator.php:296`

### Recovery Logic

- `full_artifact_retention` provides the only built-in rollback-style retained quarantine copy.
- `metadata_only` and `compressed_archive` preserve metadata or archive but do not retain a live restorable plugin directory in `.msp-remediation`; the temporary quarantine dir is deleted immediately after move.  
  `portfolio-guard/includes/class-msp-pg-remediator.php:313`, `portfolio-guard/includes/class-msp-pg-remediator.php:321`
- MU-runtime suppression of known slugs also reduces the chance that a still-present plugin executes before remediation. `portfolio-guard/includes/class-msp-pg-runtime.php:24`

### Safety Controls

- Safe mode gate: `shouldModify = !$reportOnly && !$dryRun && (!$safeMode || $allowTier1Remediation)`. `portfolio-guard/includes/class-msp-pg-remediator.php:115`
- Dry-run adds `WOULD_*` actions instead of filesystem changes. `portfolio-guard/includes/class-msp-pg-remediator.php:156`, `portfolio-guard/includes/class-msp-pg-remediator.php:189`, `portfolio-guard/includes/class-msp-pg-remediator.php:347`
- Protected plugins are report-only for non-Tier-1 detections. `portfolio-guard/includes/class-msp-pg-remediator.php:160`
- Evidence must verify before deletion. `portfolio-guard/includes/class-msp-pg-remediator.php:296`
- Delete gate requires strong exactness. `portfolio-guard/includes/class-msp-pg-remediator.php:117`

### Step-by-Step Remediation Execution Flow

1. `run_scan()` acquires a transient lock. `portfolio-guard/includes/class-msp-pg-remediator.php:11`
2. It loads state, config, site metadata, and cleanup results. `portfolio-guard/includes/class-msp-pg-remediator.php:21`
3. It enumerates top-level plugin directories. `portfolio-guard/includes/class-msp-pg-remediator.php:31`
4. Each plugin is analyzed by `MSP_PG_Detector::detect()`. `portfolio-guard/includes/class-msp-pg-remediator.php:40`
5. For each detection:
   1. Determine `reportOnly`, `shouldModify`, `bundleEligible`, `canDeleteOriginal`, evidence mode. `portfolio-guard/includes/class-msp-pg-remediator.php:114`
   2. Add identification action codes and warnings. `portfolio-guard/includes/class-msp-pg-remediator.php:137`
   3. Deactivate active plugins if allowed. `portfolio-guard/includes/class-msp-pg-remediator.php:164`
   4. Calculate file counts, directory counts, size, fingerprint. `portfolio-guard/includes/class-msp-pg-remediator.php:176`
   5. Build manifest/report structures. `portfolio-guard/includes/class-msp-pg-remediator.php:201`
   6. Create archive or snapshot if the evidence mode requires it and `shouldModify` is true. `portfolio-guard/includes/class-msp-pg-remediator.php:267`
   7. Write `evidence.json`, artifact `report.json`, `report.md`, `report.txt`. `portfolio-guard/includes/class-msp-pg-remediator.php:287`
   8. Verify preservation. `portfolio-guard/includes/class-msp-pg-remediator.php:296`
   9. If Tier 1 + allowed + verified + deletion gate:
      - move to persistent or temporary quarantine
      - optionally delete temporary quarantine
      - mark live removal complete  
      `portfolio-guard/includes/class-msp-pg-remediator.php:311`
   10. Rewrite final remediation status into manifest and artifact report. `portfolio-guard/includes/class-msp-pg-remediator.php:352`
6. Aggregate results into scan report. `portfolio-guard/includes/class-msp-pg-remediator.php:58`
7. Update `msp_pg_state`. `portfolio-guard/includes/class-msp-pg-remediator.php:92`
8. Write scan report files and send HTML email. `portfolio-guard/includes/class-msp-pg-remediator.php:100`

## SECTION 5: EVIDENCE RETENTION

### Current Retention Modes

- `metadata_only`
- `compressed_archive`
- `full_artifact_retention`  
  `portfolio-guard/includes/class-msp-pg-config.php:116`

### Default Retention Mode

- Default is `metadata_only` via `msp_pg_evidence_retention_mode` fallback. `portfolio-guard/includes/class-msp-pg-config.php:118`

### `evidence.json` Structure

- Built in `remediate_detection()` and finalized after action resolution. `portfolio-guard/includes/class-msp-pg-remediator.php:201`, `portfolio-guard/includes/class-msp-pg-remediator.php:352`
- Current fields include:
  - `family`
  - `classification`
  - `tier`
  - `confidence`
  - `source`
  - `action`
  - `detected_at`
  - `plugin_slug`
  - `file_count`
  - `directory_count`
  - `total_bytes`
  - `sha256_directory_fingerprint`
  - `evidence_retention_mode`
  - `variant_fingerprint`
  - `detection_tier`
  - `score`
  - `reasons`
  - `safe_mode`
  - `allow_tier1_remediation`
  - `dry_run`
  - `site_url`
  - `detection_timestamp`
  - `wordpress_version`
  - `php_version`
  - `active_plugins`
  - `active_theme`
  - `protected_plugin`
  - `exact_match_types`
  - `matched_indicators`
  - `payload_hashes`
  - `hashes`
  - `domains`
  - `routes`
  - `backdoor_indicators`
  - `structural_indicators`
  - `signature_version`
  - `heuristic_version`
  - final `remediation_status`  
  `portfolio-guard/includes/class-msp-pg-remediator.php:201`, `portfolio-guard/includes/class-msp-pg-remediator.php:354`

### Metadata-Only Behavior

- No raw malware copy is retained in `.msp-remediation`.
- Evidence consists of:
  - `evidence.json`
  - `report.json`
  - `report.md`
  - `report.txt`
- Live plugin removal uses temporary quarantine in system temp, then deletes that temp copy.  
  `portfolio-guard/includes/class-msp-pg-remediator.php:296`, `portfolio-guard/includes/class-msp-pg-remediator.php:321`
- The included test explicitly asserts no `artifact.zip`, `snapshot`, `quarantine`, `.php`, or `.js` remain in the artifact directory for default mode. `portfolio-guard/tests/SignatureRegistryTest.php:64`

### Archive Behavior

- `compressed_archive`
  - creates `artifact.zip` directly from the live plugin directory before removal
  - does not extract archive contents into `.msp-remediation`  
  `portfolio-guard/includes/class-msp-pg-remediator.php:267`
- `full_artifact_retention`
  - copies the live plugin into `snapshot/`
  - zips the artifact dir
  - moves live plugin into retained `quarantine/` on successful deletion path  
  `portfolio-guard/includes/class-msp-pg-remediator.php:274`, `portfolio-guard/includes/class-msp-pg-remediator.php:313`

### Cleanup Behavior

- Retention cleanup runs at scan startup, not via separate cron. `portfolio-guard/includes/class-msp-pg-remediator.php:28`
- Deletes entire scan directories older than `artifact_retention_days()`; default 7 days. `portfolio-guard/includes/class-msp-pg-config.php:223`, `portfolio-guard/includes/class-msp-pg-remediator.php:544`
- When not in `full_artifact_retention`, it also scrubs legacy `snapshot` and `quarantine` directories under existing artifact trees. `portfolio-guard/includes/class-msp-pg-remediator.php:550`
- Cleanup results are included in reports through `cleanup_summary()`. `portfolio-guard/includes/class-msp-pg-utils.php:571`

### Retention Schedule

- Trigger: every scan invocation.
- Not a separate maintenance job.
- Applies to both cron-triggered and admin-triggered scans.  
  `portfolio-guard/includes/class-msp-pg-remediator.php:28`

### Visible Implementation Rationale

- The README and current defaults show a clear pivot away from retaining raw malware because of downstream scanner noise. `portfolio-guard/README.md:22`, `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:30`
- The code preserves enough metadata to identify the removed family and variant while minimizing executable artifacts left under uploads.

## SECTION 6: MALWARE KNOWLEDGE MODEL

### Known Family Name

- Global family label: `wordpress-shared-plugin-framework`. `portfolio-guard/includes/class-msp-pg-config.php:9`

### Families in Registry

1. `laravel-janet` `portfolio-guard/includes/class-msp-pg-signatures.php:14`
2. `framework-triappment` `portfolio-guard/includes/class-msp-pg-signatures.php:36`
3. `platformist-quadendpointer` `portfolio-guard/includes/class-msp-pg-signatures.php:58`
4. `smartrestal-serverful` `portfolio-guard/includes/class-msp-pg-signatures.php:80`
5. `uniserviceist-multiinfrastructure` `portfolio-guard/includes/class-msp-pg-signatures.php:102`
6. `miniapplicationing-protypescriptic` `portfolio-guard/includes/class-msp-pg-signatures.php:113`
7. `these-middleware` `portfolio-guard/includes/class-msp-pg-signatures.php:124`

### Shared Indicators / Patterns

- Known domains:
  - `opertoraza.com`
  - `juioprtexi.com`
  - `kiloporotolimo.com`  
  `portfolio-guard/includes/class-msp-pg-signatures.php:212`
- Known route namespaces:
  - `framework-triappment-xk30rc/v1`
  - `platformist-quadendpointer-sxadtr/v1`  
  `portfolio-guard/includes/class-msp-pg-signatures.php:157`
- Known relative payload filenames:
  - `wqugu3/s1wwptag.php`
  - `yhwb11/d5wffaqd.php`
  - `3paddm/5auul2bn.php`
  - `1ch325/1xjh0u6z.php`  
  `portfolio-guard/includes/class-msp-pg-signatures.php:221`
- Heuristic markers include:
  - auth/session markers: `wp_set_auth_cookie(`, `wp_safe_redirect(`, `/wp-admin`
  - network markers: `/api/config/`, `/api/click`, `wp_remote_request(`, `data-ph-pid`
  - shared family markers: `add_filter('all_plugins'`, `add_action('template_redirect'`, `permission_callback' => '__return_true'`, `fastreactic_nanomicroserviceing`, `tridatation_quicktypescriptal`  
  `portfolio-guard/includes/class-msp-pg-signatures.php:188`
- Structural naming convention:
  - random short subdirectory
  - random 8-char PHP payload
  - random 8-char JS in `assets/`  
  `portfolio-guard/includes/class-msp-pg-utils.php:218`

### Per-Family Model

#### `laravel-janet`

- Detection logic: exact slug, exact main file, two known hashes, domain `opertoraza.com`, one backdoor param/token set, IOC strings including `wqugu3/s1wwptag.php`. `portfolio-guard/includes/class-msp-pg-signatures.php:14`
- Remediation logic: generic Tier 1 flow.
- Confidence: Tier 1 exact when any exact IOC hits.

#### `framework-triappment`

- Detection logic: exact slug/main file, two hashes, domain `juioprtexi.com`, route namespace, backdoor pair, IOC strings including `data-ph-pid`, `yhwb11/d5wffaqd.php`. `portfolio-guard/includes/class-msp-pg-signatures.php:36`
- Remediation logic: generic Tier 1 flow.
- Confidence: Tier 1 exact.

#### `platformist-quadendpointer`

- Detection logic: exact slug/main file, two hashes, domain `kiloporotolimo.com`, route namespace, backdoor pair, IOC strings including `data-ph-pid`, `3paddm/5auul2bn.php`. `portfolio-guard/includes/class-msp-pg-signatures.php:58`
- Remediation logic: generic Tier 1 flow.
- Confidence: Tier 1 exact.

#### `smartrestal-serverful`

- Detection logic: exact slug/main file, two hashes, domain `opertoraza.com`, backdoor pair, IOC strings including `template_redirect`, `fastreactic_nanomicroserviceing`, `1ch325/1xjh0u6z.php`. `portfolio-guard/includes/class-msp-pg-signatures.php:80`
- Remediation logic: generic Tier 1 flow.
- Confidence: Tier 1 exact.

#### `uniserviceist-multiinfrastructure`

- Detection logic: exact slug/main file; no hashes/domains/routes/backdoors currently defined. `portfolio-guard/includes/class-msp-pg-signatures.php:102`
- Remediation logic: generic Tier 1 flow.
- Confidence: Tier 1 exact if slug or primary plugin file matches.

#### `miniapplicationing-protypescriptic`

- Detection logic: exact slug/main file only. `portfolio-guard/includes/class-msp-pg-signatures.php:113`
- Remediation logic: generic Tier 1 flow.
- Confidence: Tier 1 exact.

#### `these-middleware`

- Detection logic: exact slug/main file only. `portfolio-guard/includes/class-msp-pg-signatures.php:124`
- Remediation logic: generic Tier 1 flow.
- Confidence: Tier 1 exact.

### Remediation Logic Across Families

- There is no family-specific remediation branching after detection.
- All Tier 1 variants use the same deactivation/preservation/verification/removal pipeline. `portfolio-guard/includes/class-msp-pg-remediator.php:311`

## SECTION 7: CONFIGURATION SYSTEM

### Runtime Constants

- `PORTFOLIO_GUARD_SAFE_MODE` → overrides safe mode. `portfolio-guard/includes/class-msp-pg-config.php:49`
- `PORTFOLIO_GUARD_DRY_RUN` → default dry-run mode. `portfolio-guard/includes/class-msp-pg-config.php:58`
- `PORTFOLIO_GUARD_ALLOW_TIER1_REMEDIATION` → overrides Tier 1 remediation allowance. `portfolio-guard/includes/class-msp-pg-config.php:67`

### Filter-Based Configuration

- `msp_pg_report_recipient` default from the WordPress `admin_email` option, with optional `msp_pg_report_recipient` override `portfolio-guard/includes/class-msp-pg-config.php:14`
- `msp_pg_scan_interval` default `hourly` `portfolio-guard/includes/class-msp-pg-config.php:39`
- `msp_pg_delete_tier1_enabled` default `true` `portfolio-guard/includes/class-msp-pg-config.php:44`
- `msp_pg_safe_mode` default `true` `portfolio-guard/includes/class-msp-pg-config.php:55`
- `msp_pg_allow_tier1_remediation` default from stored option `portfolio-guard/includes/class-msp-pg-config.php:76`
- `msp_pg_default_dry_run` default `false` `portfolio-guard/includes/class-msp-pg-config.php:63`
- `msp_pg_signature_version` default `2026-06-05.1` `portfolio-guard/includes/class-msp-pg-config.php:84`
- `msp_pg_heuristic_version` default `2026-06-03.2` `portfolio-guard/includes/class-msp-pg-config.php:89`
- `msp_pg_max_scan_file_bytes` default `2*1024*1024` `portfolio-guard/includes/class-msp-pg-config.php:94`
- `msp_pg_scan_extensions` default `php/js/json/txt` `portfolio-guard/includes/class-msp-pg-config.php:99`
- `msp_pg_artifact_base_dir` uploads-based default `portfolio-guard/includes/class-msp-pg-config.php:104`
- `msp_pg_evidence_retention_mode` default `metadata_only` `portfolio-guard/includes/class-msp-pg-config.php:116`
- `msp_pg_temporary_quarantine_base_dir` default `sys_get_temp_dir()/msp-portfolio-guard-quarantine` `portfolio-guard/includes/class-msp-pg-config.php:124`
- `msp_pg_protected_plugin_slugs` default list `portfolio-guard/includes/class-msp-pg-config.php:178`
- `msp_pg_score_weights` default array `portfolio-guard/includes/class-msp-pg-config.php:194`
- `msp_pg_score_thresholds` default array `portfolio-guard/includes/class-msp-pg-config.php:215`
- `msp_pg_artifact_retention_days` default `7` `portfolio-guard/includes/class-msp-pg-config.php:223`

### Stored Options

- `msp_pg_state` — scan state, last scan timestamp/result. `portfolio-guard/includes/class-msp-pg-config.php:24`
- `msp_pg_allow_tier1_remediation` — stored Tier 1 override fallback. `portfolio-guard/includes/class-msp-pg-config.php:79`
- `msp_pg_pending_activation_scan` — activation catch-up flag. `portfolio-guard/includes/class-msp-pg-config.php:168`
- `msp_pg_setup_notice` — admin notice message. `portfolio-guard/includes/class-msp-pg-config.php:173`
- `msp_pg_version` — installed plugin version. `portfolio-guard/includes/class-msp-pg-plugin.php:32`

### Stored Transients

- `msp_pg_scan_lock` — scan mutex. `portfolio-guard/includes/class-msp-pg-config.php:29`
- `msp_pg_catchup_lock` — admin catch-up mutex. `portfolio-guard/includes/class-msp-pg-config.php:34`

### Admin Settings

- There is **no settings page, settings API integration, or menu UI** in the current codebase.
- The only admin-facing UI is an error notice for setup/scheduling problems. `portfolio-guard/includes/class-msp-pg-plugin.php:138`

### Important Behavioral Nuance

- Config default for `allow_tier1_remediation()` is false if no constant and no stored option. `portfolio-guard/includes/class-msp-pg-config.php:73`
- But activation and setup seed the stored option to `true` if missing. `portfolio-guard/includes/class-msp-pg-plugin.php:33`, `portfolio-guard/includes/class-msp-pg-plugin.php:94`
- So the effective post-install behavior is typically:
  - safe mode enabled
  - Tier 1 override enabled via option
- This differs from the simpler “safe mode disables remediation by default” phrasing in the README. `portfolio-guard/README.md:21`

## SECTION 8: REPORTING

### Email Reporting

- Scan email subject format: `[MSP Portfolio Guard] <count> detections on <site_url>`. `portfolio-guard/includes/class-msp-pg-remediator.php:462`
- Recipient: `MSP_PG_Config::report_recipient()`. `portfolio-guard/includes/class-msp-pg-remediator.php:503`
- Email content type forced to HTML via `html_mail_content_type()`. `portfolio-guard/includes/class-msp-pg-remediator.php:509`
- HTML report includes:
  - site/timestamp/trigger
  - safe mode / Tier 1 override / evidence mode
  - executive summary
  - confirmed/heuristic/interesting sections
  - evidence status
  - artifact locations  
  `portfolio-guard/includes/class-msp-pg-utils.php:373`
- A plaintext `$body` array is assembled in `send_scan_report()` but not actually sent. `portfolio-guard/includes/class-msp-pg-remediator.php:468`

### Scan Reporting

- Scan-level files written under `.msp-remediation/<site>/<timestamp>/`:
  - `report.json`
  - `report.md`
  - `report.txt`  
  `portfolio-guard/includes/class-msp-pg-remediator.php:453`
- Reports include:
  - site metadata
  - trigger
  - safe mode / override / retention mode / dry run
  - cleanup summary
  - grouped detections
  - warnings/errors  
  `portfolio-guard/includes/class-msp-pg-utils.php:246`, `portfolio-guard/includes/class-msp-pg-utils.php:305`, `portfolio-guard/includes/class-msp-pg-utils.php:373`

### Evidence Reporting

- Per-detection artifact files (Tier 1 and Tier 2):
  - `evidence.json`
  - `report.json`
  - `report.md`
  - `report.txt`
  - `artifact.zip` only in archive/full-retention modes  
  `portfolio-guard/includes/class-msp-pg-remediator.php:121`
- Artifact markdown includes remediation status, manifest path, archive path, size/fingerprint, actions, matched indicators, domains, and routes. `portfolio-guard/includes/class-msp-pg-remediator.php:422`

### Administrative Notifications

- Setup warning notice is stored in `msp_pg_setup_notice` and rendered as an admin error notice. `portfolio-guard/includes/class-msp-pg-plugin.php:138`
- Trigger conditions:
  - MU-loader creation/update failure
  - scan scheduling failure  
  `portfolio-guard/includes/class-msp-pg-plugin.php:110`
- Runtime blocks emit `do_action('msp_pg_runtime_blocked', $reason)` but no persistent log is stored by default. `portfolio-guard/includes/class-msp-pg-runtime.php:69`

## SECTION 9: VERSION HISTORY

### Source of History

- There is no `.git` directory in the workspace, so Git history is not available here.
- History must be inferred from:
  - `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt`
  - older packaged artifacts in `dist/` and `dist-onefile/`

### Observed Milestones

- **0.1.3** — multi-file plugin packaging exists in `dist/portfolio-guard-0.1.3/`, with evidence-first quarantine/bundling language and simpler two-tier framing. `dist/portfolio-guard-0.1.3/portfolio-guard.php:5`, `dist/portfolio-guard-0.1.3/README.md:5`
- **0.1.4** — single-file bundle exists in `dist-onefile/portfolio-guard/`, indicating a packaging/bundling shift while functionality remained similar. `dist-onefile/portfolio-guard/portfolio-guard.php:5`
- **1.5 (2026-06-03)** — major operational pivot:
  - safe mode + Tier 1 override
  - cron fallback + admin catch-up
  - 7-day cleanup
  - human-readable action descriptions
  - stricter deletion gate
  - Tier 3 artifacts disabled  
  `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:57`
- **1.5.1** — fixed Tier 1 override reaching the final deletion gate and report wording. `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:53`
- **1.5.2** — Tier 3 artifact suppression fixed, plaintext/HTML formatting stabilized, safe-mode messaging cleaned up. `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:48`
- **1.5.3** — Tier 1 override moved from pure constant-based control to include stored option fallback for managed hosts. `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:44`
- **1.5.4** — malware registry expanded with three new families; built-in source labeling and self-contained PHP test harness added. `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:37`
- **1.5.5** — major evidence-retention pivot:
  - metadata-only default
  - `evidence.json`
  - compressed archive / full artifact modes
  - cleanup scrubbing of legacy executable artifacts
  - report wording updated around retention  
  `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:30`

### Visible Architectural Pivots

- Multi-file source → one-file distributable packaging
- Quarantine-as-retention → metadata-first evidence retention
- Constant-based control → option fallback for managed hosting
- simpler tier model → explicit Tier 1 / Tier 2 / Tier 3 with safe-mode and dry-run semantics
- basic reporting → grouped HTML/markdown/plaintext reports with action-code translation

## SECTION 10: TECHNICAL DEBT

- **No Git metadata in repo**
  - There is no `.git` directory, so change history, blame, and commit context are absent in the current workspace.
- **Documentation/runtime mismatch on default Tier 1 remediation**
  - README says safe mode disables automatic remediation by default, but activation/setup seed `msp_pg_allow_tier1_remediation = true`, making confirmed Tier 1 remediation effectively enabled on fresh installs unless explicitly overridden. `portfolio-guard/README.md:21`, `portfolio-guard/includes/class-msp-pg-plugin.php:33`
- **Unused registry surfaces**
  - `exact_ioc_strings()` exists but is unused.
  - `heuristic_markers()` is defined, and `detect()` fetches it, but detection logic does not iterate the structure; markers are hardcoded inline. `portfolio-guard/includes/class-msp-pg-signatures.php:178`, `portfolio-guard/includes/class-msp-pg-detector.php:15`
- **Uneven family depth**
  - The three newer families have slug/main-file detection only; no hashes, routes, domains, or backdoor pairs are modeled for them. `portfolio-guard/includes/class-msp-pg-signatures.php:102`
- **Recovery semantics depend heavily on evidence mode**
  - `QUARANTINE_COMPLETED` can mean either a retained quarantine copy (`full_artifact_retention`) or a temporary move that is immediately deleted (`metadata_only` / `compressed_archive`). `portfolio-guard/includes/class-msp-pg-remediator.php:313`, `portfolio-guard/includes/class-msp-pg-remediator.php:321`
- **Tier 2 archive mode behavior is incomplete**
  - Evidence archive creation is gated by `shouldModify`, so Tier 2 detections in `compressed_archive` or `full_artifact_retention` mode do not actually produce an archive/snapshot even though the retention mode suggests it. `portfolio-guard/includes/class-msp-pg-remediator.php:267`
- **“Bundle verified” is existence/readability verification, not cryptographic validation**
  - Verification checks file creation/readability, plus ZIP presence if applicable; it does not validate archive contents against stored hashes. `portfolio-guard/includes/class-msp-pg-remediator.php:296`
- **Scheduling cleanup only unschedules one timestamp**
  - `clear_scan_schedule()` calls `wp_unschedule_event()` on the first `wp_next_scheduled()` result only. `portfolio-guard/includes/class-msp-pg-plugin.php:198`
- **Email implementation ignores assembled plaintext body**
  - `send_scan_report()` builds a text body array but always sends the HTML report only. `portfolio-guard/includes/class-msp-pg-remediator.php:468`
- **Test coverage is narrow**
  - Only one self-contained test exists, and it covers three newer Tier 1 families plus default metadata-only evidence behavior. There are no repository-native tests for:
    - original four families
    - runtime blocking
    - cron scheduling
    - report rendering
    - safe mode / override permutations  
    `portfolio-guard/tests/SignatureRegistryTest.php:7`
- **No admin configuration surface**
  - All controls are constants, filters, or stored internal options; there is no operator UI for status, mode changes, or diagnostics.
- **Unused `boot/mu-bootstrap.php`**
  - Current MU-loader creation writes inline PHP and does not use the `boot/` file, leaving an apparent stale boot path in the repo. `portfolio-guard/boot/mu-bootstrap.php:7`, `portfolio-guard/includes/class-msp-pg-plugin.php:206`
- **Build pipeline is only partially captured**
  - ZIP creation is scripted, but single-file bundling from source to `dist-match-known-good` is not represented by a committed build script in the repository.

## SECTION 11: ROADMAP RECOMMENDATIONS

Prioritized by operational value, based on the current code:

- **1. Stabilize runtime semantics**
  - Reconcile README/default-behavior language with the seeded Tier 1 override option.
  - Clarify whether fresh installs should truly auto-remediate confirmed malware.
- **2. Expand test coverage**
  - Add tests for:
    - original four families
    - runtime backdoor blocking
    - safe mode vs Tier 1 override combinations
    - `compressed_archive` and `full_artifact_retention`
    - retention cleanup behavior
    - scheduling fallback behavior
- **3. Document the build/deployment pipeline**
  - Capture the missing source → bundled single-file build step.
  - Document which artifact is canonical for deployment: source tree vs `dist-match-known-good`.
- **4. Normalize the knowledge model**
  - Bring the newer three families up to the same signature depth, or explicitly document them as slug/main-file-only families.
  - Either remove or wire in unused signature registry elements.
- **5. Add operator diagnostics**
  - Even a read-only status surface would help expose:
    - last scan time
    - last cleanup
    - effective safe mode
    - effective Tier 1 override source
    - evidence retention mode
- **6. Stabilize archive/report semantics**
  - Align Tier 2 artifact behavior with configured evidence modes, or document the current report-only/metadata-only behavior more explicitly.
- **7. Add source control**
  - The workspace has no Git metadata; introducing version control would materially improve maintainability and future AI handoffs.

## SECTION 12: AI_HANDOFF

### AI_ENGINEER_HANDOFF

- Current canonical version is `1.5.5`. Source bootstrap: `portfolio-guard/portfolio-guard.php:5`. Shipping packaged readme: `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt:30`.
- Development happens in the multi-file source tree under `portfolio-guard/`.
- Shipping/deploy artifact is the bundled single-file plugin under `dist-match-known-good/portfolio-guard/`, paired with `portfolio-guard-readme.txt`.
- ZIP packaging is handled by `scripts/build-wordpress-plugin-zip.ps1:1`. The repo does **not** contain a committed script for source-to-single-file bundling.
- Core runtime split:
  - `MSP_PG_Plugin` = lifecycle/setup/scheduling
  - `MSP_PG_Runtime` = early MU-loader blocking
  - `MSP_PG_Detector` = per-plugin classification
  - `MSP_PG_Remediator` = scan orchestration + artifacts + email
  - `MSP_PG_Signatures` = hardcoded knowledge base
  - `MSP_PG_Config` = defaults and overrides
  - `MSP_PG_Utils` = filesystem/report helpers
- The MU-loader is installed automatically by `MSP_PG_Plugin::ensure_mu_loader()` and points directly to the plugin main file under `WP_PLUGIN_DIR/<folder>/portfolio-guard.php`. `portfolio-guard/includes/class-msp-pg-plugin.php:206`
- `boot/mu-bootstrap.php` exists but is not used by the inline MU-loader generation path.
- Scan entrypoint is `MSP_PG_Remediator::run_scan($trigger, $args)`. It:
  1. acquires `msp_pg_scan_lock`
  2. does cleanup
  3. enumerates top-level plugin directories
  4. calls `MSP_PG_Detector::detect()`
  5. remediates each detection
  6. writes scan reports
  7. emails HTML report
  8. updates `msp_pg_state`  
  `portfolio-guard/includes/class-msp-pg-remediator.php:9`
- Detection model:
  - exact match if **any** of these hit:
    - known slug
    - known relative filename
    - known primary plugin file
    - known hash
    - known domain
    - known route
  - otherwise weighted heuristic score with thresholds:
    - Tier 2 >= 100
    - Tier 3 >= 20  
    `portfolio-guard/includes/class-msp-pg-detector.php:199`, `portfolio-guard/includes/class-msp-pg-config.php:215`
- Confidence labels:
  - Tier 1 = `Exact Match`
  - Tier 2 = `Strong Heuristic`
  - Tier 3 = `Interesting`
- Detection source:
  - Tier 1 = `Built-In Signature Registry`
  - else = `Behavioral / Heuristic Analysis`
- Known families:
  - `laravel-janet`
  - `framework-triappment`
  - `platformist-quadendpointer`
  - `smartrestal-serverful`
  - `uniserviceist-multiinfrastructure`
  - `miniapplicationing-protypescriptic`
  - `these-middleware`  
  `portfolio-guard/includes/class-msp-pg-signatures.php:14`
- First four families have richer signatures: hashes/domains/routes/backdoor pairs. Newer three are slug/main-file-centric only.
- Runtime blocking:
  - filters `option_active_plugins` to strip all known malware slugs
  - blocks requests containing known backdoor param/token pairs
  - blocks REST calls into known malicious namespaces
  - emits `do_action('msp_pg_runtime_blocked', $reason)` before exit  
  `portfolio-guard/includes/class-msp-pg-runtime.php:19`
- Remediation semantics:
  - Tier 1 only can modify site state.
  - Tier 2 and Tier 3 are report-only.
  - Tier 2 is still bundle-eligible and writes evidence manifests/reports.
  - Tier 3 is not bundle-eligible.
- Deletion gate for Tier 1:
  - must pass `shouldModify`
  - `msp_pg_delete_tier1_enabled` true
  - preservation verified
  - exactness gate = `known_hash` OR 2+ exact match types  
  `portfolio-guard/includes/class-msp-pg-remediator.php:117`, `portfolio-guard/includes/class-msp-pg-remediator.php:311`
- `shouldModify` currently equals:
  - not report-only
  - not dry-run
  - and either safe mode is off or Tier 1 override is on  
  `portfolio-guard/includes/class-msp-pg-remediator.php:115`
- Evidence retention modes:
  - `metadata_only` default
  - `compressed_archive`
  - `full_artifact_retention`
- Default evidence behavior (`metadata_only`):
  - writes `evidence.json`, `report.json`, `report.md`, `report.txt`
  - no raw malware copy in `.msp-remediation`
  - live plugin moved to temp quarantine then temp dir deleted
- `compressed_archive`:
  - creates `artifact.zip` from live plugin dir
  - does not extract malware files into remediation folders
- `full_artifact_retention`:
  - copies plugin into `snapshot/`
  - zips artifact tree
  - retains live moved copy under `quarantine/`
- Cleanup:
  - runs at scan start
  - deletes scan dirs older than 7 days by default
  - additionally scrubs legacy `snapshot`/`quarantine` dirs when evidence mode is not `full_artifact_retention`
- Important stored state:
  - `msp_pg_state`
  - `msp_pg_allow_tier1_remediation`
  - `msp_pg_pending_activation_scan`
  - `msp_pg_setup_notice`
  - `msp_pg_version`
- Important transients:
  - `msp_pg_scan_lock`
  - `msp_pg_catchup_lock`
- Important filter names:
  - `msp_pg_report_recipient`
  - `msp_pg_scan_interval`
  - `msp_pg_delete_tier1_enabled`
  - `msp_pg_safe_mode`
  - `msp_pg_allow_tier1_remediation`
  - `msp_pg_default_dry_run`
  - `msp_pg_signature_version`
  - `msp_pg_heuristic_version`
  - `msp_pg_max_scan_file_bytes`
  - `msp_pg_scan_extensions`
  - `msp_pg_artifact_base_dir`
  - `msp_pg_evidence_retention_mode`
  - `msp_pg_temporary_quarantine_base_dir`
  - `msp_pg_protected_plugin_slugs`
  - `msp_pg_score_weights`
  - `msp_pg_score_thresholds`
  - `msp_pg_artifact_retention_days`
- Important constants:
  - `PORTFOLIO_GUARD_SAFE_MODE`
  - `PORTFOLIO_GUARD_DRY_RUN`
  - `PORTFOLIO_GUARD_ALLOW_TIER1_REMEDIATION`
- Important nuance: activation/setup seeds `msp_pg_allow_tier1_remediation = true` if missing. This means fresh installs likely run with safe mode on **and** Tier 1 override on unless explicitly changed. `portfolio-guard/includes/class-msp-pg-plugin.php:33`, `portfolio-guard/includes/class-msp-pg-plugin.php:94`
- Tests:
  - only `portfolio-guard/tests/SignatureRegistryTest.php`
  - self-contained, not PHPUnit
  - validates the three newer families in `metadata_only` mode
  - stubs WordPress APIs in `tests/bootstrap.php`
- Missing/incomplete areas another AI should know immediately:
  - no Git metadata in repo
  - `boot/mu-bootstrap.php` is unused by current loader path
  - `exact_ioc_strings()` and `heuristic_markers()` are not fully wired into live detection logic
  - Tier 2 archive/full-retention behavior is inconsistent with evidence mode naming
  - plaintext email body is built but unused
  - build pipeline to generate `dist-match-known-good/portfolio-guard.php` is not represented by a committed script
- Historical artifacts worth consulting:
  - `dist/portfolio-guard-0.1.3/` for early multi-file design
  - `dist-onefile/portfolio-guard/` for early one-file bundle
  - `dist-match-known-good/portfolio-guard/portfolio-guard-readme.txt` for version/changelog timeline
  - `malware-corpus/specimens/fake-plugin-ecosystem/known-families/` specimen corpus for the original four families
