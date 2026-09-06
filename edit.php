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

global $DB, $PAGE, $OUTPUT, $CFG;

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
    $maxdbnamelen = ((string) $CFG->dbtype === 'pgsql') ? 63 : 64;
    if (strlen($dbname) > $maxdbnamelen) {
        $suffix = '_' . substr(md5($code), 0, 6);
        $dbname = substr($dbname, 0, $maxdbnamelen - strlen($suffix)) . $suffix;
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

$allowedshortcodesraw = (string) get_config('local_multitenancy', 'allowedshortcodes');
$allowedshortcodes = [];
if ($allowedshortcodesraw !== '') {
    $rows = preg_split('/\R/u', $allowedshortcodesraw) ?: [];
    foreach ($rows as $row) {
        $code = trim($row);
        if ($code === '') {
            continue;
        }
        $allowedshortcodes[$code] = $code;
    }
}

$editoroptions = [
    'subdirs' => 0,
    'maxbytes' => 0,
    'maxfiles' => 0,
    'context' => context_system::instance(),
];

$form = new \local_multitenancy\form\tenant_edit_form(null, [
    'existing' => $record,
    'allowedshortcodes' => $allowedshortcodes,
]);

if ($record) {
    $formdata = file_prepare_standard_editor(
        $record,
        'midurim',
        $editoroptions,
        context_system::instance(),
        'local_multitenancy',
        'tenant_midurim',
        (int) $record->id
    );
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    $now = time();
    $isnew = empty($data->id);
    $row = new stdClass();
    if ($isnew) {
        $row->shortcode = trim($data->shortcode);
    } else {
        $existingrecord = $DB->get_record('local_multitenancy_tenant', ['id' => (int) $data->id], '*', MUST_EXIST);
        $row->shortcode = (string) $existingrecord->shortcode;
    }
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
    if (!empty($CFG->dboptions) && is_array($CFG->dboptions)) {
        $row->dboptions = json_encode($CFG->dboptions, JSON_UNESCAPED_SLASHES);
    }
    $row->enabled = !empty($data->enabled) ? 1 : 0;
    $row->showonlogin = !empty($data->showonlogin) ? 1 : 0;
    $row->sortorder = (int) $data->sortorder;

    if ($isnew) {
        $row->midurim = '';
        $row->midurimformat = FORMAT_HTML;
        $row->provisionstatus = \local_multitenancy\tenant_provisioner::STATUS_PENDING;
        $row->provisionerror = null;
        $row->timecreated = $now;
        $row->timemodified = $now;
        $row->id = $DB->insert_record('local_multitenancy_tenant', $row);
    } else {
        $row->id = (int) $data->id;
        $row->timemodified = $now;
        $DB->update_record('local_multitenancy_tenant', $row);
    }

    $data = file_postupdate_standard_editor(
        $data,
        'midurim',
        $editoroptions,
        context_system::instance(),
        'local_multitenancy',
        'tenant_midurim',
        (int) $row->id
    );

    $midurimupdate = new stdClass();
    $midurimupdate->id = (int) $row->id;
    $midurimupdate->midurim = (string) ($data->midurim ?? '');
    $midurimupdate->midurimformat = isset($data->midurimformat) ? (int) $data->midurimformat : FORMAT_HTML;
    $midurimupdate->timemodified = time();
    $DB->update_record('local_multitenancy_tenant', $midurimupdate);

    \local_multitenancy\gateway_manager::sync();
    $registrywritten = \local_multitenancy\registry_writer::sync();

    $copycourses = $isnew && !empty($data->copycoursesdata);

    if ($isnew) {
        // Always clone parent DB automatically (no manual init checkbox).
        \local_multitenancy\tenant_provisioner::queue((int) $row->id, true, $copycourses);
        \core\notification::success(get_string('tenantprovisionqueued', 'local_multitenancy', $row->shortcode));
    } else if ($registrywritten) {
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
