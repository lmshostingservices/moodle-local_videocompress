<?php
/**
 * Event observers for Video Compress plugin.
 *
 * Registers event handlers for file upload events to trigger video compression.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\assessable_uploaded',
        'callback' => '\local_videocompress\observer::assessable_uploaded',
        'internal' => false,
        'priority' => 100,
    ],
];
