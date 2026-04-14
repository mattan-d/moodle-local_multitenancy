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

    if ($oldversion < 2026041500) {
        $table = new xmldb_table('local_multitenancy_tenant');

        $oldindex = new xmldb_index('host_uix', XMLDB_INDEX_UNIQUE, ['host']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        $newindex = new xmldb_index('host_ix', XMLDB_INDEX_NOTUNIQUE, ['host']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        $field = new xmldb_field('host', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'shortcode');
        $dbman->change_field_notnull($table, $field);

        upgrade_plugin_savepoint(true, 2026041500, 'local', 'multitenancy');
    }

    return true;
}
