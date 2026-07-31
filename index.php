<?php
/**
 * Video Compress - Admin dashboard showing queue status and diagnostics.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/videocompress/index.php'));
$PAGE->set_title(get_string('pluginname', 'local_videocompress') . ' - Dashboard');
$PAGE->set_heading(get_string('pluginname', 'local_videocompress'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// Diagnostics section.
echo $OUTPUT->heading('System Diagnostics', 3);

$diagnostics = [];

// Check if enabled.
$enabled = local_videocompress_is_enabled();
$diagnostics[] = [
    'check' => 'Plugin Enabled',
    'status' => $enabled ? 'Yes' : 'No',
    'ok' => $enabled,
];

// Check FFmpeg.
$ffmpeg = local_videocompress_get_ffmpeg_path();
$diagnostics[] = [
    'check' => 'FFmpeg Installed',
    'status' => $ffmpeg ? $ffmpeg : 'NOT FOUND - FFmpeg is required for video compression',
    'ok' => (bool)$ffmpeg,
];

// Check cron.
$lastcron = $DB->get_field_sql("SELECT MAX(lastruntime) FROM {task_scheduled} WHERE component = 'local_videocompress'");
$cronStatus = $lastcron ? userdate($lastcron) : 'Never run';
$cronOk = $lastcron && (time() - $lastcron) < 600; // Within 10 minutes.
$diagnostics[] = [
    'check' => 'Cron Last Run',
    'status' => $cronStatus . ($cronOk ? '' : ' - Cron may not be running. Ad-hoc tasks require cron.'),
    'ok' => $cronOk,
];

// Check ad-hoc tasks pending.
$pendingTasks = $DB->count_records('task_adhoc', ['component' => 'local_videocompress']);
$diagnostics[] = [
    'check' => 'Pending Ad-hoc Tasks',
    'status' => $pendingTasks . ' task(s) waiting to be processed',
    'ok' => true,
];

// Compression settings (fixed).
$diagnostics[] = [
    'check' => 'Compression Settings',
    'status' => '720p, CRF 30, 96k audio (optimised for space savings)',
    'ok' => true,
];

// Min file size (fixed).
$diagnostics[] = [
    'check' => 'Minimum File Size',
    'status' => '1 MB (videos smaller than this are not compressed)',
    'ok' => true,
];

$table = new html_table();
$table->head = ['Check', 'Status'];
$table->attributes['class'] = 'generaltable';
foreach ($diagnostics as $d) {
    $icon = $d['ok'] ? '✓' : '✗';
    $color = $d['ok'] ? 'color: green;' : 'color: red; font-weight: bold;';
    $table->data[] = [
        html_writer::tag('span', $icon, ['style' => $color]) . ' ' . $d['check'],
        html_writer::tag('span', $d['status'], ['style' => $d['ok'] ? '' : $color]),
    ];
}
echo html_writer::table($table);

// Queue statistics.
echo $OUTPUT->heading('Queue Statistics', 3);

$stats = [
    'pending' => $DB->count_records('local_videocompress_queue', ['status' => 'pending']),
    'processing' => $DB->count_records('local_videocompress_queue', ['status' => 'processing']),
    'completed' => $DB->count_records('local_videocompress_queue', ['status' => 'completed']),
    'failed' => $DB->count_records('local_videocompress_queue', ['status' => 'failed']),
];

$table = new html_table();
$table->head = ['Status', 'Count'];
$table->attributes['class'] = 'generaltable';
$table->data[] = ['Pending', $stats['pending']];
$table->data[] = ['Processing', $stats['processing']];
$table->data[] = ['Completed', $stats['completed']];
$table->data[] = ['Failed', $stats['failed']];
echo html_writer::table($table);

// Recent activity.
echo $OUTPUT->heading('Recent Queue Activity', 3);

$recent = $DB->get_records_sql(
    "SELECT * FROM {local_videocompress_queue} ORDER BY timemodified DESC",
    [],
    0,
    20
);

if (empty($recent)) {
    echo html_writer::tag('p', 'No videos have been queued yet. Upload a video to an assignment to trigger compression.');
} else {
    $table = new html_table();
    $table->head = ['Filename', 'Original Size', 'Status', 'Compressed Size', 'Ratio', 'Time'];
    $table->attributes['class'] = 'generaltable';
    
    foreach ($recent as $item) {
        $statusClass = '';
        if ($item->status === 'completed') {
            $statusClass = 'style="color: green;"';
        } elseif ($item->status === 'failed') {
            $statusClass = 'style="color: red;"';
        } elseif ($item->status === 'processing') {
            $statusClass = 'style="color: orange;"';
        }
        
        $compressedSize = $item->compressed_size ? local_videocompress_format_bytes($item->compressed_size) : '-';
        $ratio = $item->compression_ratio ? $item->compression_ratio . 'x' : '-';
        
        $statusText = ucfirst($item->status);
        if ($item->status === 'failed' && !empty($item->error_message)) {
            $statusText .= ': ' . s(substr($item->error_message, 0, 50));
        }
        
        $table->data[] = [
            s($item->filename),
            local_videocompress_format_bytes($item->filesize),
            html_writer::tag('span', $statusText, ['style' => $statusClass ? trim(str_replace(['style="', '"'], '', $statusClass)) : '']),
            $compressedSize,
            $ratio,
            userdate($item->timemodified, '%Y-%m-%d %H:%M'),
        ];
    }
    echo html_writer::table($table);
}

// Compression log (total savings).
echo $OUTPUT->heading('Total Space Saved', 3);

$totals = $DB->get_record_sql(
    "SELECT COUNT(*) as count, SUM(space_saved) as total_saved, SUM(original_size) as total_original 
     FROM {local_videocompress_log}"
);

if ($totals && $totals->count > 0) {
    $percentage = $totals->total_original > 0 ? round((1 - ($totals->total_original - $totals->total_saved) / $totals->total_original) * 100, 1) : 0;
    echo html_writer::tag('p', 
        html_writer::tag('strong', $totals->count) . ' videos compressed, saving ' .
        html_writer::tag('strong', local_videocompress_format_bytes($totals->total_saved)) . 
        ' (' . $percentage . '% reduction)'
    );
} else {
    echo html_writer::tag('p', 'No videos have been compressed yet.');
}

// Help section.
echo $OUTPUT->heading('Troubleshooting', 3);

$help = [];
if (!$ffmpeg) {
    $help[] = html_writer::tag('strong', 'FFmpeg Not Found:') . ' Video compression requires FFmpeg to be installed on your server. Contact your server administrator to install FFmpeg. On Ubuntu/Debian: <code>sudo apt install ffmpeg</code>';
}
if (!$cronOk) {
    $help[] = html_writer::tag('strong', 'Cron Not Running:') . ' Video Compress uses Moodle\'s task system which requires cron. Make sure cron is configured and running. See <a href="https://docs.moodle.org/en/Cron" target="_blank">Moodle Cron Documentation</a>';
}
if ($stats['pending'] > 0 && !$cronOk) {
    $help[] = html_writer::tag('strong', 'Videos Waiting:') . ' There are ' . $stats['pending'] . ' videos waiting to be compressed. They will be processed when cron runs.';
}

if (!empty($help)) {
    echo html_writer::alist($help);
} else {
    echo html_writer::tag('p', 'No issues detected. Videos should be compressed automatically when uploaded.');
}

echo $OUTPUT->footer();
