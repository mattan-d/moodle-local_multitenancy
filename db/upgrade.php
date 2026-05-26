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
 * Upgrade steps.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade hook.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_multitenancy_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026041400) {
        upgrade_plugin_savepoint(true, 2026041400, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041410) {
        \local_multitenancy\gateway_manager::sync();
        upgrade_plugin_savepoint(true, 2026041410, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041420) {
        \local_multitenancy\gateway_manager::sync();
        upgrade_plugin_savepoint(true, 2026041420, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041440) {
        upgrade_plugin_savepoint(true, 2026041440, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041450) {
        upgrade_plugin_savepoint(true, 2026041450, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041460) {
        upgrade_plugin_savepoint(true, 2026041460, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041470) {
        $table = new xmldb_table('local_multitenancy_tenant');
        $field = new xmldb_field('showonlogin', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026041470, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041480) {
        upgrade_plugin_savepoint(true, 2026041480, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041490) {
        $table = new xmldb_table('local_multitenancy_tenant');
        $statusfield = new xmldb_field('provisionstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'complete');
        if (!$dbman->field_exists($table, $statusfield)) {
            $dbman->add_field($table, $statusfield);
        }
        $errorfield = new xmldb_field('provisionerror', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $errorfield)) {
            $dbman->add_field($table, $errorfield);
        }
        upgrade_plugin_savepoint(true, 2026041490, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041500) {
        upgrade_plugin_savepoint(true, 2026041500, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041510) {
        $table = new xmldb_table('local_multitenancy_tenant');
        $midurimfield = new xmldb_field('midurim', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $midurimfield)) {
            $dbman->add_field($table, $midurimfield);
        }
        $formatfield = new xmldb_field('midurimformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        if (!$dbman->field_exists($table, $formatfield)) {
            $dbman->add_field($table, $formatfield);
        }
        upgrade_plugin_savepoint(true, 2026041510, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041511) {
        $DB->execute(
            "UPDATE {local_multitenancy_tenant} SET midurim = '' WHERE midurim IS NULL"
        );
        $DB->execute(
            "UPDATE {local_multitenancy_tenant} SET midurimformat = ? WHERE midurimformat IS NULL",
            [FORMAT_HTML]
        );
        upgrade_plugin_savepoint(true, 2026041511, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041512) {
        get_string_manager()->reset_caches();
        upgrade_plugin_savepoint(true, 2026041512, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041513) {
        upgrade_plugin_savepoint(true, 2026041513, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041514) {
        upgrade_plugin_savepoint(true, 2026041514, 'local', 'multitenancy');
    }

    if ($oldversion < 2026041515) {
        upgrade_plugin_savepoint(true, 2026041515, 'local', 'multitenancy');
    }

    return true;
}
