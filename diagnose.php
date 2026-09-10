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
 * Diagnose a tenant (gateway / install / redirect-loop causes) and suggest fixes.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_multitenancy\gateway_diagnostic;
use local_multitenancy\tenant_provisioner;

global $DB, $PAGE, $OUTPUT, $CFG;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('local_multitenancy_manage');
require_capability('local/multitenancy:manage', context_system::instance());

$tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $id], '*', MUST_EXIST);
$returnurl = new moodle_url('/local/multitenancy/manage.php');
$pageurl = new moodle_url('/local/multitenancy/diagnose.php', ['id' => $id]);

$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('diagnose_title', 'local_multitenancy', $tenant->shortcode));
$PAGE->set_heading(get_string('diagnose_title', 'local_multitenancy', $tenant->shortcode));

if ($action === 'reprovision' && confirm_sesskey()) {
    if (!$confirm) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('diagnose_reprovision_confirm', 'local_multitenancy', format_string($tenant->name)),
            new moodle_url($pageurl, ['action' => 'reprovision', 'confirm' => 1, 'sesskey' => sesskey()]),
            $pageurl
        );
        echo $OUTPUT->footer();
        exit;
    }

    // If Moodle is not installed but tables exist (broken clone), wipe then re-queue.
    if (!\local_multitenancy\database_provisioner::moodle_is_installed($tenant)
            && \local_multitenancy\database_provisioner::tenant_database_ready($tenant)) {
        $empty = \local_multitenancy\database_provisioner::empty_tenant_database($tenant);
        if (!$empty['ok']) {
            \core\notification::error(get_string('diagnose_emptyfailed', 'local_multitenancy', $empty['detail']));
            redirect($pageurl);
        }
    }

    tenant_provisioner::queue((int) $tenant->id, true, false);
    \core\notification::success(get_string('tenantprovisionqueued', 'local_multitenancy', $tenant->shortcode));
    redirect($returnurl);
}

$results = gateway_diagnostic::run((string) $tenant->shortcode);

$haderror = false;
$hadwarn = false;
foreach ($results as $row) {
    if ($row['level'] === gateway_diagnostic::LEVEL_ERROR) {
        $haderror = true;
    } else if ($row['level'] === gateway_diagnostic::LEVEL_WARN) {
        $hadwarn = true;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('diagnose_title', 'local_multitenancy', $tenant->shortcode));

echo html_writer::tag('p', get_string('diagnose_intro', 'local_multitenancy', (object) [
    'name' => format_string($tenant->name),
    'code' => s($tenant->shortcode),
    'dbname' => s($tenant->dbname),
]));

$leaveurl = new moodle_url('/local/multitenancy/leave.php');
echo $OUTPUT->notification(
    get_string('manage_recovery_hint', 'local_multitenancy', $leaveurl->out(false)),
    'info'
);

if ($haderror) {
    echo $OUTPUT->notification(get_string('diagnose_summary_fail', 'local_multitenancy'), 'error');
} else if ($hadwarn) {
    echo $OUTPUT->notification(get_string('diagnose_summary_warn', 'local_multitenancy'), 'warning');
} else {
    echo $OUTPUT->notification(get_string('diagnose_summary_ok', 'local_multitenancy'), 'success');
}

$table = new html_table();
$table->head = [
    get_string('diagnose_col_level', 'local_multitenancy'),
    get_string('diagnose_col_check', 'local_multitenancy'),
    get_string('diagnose_col_fix', 'local_multitenancy'),
];
$table->attributes['class'] = 'generaltable';
$table->data = [];

foreach ($results as $row) {
    $level = $row['level'];
    $levelclass = $level === gateway_diagnostic::LEVEL_ERROR ? 'text-danger' :
        ($level === gateway_diagnostic::LEVEL_WARN ? 'text-warning' : 'text-success');
    $levellabel = get_string('diagnose_level_' . $level, 'local_multitenancy');
    $check = get_string('cli_diag_' . $row['key'], 'local_multitenancy', $row['detail']);
    $fix = '';
    if (!empty($row['fix'])) {
        $fix = get_string('diagnose_' . $row['fix'], 'local_multitenancy');
    } else if ($level === gateway_diagnostic::LEVEL_ERROR) {
        $fix = get_string('diagnose_fix_generic', 'local_multitenancy');
    }
    $table->data[] = [
        html_writer::span($levellabel, $levelclass),
        $check,
        $fix,
    ];
}

echo html_writer::table($table);

// Provisioning step log (auto-written during adhoc task).
$loglines = \local_multitenancy\provision_logger::read_tail((string) $tenant->shortcode, 100);
echo $OUTPUT->heading(get_string('diagnose_log_heading', 'local_multitenancy'), 3);
if ($loglines) {
    $logfile = \local_multitenancy\provision_logger::log_file_path((string) $tenant->shortcode);
    echo html_writer::tag('p', get_string('diagnose_log_path', 'local_multitenancy', s($logfile)), ['class' => 'text-muted']);
    echo html_writer::tag(
        'pre',
        s(implode("\n", $loglines)),
        ['style' => 'direction:ltr;text-align:left;background:#f5f5f5;padding:0.75em;max-height:28em;overflow:auto;']
    );
} else {
    echo $OUTPUT->notification(get_string('diagnose_log_empty', 'local_multitenancy'), 'info');
}

echo html_writer::start_div('mt-3');
echo $OUTPUT->single_button($pageurl, get_string('diagnose_rerun', 'local_multitenancy'), 'get');
echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['action' => 'reprovision', 'sesskey' => sesskey()]),
    get_string('diagnose_reprovision', 'local_multitenancy'),
    'post'
);
echo $OUTPUT->single_button($leaveurl, get_string('footerleavetenant', 'local_multitenancy'), 'get');
echo $OUTPUT->single_button($returnurl, get_string('back'), 'get');
echo html_writer::end_div();

echo $OUTPUT->footer();
