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
 * English language pack.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Multitenancy (separate DB and dataroot)';
$string['manage_tenants'] = 'Manage tenants';
$string['addtenant'] = 'Add tenant';
$string['edittenant'] = 'Edit tenant';
$string['deletetenant'] = 'Delete tenant';
$string['shortcode'] = 'Tenant code';
$string['shortcode_help'] = 'Short unique identifier used for CLI (environment variable MOODLE_TENANT). Use letters, numbers and underscores only.';
$string['host'] = 'HTTP host';
$string['host_help'] = 'Exact value of the HTTP Host header for this tenant (e.g. school1.example.com). The parent site host must not match any tenant.';
$string['wwwroot'] = 'Tenant wwwroot';
$string['dataroot'] = 'Tenant dataroot';
$string['dbhost'] = 'Database host';
$string['dbname'] = 'Database name';
$string['dbuser'] = 'Database user';
$string['dbpass'] = 'Database password';
$string['dbprefix'] = 'Table prefix';
$string['dbtype'] = 'Database type';
$string['dblibrary'] = 'Database library';
$string['dboptions'] = 'dboptions (JSON)';
$string['dboptions_help'] = 'Optional JSON object merged into $CFG->dboptions for this tenant. Leave empty to inherit defaults from config.php.';
$string['sortorder'] = 'Sort order';
$string['passwordunchanged'] = 'Leave blank to keep the current password';
$string['errorshortcodeexists'] = 'This tenant code is already in use.';
$string['errorhostexists'] = 'This host is already mapped to another tenant.';
$string['errordboptionsjson'] = 'Invalid JSON for dboptions.';
$string['registrydir'] = 'Registry directory (absolute path)';
$string['registrydir_desc'] = 'Directory on the server where registry.php will be written. It must match MULTITENANCY_REGISTRY_DIR in config.php (same path). The web server user must be able to write here.';
$string['registrydirmissing'] = 'Registry directory is not configured. Set it in Site administration → Plugins → Local plugins → Multitenancy, then save tenants again or use Rebuild registry.';
$string['registrydirset'] = 'Registry directory: {$a}';
$string['registryupdated'] = 'Registry file was updated successfully.';
$string['registrynotwritten'] = 'Registry file was not written. Check the registry directory path and permissions.';
$string['notenants'] = 'No tenants defined yet.';
$string['deleteconfirm'] = 'Delete tenant "{$a}"? This does not remove the tenant database or files.';
$string['tenantdeleted'] = 'Tenant removed.';
$string['rebuildregistry'] = 'Rebuild registry file';
$string['configsnippet_title'] = 'config.php snippet';
$string['configsnippet_desc'] = 'After you set $CFG->dirroot (and default database settings for the parent site), add the following lines before require_once($CFG->dirroot . \'/lib/setup.php\'): define(\'MULTITENANCY_REGISTRY_DIR\', \'/absolute/path/same/as/registry/directory\'); require_once($CFG->dirroot . \'/local/multitenancy/bootstrap.php\'); local_multitenancy_bootstrap($CFG); For CLI on a tenant, export MOODLE_TENANT=tenantcode matching the tenant code.';
