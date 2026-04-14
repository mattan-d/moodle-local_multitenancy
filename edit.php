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
 * Add or edit a tenant.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('local_multitenancy_manage');

require_capability('local/multitenancy:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/multitenancy/edit.php', ['id' => $id]));
if ($id) {
    $record = $DB->get_record('local_multitenancy_tenant', ['id' => $id], '*', MUST_EXIST);
    $title = get_string('edittenant', 'local_multitenancy');
} else {
    $record = null;
    $title = get_string('addtenant', 'local_multitenancy');
}
$PAGE->set_title($title);
$PAGE->set_heading($title);

$returnurl = new moodle_url('/local/multitenancy/manage.php');

$buildtenantvalues = static function(string $shortcode) use ($CFG): array {
    $code = trim($shortcode);
    $lower = \core_text::strtolower($code);
    $basewww = rtrim((string) $CFG->wwwroot, '/');
    $basedataroot = rtrim((string) $CFG->dataroot, "/\\\0");
    return [
        'host' => $lower . '.tenant.local',
        'wwwroot' => $basewww . '/local/multitenancy/users/' . rawurlencode($code),
        'dataroot' => $basedataroot . '/multitenancy/tenants/' . $code,
    ];
};

$buildtenantdbvalues = static function(string $shortcode) use ($CFG): array {
    $code = \core_text::strtolower(trim($shortcode));
    $code = preg_replace('/[^a-zA-Z0-9_]+/', '_', $code);
    $base = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $CFG->dbname);
    if ($base === '') {
        $base = 'moodle';
    }
    if ($code === '') {
        $code = 'tenant';
    }
    $dbname = $base . '_' . $code;
    if (strlen($dbname) > 64) {
        $suffix = '_' . substr(md5($code), 0, 6);
        $dbname = substr($dbname, 0, 64 - strlen($suffix)) . $suffix;
    }
    return [
        'dbhost' => (string) $CFG->dbhost,
        'dbname' => $dbname,
        'dbuser' => (string) $CFG->dbuser,
        'dbpass' => (string) $CFG->dbpass,
        'dbtype' => (string) $CFG->dbtype,
        'dblibrary' => (string) $CFG->dblibrary,
        'dbprefix' => (string) $CFG->prefix,
    ];
};

$ensuretenantdbschema = static function(array $dbvalues) use ($CFG): ?string {
    if (!in_array((string) $dbvalues['dbtype'], ['mysqli', 'mariadb', 'auroramysql'], true)) {
        return null;
    }
    $conn = @new mysqli((string) $dbvalues['dbhost'], (string) $dbvalues['dbuser'], (string) $dbvalues['dbpass']);
    if ($conn->connect_errno) {
        return 'DB connect failed: ' . $conn->connect_error;
    }
    $parentdb = $conn->real_escape_string((string) $CFG->dbname);
    $cs = 'utf8mb4';
    $coll = 'utf8mb4_unicode_ci';
    $res = $conn->query("SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS coll FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$parentdb}'");
    if ($res && ($row = $res->fetch_assoc())) {
        if (!empty($row['cs'])) {
            $cs = (string) $row['cs'];
        }
        if (!empty($row['coll'])) {
            $coll = (string) $row['coll'];
        }
    }
    if ($res) {
        $res->close();
    }
    $dbname = '`' . str_replace('`', '``', (string) $dbvalues['dbname']) . '`';
    $sql = "CREATE DATABASE IF NOT EXISTS {$dbname} DEFAULT CHARACTER SET {$cs} COLLATE {$coll}";
    if (!$conn->query($sql)) {
        $err = $conn->error;
        $conn->close();
        return 'CREATE DATABASE failed: ' . $err;
    }
    $conn->close();
    return null;
};

$form = new \local_multitenancy\form\tenant_edit_form(null, ['existing' => $record]);

if ($record) {
    $data = (array) $record;
    if (!empty($record->dboptions)) {
        $decoded = json_decode($record->dboptions, true);
        $data['dboptions'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $record->dboptions;
    } else {
        $data['dboptions'] = '';
    }
    $form->set_data($data);
}

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    $now = time();
    $row = new stdClass();
    $row->shortcode = trim($data->shortcode);
    $generated = $buildtenantvalues($row->shortcode);
    $generateddb = $buildtenantdbvalues($row->shortcode);
    $row->name = trim($data->name);
    $row->host = $generated['host'];
    $row->wwwroot = $generated['wwwroot'];
    $row->dataroot = $generated['dataroot'];
    $row->dbhost = $generateddb['dbhost'];
    $row->dbname = $generateddb['dbname'];
    $row->dbuser = $generateddb['dbuser'];
    $row->dbpass = $generateddb['dbpass'];
    $row->dbprefix = $generateddb['dbprefix'];
    $row->dbtype = $generateddb['dbtype'];
    $row->dblibrary = $generateddb['dblibrary'];
    $row->enabled = !empty($data->enabled) ? 1 : 0;
    $row->sortorder = (int) $data->sortorder;
    $opts = trim($data->dboptions ?? '');
    if ($opts === '') {
        $row->dboptions = null;
    } else {
        $row->dboptions = $opts;
    }

    if (!empty($data->id)) {
        $row->id = $data->id;
        $row->timemodified = $now;
        $DB->update_record('local_multitenancy_tenant', $row);
    } else {
        $row->timecreated = $now;
        $row->timemodified = $now;
        $DB->insert_record('local_multitenancy_tenant', $row);
    }

    if (!is_dir($row->dataroot) && !make_writable_directory($row->dataroot, false)) {
        \core\notification::warning(get_string('datarootautocreatefailed', 'local_multitenancy', $row->dataroot));
    }
    $schemaerr = $ensuretenantdbschema($generateddb);
    if ($schemaerr !== null) {
        \core\notification::warning(get_string('dbschemaautocreatefailed', 'local_multitenancy', $schemaerr));
    } else {
        \core\notification::info(get_string('dbschemaautocreated', 'local_multitenancy', $generateddb['dbname']));
    }

    if (($data->dbseedmode ?? 'copyparent') === 'copyparent') {
        $tenant = $DB->get_record('local_multitenancy_tenant', ['shortcode' => $row->shortcode], '*', MUST_EXIST);
        $result = \local_multitenancy\database_provisioner::provision_if_empty($tenant);
        if ($result['state'] === 'provisioned') {
            \core\notification::success(get_string('dbprovisioned', 'local_multitenancy', $tenant->dbname));
        } else if ($result['state'] === 'skipped_notempty') {
            \core\notification::info(get_string('dbprovisionskippednotempty', 'local_multitenancy', $tenant->dbname));
        } else if ($result['state'] === 'skipped_unsupported') {
            \core\notification::warning(get_string('dbprovisionskippedunsupported', 'local_multitenancy', $tenant->dbtype));
        } else {
            \core\notification::error(get_string('dbprovisionfailed', 'local_multitenancy', $result['detail']));
        }
    } else {
        $tenant = $DB->get_record('local_multitenancy_tenant', ['shortcode' => $row->shortcode], '*', MUST_EXIST);
        $result = \local_multitenancy\database_provisioner::provision_clean_if_empty($tenant);
        if ($result['state'] === 'provisioned') {
            \core\notification::success(get_string('dbseedcleanselected', 'local_multitenancy', $tenant->dbname));
        } else if ($result['state'] === 'skipped_notempty') {
            \core\notification::info(get_string('dbseedcleanselected', 'local_multitenancy', $generateddb['dbname']));
        } else if ($result['state'] === 'skipped_unsupported') {
            \core\notification::warning(get_string('dbprovisionskippedunsupported', 'local_multitenancy', $tenant->dbtype));
        } else {
            \core\notification::error(get_string('dbprovisionfailed', 'local_multitenancy', $result['detail']));
        }
    }

    \local_multitenancy\gateway_manager::sync();
    if (\local_multitenancy\registry_writer::sync()) {
        \core\notification::success(get_string('registryupdated', 'local_multitenancy'));
    } else {
        \core\notification::warning(get_string('registrynotwritten', 'local_multitenancy'));
    }

    redirect($returnurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
