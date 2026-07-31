<?php
/**
 * Event observer for Video Compress plugin.
 *
 * Listens for file upload events and queues videos for compression.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videocompress;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Event observer class for handling file uploads.
 */
class observer {

    /**
     * Handle assessable uploaded event.
     *
     * This event fires AFTER files are saved. Immediately queues an ad-hoc task
     * for each video found that meets the minimum size requirement.
     *
     * @param \core\event\assessable_uploaded $event The event object.
     * @return void
     */
    public static function assessable_uploaded(\core\event\assessable_uploaded $event): void {
        global $DB;

        if (!local_videocompress_is_enabled()) {
            return;
        }

        $data = $event->get_data();
        $contextid = $data['contextid'];
        $fs = get_file_storage();
        $videosfound = [];

        // Method 1: Try pathnamehashes if available.
        if (!empty($data['other']['pathnamehashes']) && is_array($data['other']['pathnamehashes'])) {
            foreach ($data['other']['pathnamehashes'] as $pathnamehash) {
                $file = $fs->get_file_by_hash($pathnamehash);
                if ($file && !$file->is_directory() && local_videocompress_is_video($file)) {
                    $videosfound[] = $file;
                }
            }
        }

        // Method 2: If no files found, scan context for recent video files.
        if (empty($videosfound)) {
            $component = $data['component'] ?? '';
            $recenttime = time() - 60;

            // Build list of components to check.
            $components = [$component];
            if ($component === 'mod_assign') {
                $components[] = 'assignsubmission_file';
            }
            if ($component === 'mod_forum') {
                $components[] = 'mod_forum';
            }
            if ($component === 'mod_workshop') {
                $components[] = 'mod_workshop';
            }

            // Filter out empty components.
            $components = array_filter($components, function($c) {
                return !empty($c);
            });

            if (empty($components)) {
                return;
            }

            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context) {
                return;
            }

            // Use parameterised query with get_in_or_equal to prevent SQL injection.
            list($insql, $inparams) = $DB->get_in_or_equal($components, SQL_PARAMS_NAMED, 'comp');

            $sql = "SELECT f.*
                      FROM {files} f
                     WHERE f.contextid = :contextid
                       AND f.component {$insql}
                       AND f.filename != '.'
                       AND f.filesize > 0
                       AND f.timecreated >= :recenttime
                       AND f.mimetype LIKE :mimetype
                  ORDER BY f.timecreated DESC";

            $params = array_merge($inparams, [
                'contextid' => $contextid,
                'recenttime' => $recenttime,
                'mimetype' => 'video/%',
            ]);

            $records = $DB->get_records_sql($sql, $params, 0, 20);

            foreach ($records as $record) {
                $file = $fs->get_file_by_id($record->id);
                if ($file && !$file->is_directory() && local_videocompress_is_video($file)) {
                    $videosfound[] = $file;
                }
            }
        }

        // Queue ad-hoc task for each video for immediate processing.
        foreach ($videosfound as $file) {
            $minsize = local_videocompress_get_min_filesize();
            if ($file->get_filesize() < $minsize) {
                continue;
            }

            // Check if already queued or processed.
            if ($DB->record_exists('local_videocompress_queue', ['contenthash' => $file->get_contenthash()])) {
                continue;
            }

            // Add to queue table for tracking.
            $record = new \stdClass();
            $record->fileid = $file->get_id();
            $record->contextid = $contextid;
            $record->contenthash = $file->get_contenthash();
            $record->filename = $file->get_filename();
            $record->filesize = $file->get_filesize();
            $record->mimetype = $file->get_mimetype();
            $record->status = 'pending';
            $record->timecreated = time();
            $record->timemodified = time();
            $queueid = $DB->insert_record('local_videocompress_queue', $record);

            // Queue ad-hoc task for immediate processing.
            $task = new \local_videocompress\task\compress_video();
            $task->set_custom_data([
                'queueid' => $queueid,
                'fileid' => $file->get_id(),
                'contextid' => $contextid,
            ]);
            \core\task\manager::queue_adhoc_task($task, true);
        }
    }
}
