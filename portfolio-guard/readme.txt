=== MSP Portfolio Guard ===
Contributors: MSP WebOps, Joshua Garza
Tags: MainWP, security, malware remediation, malware detection, wordpress
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Family-specific WordPress malware detection and remediation with safe mode, Tier 1 signatures, and metadata-only evidence retention.

== Description ==

MSP Portfolio Guard scans the WordPress plugins directory from the filesystem and detects known fake-plugin malware families using exact signatures and controlled heuristics.

The plugin is designed for safe operational use:

* Safe mode is enabled by default.
* Tier 1 confirmed malware can be remediated when the operator explicitly allows it.
* Metadata-only evidence retention avoids leaving executable malware copies behind in remediation storage.

Current coverage includes built-in Tier 1 signatures for the known fake-plugin family set and Atlas-ecosystem coverage for `macrolayer-macroflag`.

Key behaviors:

* filesystem-first malware detection
* tiered malware classification
* safe mode reporting without destructive action by default
* Tier 1 malware remediation workflow
* metadata-first evidence manifests and reporting
* malware intelligence corpus-informed family tracking

== Installation ==

1. Upload the `portfolio-guard` plugin folder to `/wp-content/plugins/`, or install it as a ZIP through the WordPress plugin uploader.
2. Activate `MSP Portfolio Guard` from the Plugins screen.
3. Confirm the site can write the MU-loader into `/wp-content/mu-plugins/`.
4. Review safe mode behavior before enabling live Tier 1 remediation.

== Frequently Asked Questions ==

= What does safe mode do? =

Safe mode keeps scanning, classification, and reporting active while preventing live deactivation, quarantine, and deletion by default.

= What can be auto-remediated? =

Only Tier 1 confirmed malware families are eligible for automatic remediation. Heuristic and uncertain findings remain report-first.

= What evidence is retained? =

The default retention mode is `metadata_only`, which stores evidence manifests and reports without retaining executable malware files in remediation storage.

= Does this plugin use a malware intelligence corpus? =

Yes. Portfolio Guard development and built-in family signatures are informed by preserved malware specimens and reverse-engineering research, including Atlas-ecosystem coverage.

== Changelog ==

= 2.0.0 =
* Added behavior classifier with five named profiles: Persistence, Command & Control, Payload Delivery, Operator Access, and Stealth.
* Added profile-based Tier 2 classification with signal-level explainability and per-signal file evidence.
* Added native signature registry update infrastructure with HMAC-authenticated manifests and SHA-256 registry integrity verification.
* Added engineering diagnostics page for MSP operators (hidden admin page at /wp-admin/admin.php?page=msp-pg-diagnostics).
* Added native WordPress plugin update participation through the standard admin plugin update interface.
* Completed the plugin uninstall lifecycle: all msp_pg_* options, transients, scheduled events, and generated files are removed on uninstall.
* Updated plugin to production release 2.0.0.

= 1.5.6 =
* Added standard WordPress plugin `readme.txt` metadata for release consistency.
* Added Tier 1 exact-match support for `macrolayer-macroflag`.
* Reorganized the repository to separate active source, malware corpus, research, documentation, and release artifacts.

= 1.5.5 =
* Changed default evidence retention to `metadata_only`.
* Added `evidence.json` manifest generation and evidence-mode reporting.
* Added `compressed_archive` and `full_artifact_retention` evidence modes.
* Added Tier 1 signatures for `uniserviceist-multiinfrastructure`, `miniapplicationing-protypescriptic`, and `these-middleware`.

== Upgrade Notice ==

= 2.0.0 =
Major release. Adds behavioral detection profiles, registry update infrastructure, MSP diagnostics, and native plugin update notifications. Complete uninstall cleanup is included.

= 1.5.6 =
This release adds `macrolayer-macroflag` coverage, standardizes WordPress plugin packaging metadata, and keeps versioning aligned for the initial Git baseline.
