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

require_once(__DIR__ . '/../upgrade_lib.php');

/**
 * Automatic Moodle upgrade for tenant databases (shared codebase).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_upgrader {

    /**
     * Persist parent allversionshash so bootstrap can detect stale tenants.
     *
     * @return string Hash written (empty on failure)
     */
    public static function sync_parent_versions_hash(): string {
        if (!class_exists('\core_component', false) && !class_exists('core_component')) {
            return '';
        }
        $hash = \core_component::get_all_versions_hash();
        if ($hash === '' || $hash === null) {
            return '';
        }
        $hash = (string) $hash;
        set_config('parent_allversionshash', $hash, 'local_multitenancy');

        $file = local_multitenancy_parent_versions_hash_file();
        if ($file !== '') {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0770, true);
            }
            @file_put_contents($file, $hash . "\n");
        }
        return $hash;
    }

    /**
     * Queue adhoc CLI upgrade for one tenant.
     *
     * @param int $tenantid
     * @return bool
     */
    public static function queue(int $tenantid): bool {
        global $DB;

        $tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $tenantid]);
        if (!$tenant || empty($tenant->enabled)) {
            return false;
        }
        $status = (string) ($tenant->provisionstatus ?? tenant_provisioner::STATUS_COMPLETE);
        if ($status !== '' && $status !== tenant_provisioner::STATUS_COMPLETE) {
            return false;
        }

        $task = new \local_multitenancy\task\upgrade_tenant();
        $task->set_custom_data((object) ['tenantid' => $tenantid]);
        $task->set_component('local_multitenancy');
        \core\task\manager::queue_adhoc_task($task, true);
        local_multitenancy_queue_tenant_upgrade_flag((string) $tenant->shortcode);
        provision_logger::log_tenant($tenant, 'upgrade', 'Queued automatic upgrade');
        return true;
    }

    /**
     * Queue upgrades for all enabled tenants that are behind the codebase.
     *
     * @param bool $force All enabled complete tenants, even if versions match
     * @return int Number queued
     */
    public static function queue_all_needing_upgrade(bool $force = false): int {
        global $DB;

        self::sync_parent_versions_hash();

        $tenants = $DB->get_records('local_multitenancy_tenant', ['enabled' => 1], 'sortorder ASC, id ASC');
        $queued = 0;
        foreach ($tenants as $tenant) {
            $status = (string) ($tenant->provisionstatus ?? tenant_provisioner::STATUS_COMPLETE);
            if ($status !== '' && $status !== tenant_provisioner::STATUS_COMPLETE) {
                continue;
            }
            if (!$force && !self::tenant_record_needs_upgrade($tenant)) {
                continue;
            }
            if (self::queue((int) $tenant->id)) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Process flag files written by bootstrap when a tenant hit a stale DB.
     *
     * @return int Queued
     */
    public static function process_upgrade_flags(): int {
        global $DB;

        $dir = local_multitenancy_upgrade_queue_dir();
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }
        $queued = 0;
        foreach (glob($dir . '/*.flag') ?: [] as $file) {
            $code = basename($file, '.flag');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
                @unlink($file);
                continue;
            }
            $tenant = $DB->get_record('local_multitenancy_tenant', ['shortcode' => $code, 'enabled' => 1]);
            if ($tenant && self::queue((int) $tenant->id)) {
                $queued++;
            }
            @unlink($file);
        }
        return $queued;
    }

    /**
     * If the parent versions hash changed since last run, queue all tenants.
     *
     * @return int Queued (0 when hash unchanged)
     */
    public static function queue_if_parent_changed(): int {
        $hash = self::sync_parent_versions_hash();
        if ($hash === '') {
            return 0;
        }
        $last = (string) get_config('local_multitenancy', 'last_upgraded_parent_hash');
        if ($last === $hash) {
            // Still pick up any version-mismatched tenants / flags.
            $fromflags = self::process_upgrade_flags();
            $needing = self::queue_all_needing_upgrade(false);
            return $fromflags + $needing;
        }
        $queued = self::queue_all_needing_upgrade(true);
        set_config('last_upgraded_parent_hash', $hash, 'local_multitenancy');
        return $queued;
    }

    /**
     * @param \stdClass $tenant
     * @return bool
     */
    public static function tenant_record_needs_upgrade(\stdClass $tenant): bool {
        $row = [
            'shortcode' => (string) $tenant->shortcode,
            'dbhost' => (string) $tenant->dbhost,
            'dbname' => (string) $tenant->dbname,
            'dbuser' => (string) $tenant->dbuser,
            'dbpass' => (string) $tenant->dbpass,
            'dbtype' => (string) $tenant->dbtype,
            'prefix' => (string) ($tenant->dbprefix ?? 'mdl_'),
            'dboptions' => [],
        ];
        if (!empty($tenant->dboptions)) {
            $decoded = json_decode((string) $tenant->dboptions, true);
            if (is_array($decoded)) {
                $row['dboptions'] = $decoded;
            }
        }
        return local_multitenancy_tenant_needs_code_upgrade($row);
    }

    /**
     * Run admin/cli/upgrade.php for one tenant in a separate PHP process.
     *
     * @param \stdClass $tenant
     * @return array{ok:bool, detail:string}
     */
    public static function run_cli_upgrade(\stdClass $tenant): array {
        global $CFG;

        $code = (string) ($tenant->shortcode ?? '');
        if ($code === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            return ['ok' => false, 'detail' => 'Invalid shortcode'];
        }
        if (!database_provisioner::moodle_is_installed($tenant)) {
            return ['ok' => false, 'detail' => 'Tenant Moodle not installed yet'];
        }

        $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
        $script = $CFG->dirroot . '/admin/cli/upgrade.php';
        if (!is_readable($script)) {
            return ['ok' => false, 'detail' => 'upgrade.php not found'];
        }

        provision_logger::log_tenant($tenant, 'upgrade', 'Starting CLI upgrade');

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --non-interactive --allow-unstable';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = self::build_process_env($code);
        $process = @proc_open($cmd, $descriptors, $pipes, $CFG->dirroot, $env);
        if (!is_resource($process)) {
            return ['ok' => false, 'detail' => 'proc_open failed for upgrade.php'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();
        $timeout = 3600;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                provision_logger::log_tenant($tenant, 'upgrade', 'CLI upgrade timed out', 'error');
                return ['ok' => false, 'detail' => 'CLI upgrade timed out after ' . $timeout . 's'];
            }
            usleep(200000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $tail = trim(substr($stdout . "\n" . $stderr, -2000));
        if ($exit !== 0) {
            provision_logger::log_tenant($tenant, 'upgrade', 'CLI failed (exit ' . $exit . '): ' . $tail, 'error');
            return ['ok' => false, 'detail' => 'upgrade.php exit ' . $exit . ': ' . $tail];
        }

        // Re-check; CLI may no-op successfully when already current.
        if (self::tenant_record_needs_upgrade($tenant)) {
            // Reload tenant in case of stale object; still behind.
            provision_logger::log_tenant($tenant, 'upgrade', 'CLI finished but versions still mismatch: ' . $tail, 'warn');
            return ['ok' => false, 'detail' => 'Versions still mismatch after CLI: ' . $tail];
        }

        provision_logger::log_tenant($tenant, 'upgrade', 'CLI upgrade OK');
        $flag = local_multitenancy_upgrade_queue_dir() . '/' . $code . '.flag';
        if (is_file($flag)) {
            @unlink($flag);
        }
        return ['ok' => true, 'detail' => $tail !== '' ? $tail : 'OK'];
    }

    /**
     * @param string $shortcode
     * @return array<string, string>
     */
    private static function build_process_env(string $shortcode): array {
        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                $env[$key] = $value;
            }
        }
        // Prefer real environment when available.
        if (function_exists('getenv')) {
            foreach (['PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'TMPDIR', 'TMP', 'TEMP'] as $key) {
                $v = getenv($key);
                if (is_string($v) && $v !== '') {
                    $env[$key] = $v;
                }
            }
        }
        $env['MOODLE_TENANT'] = $shortcode;
        return $env;
    }
}
