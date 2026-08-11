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
 * Settings for Video Compress plugin.
 *
 * Provides minimal configuration options - FFmpeg path override only.
 * All compression settings are fixed for zero-config operation.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_videocompress', get_string('pluginname', 'local_videocompress'));
    $ADMIN->add('localplugins', $settings);

    // Add external page for compression logs/dashboard.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_videocompress_logs',
        get_string('compressionlogs', 'local_videocompress'),
        new moodle_url('/local/videocompress/index.php'),
        'moodle/site:config'
    ));

    // Check if Central Config plugin is installed (provides site-wide credentials)
    $centralconfiginstalled = file_exists($CFG->dirroot . '/local/aiconfig/version.php');
    
    // API Credentials heading
    $settings->add(new admin_setting_heading(
        'local_videocompress/apicredentials',
        get_string('apicredentials', 'local_videocompress'),
        get_string('apicredentials_desc', 'local_videocompress')
    ));
    
    // Site ID (fallback if Central Config not installed)
    $settings->add(new admin_setting_configtext(
        'local_videocompress/siteid',
        get_string('siteid', 'local_videocompress'),
        get_string('siteid_desc', 'local_videocompress') . ($centralconfiginstalled ? ' ' . get_string('centralconfig_fallback', 'local_videocompress') : ''),
        '',
        PARAM_TEXT
    ));
    
    // API Key (fallback if Central Config not installed)
    $settings->add(new admin_setting_configpasswordunmask(
        'local_videocompress/apikey',
        get_string('apikey', 'local_videocompress'),
        get_string('apikey_desc', 'local_videocompress') . ($centralconfiginstalled ? ' ' . get_string('centralconfig_fallback', 'local_videocompress') : ''),
        ''
    ));

    // Plugin information heading.
    $settings->add(new admin_setting_heading(
        'local_videocompress/info',
        get_string('plugininfo', 'local_videocompress'),
        get_string('plugininfo_desc', 'local_videocompress')
    ));

    // FFmpeg path (only setting - for custom paths).
    $settings->add(new admin_setting_configtext(
        'local_videocompress/ffmpegpath',
        get_string('ffmpegpath', 'local_videocompress'),
        get_string('ffmpegpath_desc', 'local_videocompress'),
        ''
    ));

    // FFmpeg status display.
    $settings->add(new admin_setting_heading(
        'local_videocompress/status',
        get_string('statussettings', 'local_videocompress'),
        ''
    ));

    // Build status message using Moodle notification classes.
    $ffmpegpath = local_videocompress_get_ffmpeg_path();
    if ($ffmpegpath) {
        $statushtml = \html_writer::div(
            get_string('ffmpeg_found', 'local_videocompress', $ffmpegpath),
            'alert alert-success'
        );
    } else {
        $statushtml = \html_writer::div(
            get_string('ffmpeg_notfound', 'local_videocompress'),
            'alert alert-danger'
        );
    }

    $settings->add(new admin_setting_heading(
        'local_videocompress/ffmpegstatus',
        get_string('ffmpegstatus', 'local_videocompress'),
        $statushtml
    ));
}
