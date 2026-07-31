<?php
/**
 * Scheduled tasks for Video Compress plugin.
 *
 * Defines scheduled tasks for:
 * - Processing pending videos every 5 minutes (fallback for ad-hoc tasks)
 * - Cleaning up old queue entries daily at 3 AM
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    // Process any pending videos in the queue (fallback for failed ad-hoc tasks).
    [
        'classname' => '\local_videocompress\task\process_queue',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    // Clean up completed/failed queue entries older than 30 days.
    [
        'classname' => '\local_videocompress\task\cleanup_old_entries',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
];
