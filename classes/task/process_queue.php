<?php
/**
 * Scheduled task to process the video compression queue.
 *
 * Processes up to 5 pending videos per execution. This task serves as a fallback
 * for any videos that weren't processed by ad-hoc tasks.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videocompress\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Scheduled task to process pending video compressions.
 */
class process_queue extends \core\task\scheduled_task {

    /**
     * Get the task name.
     *
     * @return string The localised task name.
     */
    public function get_name(): string {
        return get_string('task_processqueue', 'local_videocompress');
    }

    /**
     * Execute the scheduled task.
     *
     * Processes up to 5 pending videos from the queue. Uses fixed compression
     * settings (720p, CRF 30, 96k audio) for all videos.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        if (!local_videocompress_is_enabled()) {
            mtrace('Video Compress: Plugin is disabled.');
            return;
        }

        $ffmpeg = local_videocompress_get_ffmpeg_path();
        if (!$ffmpeg) {
            mtrace('Video Compress: FFmpeg not found. Skipping.');
            return;
        }

        $maxprocess = 5;
        $pending = $DB->get_records('local_videocompress_queue', ['status' => 'pending'], 'timecreated ASC', '*', 0, $maxprocess);

        if (empty($pending)) {
            mtrace('Video Compress: No pending videos in queue.');
            return;
        }

        $fs = get_file_storage();

        foreach ($pending as $item) {
            mtrace("Video Compress: Processing {$item->filename} ({$this->format_bytes($item->filesize)})...");

            $DB->set_field('local_videocompress_queue', 'status', 'processing', ['id' => $item->id]);
            $DB->set_field('local_videocompress_queue', 'timemodified', time(), ['id' => $item->id]);

            $file = $fs->get_file_by_id($item->fileid);
            if (!$file) {
                $this->mark_failed($item->id, 'Original file no longer exists');
                continue;
            }

            $tempdir = make_temp_directory('videocompress');
            $inputpath = $tempdir . '/' . $item->contenthash . '_input';
            $outputpath = $tempdir . '/' . $item->contenthash . '_compressed.mp4';

            $file->copy_content_to($inputpath);

            $result = local_videocompress_compress_video($inputpath, $outputpath);

            if (!$result['success']) {
                $this->mark_failed($item->id, $result['error']);
                $this->safe_unlink($inputpath);
                continue;
            }

            mtrace("  Compressed: {$this->format_bytes($result['output_size'])} ({$result['compression_ratio']}x reduction)");

            if ($result['output_size'] >= $result['input_size']) {
                mtrace("  Skipping: Compressed file is not smaller.");
                $this->mark_completed($item->id, $item->filesize, 1.0);
                $this->safe_unlink($inputpath);
                $this->safe_unlink($outputpath);
                continue;
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

                    $this->mark_completed($item->id, $result['output_size'], $result['compression_ratio']);

                    mtrace("  Success: Video replaced with compressed version.");
                } else {
                    $this->mark_failed($item->id, 'Failed to create new file in Moodle');
                }
            } catch (\Exception $e) {
                $this->mark_failed($item->id, $e->getMessage());
            }

            $this->safe_unlink($inputpath);
            $this->safe_unlink($outputpath);
        }
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
