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
$string['wwwroot_help'] = 'Moodle\'s public site base URL (e.g. https://example.com or https://example.com/moodle) — not the gateway path /local/multitenancy/users/.... Path tenants on one hostname use the same base as the parent. Subdomain tenants can use their own full URL.';
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
$string['errorwwwrootinvalid'] = 'wwwroot must be a full URL starting with http:// or https:// (e.g. https://yoursite.example).';
$string['errordatarootnotabsolute'] = 'dataroot must be an absolute path on the server (e.g. /var/moodledata/tenant1), not a short name.';
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
$string['cli_diag_help'] = 'Diagnose multitenancy gateway (blank page) issues.

Run from your Moodle root directory, e.g.:
  cd /path/to/moodle
  php local/multitenancy/cli/diagnose_gateway.php --shortcode=CODE
  php local/multitenancy/cli/diagnose_gateway.php -s CODE

Checks: MULTITENANCY_REGISTRY_DIR, registry.php, tenant row, wwwroot URL, dataroot path, gateway index.php, DB connect (mysqli family), expected browser URL.

';
$string['cli_diag_invalidshortcode'] = 'Invalid tenant code: {$a}';
$string['cli_diag_registrydirundefined'] = 'MULTITENANCY_REGISTRY_DIR is not defined in config.php.';
$string['cli_diag_registrydirmissing'] = 'Registry directory does not exist: {$a}. Create it (mkdir), make it writable for the web server, then in Moodle save a tenant or use Rebuild registry.';
$string['cli_diag_registryfilenotfound'] = 'registry.php does not exist yet: {$a}. In Moodle: Site administration → Local plugins → Multitenancy — set Registry directory to this folder, add tenants, then Save or Rebuild registry.';
$string['cli_diag_registryfilenotreadable'] = 'registry.php exists but is not readable: {$a}. Fix permissions (chmod/chown) so CLI and the web server user can read it.';
$string['cli_diag_registryfileok'] = 'Registry file OK: {$a}';
$string['cli_diag_registryempty'] = 'registry.php returned an empty map.';
$string['cli_diag_tenantnotinregistry'] = 'No tenant with shortcode "{$a}" in registry — add the tenant in admin and save or rebuild registry.';
$string['cli_diag_tenantdisabled'] = 'Tenant "{$a}" is disabled in registry.';
$string['cli_diag_tenantenabled'] = 'Tenant "{$a}" is enabled.';
$string['cli_diag_wwwrootinvalid'] = 'wwwroot in registry is not a full URL (value: "{$a}"). Use e.g. https://yoursite.example';
$string['cli_diag_wwwrootok'] = 'wwwroot OK (effective base): {$a}';
$string['cli_diag_wwwrootgatewaystripped'] = 'Registry wwwroot "{$a->from}" pointed at the gateway path only; the plugin normalises it to "{$a->to}". Prefer saving the tenant with the real site base URL.';
$string['cli_diag_datarootnotabsolute'] = 'dataroot is not an absolute path (value: "{$a}").';
$string['cli_diag_datarootabsolute'] = 'dataroot is absolute: {$a}';
$string['cli_diag_datarootmissing'] = 'dataroot directory does not exist: {$a}';
$string['cli_diag_datarootnotwritable'] = 'dataroot is not writable by this user: {$a}';
$string['cli_diag_datarootok'] = 'dataroot exists and is writable: {$a}';
$string['cli_diag_gatewayindexmissing'] = 'Gateway index.php missing: {$a} — save tenant or rebuild registry in admin.';
$string['cli_diag_gatewayindexok'] = 'Gateway index.php OK: {$a}';
$string['cli_diag_gatewaystuboutdated'] = 'Gateway index.php differs from the plugin stub — save a tenant or use Rebuild registry to refresh it.';
$string['cli_diag_dbconnectok'] = 'Database connection OK (database: {$a}).';
$string['cli_diag_dbskipped'] = 'Database check skipped (driver: {$a}); only mysqli/mariadb/auroramysql are tested by this script.';
$string['cli_diag_dbconnectfail'] = 'Database connection failed: {$a}';
$string['cli_diag_hintweb'] = 'Expected gateway URL in browser: {$a}';
$string['cli_diag_summary_ok'] = 'All critical checks passed. If the browser still shows a blank page, check: web server SCRIPT_NAME vs REQUEST_URI, HTTPS/cookie Secure, PHP/web error logs, and the browser Network tab (empty 302/500).';
$string['cli_diag_summary_fail'] = 'One or more [ERR] lines above explain the likely cause. Fix them, rebuild registry if needed, then run this script again.';
