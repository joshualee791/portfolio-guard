# Specification 002 — Scheduling

**Status:** Approved  
**Covers:** Milestone 1.2  
**Depends on:** Spec 001 (approved)  
**Does not cover:** Reporting format (Spec 003), diagnostics (Phase 4)

---

## 1. Purpose

This specification defines the scheduling contract for PC1: the initial install scan,
the daily recurring scan targeting 6:00 AM local site time, catch-up behavior, and
server cron documentation. Every implementation decision in Milestone 1.2 must be
traceable to a statement here.

---

## 2. Scan Schedule Contract

PC1 schedules one scan per calendar day, targeting 6:00 AM in the site's configured
WordPress timezone. This replaces the v1.5.6 hourly recurrence.

**Design principle:** The schedule is best-effort. WP-Cron fires when traffic hits the
site. A site with no traffic between midnight and 6:00 AM will have the scan fire on
the first page load after 6:00 AM. This is acceptable. The objective is "once daily,
roughly at 6am local time" — not precision scheduling.

---

## 3. WordPress Timezone Resolution

The target timezone is resolved from WordPress site configuration using the following
priority:

1. `get_option('timezone_string')` — if non-empty, use as a named `DateTimeZone`
   (e.g., `America/Chicago`). Named timezones are DST-aware; PHP handles offset
   transitions automatically.
2. `get_option('gmt_offset')` — if `timezone_string` is empty, construct a UTC offset
   string. The offset is a PHP float (e.g., `5.5` for UTC+5:30). Convert to
   `UTC+HH:MM` form before constructing the `DateTimeZone`. Half-hour and quarter-hour
   offsets must be handled correctly.
3. If both are empty or the resulting `DateTimeZone` construction fails, fall back to
   `UTC`.

---

## 4. Next-Run Timestamp Algorithm

Given the resolved timezone, the first-run Unix timestamp is computed as:

```
1. Get the current moment as a DateTime in the resolved timezone.
2. Construct a target DateTime for today at 06:00:00 in the resolved timezone.
3. If current time >= today's 06:00:00, advance target to tomorrow at 06:00:00.
4. Convert target to a Unix timestamp (format('U')).
5. Pass that timestamp to wp_schedule_event() with the 'daily' recurrence.
```

**Recurrence:** Use the WordPress built-in `daily` interval (86400 seconds). If
`wp_schedule_event()` fails for any reason, fall through to the existing single-event
fallback at now + 5 minutes already present in `schedule_scan()`. No custom interval
is introduced.

---

## 5. Initial Install Scan

Unchanged from v1.5.6. No implementation work required.

The activation hook writes `msp_pg_pending_activation_scan`. On the next `admin_init`
with `manage_options` capability, `run_scan('activation-catchup')` fires and the
pending option is deleted. This path is confirmed correct by Spec 001 §4.1 and the
existing test suite.

---

## 6. Catch-Up Scan

A catch-up scan fires when the last recorded scan is more than 23 hours ago. This
handles sites where WP-Cron has been unreliable or the site was inactive during the
scheduled window.

**Threshold: 23 hours, not 24.** A daily scan at 6:00 AM Monday followed by a
catch-up check at 5:45 AM Tuesday represents 23h45m elapsed — a 24h threshold would
miss it; 23h catches it correctly without producing false triggers under normal
scheduling.

**Updated staleness check in `maybe_run_catchup_scan()`:**

```php
if ($lastScan > 0 && (time() - $lastScan) < 23 * HOUR_IN_SECONDS) {
    return;
}
```

The `$interval = MSP_PG_Config::interval_seconds()` line and its use in the comparison
are replaced by this constant. `interval_seconds()` is then unused and removed.

**Catch-up lock:** The `msp_pg_catchup_lock` transient (5-minute TTL) and the
activation-catchup path are unchanged.

---

## 7. Upgrade Migration

v1.5.6 installs have an existing **hourly** WP-Cron event registered under
`msp_pg_run_scan`. That event must be replaced by the new daily schedule.

**Where the migration runs:** The version-mismatch branch of `maybe_complete_setup()`,
immediately after updating `msp_pg_version` and before the `has_setup_completed()`
early return.

**Current code:**
```php
if ($installedVersion !== MSP_PG_VERSION) {
    update_option('msp_pg_version', MSP_PG_VERSION, false);
}

if ($this->has_setup_completed()) {
    return;
}
```

**PC1 code:**
```php
if ($installedVersion !== MSP_PG_VERSION) {
    update_option('msp_pg_version', MSP_PG_VERSION, false);
    self::clear_scan_schedule();
    self::schedule_scan();
}

if ($this->has_setup_completed()) {
    return;
}
```

**Why this placement:** On a v1.5.6 upgrade, the MU-loader already exists, so
`has_setup_completed()` returns true and the function would exit without touching the
schedule. Placing the migration in the version-mismatch branch ensures it runs before
that early return. `clear_scan_schedule()` removes the existing event (hourly or
otherwise); `schedule_scan()` registers the new daily event. `schedule_scan()` opens
by checking `wp_next_scheduled()` and returning early if already scheduled — after
the clear, the check passes through correctly.

This migration also runs on any subsequent version bump.

---

## 8. Scheduler State Contract

After `schedule_scan()` returns `['ok' => true]`:

- `wp_next_scheduled(MSP_PG_Config::cron_hook())` returns a Unix timestamp (not false).
- That timestamp is in the future relative to when `schedule_scan()` was called.
- The event recurs with the `daily` interval.

---

## 9. Config Method Removals

**`scan_interval()`** — called only in `schedule_scan()` and `interval_seconds()`.
After the rewrite, `schedule_scan()` no longer uses a filterable interval string.
No other callers. Naturally removed.

**`interval_seconds()`** — called only in `maybe_run_catchup_scan()`. After §6
replaces that call with a constant, no other callers. Naturally removed.

---

## 10. Server Cron Documentation

Create `docs/operations/server-cron.md` with the following content for MSP engineers
who replace WP-Cron with a real server scheduler:

```
*/15 * * * * /usr/bin/wp --path=/var/www/html cron event run --due-now --quiet
```

The document notes:
- `DISABLE_WP_CRON` must be `true` in `wp-config.php` when using server cron.
- The `wp` binary path and WordPress root must be adjusted per hosting environment.
- Portfolio Guard's 6:00 AM scan fires on the first WP-Cron execution after 6:00 AM
  local time.

---

## 11. Open Questions — Resolved

| Question | Resolution |
|---|---|
| What if the site has no configured timezone? | Fall back to UTC. |
| What if `daily` recurrence registration fails? | Existing single-event fallback at now+5min. No custom interval added. |
| Does the initial install scan change? | No. |
| What happens to the v1.5.6 hourly event on upgrade? | Cleared and replaced in the version-mismatch branch of `maybe_complete_setup()`. |
| Should `scan_interval()` / `interval_seconds()` be kept? | No — both are naturally unused after the rewrite. |
| What about half-hour / quarter-hour UTC offsets? | Handled in `site_timezone()` by converting the float gmt_offset to `UTC+HH:MM`. |
| What about DST with named timezones? | PHP's `DateTimeZone` handles DST automatically. Named timezone takes priority over gmt_offset. |
