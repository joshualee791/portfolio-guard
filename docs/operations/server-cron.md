# Server Cron Configuration

By default, Portfolio Guard relies on WP-Cron for scheduled scanning. WP-Cron fires
on page load, so on low-traffic sites the 6:00 AM scan may fire late.

For reliable daily scheduling, replace WP-Cron with a real server cron job.

## Setup

**Step 1.** Disable WP-Cron in `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

**Step 2.** Add a cron job to the server running every 15 minutes:

```
*/15 * * * * /usr/bin/wp --path=/var/www/html cron event run --due-now --quiet
```

Adjust `/usr/bin/wp` to the path of your WP-CLI binary and `/var/www/html` to the
WordPress root directory for the site.

## Behavior

With server cron active, Portfolio Guard's daily scan fires on the first WP-Cron
execution at or after 6:00 AM in the site's configured WordPress timezone. A 15-minute
cron interval means the scan fires no later than 6:15 AM on any normally operating
site.

## Notes

- Each WordPress site requires its own cron job entry if running multiple sites.
- The `--quiet` flag suppresses output; remove it temporarily for debugging.
- If WP-CLI is not installed globally, use the full path to the `wp` phar.
- `DISABLE_WP_CRON` must be set before Portfolio Guard is activated, or the
  activation-catchup scan (which fires on the first admin page load) will not be
  affected.
