<?php
/**
 * Capabilities for Video Compress plugin.
 *
 * Defines the capabilities used to control access to plugin features.
 *
 * @package    local_videocompress
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Capability to view compression statistics and logs.
    'local/videocompress:viewstats' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:config',
    ],
    // Capability to manage video compression settings.
    'local/videocompress:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'riskbitmask' => RISK_CONFIG,
        'clonepermissionsfrom' => 'moodle/site:config',
    ],
];
