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
 * Web entry to run all local_multitenancy scheduled + adhoc tasks.
 *
 * Same remote password as Moodle's admin/cron.php:
 *   https://your-moodle-site/local/multitenancy/cron.php?password=opensesame
 *
 * Parent admin/cron.php?password=… also runs the scheduled task when it is due.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignoreFile moodle.Files.MoodleInternal.MoodleInternalGlobalState

if (defined('STDIN')) {
    fwrite(STDERR, "ERROR: Use admin/cli/cron.php or scheduled_task.php for CLI.\n");
    exit(1);
}

// Match Moodle admin/cron.php: emulated CLI so tasks get a CLI-like environment.
define('CLI_SCRIPT', true);
define('WEB_CRON_EMULATED_CLI', 'defined');
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/multitenancy/lib.php');

\core\session\manager::write_close();

if (!empty($CFG->cronclionly)) {
    throw new \moodle_exception('cronerrorclionly', 'admin');
}

if (!empty($CFG->cronremotepassword)) {
    $pass = optional_param('password', '', PARAM_RAW);
    if ($pass !== $CFG->cronremotepassword) {
        throw new \moodle_exception('cronerrorpassword', 'admin');
    }
}

if (function_exists('local_multitenancy_is_parent_site') && !local_multitenancy_is_parent_site()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "local_multitenancy cron runs on the parent site only.\n";
    exit(1);
}

if (moodle_needs_upgrading()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Moodle upgrade pending, cannot execute tasks.\n";
    exit(1);
}

@header('Content-Type: text/plain; charset=utf-8');
@ini_set('html_errors', 'off');
@set_time_limit(0);
raise_memory_limit(MEMORY_EXTRA);

\core\cron::setup_user();

mtrace('local_multitenancy web cron started at ' . date('c'));
mtrace('Server Time: ' . date('r'));

$cronlockfactory = \core\lock\lock_config::get_lock_factory('cron');

$scheduled = \core\task\manager::get_all_scheduled_tasks();
foreach ($scheduled as $task) {
    $classname = '\\' . get_class($task);
    if (strpos($classname, '\\local_multitenancy\\task\\') !== 0) {
        continue;
    }
    if ($task->get_disabled()) {
        mtrace('Skipping disabled scheduled task: ' . $classname);
        continue;
    }

    \core\task\manager::scheduled_task_starting($task);

    if (!$lock = $cronlockfactory->get_lock($classname, 10)) {
        mtrace('Cannot obtain task lock for ' . $classname);
        continue;
    }
    $task->set_lock($lock);

    mtrace('Execute scheduled task: ' . $task->get_name() . ' (' . $classname . ')');
    \core\cron::run_inner_scheduled_task($task);
}

$adhocclasses = [
    '\\local_multitenancy\\task\\provision_tenant',
    '\\local_multitenancy\\task\\upgrade_tenant',
];
foreach ($adhocclasses as $classname) {
    $tasks = \core\task\manager::get_adhoc_tasks($classname);
    foreach ($tasks as $task) {
        $lockkey = $classname;
        if (method_exists($task, 'get_lockkey') && $task->get_lockkey()) {
            $lockkey = $classname . '_' . $task->get_lockkey();
        } else if (method_exists($task, 'get_id') && $task->get_id()) {
            $lockkey = $classname . '_' . $task->get_id();
        }
        if (!$lock = $cronlockfactory->get_lock($lockkey, 10)) {
            mtrace('Cannot obtain adhoc lock for ' . $classname);
            continue;
        }
        $task->set_lock($lock);
        mtrace('Execute adhoc task: ' . $classname);
        \core\cron::run_inner_adhoc_task($task);
    }
}

mtrace('local_multitenancy web cron finished at ' . date('c'));
exit(0);
