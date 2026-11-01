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
 * Run each tenant's Moodle cron from the parent cron.
 *
 * Course copy and other background work are adhoc tasks stored in the tenant
 * database. They never run if only the parent crontab is configured.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_cron {

    /**
     * Run admin/cli/cron.php once for every enabled, provisioned tenant.
     *
     * @return array{ran:int, ok:int, fail:int, messages:string[]}
     */
    public static function run_all(): array {
        global $DB;

        $summary = [
            'ran' => 0,
            'ok' => 0,
            'fail' => 0,
            'messages' => [],
        ];
        $tenants = $DB->get_records('local_multitenancy_tenant', ['enabled' => 1], 'sortorder ASC, id ASC');
        foreach ($tenants as $tenant) {
            $status = (string) ($tenant->provisionstatus ?? '');
            if ($status !== '' && $status !== tenant_provisioner::STATUS_COMPLETE) {
                continue;
            }
            if (!database_provisioner::moodle_is_installed($tenant)) {
                continue;
            }
            $summary['ran']++;
            $code = (string) $tenant->shortcode;
            mtrace('local_multitenancy: running cron for tenant ' . $code);
            $result = self::run_one($tenant);
            if ($result['ok']) {
                $summary['ok']++;
                $summary['messages'][] = $code . ': cron OK';
            } else {
                $summary['fail']++;
                $summary['messages'][] = $code . ': cron FAIL — ' . $result['detail'];
            }
            if ($result['detail'] !== '') {
                mtrace($result['detail']);
            }
        }
        return $summary;
    }

    /**
     * @param \stdClass $tenant
     * @return array{ok:bool, detail:string}
     */
    public static function run_one(\stdClass $tenant): array {
        global $CFG;

        $code = (string) ($tenant->shortcode ?? '');
        if ($code === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            return ['ok' => false, 'detail' => 'Invalid shortcode'];
        }
        $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
        $script = $CFG->dirroot . '/admin/cli/cron.php';
        if (!is_readable($script)) {
            return ['ok' => false, 'detail' => 'cron.php not found'];
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes, $CFG->dirroot, tenant_upgrader::build_process_env($code));
        if (!is_resource($process)) {
            return ['ok' => false, 'detail' => 'proc_open failed for cron.php'];
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
                return ['ok' => false, 'detail' => 'Tenant cron timed out after ' . $timeout . 's'];
            }
            usleep(200000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = isset($status['exitcode']) ? (int) $status['exitcode'] : -1;
        proc_close($process);

        $tail = trim($stdout . "\n" . $stderr);
        if (strlen($tail) > 1500) {
            $tail = substr($tail, -1500);
        }
        if ($exit === 0) {
            return ['ok' => true, 'detail' => $tail];
        }
        return ['ok' => false, 'detail' => 'cron.php exit ' . $exit . ': ' . $tail];
    }
}
