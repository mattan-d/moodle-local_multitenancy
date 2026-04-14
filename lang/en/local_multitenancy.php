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
$string['host_help'] = 'For subdomain tenants: exact HTTP Host (e.g. school1.example.com). For path-style URLs on the same domain as the parent, use a unique placeholder host per tenant (e.g. mattan.path.local) that never appears in real requests — resolution uses /local/multitenancy/users/{code}/ and a cookie instead.';
$string['wwwroot'] = 'Tenant wwwroot';
$string['wwwroot_help'] = 'When using path gateways on one public hostname, set this to the same URL as the parent site (e.g. https://example.com). Subdomain tenants can use their own full URL.';
$string['gatewayurl'] = 'Gateway URL';
$string['gatewayexplain'] = 'Each enabled tenant gets a real folder local/multitenancy/users/{tenant code}/ so URLs like .../users/mattan/ work without Apache rewrites. Visiting that URL selects the tenant and sets cookies (shortcode + public wwwroot) so Moodle\'s URL checks match the real SCRIPT_NAME. Use /local/multitenancy/leave.php to clear cookies and return to the parent site. Rebuild registry or save a tenant after changing codes.';
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
$string['configsnippet_desc'] = 'Add the following lines in config.php before require_once(__DIR__ . \'/lib/setup.php\'). Do not use $CFG->dirroot here: Moodle only sets it inside setup.php. Use __DIR__ for the bootstrap path. Example: if (!defined(\'MULTITENANCY_REGISTRY_DIR\')) { define(\'MULTITENANCY_REGISTRY_DIR\', $CFG->dataroot . \'/multitenancy\'); } require_once(__DIR__ . \'/local/multitenancy/bootstrap.php\'); local_multitenancy_bootstrap($CFG); Match MULTITENANCY_REGISTRY_DIR with the plugin "Registry directory" setting. For CLI on a tenant, export MOODLE_TENANT=tenantcode.';
