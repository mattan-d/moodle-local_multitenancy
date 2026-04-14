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
 * Admin settings.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_multitenancy_settings', get_string('pluginname', 'local_multitenancy'));

    $settings->add(new admin_setting_configtext(
        'local_multitenancy/registrydir',
        get_string('registrydir', 'local_multitenancy'),
        get_string('registrydir_desc', 'local_multitenancy'),
        '',
        PARAM_RAW_TRIMMED,
        80
    ));

    $settings->add(new admin_setting_description(
        'local_multitenancy/configsnippet',
        get_string('configsnippet_title', 'local_multitenancy'),
        get_string('configsnippet_desc', 'local_multitenancy')
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_multitenancy_manage',
        get_string('manage_tenants', 'local_multitenancy'),
        new moodle_url('/local/multitenancy/manage.php'),
        'local/multitenancy:manage'
    ));
}
