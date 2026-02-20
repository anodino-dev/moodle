<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $CFG, $DB;
require_once($CFG->libdir . '/clilib.php');

use mod_bigbluebuttonbn\local\config;
use mod_bigbluebuttonbn\local\proxy\recording_proxy;

list($options, $unrecognized) = cli_get_params(
    ['help' => false, 'format' => 'summary', 'courseid' => 0],
    ['h' => 'help', 'f' => 'format', 'c' => 'courseid']
);

if ($options['help']) {
    echo "List recordings on the BBB server using Moodle's proxy.

Options:
-h, --help       Print this help
-f, --format     Output format: summary (default), csv, full
-c, --courseid   Course ID to filter

Examples:
  \$ sudo -u www-data php mod/bigbluebuttonbn/cli/list_bbb_recordings.php
  \$ sudo -u www-data php mod/bigbluebuttonbn/cli/list_bbb_recordings.php -c=4
  \$ sudo -u www-data php mod/bigbluebuttonbn/cli/list_bbb_recordings.php -f csv > recordings.csv
";
    die;
}

// Collect recordingids from local DB.
if (!empty($options['courseid'])) {
    $recordingids = $DB->get_fieldset_sql(
        "SELECT DISTINCT recordingid FROM {bigbluebuttonbn_recordings} WHERE courseid = :cid",
        ['cid' => $options['courseid']]
    );
    cli_writeln("Course {$options['courseid']}: " . count($recordingids) . " recordingids in local DB.");
} else {
    $recordingids = $DB->get_fieldset_sql(
        "SELECT DISTINCT recordingid FROM {bigbluebuttonbn_recordings}"
    );
    cli_writeln("All courses: " . count($recordingids) . " recordingids in local DB.");
}

if (empty($recordingids)) {
    cli_writeln("No recordings found.");
    die;
}

// Query BBB using Moodle's recording_proxy (same method recovery script uses).
cli_writeln("Querying BBB server via recording_proxy::fetch_recordings()...");

$recordings = [];
$notfound = [];
$batches = array_chunk($recordingids, 25);
$errors = 0;

foreach ($batches as $i => $batch) {
    try {
        $result = recording_proxy::fetch_recordings($batch);
        foreach ($result as $rid => $meta) {
            $recordings[$rid] = $meta;
        }
        // Track which ones BBB didn't return.
        foreach ($batch as $rid) {
            if (!isset($result[$rid])) {
                $notfound[] = $rid;
            }
        }
    } catch (\Exception $e) {
        cli_writeln("  Batch " . ($i + 1) . " error: " . $e->getMessage());
        $errors++;
    }

    if (($i + 1) % 20 == 0) {
        cli_writeln("  " . ($i + 1) . "/" . count($batches) . " batches, " . count($recordings) . " found...");
    }
}

cli_writeln("Done. Found " . count($recordings) . " on BBB, " . count($notfound) . " not found." . ($errors ? " ({$errors} errors)" : ""));
cli_writeln("");

$format = $options['format'];

if ($format === 'csv') {
    echo "recordID,meetingID,name,state,published,startTime,endTime\n";
    foreach ($recordings as $rid => $r) {
        $name = str_replace('"', '""', $r['meetingName'] ?? $r['name'] ?? '');
        $ts = !empty($r['startTime']) ? date('Y-m-d H:i:s', (int)($r['startTime'] / 1000)) : '';
        $te = !empty($r['endTime']) ? date('Y-m-d H:i:s', (int)($r['endTime'] / 1000)) : '';
        echo "\"{$rid}\",\"{$r['meetingID']}\",\"{$name}\",{$r['state']},{$r['published']},{$ts},{$te}\n";
    }
    if (!empty($notfound)) {
        cli_writeln("");
        cli_writeln("# Not found on BBB (" . count($notfound) . "):");
        foreach ($notfound as $rid) {
            echo "{$rid}\n";
        }
    }
} else if ($format === 'full') {
    foreach ($recordings as $rid => $r) {
        $ts = !empty($r['startTime']) ? date('Y-m-d H:i:s', (int)($r['startTime'] / 1000)) : 'N/A';
        cli_writeln("recordID:  {$rid}");
        cli_writeln("meetingID: {$r['meetingID']}");
        cli_writeln("name:      " . ($r['meetingName'] ?? $r['name'] ?? 'N/A'));
        cli_writeln("state:     {$r['state']}");
        cli_writeln("published: {$r['published']}");
        cli_writeln("startTime: {$ts}");
        cli_writeln("");
    }
} else {
    $states = [];
    $published = ['true' => 0, 'false' => 0];
    $oldest = PHP_INT_MAX;
    $newest = 0;
    foreach ($recordings as $r) {
        $states[$r['state']] = ($states[$r['state']] ?? 0) + 1;
        $published[$r['published']] = ($published[$r['published']] ?? 0) + 1;
        $ts = (int) ($r['startTime'] ?? 0);
        if ($ts > 0 && $ts < $oldest) $oldest = $ts;
        if ($ts > $newest) $newest = $ts;
    }

    cli_writeln("=== BBB Server Recordings ===");
    cli_writeln("Found on BBB:     " . count($recordings));
    cli_writeln("Not found on BBB: " . count($notfound));
    cli_writeln("");
    cli_writeln("By state:");
    foreach ($states as $state => $count) {
        cli_writeln("  {$state}: {$count}");
    }
    cli_writeln("");
    cli_writeln("By published:");
    foreach ($published as $pub => $count) {
        cli_writeln("  {$pub}: {$count}");
    }
    if ($oldest < PHP_INT_MAX) {
        cli_writeln("");
        cli_writeln("Oldest: " . date('Y-m-d H:i:s', (int)($oldest / 1000)));
        cli_writeln("Newest: " . date('Y-m-d H:i:s', (int)($newest / 1000)));
    }
}
