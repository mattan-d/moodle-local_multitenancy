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

$form = new \local_multitenancy\form\tenant_edit_form(null, ['existing' => $record]);

if ($record) {
    $data = (array) $record;
    unset($data['dbpass']);
    $data['dbpass'] = '';
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
    $row->name = trim($data->name);
    $row->host = $generated['host'];
    $row->wwwroot = $generated['wwwroot'];
    $row->dataroot = $generated['dataroot'];
    $row->dbhost = trim($data->dbhost);
    $row->dbname = trim($data->dbname);
    $row->dbuser = trim($data->dbuser);
    $row->dbprefix = trim($data->dbprefix);
    $row->dbtype = trim($data->dbtype);
    $row->dblibrary = trim($data->dblibrary);
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
        if (trim($data->dbpass ?? '') === '') {
            unset($row->dbpass);
        } else {
            $row->dbpass = $data->dbpass;
        }
        $DB->update_record('local_multitenancy_tenant', $row);
    } else {
        $row->dbpass = $data->dbpass;
        $row->timecreated = $now;
        $row->timemodified = $now;
        $DB->insert_record('local_multitenancy_tenant', $row);
    }

    if (!is_dir($row->dataroot) && !make_writable_directory($row->dataroot, false)) {
        \core\notification::warning(get_string('datarootautocreatefailed', 'local_multitenancy', $row->dataroot));
    }

    if (!empty($data->initdbfromparent)) {
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
