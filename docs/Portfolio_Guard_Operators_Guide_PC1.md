# Portfolio Guard Operator's Guide

**Version 2.0.0 — Production Candidate 1**

My Social Practice — Internal Distribution

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Installing Portfolio Guard](#2-installing-portfolio-guard)
3. [Daily Operation](#3-daily-operation)
4. [Understanding Results](#4-understanding-results)
5. [Updating Portfolio Guard](#5-updating-portfolio-guard)
6. [Signature Updates vs. Plugin Updates](#6-signature-updates-vs-plugin-updates)
7. [Creating a New Release (Internal Maintainers)](#7-creating-a-new-release-internal-maintainers)
8. [Uninstalling Portfolio Guard](#8-uninstalling-portfolio-guard)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Introduction

### What Portfolio Guard Is

Portfolio Guard is a WordPress security plugin deployed across the MSP fleet. It continuously monitors managed sites for a specific family of malware that embeds itself into WordPress installations under the guise of a legitimate plugin.

When Portfolio Guard detects a known threat, it removes it automatically. When it detects something suspicious but inconclusive, it flags it for operator review. In both cases, it sends an email report.

Portfolio Guard is designed to run quietly in the background. On a healthy site, operators will see nothing. Alerts are reserved for sites that need attention.

### What It Protects Against

Portfolio Guard specifically targets malware distributed as `wordpress-shared-plugin-framework` and its known variants. This malware family is characterized by:

- Installing itself as a plugin that mimics a legitimate shared framework
- Establishing persistent access mechanisms
- Attempting to evade detection and removal

Portfolio Guard maintains a signature registry that is updated automatically as new variants are identified. No operator action is required to receive new signatures.

### What It Does Not Do

Portfolio Guard is a **targeted** security tool, not a general-purpose malware scanner.

It does not scan for arbitrary malware, viruses, or compromised themes. It does not monitor user accounts, file permissions, or database integrity. It does not replace broader site security practices such as strong passwords, up-to-date WordPress core, or firewall rules.

> **Note:** Portfolio Guard is one layer of a defense-in-depth approach. It excels at detecting and removing the specific threat family it was built for.

---

## 2. Installing Portfolio Guard

### Before You Begin

Confirm the following before installing:

| Requirement | Minimum |
|---|---|
| WordPress version | 5.0 or later |
| PHP version | 7.4 or later |
| Administrator access | Required |

### Step-by-Step Installation

**1. Obtain the release package.**

Retrieve `portfolio-guard-2.0.0.zip` from the internal release repository. Do not install ZIP files from any other source.

**2. Navigate to the WordPress Plugins screen.**

In the WordPress Admin, go to **Plugins → Add New Plugin**.

**3. Upload the plugin.**

Click **Upload Plugin**, then **Choose File**. Select `portfolio-guard-2.0.0.zip` and click **Install Now**.

**4. Activate the plugin.**

Once the upload completes, click **Activate Plugin**.

### What to Expect After Activation

Portfolio Guard performs a catch-up scan immediately on first activation if the daily scan time has already passed today. Otherwise, the first scheduled scan will run at **6:00 AM** tomorrow.

Within the first few minutes, you may see a brief admin notice confirming activation. This clears automatically.

### Verifying Successful Installation

To confirm Portfolio Guard is running correctly:

1. Go to **Plugins → Installed Plugins**.
2. Confirm **MSP Portfolio Guard** is listed and active.
3. In the WordPress Admin menu, locate the **Portfolio Guard** diagnostics page (under Settings or Tools, depending on your setup).
4. The diagnostics page should display the current plugin version (2.0.0), the status of the signature registry, and the next scheduled scan time.

> **Expected:** Signature registry source shows **installed**, registry version is current, next scan is scheduled.

If the diagnostics page shows a warning, see [Section 9: Troubleshooting](#9-troubleshooting).

---

## 3. Daily Operation

Portfolio Guard is designed to require minimal operator involvement on healthy sites.

### Scheduled Scans

Portfolio Guard scans the site automatically at **6:00 AM** each day. This timing is fixed and does not require operator configuration.

Scans run as a WordPress background job (wp-cron). If no visitors load the site around 6:00 AM, the scan will run on the next page load after that time.

> **Note for server-cron users:** If your hosting environment runs a true system-level cron job to trigger WordPress scheduled tasks, scans will fire reliably at 6:00 AM regardless of site traffic. This is the recommended configuration for MSP-managed sites.

### Automatic Signature Updates

Every six hours, Portfolio Guard checks the signature update server for new malware intelligence. If a newer signature registry is available, it downloads, verifies, and applies it automatically.

**No operator action is required.** Signature updates are silent and do not affect the site in any noticeable way.

The diagnostics page shows the current registry version and when the last update was checked.

### Plugin Updates

When a new version of Portfolio Guard itself is available, a standard WordPress update notice will appear on the Plugins screen — the same notification you see for any WordPress plugin.

Plugin updates are installed through the normal WordPress update workflow. See [Section 5: Updating Portfolio Guard](#5-updating-portfolio-guard) for details.

### Email Reports

Portfolio Guard sends an email notification when a scan produces a result that requires attention — specifically, when malware is found and removed, or when something suspicious is flagged for review.

**No email is sent for clean scans.** Silence means the site is healthy.

Reports are sent to the address configured in the plugin settings. Verify this address is set to an actively monitored inbox for each deployment.

### Operator Responsibilities

On a day-to-day basis, Portfolio Guard asks very little:

| Responsibility | Frequency |
|---|---|
| Review email alerts | When received |
| Check diagnostics page if concerns arise | As needed |
| Apply plugin updates when available | When notified |
| Verify scan schedule if site has unusual hosting setup | At installation |

---

## 4. Understanding Results

Every scan produces one of three outcomes.

---

### Healthy

**What it means:** No malware was detected.

**Operator action required:** None.

Portfolio Guard will not send an email for a healthy scan. No admin notice will appear. The site is clean.

---

### Confirmed Malware

**What it means:** Portfolio Guard identified a known malware plugin and removed it automatically.

**Operator action required:** Review the email report. No manual removal is needed — Portfolio Guard has already handled it.

> **Important:** Portfolio Guard removes the malware plugin, but it cannot reverse any changes the malware may have made to the database, user accounts, or remote systems during the time it was active. After a confirmed malware detection, a broader site review is appropriate.

**What the email report includes:**

- Which plugin was identified as malicious
- When it was detected
- Confirmation that it was removed

**Recommended next steps:**

1. Review WordPress user accounts for unauthorized additions.
2. Check for any scheduled posts or modified content that may have been created by the malicious plugin.
3. Consider rotating API keys and application passwords if the malware was active for more than a brief period.
4. Verify the report with the site owner if appropriate.

---

### Review Required

**What it means:** Portfolio Guard detected behavior that matches patterns associated with malware, but the match is not strong enough to act automatically. The finding is reported for human review.

**Operator action required:** Evaluate the flagged plugin and decide whether it is legitimate or should be removed.

> **Note:** A Review Required result is not a confirmed threat. It is a flag. The plugin in question may be entirely legitimate.

**What the email report includes:**

- Which plugin was flagged
- A plain-language description of why it was flagged
- The specific behavior pattern that triggered the review

**How to evaluate a flagged plugin:**

1. Look up the plugin in the WordPress Plugin Directory or by searching online.
2. Check whether it is an expected plugin on this site.
3. Contact the site owner to ask whether they installed or authorized the plugin.
4. If the plugin is unknown or unexpected, remove it manually and monitor the site.

---

## 5. Updating Portfolio Guard

### How Plugin Updates Work

When a new version of Portfolio Guard is available, WordPress will display an update notice in two places:

- On the **Dashboard → Updates** screen
- On the **Plugins → Installed Plugins** screen, next to MSP Portfolio Guard

This works the same as updating any other WordPress plugin.

### Step-by-Step Update

**1. Go to Plugins → Installed Plugins.**

Locate **MSP Portfolio Guard** in the list. If an update is available, you will see a notice beneath the plugin name.

**2. Click "Update now".**

WordPress will download the new version, install it, and reactivate the plugin automatically.

**3. Confirm the update.**

After installation, the version number shown in the plugin list should reflect the new version. You can also verify on the Portfolio Guard diagnostics page.

> **Note:** Plugin updates are checked every 12 hours. If you expect an update to be available but do not see a notice, wait up to 12 hours for the cache to refresh, or visit the diagnostics page and check the "Plugin Update" section.

### What Is Preserved Across Plugin Updates

WordPress plugin updates replace the plugin files. All Portfolio Guard configuration and state is stored in the WordPress database, not in the plugin files. This means:

- Your report recipient email address is preserved
- Scan history is preserved
- The signature registry and update history are preserved

No reconfiguration is needed after an update.

---

## 6. Signature Updates vs. Plugin Updates

This distinction is important for day-to-day operation.

### Two Separate Systems

Portfolio Guard uses two independent update mechanisms. Operators frequently ask why these are separate — the answer is that they serve different purposes and operate on different timescales.

---

### Plugin Updates

| | |
|---|---|
| **What updates** | Portfolio Guard itself — new features, bug fixes, behavior changes |
| **How often** | Infrequently; only when a new version is released |
| **How delivered** | Through the WordPress Plugins screen (same as any plugin) |
| **Operator action** | Click "Update now" when notified |

---

### Signature Updates

| | |
|---|---|
| **What updates** | The malware intelligence registry — new threat signatures and detection patterns |
| **How often** | Frequently; checked every 6 hours |
| **How delivered** | Automatically, in the background |
| **Operator action** | None required |

---

### Why They Are Separate

Malware signatures need to be updated frequently as new variants emerge. Bundling signatures into the plugin would mean a new plugin release every time a new variant is identified — which is impractical and would require operator action for every intelligence update.

By separating them, signature intelligence can be pushed to all sites automatically, while plugin updates remain a deliberate operator action that is applied at a controlled pace.

> **Practical implication:** A site running Portfolio Guard 2.0.0 from six months ago can still have fully current malware intelligence, because signature updates happen continuously. The plugin version and the registry version are independent.

---

## 7. Creating a New Release (Internal Maintainers)

This section is intended for the engineers or MSP staff responsible for releasing Portfolio Guard updates. It does not require knowledge of the underlying implementation.

### Prerequisites

Before creating a release, confirm:

- You have access to the Portfolio Guard engineering repository.
- PHP 8.4 or later is installed and available on your workstation.
- The PHP `zip` extension is enabled. To verify, run:
  ```
  php -r "echo class_exists('ZipArchive') ? 'OK' : 'MISSING';"
  ```
  If the output is `MISSING`, see [Troubleshooting: PHP zip extension not available](#php-zip-extension-not-available).
- PowerShell 5.1 or later is available (Windows workstation).

### Release Workflow

**Step 1: Update the version number.**

The version must be updated in the following locations:

- Plugin file header (`Version:` field in `portfolio-guard/portfolio-guard.php`)
- Version constant in the same file (`MSP_PG_VERSION`)
- `Stable tag:` line in `portfolio-guard/readme.txt`
- Changelog entry in `portfolio-guard/readme.txt` (add a new `= X.Y.Z =` section)

All four values must match exactly. If they do not, the build will fail at the version verification step.

**Step 2: Run the release build.**

From the `portfolio-guard/scripts/` directory:

```powershell
.\build-release.ps1 -Version X.Y.Z -PhpPath "path\to\php.exe"
```

Replace `X.Y.Z` with the new version number. Replace the PHP path with the path to your PHP executable if it is not on your system PATH.

**Step 3: Review build output.**

The build script will report progress for each step:

```
=== Portfolio Guard Release Build vX.Y.Z ===

Step 0 -- Environment verification
  OK: PHP 8.5.x
  OK: ZipArchive extension available

Step 1 -- Development gate
  [PASS] ...67 tests...
  RESULT: PASS

Step 2 -- Version verification
  OK: header=X.Y.Z, constant=X.Y.Z, stable-tag=X.Y.Z, changelog present

Step 3 -- Build release ZIP
  Created canonical plugin ZIP: ...portfolio-guard-X.Y.Z.zip

Step 4 -- Release package validation
  12 / 12 checks passed
  RESULT: PASS

Step 5 -- SHA-256
  <fingerprint>

=== Build complete ===
```

All steps must succeed. If any step fails, the build script will print the reason and stop. Do not publish a partial build.

**Step 4: Locate the release artifact.**

The completed ZIP and SHA-256 fingerprint are written to:

```
releases/portfolio-guard/portfolio-guard-X.Y.Z.zip
releases/portfolio-guard/portfolio-guard-X.Y.Z.sha256
```

**Step 5: Publish and distribute.**

1. Commit the SHA-256 fingerprint file to the repository. (The ZIP itself is excluded from version control by design.)
2. Upload the ZIP to the internal distribution point (releases server or shared storage).
3. Publish the corresponding `plugin.json` manifest to the release endpoint so WordPress sites receive the update notification.

**Step 6: Verify update availability.**

After publishing, verify that at least one managed site receives the update notification within 12 hours (the check interval for the plugin updater).

### What the Build Does Not Do

The build script produces and validates the release artifact. It does not:

- Commit or push changes to the repository
- Tag the release in version control
- Upload files to any server
- Modify the update manifest

These steps remain the responsibility of the person running the release.

---

## 8. Uninstalling Portfolio Guard

### How to Uninstall

1. In the WordPress Admin, go to **Plugins → Installed Plugins**.
2. Click **Deactivate** under MSP Portfolio Guard.
3. Once deactivated, click **Delete**.
4. WordPress will ask you to confirm. Click **Yes, delete these files and data**.

Portfolio Guard's uninstall routine runs automatically when you confirm deletion.

### What Portfolio Guard Removes

When uninstalled, Portfolio Guard removes everything it created:

- All plugin configuration (report recipient, scan settings, update state)
- All signature update state and cache
- All plugin update state and cache
- All scheduled scans and update checks
- The MU-loader file (if installed)
- The applied signature registry directory

After uninstallation, no Portfolio Guard data remains in the WordPress database or filesystem.

### What Intentionally Remains

**Remediation evidence is preserved.**

If Portfolio Guard detected and removed malware during its deployment, the record of that removal is kept — even after uninstallation. This is by design. Remediation logs serve as evidence of what happened on the site and may be needed for incident review, compliance documentation, or communicating with a site owner.

These files are stored in a separate directory within the WordPress uploads folder and are not touched by the uninstall process.

> **If you need to remove remediation evidence as well**, this must be done manually. The files are located in `{uploads}/portfolio-guard-remediation/` or a similarly named subdirectory within your WordPress uploads directory.

### Reinstalling After Uninstall

Portfolio Guard can be reinstalled cleanly after uninstallation. No residual data remains from the previous installation. The plugin will behave exactly as it did on first installation.

---

## 9. Troubleshooting

### No email received after a scan

**Possible cause 1: The site was clean.**
Portfolio Guard does not send email for clean scans. No news is good news.

**Possible cause 2: Report recipient not configured.**
Check the Portfolio Guard settings to confirm an email address is saved as the report recipient.

**Possible cause 3: Email delivery issue.**
WordPress uses `wp_mail()` to send email, which depends on your hosting environment's mail configuration. Test by using a dedicated email delivery plugin (such as WP Mail SMTP) to route outgoing email through a reliable transactional mail service.

**Possible cause 4: Scan has not run yet.**
If Portfolio Guard was just installed, the first scan runs at 6:00 AM. Check the diagnostics page for the scheduled next scan time.

---

### No plugin update available, but I expect one

Updates are cached for 12 hours. Wait up to 12 hours and check again.

If 12 hours have passed and no update notice appears:

1. Check the Portfolio Guard diagnostics page for the "plugin update last checked" timestamp.
2. Confirm the release endpoint has been updated with the new `plugin.json` manifest.
3. Verify the new version number in the manifest is higher than the currently installed version. WordPress only shows update notices for versions higher than the currently active version.

---

### Signature update not applied

The diagnostics page shows the current registry version and the consecutive failure count.

**If the consecutive failure count is greater than zero:**

The update server may be temporarily unavailable, or the update manifest may have a verification error. Portfolio Guard retries automatically. If failures persist beyond 24 hours, check:

- That the update server endpoint is reachable from the site's hosting environment.
- That the signature manifest and registry files on the update server are intact.

**If the registry version has not changed in several days:**

This is expected if no new signatures have been published. The registry version only advances when new signatures are released. Check the internal signature update schedule to confirm whether a new registry version exists.

---

### Diagnostics page reports a problem

The diagnostics page describes its findings in plain language. Common reports and their meanings:

| Diagnostic message | Meaning |
|---|---|
| "Scan not scheduled" | WordPress cron may be disabled. Check wp-cron configuration. |
| "Consecutive update failures: N" | The signature update server was unreachable N times. Monitor and check server availability. |
| "Registry source: installed" | The applied (downloaded) registry either does not exist or is outdated. The installed registry bundled with the plugin is in use. This is normal after a fresh installation. |
| "Registry source: applied" | A downloaded registry is active. This is the expected steady state after the first successful signature update. |

---

### Scan did not run at 6:00 AM

WordPress scheduled tasks depend on site traffic. If no visitors loaded the site near 6:00 AM, the scan will run at the next page load after that time.

**Recommended fix for MSP-managed sites:** Configure a true server-level cron job to trigger WordPress scheduled tasks. This ensures scans run reliably regardless of traffic. See the internal server cron documentation (`docs/operations/server-cron.md`) for setup instructions.

---

### Review Required result — plugin looks legitimate

A "Review Required" result does not mean the plugin is malicious. Portfolio Guard flagged it because it matched behavioral patterns associated with malware, but the match was inconclusive.

To evaluate:

1. Search for the plugin name in the WordPress Plugin Directory and online.
2. Check with the site owner whether they installed it deliberately.
3. Review what the plugin claims to do and whether that matches its actual presence on the site.

If the plugin is legitimate, no action is required. Portfolio Guard will continue monitoring.
If the plugin cannot be accounted for, remove it manually and monitor the site for recurrence.

---

### PHP zip extension not available

This applies to internal maintainers running the release build.

If the build fails at Step 0 with:

```
[FAIL] ZipArchive PHP extension is not available
  Fix: enable the 'zip' extension in your php.ini
    1. Run: php --ini
    2. Open the file listed as 'Loaded Configuration File'
    3. Find the line: ;extension=zip
    4. Remove the leading semicolon and save the file
    5. Re-run this build script
```

Follow those steps exactly. The PHP `zip` extension is bundled with standard PHP installations but is sometimes disabled by default.

After enabling the extension, re-run the build script from Step 0. No other changes are needed.

---

## Appendix: Quick Reference

### Portfolio Guard Scan Schedule

| Event | Timing |
|---|---|
| Daily scan | 6:00 AM |
| Signature update check | Every 6 hours |
| Plugin update check | Every 12 hours |

### Scan Outcomes

| Outcome | Email sent? | Action required? |
|---|---|---|
| Healthy | No | None |
| Confirmed Malware | Yes | Review report; broader site review recommended |
| Review Required | Yes | Evaluate flagged plugin manually |

### Diagnostics Page Location

**WordPress Admin → Settings → Portfolio Guard Diagnostics**
(exact menu placement may vary by WordPress theme)

### Release Artifact Locations

| File | Location |
|---|---|
| Release ZIP | `releases/portfolio-guard/portfolio-guard-X.Y.Z.zip` |
| SHA-256 fingerprint | `releases/portfolio-guard/portfolio-guard-X.Y.Z.sha256` |

---

*Portfolio Guard 2.0.0 — Production Candidate 1*
*My Social Practice — Internal Distribution*
*Document date: June 2026*
