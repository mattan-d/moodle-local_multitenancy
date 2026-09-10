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
$string['manageusers'] = 'Manage users (all tenants)';
$string['manageusers_desc'] = 'Browse users from all tenant databases in one place. Use filters to search by tenant, name, username or email.';
$string['backtotenants'] = 'Back to tenant list';
$string['alltenants'] = 'All tenants';
$string['filtertenant'] = 'Tenant';
$string['filtersearch'] = 'Search';
$string['filtersearchplaceholder'] = 'Username, email, name or idnumber';
$string['filterincludesuspended'] = 'Include suspended users';
$string['clearfilters'] = 'Clear filters';
$string['activefilters'] = 'Active filters:';
$string['usersfound'] = '{$a} user(s) found';
$string['usersshowing'] = 'Showing {$a->from}–{$a->to} of {$a->total} users';
$string['nousersfound'] = 'No users matched your filters.';
$string['columntenant'] = 'Tenant';
$string['columnusername'] = 'Username';
$string['columnauth'] = 'Authentication';
$string['columnlastlogin'] = 'Last login';
$string['columnsiteadmin'] = 'Site admin';
$string['columnactions'] = 'Actions';
$string['usertransfer_action'] = 'Change tenant';
$string['usertransfer_siteadmin_na'] = 'Not available';
$string['usertransfer_title'] = 'Move user to another tenant';
$string['usertransfer_intro'] = 'The user account will be created or updated in the target tenant database, then removed (soft-deleted) from the source tenant. Enrolments and files are not copied.';
$string['usertransfer_source_tenant'] = 'Current tenant';
$string['usertransfer_target_tenant'] = 'Target tenant';
$string['usertransfer_target_tenant_help'] = 'Select the tenant where this user should be moved.';
$string['usertransfer_user_details'] = 'User details';
$string['usertransfer_newpassword'] = 'New password';
$string['usertransfer_newpassword_help'] = 'Leave empty to keep the existing password hash. Enter a new password only if you want to change it in the target tenant.';
$string['usertransfer_same_tenant'] = 'Source and target tenant must be different.';
$string['usertransfer_invalid_user'] = 'Invalid user.';
$string['usertransfer_source_not_ready'] = 'Source tenant database is not ready.';
$string['usertransfer_target_not_ready'] = 'Target tenant database is not ready.';
$string['usertransfer_unsupported_db'] = 'Database type is not supported for user transfer.';
$string['usertransfer_user_not_found'] = 'User was not found in the source tenant.';
$string['usertransfer_siteadmin_blocked'] = 'Site administrators cannot be moved between tenants.';
$string['usertransfer_email_conflict'] = 'The email is already used by another user ({$a->username}) in the target tenant.';
$string['usertransfer_db_connect'] = 'Could not connect to the tenant database.';
$string['usertransfer_allocate_id'] = 'Could not allocate a new user id in the target tenant.';
$string['usertransfer_remove_failed'] = 'User was copied to the target tenant but could not be removed from the source.';
$string['usertransfer_partial'] = 'User was moved to {$a->target}, but removal from the source failed: {$a->error}';
$string['usertransfer_success'] = '{$a->user} was moved from {$a->source} to {$a->target}.';
$string['usertransfer_no_targets'] = 'No other tenant with a ready database is available.';
$string['usercreate_add'] = 'Add user';
$string['usercreate_title'] = 'Add user to tenant';
$string['usercreate_intro'] = 'Creates a new account in the selected tenant database. The user is not added to the parent site.';
$string['usercreate_tenant'] = 'Tenant';
$string['usercreate_tenant_help'] = 'Select which tenant database will store this user account.';
$string['usercreate_tenant_required'] = 'Please select a tenant.';
$string['usercreate_tenant_not_found'] = 'Tenant was not found or is disabled.';
$string['usercreate_tenant_not_ready'] = 'Tenant database is not ready.';
$string['usercreate_no_tenants'] = 'No enabled tenant with a ready database is available.';
$string['usercreate_password_required'] = 'Password is required for new users.';
$string['usercreate_invalid_username'] = 'Username is required.';
$string['usercreate_username_exists'] = 'Username "{$a}" already exists in this tenant.';
$string['usercreate_email_conflict'] = 'Email is already used by another user ({$a->username}) in this tenant.';
$string['usercreate_success'] = '{$a->user} was created in {$a->tenant}.';
$string['addtenant'] = 'Add tenant';
$string['edittenant'] = 'Edit tenant';
$string['deletetenant'] = 'Delete tenant';
$string['shortcode'] = 'Tenant code';
$string['shortcode_help'] = 'Short unique identifier used for CLI (environment variable MOODLE_TENANT). Use letters, numbers and underscores only.';
$string['errorshortcodenotallowed'] = 'Tenant code must be selected from the allowed tenant list.';
$string['manageallowedshortcodes'] = 'Manage allowed tenant codes';
$string['allowedshortcodes'] = 'Allowed tenant codes';
$string['allowedshortcodes_help'] = 'Enter one tenant code per line. These codes will be offered as a dropdown when adding a new tenant.';
$string['allowedshortcodesdesc'] = 'Define the list of possible tenant codes. On the Add tenant screen, the tenant code field will use this list as a dropdown.';
$string['allowedshortcodessaved'] = 'Allowed tenant code list saved.';
$string['errorallowedshortcodesinvalid'] = 'Invalid tenant code "{$a}". Use letters, numbers and underscores only.';
$string['host'] = 'HTTP host';
$string['host_help'] = 'For subdomain tenants: exact HTTP Host (e.g. school1.example.com). For path-style URLs on the same domain as the parent, use a unique placeholder host per tenant (e.g. mattan.path.local) that never appears in real requests — resolution uses /local/multitenancy/users/{code}/ and a cookie instead.';
$string['wwwroot'] = 'Tenant wwwroot';
$string['wwwroot_help'] = 'What Moodle needs here is the same public site base as in the parent config.php (e.g. https://yoursite.example or https://yoursite.example/moodle). The gateway URL https://…/local/multitenancy/users/{code}/ is optional: it is the same information as base + code, and the plugin can derive the base from it. Path tenants: using only the base URL is enough and avoids duplication.';
$string['errorwwwrootgatewaymismatch'] = 'This wwwroot includes /local/multitenancy/users/… but the segment in the path ("{$a->found}") does not match the tenant code ("{$a->expected}").';
$string['gatewayurl'] = 'Gateway URL';
$string['gatewayexplain'] = 'Each enabled tenant gets a real folder local/multitenancy/users/{tenant code}/ so URLs like .../users/mattan/ work without Apache rewrites. Visiting that URL selects the tenant and sets cookies. Use /local/multitenancy/leave.php to clear cookies and return to the parent site. Gateway folders update automatically when you save a tenant.';
$string['dataroot'] = 'Tenant dataroot';
$string['dbhost'] = 'Database host';
$string['dbname'] = 'Database name';
$string['dbuser'] = 'Database user';
$string['dbpass'] = 'Database password';
$string['dbprefix'] = 'Table prefix';
$string['dbtype'] = 'Database type';
$string['dblibrary'] = 'Database library';
$string['enabled'] = 'Enabled';
$string['showonlogin'] = 'Show on login page';
$string['showonlogin_help'] = 'When enabled, this tenant appears in the tenant list on the parent site login page (/login/index.php).';
$string['tasksyncparentadmins'] = 'Sync parent site administrators and settings to tenants';
$string['settingssynced'] = 'Parent site settings and language packs synced to tenant: {$a}';
$string['settingssyncfailed'] = 'Syncing parent site settings to tenant failed: {$a}';
$string['taskprovisiontenant'] = 'Provision multitenancy tenant';
$string['tenantprovisionqueued'] = 'Tenant "{$a}" was saved. Provisioning (database, files) runs in the background — refresh the tenant list in a few minutes. Do not open the tenant gateway until status is Complete.';
$string['columnprovision'] = 'Provisioning';
$string['provisionstatus_pending'] = 'Queued';
$string['provisionstatus_processing'] = 'In progress';
$string['provisionstatus_complete'] = 'Complete';
$string['provisionstatus_failed'] = 'Failed';
$string['provisionstatus_unknown'] = '—';
$string['dboptions'] = 'dboptions (JSON)';
$string['dboptions_help'] = 'Optional JSON object merged into $CFG->dboptions for this tenant. Leave empty to inherit defaults from config.php.';
$string['dboptionsexample'] = 'Example JSON (object only): {"dbpersist": false, "dbport": 3306}. Use key/value pairs as in $CFG->dboptions. Do not wrap with [] and do not add trailing commas.';
$string['sortorder'] = 'Sort order';
$string['midurim'] = 'Midurim';
$string['midurim_help'] = 'HTML content displayed at the top of every page while users browse this tenant (jumbotron-style banner). Shown only when the tenant is active. Saved automatically with the tenant.';
$string['autogeneratedtenantfields'] = 'Tenant Host, tenant wwwroot and tenant dataroot are generated automatically from the tenant code and the parent site config.';
$string['autogenerateddbfields'] = 'Database host/name/user/password, table prefix, DB type and DB library are generated automatically from parent config and tenant code. The tenant database schema is created automatically when saving.';
$string['initdbfromparent'] = 'Initialize tenant database from parent if empty';
$string['initdbfromparent_help'] = 'When enabled, saving the tenant will automatically copy the parent Moodle database into the tenant database if the tenant database currently has zero tables.';
$string['copycoursesdata'] = 'Copy courses and course data';
$string['copycoursesdata_help'] = 'Enabled: full tenant copy including all courses. Disabled: only front-page course data is copied; data tied to other course IDs is skipped.';
$string['dbprovisioned'] = 'Tenant database "{$a}" was initialized from the parent database.';
$string['dbprovisionednocourses'] = 'Tenant database "{$a}" was initialized from the parent database without copying non-frontpage course data.';
$string['dbprovisionskippednotempty'] = 'Tenant database "{$a}" already contains tables, so initialization was skipped.';
$string['dbprovisionskippedunsupported'] = 'Automatic DB initialization is currently supported only for mysql-family and pgsql drivers (current tenant dbtype: "{$a}").';
$string['dbprovisionfailed'] = 'Automatic tenant DB initialization failed: {$a}';
$string['dbnotinstalled'] = 'Tenant database "{$a}" is still not a finished Moodle install after automatic clone/retry. Check PostgreSQL permissions for CREATE DATABASE / TEMPLATE, then wait for the next automatic retry (or open Diagnose).';
$string['diagnose_autoretry'] = 'Automatically re-queued provisioning for {$a} tenant(s) with an incomplete database clone. Refresh in a few minutes after cron runs.';
$string['initdbfromparent'] = 'Initialize tenant database from parent if empty';
$string['initdbfromparent_help'] = 'Always done automatically when creating a tenant. Kept for compatibility with older language caches.';
$string['datarootautocreatefailed'] = 'Tenant dataroot could not be created automatically: {$a}. Create it manually and ensure web server write permissions.';
$string['dbschemaautocreated'] = 'Tenant database schema exists and is ready: {$a}.';
$string['dbschemaautocreatefailed'] = 'Tenant database schema could not be ensured automatically: {$a}';
$string['footertenantcontext'] = 'Tenant context: {$a->name} ({$a->code})';
$string['footertenantcurrent'] = 'Current tenant:';
$string['footertenantmeta'] = 'Tenant ID: {$a->id} · {$a->code}';
$string['footertenantmeta_code'] = 'Tenant code: {$a}';
$string['footertenantregion'] = 'Active tenant context';
$string['footerleavetenant'] = 'Leave tenant';
$string['logintenantpicker_label'] = 'Sign in to your site';
$string['logintenantpicker_placeholder'] = 'Select a site…';
$string['logintenantpicker_title'] = 'Login to a tenant';
$string['logintenantpicker_desc'] = 'Choose a tenant to continue to its login context.';
$string['passwordunchanged'] = 'Leave blank to keep the current password';
$string['errorshortcodeexists'] = 'This tenant code is already in use.';
$string['errorhostexists'] = 'This host is already mapped to another tenant.';
$string['errordboptionsjson'] = 'Invalid JSON for dboptions.';
$string['errorwwwrootinvalid'] = 'wwwroot must be a full URL starting with http:// or https:// (e.g. https://yoursite.example/local/multitenancy/users/your-tenant-code/).';
$string['errordatarootnotabsolute'] = 'dataroot must be an absolute path on the server (e.g. /var/moodledata/tenant1), not a short name.';
$string['registrydirmissing'] = 'Could not create or write the automatic tenant list under moodledata ({dataroot}/multitenancy). Check that the web server can write to moodledata.';
$string['manage_recovery_hint'] = 'If the site redirects in a loop after opening a tenant, clear the tenant cookie: {$a}';
$string['manage_provision_failed'] = 'Tenant "{$a->name}" ({$a->code}) failed provisioning: {$a->error}';
$string['manage_provision_pending'] = '{$a} tenant(s) are still being provisioned. Do not open their gateway until status is Complete. Run cron if needed.';
$string['gateway_not_ready'] = 'Not ready (wait for Complete)';
$string['diagnose'] = 'Diagnose';
$string['diagnose_title'] = 'Diagnose tenant: {$a}';
$string['diagnose_intro'] = 'Checks for tenant <strong>{$a->name}</strong> (code <code>{$a->code}</code>, database <code>{$a->dbname}</code>). Use this when the gateway opens Moodle install, login fails, or the browser shows too many redirects.';
$string['diagnose_summary_ok'] = 'No critical problems found. If the browser still loops, open Leave tenant to clear cookies, then try the gateway again.';
$string['diagnose_summary_warn'] = 'Some warnings were found. Review the Fix column before opening the gateway.';
$string['diagnose_summary_fail'] = 'Critical problems found. Do not open the gateway until they are fixed — use the Fix column and Re-provision if needed.';
$string['diagnose_col_level'] = 'Level';
$string['diagnose_col_check'] = 'Check result';
$string['diagnose_col_fix'] = 'How to fix';
$string['diagnose_level_ok'] = 'OK';
$string['diagnose_level_warn'] = 'Warning';
$string['diagnose_level_error'] = 'Error';
$string['diagnose_rerun'] = 'Run diagnosis again';
$string['diagnose_reprovision'] = 'Wipe broken DB & re-provision';
$string['diagnose_reprovision_confirm'] = 'Empty the tenant database for "{$a}" (if Moodle is not installed there) and queue provisioning again from the parent site? This does not delete the tenant record.';
$string['diagnose_emptyfailed'] = 'Could not empty the tenant database before re-provision: {$a}';
$string['diagnose_fix_generic'] = 'Fix the error above, then run diagnosis again.';
$string['diagnose_fix_dbconnect'] = 'Verify DB host/user/password and that the tenant database exists. On PostgreSQL: grant CONNECT on database postgres (or template1) to the Moodle DB user, and match socket/port with config.php dboptions. Open Diagnose → Provisioning log for the exact connection string (password redacted).';
$string['diagnose_log_heading'] = 'Provisioning log';
$string['diagnose_log_path'] = 'Log file: {$a}';
$string['diagnose_log_empty'] = 'No provisioning log yet for this tenant. Create/re-provision the tenant (and ensure cron runs) to generate log lines.';
$string['diagnose_fix_reprovision'] = 'Usually not needed — Manage tenants / cron auto-retry incomplete clones. Use “Wipe broken DB & re-provision” only if auto-retry keeps failing.';
$string['diagnose_fix_moodlenotinstalled'] = 'The tenant DB has no Moodle version row. Automatic wipe+retry should run from Manage tenants / cron. Ensure the DB user can CREATE DATABASE … WITH TEMPLATE on PostgreSQL.';
$string['diagnose_fix_redirectloop'] = 'Status was Complete but the DB is not installed — clear cookies via Leave tenant; automatic re-provision will retry.';
$string['diagnose_fix_pgdump'] = 'Optional: install pg_dump/psql for remote PostgreSQL hosts. Same-server tenants use CREATE DATABASE WITH TEMPLATE automatically.';
$string['cli_diag_provisionstatus'] = 'Provisioning status in database: {$a}';
$string['cli_diag_provisionerror'] = 'Last provisioning error: {$a}';
$string['cli_diag_moodleinstalled'] = 'Tenant DB looks like an installed Moodle site (config.version present): {$a}';
$string['cli_diag_moodlenotinstalled'] = 'Tenant DB is NOT an installed Moodle site (missing config.version): {$a}';
$string['cli_diag_redirectlooprisk'] = 'High risk of too-many-redirects for tenant "{$a}": Complete status + empty/broken Moodle DB.';
$string['cli_diag_pgdumpmissing'] = 'Required CLI tools missing for PostgreSQL clone: {$a}';
$string['cli_diag_clitoolsok'] = 'DB dump CLI tools available for driver: {$a}';
$string['cli_diag_tenantdbrowmissing'] = 'Tenant "{$a}" not found in local_multitenancy_tenant table.';
$string['cli_diag_hintleave'] = 'To clear sticky tenant cookies: {$a}';
$string['registryupdated'] = 'Tenant settings were saved successfully.';
$string['registrynotwritten'] = 'Could not write the automatic tenant list under moodledata ({dataroot}/multitenancy). Check permissions.';
$string['notenants'] = 'No tenants defined yet.';
$string['deleteconfirm'] = 'Delete tenant "{$a}"? This does not remove the tenant database or files.';
$string['tenantdeleted'] = 'Tenant removed.';
$string['configsnippet_title'] = 'One-time setup in config.php';
$string['configsnippet_desc'] = '<p><strong>Once only:</strong> add two lines to the server file <code>config.php</code> (the same file where Moodle database settings and wwwroot are defined).</p>
<ol>
<li>Open <code>config.php</code> in the Moodle installation root.</li>
<li>Find this line: <code>require_once(__DIR__ . \'/lib/setup.php\');</code></li>
<li>Paste the following <strong>immediately above</strong> that line:</li>
</ol>
<pre style="direction:ltr;text-align:left;background:#f5f5f5;padding:0.75em;overflow:auto;">require_once(__DIR__ . \'/local/multitenancy/bootstrap.php\');
local_multitenancy_bootstrap($CFG);</pre>
<p>No other settings are required on this page. The tenant registry file is created automatically under moodledata.</p>
<p><em>Optional (developers):</em> for CLI scripts against one tenant, set the environment variable <code>MOODLE_TENANT</code> to the tenant code.</p>';
$string['cli_diag_help'] = 'Diagnose multitenancy gateway (blank page) issues.

Run from your Moodle root directory, e.g.:
  cd /path/to/moodle
  php local/multitenancy/cli/diagnose_gateway.php --shortcode=CODE
  php local/multitenancy/cli/diagnose_gateway.php -s CODE

Checks: MULTITENANCY_REGISTRY_DIR, registry.php, tenant row, wwwroot URL, dataroot path, gateway index.php, DB connect (mysql-family + pgsql), expected browser URL.

';
$string['cli_diag_invalidshortcode'] = 'Invalid tenant code: {$a}';
$string['cli_diag_registrydirundefined'] = 'Could not resolve registry directory (check $CFG->dataroot).';
$string['cli_diag_registrydirmissing'] = 'Tenant list directory does not exist: {$a}. It is created automatically under moodledata when you save a tenant — check write permissions.';
$string['cli_diag_registryfilenotfound'] = 'registry.php does not exist yet: {$a}. Add/save a tenant in Site administration → Manage tenants (created automatically).';
$string['cli_diag_registryfilenotreadable'] = 'registry.php exists but is not readable: {$a}. Fix permissions (chmod/chown) so CLI and the web server user can read it.';
$string['cli_diag_registryfileok'] = 'Registry file OK: {$a}';
$string['cli_diag_registryempty'] = 'registry.php returned an empty map.';
$string['cli_diag_tenantnotinregistry'] = 'No tenant with shortcode "{$a}" in registry — add and save the tenant in admin.';
$string['cli_diag_tenantdisabled'] = 'Tenant "{$a}" is disabled in registry.';
$string['cli_diag_tenantenabled'] = 'Tenant "{$a}" is enabled.';
$string['cli_diag_wwwrootinvalid'] = 'wwwroot in registry is not a full URL (value: "{$a}"). Use e.g. https://yoursite.example';
$string['cli_diag_wwwrootok'] = 'wwwroot OK (Moodle public base derived from stored value): {$a}';
$string['cli_diag_datarootnotabsolute'] = 'dataroot is not an absolute path (value: "{$a}").';
$string['cli_diag_datarootabsolute'] = 'dataroot is absolute: {$a}';
$string['cli_diag_datarootmissing'] = 'dataroot directory does not exist: {$a}';
$string['cli_diag_datarootnotwritable'] = 'dataroot is not writable by this user: {$a}';
$string['cli_diag_datarootok'] = 'dataroot exists and is writable: {$a}';
$string['cli_diag_gatewayindexmissing'] = 'Gateway index.php missing: {$a} — save the tenant again in admin.';
$string['cli_diag_gatewayindexok'] = 'Gateway index.php OK: {$a}';
$string['cli_diag_gatewaystuboutdated'] = 'Gateway index.php differs from the plugin stub — open Manage tenants (auto-refresh) or save the tenant again.';
$string['cli_diag_dbconnectok'] = 'Database connection OK (database: {$a}).';
$string['cli_diag_dbskipped'] = 'Database check skipped (driver: {$a}); only mysqli/mariadb/auroramysql/pgsql are tested by this script.';
$string['cli_diag_dbconnectfail'] = 'Database connection failed: {$a}';
$string['cli_diag_hintweb'] = 'Expected gateway URL in browser: {$a}';
$string['cli_diag_summary_ok'] = 'All critical checks passed. If the browser still shows a blank page, check: web server SCRIPT_NAME vs REQUEST_URI, HTTPS/cookie Secure, PHP/web error logs, and the browser Network tab (empty 302/500).';
$string['cli_diag_summary_fail'] = 'One or more [ERR] lines above explain the likely cause. Fix them, then run this script again.';
