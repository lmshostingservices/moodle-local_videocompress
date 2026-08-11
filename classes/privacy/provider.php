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
 * Privacy provider for Video Compress plugin.
 *
 * Implements GDPR compliance for user data export and deletion.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videocompress\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider implementation for local_videocompress.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    /**
     * Returns metadata about this plugin's data storage.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_videocompress_log',
            [
                'userid' => 'privacy:metadata:log:userid',
                'filename' => 'privacy:metadata:log:filename',
                'original_size' => 'privacy:metadata:log:original_size',
                'compressed_size' => 'privacy:metadata:log:compressed_size',
                'quality' => 'privacy:metadata:log:quality',
                'timecreated' => 'privacy:metadata:log:timecreated',
            ],
            'privacy:metadata:log'
        );
        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * Video compression logs are stored at the system context level.
     *
     * @param int $userid The user ID.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Check if user has any compression logs.
        global $DB;
        if ($DB->record_exists('local_videocompress_log', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $sql = "SELECT DISTINCT userid FROM {local_videocompress_log} WHERE userid > 0";
        $userlist->add_from_sql('userid', $sql, []);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $logs = $DB->get_records('local_videocompress_log', ['userid' => $userid], 'timecreated DESC');

        if (empty($logs)) {
            return;
        }

        $context = \context_system::instance();
        $subcontext = [get_string('pluginname', 'local_videocompress')];

        // Export each log entry as a structured record.
        foreach ($logs as $log) {
            $exportdata = (object) [
                'filename' => format_string($log->filename, true, ['context' => $context]),
                'original_size' => self::format_bytes((int)$log->original_size),
                'compressed_size' => self::format_bytes((int)$log->compressed_size),
                'space_saved' => self::format_bytes((int)$log->space_saved),
                'compression_ratio' => $log->compression_ratio . 'x',
                'quality' => format_string($log->quality, true, ['context' => $context]),
                'timecreated' => \core_privacy\local\request\transform::datetime($log->timecreated),
            ];

            // Use unique subcontext per record for proper structured export.
            writer::with_context($context)->export_data(
                array_merge($subcontext, ['compression_' . $log->id]),
                $exportdata
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records('local_videocompress_log');
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('local_videocompress_log', ['userid' => $userid]);
    }

    /**
     * Delete multiple users' data within a single context.
     *
     * @param approved_userlist $userlist The approved userlist.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_videocompress_log', "userid {$insql}", $inparams);
    }

    /**
     * Format bytes to human readable string.
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted string.
     */
    private static function format_bytes(int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } else if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } else if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
