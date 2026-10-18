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
 * Queue and/or run Moodle upgrades for all tenant databases.
 *
 * Usage:
 *   php local/multitenancy/cli/upgrade_tenants.php
 *   php local/multitenancy/cli/upgrade_tenants.php --run
 *   php local/multitenancy/cli/upgrade_tenants.php --force --run
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/multitenancy/lib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'run' => false,
        'force' => false,
        'tenant' => '',
    ],
    [
        'h' => 'help',
        'r' => 'run',
        'f' => 'force',
        't' => 'tenant',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo "Queue / run Moodle upgrades for multitenancy tenants.\n\n"
        . "Options:\n"
        . "-h, --help              Show this help\n"
        . "-r, --run               Run CLI upgrade now (not only queue adhoc)\n"
        . "-f, --force             Include tenants that appear already current\n"
        . "-t, --tenant=CODE       Only this tenant shortcode\n\n"
        . "Examples:\n"
        . "  php local/multitenancy/cli/upgrade_tenants.php --run\n"
        . "  php local/multitenancy/cli/upgrade_tenants.php --tenant=demo03 --run\n";
    exit(0);
}

if (function_exists('local_multitenancy_is_parent_site') && !local_multitenancy_is_parent_site()) {
    cli_error('Run this on the parent (hub) site only.');
}

\local_multitenancy\tenant_upgrader::sync_parent_versions_hash();

$force = !empty($options['force']);
$run = !empty($options['run']);
$code = trim((string) $options['tenant']);

if ($code !== '') {
    $tenant = $DB->get_record('local_multitenancy_tenant', ['shortcode' => $code], '*', MUST_EXIST);
    if ($run) {
        $result = \local_multitenancy\tenant_upgrader::run_cli_upgrade($tenant);
        cli_writeln($tenant->shortcode . ': ' . ($result['ok'] ? 'OK' : 'FAIL') . ' — ' . $result['detail']);
        exit($result['ok'] ? 0 : 1);
    }
    \local_multitenancy\tenant_upgrader::queue((int) $tenant->id);
    cli_writeln('Queued upgrade for ' . $tenant->shortcode);
    exit(0);
}

$tenants = $DB->get_records('local_multitenancy_tenant', ['enabled' => 1], 'sortorder ASC, id ASC');
$ok = 0;
$fail = 0;
$skipped = 0;
foreach ($tenants as $tenant) {
    $status = (string) ($tenant->provisionstatus ?? \local_multitenancy\tenant_provisioner::STATUS_COMPLETE);
    if ($status !== '' && $status !== \local_multitenancy\tenant_provisioner::STATUS_COMPLETE) {
        cli_writeln($tenant->shortcode . ': skip (provision ' . $status . ')');
        $skipped++;
        continue;
    }
    if (!$force && !\local_multitenancy\tenant_upgrader::tenant_record_needs_upgrade($tenant)) {
        cli_writeln($tenant->shortcode . ': already current');
        $skipped++;
        continue;
    }
    if ($run) {
        $result = \local_multitenancy\tenant_upgrader::run_cli_upgrade($tenant);
        cli_writeln($tenant->shortcode . ': ' . ($result['ok'] ? 'OK' : 'FAIL'));
        if ($result['ok']) {
            $ok++;
        } else {
            $fail++;
            cli_writeln('  ' . $result['detail']);
        }
    } else {
        \local_multitenancy\tenant_upgrader::queue((int) $tenant->id);
        cli_writeln($tenant->shortcode . ': queued');
        $ok++;
    }
}

cli_writeln("Done. ok={$ok} fail={$fail} skipped={$skipped}");
exit($fail > 0 ? 1 : 0);
