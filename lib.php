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
 * Library functions for Video Compress plugin.
 *
 * Provides core functionality for automatic video compression including
 * FFmpeg detection, compression settings, and file handling.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Check if the plugin is enabled.
 *
 * Always returns true - plugin is always enabled when installed.
 * To disable, uninstall the plugin.
 *
 * @return bool True if enabled.
 */
function local_videocompress_is_enabled(): bool {
    return true;
}

/**
 * Get minimum file size in bytes.
 *
 * Fixed at 1MB - videos smaller than this are not compressed
 * as the overhead would exceed the savings.
 *
 * @return int Minimum file size in bytes.
 */
function local_videocompress_get_min_filesize(): int {
    return 1 * 1024 * 1024;
}

/**
 * Check if a file is a video based on mimetype.
 *
 * @param \stored_file $file The file to check.
 * @return bool True if the file is a video.
 */
function local_videocompress_is_video(\stored_file $file): bool {
    $mimetype = $file->get_mimetype();
    $videotypes = [
        'video/mp4',
        'video/mpeg',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-ms-wmv',
        'video/webm',
        'video/x-flv',
        'video/3gpp',
        'video/3gpp2',
        'video/ogg',
        'video/x-matroska',
    ];
    return in_array($mimetype, $videotypes);
}

/**
 * Get the path to FFmpeg executable.
 *
 * Checks configured path first, then common system paths,
 * and finally uses `which` command as fallback.
 *
 * @return string|false Path to FFmpeg or false if not found.
 */
function local_videocompress_get_ffmpeg_path() {
    $configpath = get_config('local_videocompress', 'ffmpegpath');
    if (!empty($configpath) && is_executable($configpath)) {
        return $configpath;
    }

    $paths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/bin/ffmpeg'];
    foreach ($paths as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    $output = [];
    $retval = 0;
    exec('which ffmpeg 2>/dev/null', $output, $retval);
    if ($retval === 0 && !empty($output[0]) && is_executable($output[0])) {
        return $output[0];
    }

    return false;
}

/**
 * Get compression settings.
 *
 * Uses optimized settings for maximum compression while maintaining
 * viewable quality: 720p resolution, CRF 30, 96k audio.
 *
 * @param string $quality Ignored - always uses optimized preset.
 * @return array FFmpeg parameters with keys: crf, preset, audio_bitrate, scale.
 */
function local_videocompress_get_compression_params(string $quality = 'optimized'): array {
    return [
        'crf' => '30',
        'preset' => 'faster',
        'audio_bitrate' => '96k',
        'scale' => '720:-2',
    ];
}

/**
 * Compress a video file using FFmpeg.
 *
 * @param string $inputpath Path to input video file.
 * @param string $outputpath Path for output video file.
 * @param string $quality Quality preset (ignored, uses optimized).
 * @return array Result array with keys:
 *               - success (bool): Whether compression succeeded
 *               - error (string): Error message if failed
 *               - input_size (int): Original file size in bytes
 *               - output_size (int): Compressed file size in bytes
 *               - compression_ratio (float): Compression ratio
 *               - output_path (string): Path to output file
 */
function local_videocompress_compress_video(string $inputpath, string $outputpath, string $quality = 'optimized'): array {
    $ffmpeg = local_videocompress_get_ffmpeg_path();
    if (!$ffmpeg) {
        return ['success' => false, 'error' => 'FFmpeg not found on server'];
    }

    $params = local_videocompress_get_compression_params($quality);

    $cmd = escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($inputpath) .
           ' -c:v libx264 -crf ' . escapeshellarg($params['crf']) .
           ' -preset ' . escapeshellarg($params['preset']) .
           ' -vf "scale=' . $params['scale'] . '" ' .
           ' -c:a aac -b:a ' . escapeshellarg($params['audio_bitrate']) .
           ' -movflags +faststart' .
           ' -y ' . escapeshellarg($outputpath) . ' 2>&1';

    $output = [];
    $retval = 0;
    exec($cmd, $output, $retval);

    if ($retval !== 0) {
        return [
            'success' => false,
            'error' => 'FFmpeg compression failed: ' . implode("\n", array_slice($output, -5)),
        ];
    }

    if (!file_exists($outputpath)) {
        return ['success' => false, 'error' => 'Output file was not created'];
    }

    $inputsize = filesize($inputpath);
    $outputsize = filesize($outputpath);
    $ratio = $inputsize > 0 ? round($inputsize / $outputsize, 1) : 0;

    return [
        'success' => true,
        'input_size' => $inputsize,
        'output_size' => $outputsize,
        'compression_ratio' => $ratio,
        'output_path' => $outputpath,
    ];
}

/**
 * Queue a file for compression.
 *
 * @param \stored_file $file The file to compress.
 * @param int $contextid The context ID.
 * @return bool|int Queue record ID if queued successfully, true if already queued, false on failure.
 */
function local_videocompress_queue_file(\stored_file $file, int $contextid) {
    global $DB;

    if (!local_videocompress_is_enabled()) {
        return false;
    }

    $minsize = local_videocompress_get_min_filesize();
    if ($file->get_filesize() < $minsize) {
        return false;
    }

    if ($DB->record_exists('local_videocompress_queue', ['contenthash' => $file->get_contenthash()])) {
        return true;
    }

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

    return $DB->insert_record('local_videocompress_queue', $record);
}

/**
 * Format bytes to human readable string.
 *
 * @param int $bytes The number of bytes.
 * @return string Formatted string (e.g., "5.23 MB").
 */
function local_videocompress_format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } else if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } else if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}
