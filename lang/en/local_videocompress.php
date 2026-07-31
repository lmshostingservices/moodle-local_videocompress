<?php
/**
 * Language strings for Video Compress plugin.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General strings.
$string['pluginname'] = 'Video Compress';
$string['plugininfo'] = 'Automatic Video Compression';
$string['plugininfo_desc'] = 'This plugin automatically compresses all video uploads to save storage space. Videos are compressed to 720p with optimised quality settings. No configuration needed - compression happens automatically for all videos over 1MB.';
$string['compressionlogs'] = 'Video Compression Logs';

// Settings strings.
$string['ffmpegpath'] = 'FFmpeg Path (Optional)';
$string['ffmpegpath_desc'] = 'Custom path to FFmpeg if not in standard locations. Leave blank to auto-detect.';
$string['statussettings'] = 'Status';
$string['ffmpegstatus'] = 'FFmpeg Status';
$string['ffmpeg_found'] = 'FFmpeg found at: {$a}';
$string['ffmpeg_notfound'] = 'FFmpeg not found! Video compression will not work. Please install FFmpeg on your server.';

// Task strings.
$string['task_processqueue'] = 'Process video compression queue';
$string['task_cleanup'] = 'Clean up old compression records';

// Capability strings.
$string['videocompress:viewstats'] = 'View video compression statistics';
$string['videocompress:manage'] = 'Manage video compression settings';

// Privacy API strings.
$string['privacy:metadata:log'] = 'Video compression history for the user.';
$string['privacy:metadata:log:userid'] = 'The ID of the user who uploaded the video.';
$string['privacy:metadata:log:filename'] = 'The name of the video file.';
$string['privacy:metadata:log:original_size'] = 'The original file size before compression.';
$string['privacy:metadata:log:compressed_size'] = 'The file size after compression.';
$string['privacy:metadata:log:quality'] = 'The compression quality preset used.';
$string['privacy:metadata:log:timecreated'] = 'When the compression occurred.';

// API Credentials
$string['apicredentials'] = 'API Credentials';
$string['apicredentials_desc'] = 'Enter your AI Grader credentials to enable plugin unlock verification. These credentials are available from your AI Grader dashboard at lms-labs.com.';
$string['siteid'] = 'Site ID';
$string['siteid_desc'] = 'Your unique Site ID from the AI Grader dashboard.';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Your API Key from the AI Grader dashboard.';
$string['centralconfig_fallback'] = '(Fallback - Central Config takes priority if installed)';
