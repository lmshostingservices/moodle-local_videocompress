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
 * Ad-hoc task to compress a single video immediately.
 *
 * This task is queued when a video is uploaded and processed on the next cron run.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videocompress\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Ad-hoc task for compressing a single video file.
 */
class compress_video extends \core\task\adhoc_task {
    /**
     * Get the component name.
     *
     * @return string The component name.
     */
    public function get_component(): string {
        return 'local_videocompress';
    }

    /**
     * Execute the task - compress a single video.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $queueid = $data->queueid ?? 0;
        $fileid = $data->fileid ?? 0;

        if (!$queueid || !$fileid) {
            mtrace('Video Compress: Invalid task data - missing queueid or fileid');
            return;
        }

        if (!local_videocompress_is_enabled()) {
            mtrace('Video Compress: Plugin is disabled.');
            return;
        }

        $ffmpeg = local_videocompress_get_ffmpeg_path();
        if (!$ffmpeg) {
            $this->mark_failed($queueid, 'FFmpeg not found on server. Install FFmpeg to enable video compression.');
            return;
        }

        $item = $DB->get_record('local_videocompress_queue', ['id' => $queueid]);
        if (!$item) {
            mtrace('Video Compress: Queue item not found.');
            return;
        }

        if ($item->status !== 'pending') {
            mtrace('Video Compress: Item already processed (status: ' . $item->status . ')');
            return;
        }

        mtrace("Video Compress: Processing {$item->filename} (" . $this->format_bytes($item->filesize) . ")...");

        $DB->set_field('local_videocompress_queue', 'status', 'processing', ['id' => $queueid]);
        $DB->set_field('local_videocompress_queue', 'timemodified', time(), ['id' => $queueid]);

        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file) {
            $this->mark_failed($queueid, 'Original file no longer exists');
            return;
        }

        $tempdir = make_temp_directory('videocompress');
        $inputpath = $tempdir . '/' . $item->contenthash . '_input';
        $outputpath = $tempdir . '/' . $item->contenthash . '_compressed.mp4';

        $file->copy_content_to($inputpath);

        $result = local_videocompress_compress_video($inputpath, $outputpath);

        if (!$result['success']) {
            $this->mark_failed($queueid, $result['error']);
            $this->safe_unlink($inputpath);
            return;
        }

        mtrace("  Compressed: " . $this->format_bytes($result['output_size']) . " ({$result['compression_ratio']}x reduction)");

        if ($result['output_size'] >= $result['input_size']) {
            mtrace("  Skipping replacement: Compressed file is not smaller.");
            $this->mark_completed($queueid, $item->filesize, 1.0);
            $this->safe_unlink($inputpath);
            $this->safe_unlink($outputpath);
            return;
        }

        try {
            $originaluserid = $file->get_userid();
            $originalfilename = $file->get_filename();

            $fileinfo = [
                'contextid' => $file->get_contextid(),
                'component' => $file->get_component(),
                'filearea' => $file->get_filearea(),
                'itemid' => $file->get_itemid(),
                'filepath' => $file->get_filepath(),
                'filename' => pathinfo($originalfilename, PATHINFO_FILENAME) . '.mp4',
                'userid' => $originaluserid,
                'author' => $file->get_author(),
                'license' => $file->get_license(),
            ];

            // Always delete original to save space.
            $file->delete();
            $file = null;

            $newfile = $fs->create_file_from_pathname($fileinfo, $outputpath);

            if ($newfile) {
                $this->log_compression(
                    $originaluserid,
                    $item->filename,
                    $result['input_size'],
                    $result['output_size'],
                    $result['compression_ratio']
                );

                $this->mark_completed($queueid, $result['output_size'], $result['compression_ratio']);

                mtrace("  Success: Video replaced with compressed version.");
            } else {
                $this->mark_failed($queueid, 'Failed to create new file in Moodle');
            }
        } catch (\Exception $e) {
            $this->mark_failed($queueid, $e->getMessage());
        }

        $this->safe_unlink($inputpath);
        $this->safe_unlink($outputpath);
    }

    /**
     * Safely delete a temporary file.
     *
     * @param string $filepath Path to the file to delete.
     * @return void
     */
    protected function safe_unlink(string $filepath): void {
        if (file_exists($filepath)) {
            unlink($filepath);
        }
    }

    /**
     * Mark a queue item as failed.
     *
     * @param int $id Queue item ID.
     * @param string $error Error message.
     * @return void
     */
    protected function mark_failed(int $id, string $error): void {
        global $DB;
        mtrace("  Failed: $error");
        $DB->update_record('local_videocompress_queue', (object)[
            'id' => $id,
            'status' => 'failed',
            'error_message' => $error,
            'timemodified' => time(),
            'timeprocessed' => time(),
        ]);
    }

    /**
     * Mark a queue item as completed.
     *
     * @param int $id Queue item ID.
     * @param int $compressedsize Compressed file size in bytes.
     * @param float $ratio Compression ratio.
     * @return void
     */
    protected function mark_completed(int $id, int $compressedsize, float $ratio): void {
        global $DB;
        $DB->update_record('local_videocompress_queue', (object)[
            'id' => $id,
            'status' => 'completed',
            'compressed_size' => $compressedsize,
            'compression_ratio' => $ratio,
            'timemodified' => time(),
            'timeprocessed' => time(),
        ]);
    }

    /**
     * Log compression for statistics.
     *
     * @param int|null $userid User ID who uploaded the video.
     * @param string $filename Original filename.
     * @param int $original Original file size in bytes.
     * @param int $compressed Compressed file size in bytes.
     * @param float $ratio Compression ratio.
     * @return void
     */
    protected function log_compression(?int $userid, string $filename, int $original, int $compressed, float $ratio): void {
        global $DB;
        $DB->insert_record('local_videocompress_log', (object)[
            'userid' => $userid ?: 0,
            'filename' => $filename,
            'original_size' => $original,
            'compressed_size' => $compressed,
            'compression_ratio' => $ratio,
            'space_saved' => $original - $compressed,
            'quality' => 'optimized',
            'timecreated' => time(),
        ]);
    }

    /**
     * Format bytes to human readable string.
     *
     * @param int $bytes Number of bytes.
     * @return string Formatted string (e.g., "5.23 MB").
     */
    protected function format_bytes(int $bytes): string {
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
