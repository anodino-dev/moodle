<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * BigBlueButton recording recovery CLI script.
 *
 * Recovers recordings stuck in AWAITING, DISMISSED, RESET, or DELETED status
 * by querying the BBB server directly. No 30-day time limit. No dependency on logs table.
 * Two strategies: query by recordID and/or by meetingID.
 * Dry-run by default; use -r/--run to apply changes.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2024 onwards
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\recording;
use mod_bigbluebuttonbn\local\proxy\recording_proxy;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
global $CFG, $DB;
require_once($CFG->libdir . '/clilib.php');

// Parse CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'courseid' => 0,
        'bigbluebuttoncmid' => 0,
        'run' => false,
        'strategy' => 'both',
        'include-deleted' => false,
        'verbose' => false,
    ],
    [
        'h' => 'help',
        'c' => 'courseid',
        'b' => 'bigbluebuttoncmid',
        'r' => 'run',
        's' => 'strategy',
        'd' => 'include-deleted',
        'v' => 'verbose',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Recover BigBlueButton recordings stuck in non-working statuses.

Queries the BBB server directly to find recordings and updates their local status
to PROCESSED. Works with recordings in AWAITING (0), DISMISSED (1), and RESET (4)
statuses. Optionally includes DELETED (5) recordings.

Two recovery strategies:
  recordid  - Query BBB using the stored recordingid (fast, precise)
  meetingid - Query BBB using the activity's meetingID (broader, catches mismatches)
  both      - Run both strategies (default, recommended)

Options:
-h, --help                  Print out this help
-c, --courseid              Course ID to scope recovery to
-b, --bigbluebuttoncmid     Specific BBB activity course module ID
-r, --run                   Actually apply changes (default is dry-run)
-s, --strategy              Recovery strategy: recordid, meetingid, or both (default: both)
-d, --include-deleted       Also attempt recovery of DELETED (5) recordings
-v, --verbose               Show detailed output per recording

Examples:
  # Dry-run for all activities (see what would be recovered):
  \$ sudo -u www-data php mod/bigbluebuttonbn/cli/recover_recordings.php -v

  # Actually recover all recordings:
  \$ sudo -u www-data php mod/bigbluebuttonbn/cli/recover_recordings.php -r -v

  # Recover for a specific course:
  \$ sudo -u www-data php mod/bigbluebuttonbn/cli/recover_recordings.php -c=4 -r

  # Include DELETED recordings in recovery:
  \$ sudo -u www-data php mod/bigbluebuttonbn/cli/recover_recordings.php -r -d -v
";
    echo $help;
    die;
}

// Validate strategy.
$validstrategies = ['recordid', 'meetingid', 'both'];
if (!in_array($options['strategy'], $validstrategies)) {
    cli_error("Invalid strategy '{$options['strategy']}'. Use: recordid, meetingid, or both");
}

$dryrun = !$options['run'];
$verbose = $options['verbose'];
$strategy = $options['strategy'];

// Build target statuses.
$targetstatuses = [
    recording::RECORDING_STATUS_AWAITING,   // 0
    recording::RECORDING_STATUS_DISMISSED,   // 1
    recording::RECORDING_STATUS_RESET,       // 4
];
if ($options['include-deleted']) {
    $targetstatuses[] = recording::RECORDING_STATUS_DELETED; // 5
}

/**
 * Get human-readable label for a recording status.
 *
 * @param int $status
 * @return string
 */
function get_status_label(int $status): string {
    $labels = [
        0 => 'AWAITING',
        1 => 'DISMISSED',
        2 => 'PROCESSED',
        3 => 'NOTIFIED',
        4 => 'RESET',
        5 => 'DELETED',
    ];
    return $labels[$status] ?? "UNKNOWN({$status})";
}

// Initialize counters.
$stats = [
    'activities' => 0,
    'inspected' => 0,
    'recovered' => 0,
    'created' => 0,
    'skipped' => 0,
    'failed' => 0,
];

// Header.
if ($dryrun) {
    cli_writeln("=== DRY-RUN MODE (use -r to apply changes) ===");
}
cli_writeln("Strategy: {$strategy}");
cli_writeln("Target statuses: " . implode(', ', array_map('get_status_label', $targetstatuses)));
cli_writeln("");

// Step 1: Collect BBB activities.
$bbcms = [];
if (!empty($options['courseid'])) {
    $courseid = $options['courseid'];
    $modinfos = get_fast_modinfo($courseid)->get_instances_of('bigbluebuttonbn');
    $bbcms = array_values($modinfos);
} else if (!empty($options['bigbluebuttoncmid'])) {
    [$course, $bbcm] = get_course_and_cm_from_cmid($options['bigbluebuttoncmid']);
    $bbcms = [$bbcm];
} else {
    // All bigbluebutton activities.
    foreach ($DB->get_fieldset_select('bigbluebuttonbn', 'id', '') as $bbid) {
        try {
            [$course, $bbcm] = get_course_and_cm_from_instance($bbid, 'bigbluebuttonbn');
            $bbcms[] = $bbcm;
        } catch (\Exception $e) {
            if ($verbose) {
                cli_writeln("WARNING: Could not load course module for instance {$bbid}: " . $e->getMessage());
            }
        }
    }
}

if (empty($bbcms)) {
    cli_writeln("No BigBlueButton activities found.");
    die;
}

cli_writeln("Found " . count($bbcms) . " BigBlueButton activities to process.");
cli_writeln("");

// Process each activity.
foreach ($bbcms as $bbcm) {
    try {
        $instance = instance::get_from_cmid($bbcm->id);
    } catch (\Exception $e) {
        cli_writeln("WARNING: Skipping cmid {$bbcm->id}: " . $e->getMessage());
        continue;
    }
    if (!$instance) {
        if ($verbose) {
            cli_writeln("WARNING: Instance not found for cmid {$bbcm->id}, skipping.");
        }
        continue;
    }

    $stats['activities']++;
    cli_writeln("Processing: \"{$instance->get_meeting_name()}\" "
        . "(id:{$instance->get_instance_id()}), "
        . "course: \"{$bbcm->get_course()->fullname}\" (id:{$bbcm->get_course()->id})");

    // Track which recordingids were recovered by Strategy A.
    $recoveredids = [];

    // Step 2: Get local recordings with target statuses (no time limit).
    [$insql, $inparams] = $DB->get_in_or_equal($targetstatuses, SQL_PARAMS_NAMED, 'st');
    $localrecs = $DB->get_records_select(
        'bigbluebuttonbn_recordings',
        "bigbluebuttonbnid = :bbbid AND status {$insql}",
        array_merge(['bbbid' => $instance->get_instance_id()], $inparams),
        'timecreated ASC'
    );

    if (empty($localrecs) && ($strategy === 'recordid' || $strategy === 'both')) {
        if ($verbose) {
            cli_writeln("  No local recordings with target statuses.");
        }
    }

    // === STRATEGY A: Query BBB by recordID ===
    if (!empty($localrecs) && ($strategy === 'recordid' || $strategy === 'both')) {
        $recordingids = [];
        foreach ($localrecs as $localrec) {
            $recordingids[$localrec->id] = $localrec->recordingid;
        }

        // Fetch metadata from BBB by recordID.
        $metadatas = recording_proxy::fetch_recordings(array_values($recordingids));

        if (empty($metadatas) && $verbose) {
            cli_writeln("  Strategy A: BBB returned no metadata for " . count($recordingids) . " recordingids.");
        }

        foreach ($localrecs as $localrec) {
            $stats['inspected']++;
            $rid = $localrec->recordingid;
            $age = floor((time() - $localrec->timecreated) / DAYSECS);
            $statuslabel = get_status_label($localrec->status);

            if (!empty($metadatas[$rid])) {
                $meta = $metadatas[$rid];
                // Skip if BBB says it's deleted (unless we're including deleted).
                if (($meta['state'] ?? '') === 'deleted' && !$options['include-deleted']) {
                    if ($verbose) {
                        cli_writeln("  [SKIP] {$rid} - deleted on BBB server (use -d to include)");
                    }
                    $stats['skipped']++;
                    continue;
                }

                $recname = $meta['name'] ?? $meta['meetingName'] ?? 'N/A';
                cli_writeln("  [RECOVER] {$rid}");
                cli_writeln("            name: {$recname}, status: {$statuslabel}, age: {$age}d");

                if (!$dryrun) {
                    try {
                        $recobj = new recording(0, $localrec, $meta);
                        $recobj->set('status', recording::RECORDING_STATUS_PROCESSED);
                        $recobj->save();
                        cli_writeln("            -> Updated to PROCESSED");
                    } catch (\Exception $e) {
                        cli_writeln("            -> ERROR: " . $e->getMessage());
                        $stats['failed']++;
                        continue;
                    }
                }
                $recoveredids[$rid] = true;
                $stats['recovered']++;
            } else {
                if ($verbose) {
                    cli_writeln("  [NOT FOUND by recordID] {$rid} (status: {$statuslabel}, age: {$age}d)");
                }
            }
        }
    }

    // === STRATEGY B: Query BBB by meetingID ===
    if ($strategy === 'meetingid' || $strategy === 'both') {
        // Determine all meetingIDs for this activity (considering groups).
        $meetingids = [];
        $groupmode = groups_get_activity_groupmode($instance->get_cm());

        if ($groupmode) {
            $groups = groups_get_all_groups(
                $instance->get_course_id(),
                0,
                $instance->get_cm()->groupingid
            );
            $meetingids[0] = $instance->get_meeting_id(0);
            foreach ($groups as $group) {
                $meetingids[$group->id] = $instance->get_meeting_id($group->id);
            }
        } else {
            $meetingids[0] = $instance->get_meeting_id(0);
        }

        foreach ($meetingids as $groupid => $meetingid) {
            if ($verbose) {
                cli_writeln("  Strategy B: Querying BBB by meetingID: {$meetingid} (group:{$groupid})");
            }

            try {
                $remoterecs = recording_proxy::fetch_recording_by_meeting_id([$meetingid]);
            } catch (\Exception $e) {
                cli_writeln("  WARNING: API error for meetingID {$meetingid}: " . $e->getMessage());
                $stats['failed']++;
                continue;
            }

            if (empty($remoterecs)) {
                if ($verbose) {
                    cli_writeln("    No recordings found on BBB for this meetingID.");
                }
                continue;
            }

            foreach ($remoterecs as $remoterecordid => $metadata) {
                // Skip already recovered by Strategy A.
                if (isset($recoveredids[$remoterecordid])) {
                    continue;
                }

                // Skip deleted on BBB unless --include-deleted.
                if (($metadata['state'] ?? '') === 'deleted' && !$options['include-deleted']) {
                    continue;
                }

                // Check if local record exists with this recordingid.
                $existing = $DB->get_record('bigbluebuttonbn_recordings', [
                    'recordingid' => $remoterecordid,
                    'bigbluebuttonbnid' => $instance->get_instance_id(),
                ]);

                if ($existing) {
                    if (in_array((int) $existing->status, $targetstatuses)) {
                        $stats['inspected']++;
                        $statuslabel = get_status_label($existing->status);
                        $recname = $metadata['name'] ?? $metadata['meetingName'] ?? 'N/A';
                        cli_writeln("  [RECOVER-BY-MEETINGID] {$remoterecordid}");
                        cli_writeln("            name: {$recname}, status: {$statuslabel}");

                        if (!$dryrun) {
                            try {
                                $recobj = new recording(0, $existing, $metadata);
                                $recobj->set('status', recording::RECORDING_STATUS_PROCESSED);
                                $recobj->save();
                                cli_writeln("            -> Updated to PROCESSED");
                            } catch (\Exception $e) {
                                cli_writeln("            -> ERROR: " . $e->getMessage());
                                $stats['failed']++;
                                continue;
                            }
                        }
                        $recoveredids[$remoterecordid] = true;
                        $stats['recovered']++;
                    }
                    // Already PROCESSED/NOTIFIED: skip silently.
                    continue;
                }

                // No local record exists - create new one.
                $recname = $metadata['name'] ?? $metadata['meetingName'] ?? 'N/A';
                cli_writeln("  [NEW] Unregistered recording: {$remoterecordid}");
                cli_writeln("        meetingID: {$meetingid}, group: {$groupid}, name: {$recname}");

                if (!$dryrun) {
                    // Double-check no duplicate exists.
                    if ($DB->record_exists('bigbluebuttonbn_recordings', ['recordingid' => $remoterecordid,
                            'bigbluebuttonbnid' => $instance->get_instance_id()])) {
                        cli_writeln("        -> Skipped (duplicate detected)");
                        continue;
                    }
                    try {
                        $newrec = new recording(0, (object) [
                            'courseid' => $instance->get_course_id(),
                            'bigbluebuttonbnid' => $instance->get_instance_id(),
                            'groupid' => $groupid,
                            'recordingid' => $remoterecordid,
                            'headless' => 0,
                            'imported' => 0,
                            'status' => recording::RECORDING_STATUS_PROCESSED,
                        ], $metadata);
                        $newrec->create();
                        cli_writeln("        -> Created with PROCESSED status");
                    } catch (\Exception $e) {
                        cli_writeln("        -> ERROR creating: " . $e->getMessage());
                        $stats['failed']++;
                        continue;
                    }
                }
                $stats['created']++;
            }
        }
    }

    // Count skipped (not found by either strategy).
    if ($strategy === 'recordid' || $strategy === 'both') {
        foreach ($localrecs as $localrec) {
            if (!isset($recoveredids[$localrec->recordingid])) {
                // Only count as skipped if not already counted in inspected.
                if ($strategy === 'recordid') {
                    // Already counted during Strategy A loop.
                } else {
                    $stats['skipped']++;
                }
            }
        }
    }

    cli_writeln(""); // Blank line between activities.
}

// Summary.
cli_writeln("============================================================");
cli_writeln("Recovery Summary");
cli_writeln("============================================================");
cli_writeln("Activities processed:    {$stats['activities']}");
cli_writeln("Recordings inspected:    {$stats['inspected']}");
cli_writeln("Recordings recovered:    {$stats['recovered']}");
cli_writeln("New recordings created:  {$stats['created']}");
cli_writeln("Recordings not on BBB:   {$stats['skipped']}");
cli_writeln("Errors encountered:      {$stats['failed']}");
cli_writeln("Mode:                    " . ($dryrun ? "DRY-RUN" : "LIVE"));
cli_writeln("============================================================");

if ($dryrun && ($stats['recovered'] > 0 || $stats['created'] > 0)) {
    cli_writeln("");
    cli_writeln("Run with -r to apply these changes.");
}
