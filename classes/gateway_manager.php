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

namespace local_multitenancy;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates one directory per tenant under local/multitenancy/users/{shortcode}/
 * so URLs like /local/multitenancy/users/mattan/ work without web server rewrites.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway_manager {

    /** @var string Tenant shortcode cookie. */
    public const COOKIE_NAME = 'local_mt_sc';

    /** @var string Sticky public wwwroot (must match bootstrap LOCAL_MULTITENANCY_WWWROOT_COOKIE). */
    public const WWWROOT_COOKIE_NAME = 'local_mt_wr';

    /** Current stub marker embedded in generated index.php */
    public const STUB_VERSION = 'v9';

    /**
     * Path to the users gateway root (dirroot-relative filesystem path).
     *
     * @return string
     */
    public static function gateway_root(): string {
        global $CFG;
        return $CFG->dirroot . '/local/multitenancy/users';
    }

    /**
     * Whether shortcode is safe as a directory name.
     *
     * @param string $code
     * @return bool
     */
    public static function is_safe_shortcode(string $code): bool {
        return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $code);
    }

    /**
     * Detect stub version marker from gateway index.php contents.
     *
     * @param string $contents
     * @return string e.g. v9, v6, or unknown
     */
    public static function stub_version_from_contents(string $contents): string {
        if (preg_match('/Stub\s+(v\d+)/', $contents, $m)) {
            return $m[1];
        }
        return 'unknown';
    }

    /**
     * Whether the stub still boots full Moodle (causes blank HTTP 200).
     *
     * @param string $contents
     * @return bool
     */
    public static function stub_loads_moodle_config(string $contents): bool {
        if (preg_match('/\$cfgroot/', $contents) && preg_match('/\brequire(?:_once)?\b/', $contents)) {
            return true;
        }
        if (preg_match('/config\.php/', $contents) && preg_match('/\brequire(?:_once)?\b/', $contents)) {
            return true;
        }
        return false;
    }

    /**
     * PHP source for each tenant gateway index.php.
     *
     * Must NOT load Moodle config.php / setup.php.
     *
     * @return string
     */
    public static function index_file_contents(): string {
        $ver = self::STUB_VERSION;
        return <<<PHP
<?php
// local_multitenancy gateway stub {$ver} — do not edit; rewritten by gateway_manager::sync().
// Boots gateway_entry.php only (no Moodle setup.php) to avoid blank HTTP 200 pages.

error_reporting(E_ALL);
ini_set('display_errors', '1');

\$code = basename(__DIR__);
if (!defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')) {
    define('LOCAL_MULTITENANCY_ENTRY_SHORTCODE', \$code);
}

\$entry = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'gateway_entry.php';
if (!is_readable(\$entry)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Multitenancy: gateway_entry.php missing at:\\n{\$entry}\\n";
    exit(1);
}

require \$entry;

PHP;
    }

    /**
     * .htaccess that routes this tenant folder to gateway_entry.php (bypasses stale index.php).
     *
     * @param string $shortcode
     * @return string
     */
    public static function htaccess_contents(string $shortcode): string {
        $code = preg_replace('/[^a-zA-Z0-9_-]/', '', $shortcode);
        return "# local_multitenancy gateway — auto-generated\n"
            . "DirectoryIndex disabled\n"
            . "RewriteEngine On\n"
            . "RewriteRule ^ /local/multitenancy/gateway_entry.php?code={$code} [L,QSA,END]\n";
    }

    /**
     * Sync gateway directories with the tenant table (enabled tenants only).
     *
     * @return array{written:int, htaccess:int}
     */
    public static function sync(): array {
        global $DB;

        $stats = ['written' => 0, 'htaccess' => 0];
        $root = self::gateway_root();
        if (!is_dir($root) && !make_writable_directory($root, false)) {
            return $stats;
        }

        // Root rewrite: /users/{code}/ → gateway_entry.php?code=
        $rootht = $root . '/.htaccess';
        $rootrules = "# local_multitenancy users router — auto-generated\n"
            . "RewriteEngine On\n"
            . "RewriteBase /local/multitenancy/users/\n"
            . "RewriteRule ^([A-Za-z0-9_-]+)/?$ /local/multitenancy/gateway_entry.php?code=\$1 [L,QSA,END]\n"
            . "RewriteRule ^([A-Za-z0-9_-]+)/index\\.php$ /local/multitenancy/gateway_entry.php?code=\$1 [L,QSA,END]\n";
        if (!is_readable($rootht) || file_get_contents($rootht) !== $rootrules) {
            if (@file_put_contents($rootht, $rootrules) !== false) {
                $stats['htaccess']++;
            }
        }

        $wanted = [];
        $records = $DB->get_records('local_multitenancy_tenant', ['enabled' => 1], 'id ASC');
        foreach ($records as $r) {
            if (!self::is_safe_shortcode($r->shortcode)) {
                continue;
            }
            $wanted[$r->shortcode] = true;
            $dir = $root . '/' . $r->shortcode;
            if (!is_dir($dir) && !mkdir($dir, 02770, true)) {
                continue;
            }

            $index = $dir . '/index.php';
            $contents = self::index_file_contents();
            // Always rewrite when outdated or still loading Moodle config.
            $current = is_readable($index) ? (string) file_get_contents($index) : '';
            if ($current !== $contents) {
                if (file_put_contents($index, $contents) !== false) {
                    @chmod($index, 0660);
                    $stats['written']++;
                }
            }

            $ht = $dir . '/.htaccess';
            $htcontents = self::htaccess_contents($r->shortcode);
            if (!is_readable($ht) || file_get_contents($ht) !== $htcontents) {
                if (@file_put_contents($ht, $htcontents) !== false) {
                    $stats['htaccess']++;
                }
            }

            // Empty index.html would win DirectoryIndex and produce blank 200.
            $html = $dir . '/index.html';
            if (is_file($html)) {
                @unlink($html);
            }
        }

        if (!($dh = opendir($root))) {
            return $stats;
        }
        while (($name = readdir($dh)) !== false) {
            if ($name === '.' || $name === '..' || $name === '.htaccess') {
                continue;
            }
            $path = $root . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }
            if (!self::is_safe_shortcode($name)) {
                continue;
            }
            if (isset($wanted[$name])) {
                continue;
            }
            self::remove_directory($path);
        }
        closedir($dh);

        return $stats;
    }

    /**
     * @param string $dir Absolute path
     * @return void
     */
    protected static function remove_directory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::remove_directory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
