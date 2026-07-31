<?php
/**
 * Scheduled task to clean up old queue entries.
 *
 * Removes completed and failed queue entries older than 30 days
 * to prevent the queue table from growing indefinitely.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videocompress\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task for cleaning up old compression queue entries.
 */
class cleanup_old_entries extends \core\task\scheduled_task {

    /**
     * Get the task name.
     *
     * @return string The localised task name.
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'local_videocompress');
    }

    /**
     * Execute the cleanup task.
     *
     * Deletes queue entries that are completed or failed and older than 30 days.
     * This keeps the queue table from growing too large over time.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $thirtydays = time() - (30 * 24 * 60 * 60);

        // Use parameterised query for status values.
        list($insql, $inparams) = $DB->get_in_or_equal(['completed', 'failed'], SQL_PARAMS_NAMED);
        $params = array_merge($inparams, ['cutoff' => $thirtydays]);

        $deleted = $DB->delete_records_select(
            'local_videocompress_queue',
            "status {$insql} AND timeprocessed < :cutoff",
            $params
        );

        if ($deleted) {
            mtrace("Video Compress: Cleaned up {$deleted} old queue entries.");
        }
    }
}
