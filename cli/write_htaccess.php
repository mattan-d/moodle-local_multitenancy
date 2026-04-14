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
 * Writes Apache rewrite rules into Moodle root .htaccess so tenant URLs like
 * https://site/multitenancy/CODE/course/view.php map to real PHP files.
 *
 * Usage: php local/multitenancy/cli/write_htaccess.php [--dry-run] [--remove] [--help]
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'dry-run' => false,
        'remove' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error('Unknown option: ' . implode(', ', $unrecognized));
}

if ($options['help']) {
    echo get_string('cliwritehtaccess_help', 'local_multitenancy');
    exit(0);
}

//require_capability('moodle/site:config', context_system::instance());

$pathprefix = get_config('local_multitenancy', 'pathprefix');
if (empty($pathprefix)) {
    $pathprefix = '/multitenancy';
}
$p = trim((string) $pathprefix, '/');
if ($p === '') {
    cli_error('Invalid path prefix in plugin settings.');
}
$pquoted = preg_quote($p, '#');

$begin = "# === BEGIN local_multitenancy ===\n";
$end = "# === END local_multitenancy ===\n";

$block = $begin;
$block .= "<IfModule mod_rewrite.c>\n";
$block .= "RewriteEngine On\n";
$block .= "RewriteRule ^{$pquoted}/([A-Za-z0-9_]+)/?\$ index.php [E=MOODLE_TENANT:\$1,QSA,L]\n";
$block .= "RewriteRule ^{$pquoted}/([A-Za-z0-9_]+)/(.*)\$ \$2 [E=MOODLE_TENANT:\$1,QSA,L]\n";
$block .= "</IfModule>\n";
$block .= $end;

$htpath = $CFG->dirroot . DIRECTORY_SEPARATOR . '.htaccess';

if ($options['remove']) {
    if (!file_exists($htpath)) {
        cli_writeln('No .htaccess file; nothing to remove.');
        exit(0);
    }
    $old = file_get_contents($htpath);
    if ($old === false) {
        cli_error('Could not read .htaccess');
    }
    $pattern = '#' . preg_quote($begin, '#') . '.*?' . preg_quote($end, '#') . '\s*#s';
    $new = preg_replace($pattern, '', $old, 1);
    if ($new === $old) {
        cli_writeln('Multitenancy block not found in .htaccess.');
        exit(0);
    }
    if ($options['dry-run']) {
        cli_writeln($new);
        exit(0);
    }
    file_put_contents($htpath, $new);
    cli_writeln('Removed multitenancy block from .htaccess.');
    exit(0);
}

if (!is_writable(dirname($htpath)) && !file_exists($htpath)) {
    cli_error('Cannot create .htaccess (directory not writable).');
}

$old = file_exists($htpath) ? file_get_contents($htpath) : '';
if ($old === false) {
    cli_error('Could not read .htaccess');
}

if (strpos($old, $begin) !== false) {
    $pattern = '#' . preg_quote($begin, '#') . '.*?' . preg_quote($end, '#') . '\s*#s';
    $new = preg_replace($pattern, $block, $old, 1);
} else {
    $new = rtrim($old) . "\n\n" . $block;
}

if ($options['dry-run']) {
    cli_writeln($new);
    exit(0);
}

if (file_exists($htpath) && !is_writable($htpath)) {
    cli_error('.htaccess exists but is not writable.');
}

file_put_contents($htpath, $new);
cli_writeln('Updated ' . $htpath);

set_config('routingmode', 'rewrite', 'local_multitenancy');
\local_multitenancy\registry_writer::sync();
cli_writeln('Set routing mode to "rewrite" and rebuilt registry.php.');
cli_writeln(get_string('cliwritehtaccess_done', 'local_multitenancy'));
