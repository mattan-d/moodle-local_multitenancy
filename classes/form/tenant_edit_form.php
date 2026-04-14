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

namespace local_multitenancy\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Tenant edit form.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_edit_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;
        $existing = $this->_customdata['existing'] ?? null;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->setDefault('id', 0);

        $mform->addElement('text', 'shortcode', get_string('shortcode', 'local_multitenancy'), ['size' => 40]);
        $mform->setType('shortcode', PARAM_ALPHANUMEXT);
        $mform->addRule('shortcode', null, 'required', null, 'client');
        $mform->addHelpButton('shortcode', 'shortcode', 'local_multitenancy');

        $mform->addElement('text', 'name', get_string('name'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('text', 'host', get_string('host', 'local_multitenancy'), ['size' => 60]);
        $mform->setType('host', PARAM_RAW_TRIMMED);
        $mform->addRule('host', null, 'required', null, 'client');
        $mform->addHelpButton('host', 'host', 'local_multitenancy');

        $mform->addElement('text', 'wwwroot', get_string('wwwroot', 'local_multitenancy'), ['size' => 80]);
        $mform->setType('wwwroot', PARAM_RAW_TRIMMED);
        $mform->addRule('wwwroot', null, 'required', null, 'client');
        $mform->addHelpButton('wwwroot', 'wwwroot', 'local_multitenancy');

        $mform->addElement('text', 'dataroot', get_string('dataroot', 'local_multitenancy'), ['size' => 80]);
        $mform->setType('dataroot', PARAM_RAW_TRIMMED);
        $mform->addRule('dataroot', null, 'required', null, 'client');

        $mform->addElement('text', 'dbhost', get_string('dbhost', 'local_multitenancy'), ['size' => 40]);
        $mform->setType('dbhost', PARAM_RAW_TRIMMED);
        $mform->addRule('dbhost', null, 'required', null, 'client');

        $mform->addElement('text', 'dbname', get_string('dbname', 'local_multitenancy'), ['size' => 40]);
        $mform->setType('dbname', PARAM_RAW_TRIMMED);
        $mform->addRule('dbname', null, 'required', null, 'client');

        $mform->addElement('text', 'dbuser', get_string('dbuser', 'local_multitenancy'), ['size' => 40]);
        $mform->setType('dbuser', PARAM_RAW_TRIMMED);
        $mform->addRule('dbuser', null, 'required', null, 'client');

        $passattrs = ['size' => 40];
        if ($existing) {
            $passattrs['placeholder'] = get_string('passwordunchanged', 'local_multitenancy');
        }
        $mform->addElement('passwordunmask', 'dbpass', get_string('dbpass', 'local_multitenancy'), $passattrs);
        $mform->setType('dbpass', PARAM_RAW_TRIMMED);
        if (!$existing) {
            $mform->addRule('dbpass', null, 'required', null, 'client');
        }

        $mform->addElement('text', 'dbprefix', get_string('dbprefix', 'local_multitenancy'), ['size' => 20]);
        $mform->setType('dbprefix', PARAM_ALPHANUMEXT);
        $mform->setDefault('dbprefix', 'mdl_');
        $mform->addRule('dbprefix', null, 'required', null, 'client');

        $dbtypes = [
            'mysqli' => 'mysqli',
            'mariadb' => 'mariadb',
            'pgsql' => 'pgsql',
            'auroramysql' => 'auroramysql',
            'sqlsrv' => 'sqlsrv',
            'oci' => 'oci',
        ];
        $mform->addElement('select', 'dbtype', get_string('dbtype', 'local_multitenancy'), $dbtypes);
        $mform->setDefault('dbtype', 'mysqli');

        $mform->addElement('text', 'dblibrary', get_string('dblibrary', 'local_multitenancy'), ['size' => 20]);
        $mform->setType('dblibrary', PARAM_ALPHANUMEXT);
        $mform->setDefault('dblibrary', 'native');

        $mform->addElement('textarea', 'dboptions', get_string('dboptions', 'local_multitenancy'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('dboptions', PARAM_RAW);
        $mform->addHelpButton('dboptions', 'dboptions', 'local_multitenancy');

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'core'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('text', 'sortorder', get_string('sortorder', 'local_multitenancy'), ['size' => 6]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (!empty($data['shortcode'])) {
            $params = ['shortcode' => $data['shortcode']];
            $select = 'shortcode = :shortcode';
            if (!empty($data['id'])) {
                $select .= ' AND id <> :id';
                $params['id'] = $data['id'];
            }
            if ($DB->record_exists_select('local_multitenancy_tenant', $select, $params)) {
                $errors['shortcode'] = get_string('errorshortcodeexists', 'local_multitenancy');
            }
        }

        if (!empty($data['host'])) {
            $host = \core_text::strtolower($data['host']);
            $params = ['host' => $host];
            $select = 'host = :host';
            if (!empty($data['id'])) {
                $select .= ' AND id <> :id';
                $params['id'] = $data['id'];
            }
            if ($DB->record_exists_select('local_multitenancy_tenant', $select, $params)) {
                $errors['host'] = get_string('errorhostexists', 'local_multitenancy');
            }
        }

        if (!empty($data['dboptions'])) {
            $decoded = json_decode($data['dboptions'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors['dboptions'] = get_string('errordboptionsjson', 'local_multitenancy');
            } else if ($decoded !== null && !is_array($decoded)) {
                $errors['dboptions'] = get_string('errordboptionsjson', 'local_multitenancy');
            }
        }

        if (!empty($data['wwwroot']) && !preg_match('#\Ahttps?://.#iu', trim($data['wwwroot']))) {
            $errors['wwwroot'] = get_string('errorwwwrootinvalid', 'local_multitenancy');
        }
        if (!empty($data['dataroot'])) {
            $droot = trim($data['dataroot']);
            $absolute = ($droot !== '' && ($droot[0] === '/' || $droot[0] === '\\'));
            if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-zA-Z]:[\/\\\\]/', $droot)) {
                $absolute = true;
            }
            if (!$absolute) {
                $errors['dataroot'] = get_string('errordatarootnotabsolute', 'local_multitenancy');
            }
        }

        return $errors;
    }
}
