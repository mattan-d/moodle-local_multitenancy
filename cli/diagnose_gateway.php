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
 * CLI: diagnose tenant gateway / blank page causes.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/gateway_manager.php');
require_once(__DIR__ . '/../classes/gateway_diagnostic.php');

use local_multitenancy\gateway_diagnostic;

[$options, $unrecognised] = cli_get_params(
    [
        'shortcode' => '',
        'help' => false,
    ],
    [
        'h' => 'help',
        's' => 'shortcode',
    ]
);

if (!empty($unrecognised)) {
    cli_writeln('Unrecognised options: ' . implode(' ', $unrecognised));
    exit(1);
}

if ($options['help'] && $options['shortcode'] === '') {
    echo get_string('cli_diag_help', 'local_multitenancy');
    exit(0);
}
if ($options['shortcode'] === '') {
    echo get_string('cli_diag_help', 'local_multitenancy');
    exit(1);
}

$results = gateway_diagnostic::run($options['shortcode']);

$haderror = false;
foreach ($results as $row) {
    $prefix = $row['level'] === gateway_diagnostic::LEVEL_ERROR ? '[ERR] ' :
        ($row['level'] === gateway_diagnostic::LEVEL_WARN ? '[WRN] ' : '[OK ] ');
    $msg = get_string('cli_diag_' . $row['key'], 'local_multitenancy', $row['detail']);
    cli_writeln($prefix . $msg);
    if (!empty($row['fix'])) {
        cli_writeln('       FIX: ' . get_string('diagnose_' . $row['fix'], 'local_multitenancy'));
    }
    if ($row['level'] === gateway_diagnostic::LEVEL_ERROR) {
        $haderror = true;
    }
}

cli_writeln('');
cli_writeln(get_string($haderror ? 'cli_diag_summary_fail' : 'cli_diag_summary_ok', 'local_multitenancy'));

exit($haderror ? 2 : 0);
