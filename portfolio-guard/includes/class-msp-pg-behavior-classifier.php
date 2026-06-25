<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_BehaviorClassifier
{
    /**
     * Evaluate which behavior profiles activate for a given set of signal observations.
     *
     * Input:  observations array produced by MSP_PG_FeatureExtractor::extract()
     * Output: array of activated profile records, each containing:
     *   profile_id       — identifier from Spec 005 §6
     *   profile_label    — human-readable profile name
     *   summary          — sentence specific to the observed signals (Spec 005 §10)
     *   signals_observed — subset of input observations relevant to this profile
     *
     * This method classifies only. It does not determine Tier 2 outcomes, trigger
     * remediation, or modify any plugin state. Profile activation records are consumed
     * by the reporting layer (Milestone 3.3).
     */
    public static function classify(array $observations)
    {
        $activated = array();

        foreach (array_keys(self::profile_definitions()) as $profileId) {
            if (!self::activates($profileId, $observations)) {
                continue;
            }

            $evidence = self::collect_evidence($profileId, $observations);

            $activated[] = array(
                'profile_id'       => $profileId,
                'profile_label'    => self::profile_definitions()[$profileId]['label'],
                'summary'          => self::generate_summary($profileId, $evidence),
                'signals_observed' => $evidence,
            );
        }

        return $activated;
    }

    /**
     * Return true if a specific profile is activated by the given observations.
     * Exposed for targeted testing and classifier transparency.
     */
    public static function activates($profileId, array $observations)
    {
        $has = function ($signalId) use ($observations) {
            return MSP_PG_FeatureExtractor::has_signal($observations, $signalId);
        };

        switch ($profileId) {
            case 'persistence':
                // A plugin that hides from the admin interface or hooks pre-deactivation
                // execution pathways demonstrates Persistence.
                // HP-01 and HP-02 are specific enough to activate alone.
                return $has('HP-01') || $has('HP-02');

            case 'command-and-control':
                // Family-specific SM strings are unambiguous — any single one activates.
                // Generic FC signals (REST routes, outbound HTTP) appear in many legitimate
                // plugins and are not sufficient corroboration without a family-specific marker.
                foreach (array('SM-01', 'SM-02', 'SM-03', 'SM-04', 'SM-05', 'KB-02') as $smId) {
                    if ($has($smId)) {
                        return true;
                    }
                }
                return false;

            case 'payload-delivery':
                // DM-01 (createElement in PHP output) is specific enough to activate alone.
                // SP-01 (short alphanumeric directory with 8-char PHP file) matches too many
                // legitimate vendor directories; require a corroborating family-specific marker.
                if ($has('DM-01')) {
                    return true;
                }
                if ($has('SP-01')) {
                    foreach (array('SM-01', 'SM-02', 'SM-03', 'SM-04', 'SM-05', 'KB-02') as $smId) {
                        if ($has($smId)) {
                            return true;
                        }
                    }
                }
                return false;

            case 'operator-access':
                // KB-01 (known backdoor triplet) activates alone — it is family-specific.
                // FC-03 (wp_set_auth_cookie) is used legitimately by remote management and
                // backup plugins; require FC-04 (raw setcookie) as corroboration, which
                // represents the token-write step of an impersonation flow.
                return $has('KB-01') || ($has('FC-03') && $has('FC-04'));

            case 'stealth':
                // HP-01 (hiding from plugin list) is unambiguous and activates alone.
                // CB-01 + FC-01 (anonymous REST endpoint) is too broad — many legitimate
                // plugins expose unauthenticated endpoints without stealth intent.
                return $has('HP-01');
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Profile definitions: signal membership for evidence collection (Spec 005 §8)
    // -------------------------------------------------------------------------

    private static function profile_definitions()
    {
        return array(
            'persistence' => array(
                'label'   => 'Persistence',
                'signals' => array('HP-01', 'HP-02', 'FC-01', 'FC-08', 'CB-01', 'SP-01'),
            ),
            'command-and-control' => array(
                'label'   => 'Command & Control',
                'signals' => array('SM-01', 'SM-02', 'SM-03', 'SM-04', 'SM-05', 'KB-02', 'FC-01', 'FC-02', 'CB-01', 'FC-08'),
            ),
            'payload-delivery' => array(
                'label'   => 'Payload Delivery',
                'signals' => array('DM-01', 'SP-01', 'FC-07', 'SP-02'),
            ),
            'operator-access' => array(
                'label'   => 'Operator Access',
                'signals' => array('KB-01', 'FC-03', 'FC-05', 'FC-04', 'FC-06'),
            ),
            'stealth' => array(
                'label'   => 'Stealth',
                'signals' => array('HP-01', 'CB-01', 'HP-02', 'KB-02'),
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Evidence collection
    // -------------------------------------------------------------------------

    private static function collect_evidence($profileId, array $observations)
    {
        $profile  = self::profile_definitions()[$profileId];
        $evidence = array();

        foreach ($profile['signals'] as $signalId) {
            foreach (MSP_PG_FeatureExtractor::find_by_signal($observations, $signalId) as $obs) {
                $evidence[] = $obs;
            }
        }

        return $evidence;
    }

    // -------------------------------------------------------------------------
    // Summary generation — specific to observed signals (Spec 005 §10, criterion 4)
    // -------------------------------------------------------------------------

    private static function generate_summary($profileId, array $evidence)
    {
        // Index evidence by signal_id for targeted sentence construction
        $bySignal = array();
        foreach ($evidence as $obs) {
            $bySignal[$obs['signal_id']][] = $obs;
        }

        $parts = array();

        switch ($profileId) {
            case 'persistence':
                if (isset($bySignal['HP-01'])) {
                    $parts[] = 'removes itself from the WordPress plugin list (`'
                        . $bySignal['HP-01'][0]['matched_string'] . '` in '
                        . $bySignal['HP-01'][0]['file'] . ')';
                }
                if (isset($bySignal['HP-02'])) {
                    $parts[] = 'hooks early-execution template redirect (`'
                        . $bySignal['HP-02'][0]['matched_string'] . '` in '
                        . $bySignal['HP-02'][0]['file'] . ')';
                }
                if (isset($bySignal['FC-01'])) {
                    $parts[] = 'registers a persistent REST endpoint (`'
                        . $bySignal['FC-01'][0]['matched_string'] . '` in '
                        . $bySignal['FC-01'][0]['file'] . ')';
                }
                return 'This plugin exhibits Persistence: it ' . self::join_parts($parts) . '.';

            case 'command-and-control':
                foreach (array('SM-01', 'SM-02', 'KB-02') as $smId) {
                    if (isset($bySignal[$smId])) {
                        $parts[] = 'contains known C2 bootstrap strings (`'
                            . $bySignal[$smId][0]['matched_string'] . '` in '
                            . $bySignal[$smId][0]['file'] . ')';
                        break;
                    }
                }
                if (isset($bySignal['SM-03'])) {
                    $parts[] = 'contains known C2 session identifier (`'
                        . $bySignal['SM-03'][0]['matched_string'] . '`)';
                }
                if (isset($bySignal['SM-04'])) {
                    $parts[] = 'references a known C2 configuration endpoint (`'
                        . $bySignal['SM-04'][0]['matched_string'] . '` in '
                        . $bySignal['SM-04'][0]['file'] . ')';
                }
                if (isset($bySignal['FC-01'])) {
                    $suffix = isset($bySignal['CB-01'])
                        ? 'accessible without authentication'
                        : 'for external interaction';
                    $parts[] = 'registers a REST endpoint ' . $suffix . ' (`'
                        . $bySignal['FC-01'][0]['matched_string'] . '` in '
                        . $bySignal['FC-01'][0]['file'] . ')';
                }
                if (isset($bySignal['FC-02'])) {
                    $parts[] = 'makes outbound HTTP requests (`'
                        . $bySignal['FC-02'][0]['matched_string'] . '` in '
                        . $bySignal['FC-02'][0]['file'] . ')';
                }
                return 'This plugin exhibits Command & Control: it ' . self::join_parts($parts) . '.';

            case 'payload-delivery':
                if (isset($bySignal['DM-01'])) {
                    $parts[] = 'dynamically injects JavaScript into page output (`'
                        . $bySignal['DM-01'][0]['matched_string'] . '` in '
                        . $bySignal['DM-01'][0]['file'] . ')';
                }
                if (isset($bySignal['SP-01'])) {
                    $parts[] = 'contains a concealed payload staging structure (`'
                        . $bySignal['SP-01'][0]['matched_string'] . '`)';
                }
                if (isset($bySignal['FC-07'])) {
                    $parts[] = 'registers or enqueues scripts dynamically (`'
                        . $bySignal['FC-07'][0]['matched_string'] . '` in '
                        . $bySignal['FC-07'][0]['file'] . ')';
                }
                return 'This plugin exhibits Payload Delivery: it ' . self::join_parts($parts) . '.';

            case 'operator-access':
                if (isset($bySignal['KB-01'])) {
                    $parts[] = 'contains a known authentication impersonation pattern (`'
                        . $bySignal['KB-01'][0]['matched_string'] . '` in '
                        . $bySignal['KB-01'][0]['file'] . ')';
                }
                if (isset($bySignal['FC-03'])) {
                    $parts[] = 'creates WordPress authentication cookies (`'
                        . $bySignal['FC-03'][0]['matched_string'] . '` in '
                        . $bySignal['FC-03'][0]['file'] . ')';
                }
                if (isset($bySignal['FC-06'])) {
                    $parts[] = 'redirects to the admin area after session creation (`'
                        . $bySignal['FC-06'][0]['matched_string'] . '`)';
                }
                if (isset($bySignal['FC-05'])) {
                    $parts[] = 'reads or manipulates cookies for session control (`'
                        . $bySignal['FC-05'][0]['matched_string'] . '`)';
                }
                return 'This plugin exhibits Operator Access: it ' . self::join_parts($parts) . '.';

            case 'stealth':
                if (isset($bySignal['HP-01'])) {
                    $parts[] = 'removes itself from the WordPress plugin list (`'
                        . $bySignal['HP-01'][0]['matched_string'] . '` in '
                        . $bySignal['HP-01'][0]['file'] . ')';
                }
                if (isset($bySignal['CB-01'])) {
                    $parts[] = 'registers REST endpoints that require no authentication, leaving no admin trace (`'
                        . $bySignal['CB-01'][0]['matched_string'] . '` in '
                        . $bySignal['CB-01'][0]['file'] . ')';
                }
                if (isset($bySignal['HP-02'])) {
                    $parts[] = 'hooks template redirect for execution without visible admin output (`'
                        . $bySignal['HP-02'][0]['matched_string'] . '`)';
                }
                return 'This plugin exhibits Stealth: it ' . self::join_parts($parts) . '.';
        }

        return '';
    }

    private static function join_parts(array $parts)
    {
        if (empty($parts)) {
            return 'exhibits behavioral signals without further detail';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ', and ' . $last;
    }
}
