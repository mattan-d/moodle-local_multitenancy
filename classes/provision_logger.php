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

namespace local_multitenancy;

defined('MOODLE_INTERNAL') || die();

/**
 * Append-only provisioning logs for admins (Diagnose UI + cron).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_logger {

    /**
     * @param \stdClass $tenant
     * @param string $step Short step id
     * @param string $message
     * @param string $level info|warn|error
     * @return void
     */
    public static function log_tenant(\stdClass $tenant, string $step, string $message, string $level = 'info'): void {
        $code = (string) ($tenant->shortcode ?? 'unknown');
        self::log($code, $step, $message, $level);
    }

    /**
     * @param string $shortcode
     * @param string $step
     * @param string $message
     * @param string $level
     * @return void
     */
    public static function log(string $shortcode, string $step, string $message, string $level = 'info'): void {
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] [' . $step . '] ' . $message;
        if (PHP_SAPI === 'cli') {
            mtrace('local_multitenancy: ' . $shortcode . ' ' . $line);
        }

        $file = self::log_file_path($shortcode);
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param string $shortcode
     * @param int $maxlines
     * @return string[]
     */
    public static function read_tail(string $shortcode, int $maxlines = 80): array {
        $file = self::log_file_path($shortcode);
        if ($file === '' || !is_readable($file)) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }
        if (count($lines) <= $maxlines) {
            return $lines;
        }
        return array_slice($lines, -$maxlines);
    }

    /**
     * @param string $shortcode
     * @return string Absolute path or empty.
     */
    public static function log_file_path(string $shortcode): string {
        global $CFG;
        $dir = '';
        if (function_exists('local_multitenancy_resolve_registry_dir')) {
            $dir = local_multitenancy_resolve_registry_dir($CFG);
        } else if (!empty($CFG->dataroot)) {
            $dir = rtrim((string) $CFG->dataroot, "/\\\0") . '/multitenancy';
        }
        if ($dir === '') {
            return '';
        }
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $shortcode);
        if ($safe === '') {
            $safe = 'unknown';
        }
        return $dir . '/logs/' . $safe . '.log';
    }
}
