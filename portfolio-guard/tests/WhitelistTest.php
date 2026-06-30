<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Tests for MSP_PG_Whitelist.
 *
 * Covers: add, remove, is_approved, entry, all, count, and metadata integrity.
 * Each test resets the msp_pg_whitelist option to ensure isolation.
 */
class WhitelistTest
{
    private $passed = 0;
    private $failed = 0;
    private $errors = array();

    // -------------------------------------------------------------------------
    // Test runner
    // -------------------------------------------------------------------------

    public function run()
    {
        $methods = array(
            'test_add_and_is_approved',
            'test_version_specificity',
            'test_remove_revokes_approval',
            'test_remove_absent_returns_false',
            'test_all_returns_entries_sorted_by_timestamp',
            'test_count_reflects_entries',
            'test_metadata_preserved',
            'test_add_overwrites_existing_entry',
            'test_entry_returns_null_when_absent',
            'test_entry_returns_record_when_present',
        );

        foreach ($methods as $method) {
            $this->reset();
            $this->{$method}();
        }

        $this->report();
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    private function test_add_and_is_approved()
    {
        MSP_PG_Whitelist::add('contact-form-7', '5.9.8', 1, 'admin', 'Legitimate contact form');
        $this->assertTrue(MSP_PG_Whitelist::is_approved('contact-form-7', '5.9.8'), 'is_approved must return true after add');
    }

    private function test_version_specificity()
    {
        MSP_PG_Whitelist::add('contact-form-7', '5.9.8', 1, 'admin', '');
        $this->assertFalse(MSP_PG_Whitelist::is_approved('contact-form-7', '6.0.0'), 'Different version must not be approved');
        $this->assertFalse(MSP_PG_Whitelist::is_approved('contact-form-7', '5.9.7'), 'Earlier version must not be approved');
    }

    private function test_remove_revokes_approval()
    {
        MSP_PG_Whitelist::add('some-plugin', '1.0.0', 1, 'admin', '');
        $this->assertTrue(MSP_PG_Whitelist::is_approved('some-plugin', '1.0.0'), 'Precondition: approved before remove');

        $removed = MSP_PG_Whitelist::remove('some-plugin', '1.0.0');
        $this->assertTrue($removed, 'remove must return true when entry existed');
        $this->assertFalse(MSP_PG_Whitelist::is_approved('some-plugin', '1.0.0'), 'is_approved must return false after remove');
    }

    private function test_remove_absent_returns_false()
    {
        $removed = MSP_PG_Whitelist::remove('nonexistent-plugin', '1.0.0');
        $this->assertFalse($removed, 'remove must return false when entry did not exist');
    }

    private function test_all_returns_entries_sorted_by_timestamp()
    {
        // Two adds — timestamps may be identical in fast test runs, but both must appear
        MSP_PG_Whitelist::add('plugin-a', '1.0', 1, 'admin', '');
        MSP_PG_Whitelist::add('plugin-b', '2.0', 1, 'admin', '');
        $all = MSP_PG_Whitelist::all();
        $this->assertTrue(count($all) === 2, 'all() must return both entries');
        $slugs = array_column($all, 'slug');
        $this->assertTrue(in_array('plugin-a', $slugs, true), 'all() must include plugin-a');
        $this->assertTrue(in_array('plugin-b', $slugs, true), 'all() must include plugin-b');
    }

    private function test_count_reflects_entries()
    {
        $this->assertTrue(MSP_PG_Whitelist::count() === 0, 'count() must be 0 on empty whitelist');
        MSP_PG_Whitelist::add('plugin-a', '1.0', 1, 'admin', '');
        $this->assertTrue(MSP_PG_Whitelist::count() === 1, 'count() must be 1 after first add');
        MSP_PG_Whitelist::add('plugin-b', '2.0', 1, 'admin', '');
        $this->assertTrue(MSP_PG_Whitelist::count() === 2, 'count() must be 2 after second add');
        MSP_PG_Whitelist::remove('plugin-a', '1.0');
        $this->assertTrue(MSP_PG_Whitelist::count() === 1, 'count() must be 1 after remove');
    }

    private function test_metadata_preserved()
    {
        $beforeAdd = gmdate('c');
        MSP_PG_Whitelist::add('my-plugin', '3.1.4', 42, 'jane', 'Security review 2026-06-30');
        $entry = MSP_PG_Whitelist::entry('my-plugin', '3.1.4');

        $this->assertTrue($entry !== null, 'entry() must not be null after add');
        $this->assertTrue($entry['slug']       === 'my-plugin',              'slug must match');
        $this->assertTrue($entry['version']    === '3.1.4',                  'version must match');
        $this->assertTrue($entry['user_id']    === 42,                       'user_id must match');
        $this->assertTrue($entry['user_login'] === 'jane',                   'user_login must match');
        $this->assertTrue($entry['note']       === 'Security review 2026-06-30', 'note must match');
        $this->assertTrue(isset($entry['timestamp']),                        'timestamp must be set');
        $this->assertTrue($entry['timestamp'] >= $beforeAdd,                 'timestamp must be >= time of add');
    }

    private function test_add_overwrites_existing_entry()
    {
        MSP_PG_Whitelist::add('my-plugin', '1.0', 1, 'admin', 'original note');
        MSP_PG_Whitelist::add('my-plugin', '1.0', 2, 'editor', 'updated note');

        $this->assertTrue(MSP_PG_Whitelist::count() === 1, 'overwrite must not create duplicate entry');
        $entry = MSP_PG_Whitelist::entry('my-plugin', '1.0');
        $this->assertTrue($entry['user_login'] === 'editor', 'overwrite must update user_login');
        $this->assertTrue($entry['note']       === 'updated note', 'overwrite must update note');
    }

    private function test_entry_returns_null_when_absent()
    {
        $entry = MSP_PG_Whitelist::entry('nonexistent', '1.0');
        $this->assertTrue($entry === null, 'entry() must return null when slug/version absent');
    }

    private function test_entry_returns_record_when_present()
    {
        MSP_PG_Whitelist::add('present-plugin', '2.5', 1, 'admin', '');
        $entry = MSP_PG_Whitelist::entry('present-plugin', '2.5');
        $this->assertTrue(is_array($entry), 'entry() must return array when present');
        $this->assertTrue($entry['slug'] === 'present-plugin', 'entry slug must match');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function reset()
    {
        unset($GLOBALS['msp_pg_test_options'][MSP_PG_Whitelist::OPTION_NAME]);
    }

    private function assertTrue($condition, $message)
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->errors[] = 'FAIL: ' . $message;
        }
    }

    private function assertFalse($condition, $message)
    {
        $this->assertTrue(!$condition, $message);
    }

    private function report()
    {
        $total = $this->passed + $this->failed;
        foreach ($this->errors as $err) {
            echo $err . "\n";
        }
        if ($this->failed === 0) {
            echo "WhitelistTest: {$this->passed}/{$total} passed\n";
        } else {
            echo "WhitelistTest: {$this->passed}/{$total} passed, {$this->failed} FAILED\n";
        }
    }
}

$test = new WhitelistTest();
$test->run();
