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
 * Hebrew language pack.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ריבוי דיירים (DB ו־moodledata נפרדים)';
$string['manage_tenants'] = 'ניהול דיירים (טננטים)';
$string['addtenant'] = 'הוספת דייר';
$string['edittenant'] = 'עריכת דייר';
$string['deletetenant'] = 'מחיקת דייר';
$string['shortcode'] = 'קוד דייר';
$string['shortcode_help'] = 'מזהה קצר וייחודי לשימוש ב־CLI (משתנה סביבה MOODLE_TENANT). מומלץ אותיות, מספרים וקו תחתון בלבד.';
$string['host'] = 'מארח HTTP (Host)';
$string['host_help'] = 'לדייר לפי תת־דומיין: ערך Host מדויק (למשל school1.example.com). לדיירים לפי נתיב על אותו דומיין כמו האב, השתמש במארח־מקום ייחודי לכל דייר (למשל mattan.path.local) שלא מגיע בקשות אמיתיות — הזיהוי הוא דרך /local/multitenancy/users/{קוד}/ ועוגייה.';
$string['wwwroot'] = 'כתובת wwwroot של הדייר';
$string['wwwroot_help'] = 'כתובת בסיס ציבורית של מודל (למשל https://example.com) — לא נתיב ה־gateway ‎/local/multitenancy/users/…‎. לדיירים לפי נתיב על אותו מארח: אותה בסיס כמו האתר האב. לדייר לפי תת־דומיין אפשר כתובת מלאה משלו.';
$string['gatewayurl'] = 'כתובת Gateway';
$string['gatewayexplain'] = 'לכל דייר מופעל נוצרת תיקייה local/multitenancy/users/{קוד דייר}/ כך שכתובות כמו .../users/mattan/ עובדות בלי rewrite ב־Apache. הכניסה מגדירה עוגיות (קוד דייר + wwwroot ציבורי) כדי שבדיקות ה־URL של Moodle יתאימו ל־SCRIPT_NAME. /local/multitenancy/leave.php מנקה עוגיות וחוזר לאתר האב. לאחר שינוי קוד דייר יש לשמור דייר או לבנות מחדש את ה־registry.';
$string['dataroot'] = 'נתיב moodledata של הדייר';
$string['dbhost'] = 'שרת מסד נתונים';
$string['dbname'] = 'שם מסד נתונים';
$string['dbuser'] = 'משתמש מסד';
$string['dbpass'] = 'סיסמת מסד';
$string['dbprefix'] = 'קידומת טבלאות';
$string['dbtype'] = 'סוג מסד';
$string['dblibrary'] = 'ספריית מסד';
$string['dboptions'] = 'dboptions (JSON)';
$string['dboptions_help'] = 'אובייקט JSON אופציונלי שמוזג ל־$CFG->dboptions עבור הדייר. השאר ריק לשימוש בברירות מחדל מ־config.php.';
$string['sortorder'] = 'סדר מיון';
$string['passwordunchanged'] = 'השאר ריק כדי לשמור על הסיסמה הקיימת';
$string['errorshortcodeexists'] = 'קוד הדייר כבר בשימוש.';
$string['errorhostexists'] = 'מארח זה כבר משויך לדייר אחר.';
$string['errordboptionsjson'] = 'JSON לא תקין עבור dboptions.';
$string['errorwwwrootinvalid'] = 'wwwroot חייב להיות כתובת מלאה שמתחילה ב־http:// או https:// (למשל https://yoursite.example).';
$string['errordatarootnotabsolute'] = 'dataroot חייב להיות נתיב מוחלט בשרת (למשל /var/moodledata/tenant1), לא שם קצר.';
$string['registrydir'] = 'תיקיית registry (נתיב מוחלט)';
$string['registrydir_desc'] = 'תיקייה בשרת שבה נכתב registry.php. חייבת להתאים ל־MULTITENANCY_REGISTRY_DIR ב־config.php. למשתמש שרת האינטרנט חייבת להיות הרשאת כתיבה.';
$string['registrydirmissing'] = 'תיקיית ה־registry לא הוגדרה. הגדר אותה בניהול האתר ← תוספים ← תוספים מקומיים ← ריבוי דיירים, ואז שמור דייר מחדש או לחץ על בנייה מחדש של registry.';
$string['registrydirset'] = 'תיקיית registry: {$a}';
$string['registryupdated'] = 'קובץ ה־registry עודכן בהצלחה.';
$string['registrynotwritten'] = 'קובץ ה־registry לא נכתב. בדוק את הנתיב והרשאות הכתיבה.';
$string['notenants'] = 'עדיין לא הוגדרו דיירים.';
$string['deleteconfirm'] = 'למחוק את הדייר "{$a}"? פעולה זו לא מוחקת את מסד הנתונים או קבצי ה־moodledata של הדייר.';
$string['tenantdeleted'] = 'הדייר נמחק.';
$string['rebuildregistry'] = 'בנייה מחדש של קובץ registry';
$string['configsnippet_title'] = 'קטע ל־config.php';
$string['configsnippet_desc'] = 'הוסף ב־config.php לפני require_once(__DIR__ . \'/lib/setup.php\'). אל תשתמש ב־$CFG->dirroot בשורות האלה: מודל מגדיר אותו רק בתוך setup.php. השתמש ב־__DIR__ לנתיב ל־bootstrap. לדוגמה: if (!defined(\'MULTITENANCY_REGISTRY_DIR\')) { define(\'MULTITENANCY_REGISTRY_DIR\', $CFG->dataroot . \'/multitenancy\'); } require_once(__DIR__ . \'/local/multitenancy/bootstrap.php\'); local_multitenancy_bootstrap($CFG); ודא ש־MULTITENANCY_REGISTRY_DIR תואם להגדרת "תיקיית registry" בתוסף. ב־CLI: export MOODLE_TENANT=קוד_הדייר';
$string['cli_diag_help'] = 'אבחון gateway לריבוי דיירים (דף לבן).

הרץ משורש Moodle, למשל:
  cd /path/to/moodle
  php local/multitenancy/cli/diagnose_gateway.php --shortcode=CODE
  php local/multitenancy/cli/diagnose_gateway.php -s CODE

בודק: MULTITENANCY_REGISTRY_DIR, registry.php, רשומת דייר, wwwroot, dataroot, קובץ gateway, חיבור DB, כתובת בדפדפן.

';
$string['cli_diag_invalidshortcode'] = 'קוד דייר לא תקין: {$a}';
$string['cli_diag_registrydirundefined'] = 'MULTITENANCY_REGISTRY_DIR לא מוגדר ב־config.php.';
$string['cli_diag_registrydirmissing'] = 'תיקיית ה־registry לא קיימת: {$a}. צור אותה (mkdir), תן הרשאות לשרת האינטרנט, ואז במודל שמור דייר או בנה מחדש registry.';
$string['cli_diag_registryfilenotfound'] = 'אין עדיין registry.php: {$a}. במודל: ניהול האתר → תוספים מקומיים → Multitenancy — הגדר "תיקיית registry" לתיקייה זו, הוסף דיירים, ואז שמור או בנייה מחדש.';
$string['cli_diag_registryfilenotreadable'] = 'registry.php קיים אבל לא ניתן לקריאה: {$a}. תקן הרשאות (chmod/chown) ל־CLI ולמשתמש שרת האינטרנט.';
$string['cli_diag_registryfileok'] = 'קובץ registry תקין: {$a}';
$string['cli_diag_registryempty'] = 'registry.php ריק או לא מערך.';
$string['cli_diag_tenantnotinregistry'] = 'אין דייר עם הקוד "{$a}" ב־registry — הוסף בניהול והרץ שמירה או בנייה מחדש.';
$string['cli_diag_tenantdisabled'] = 'הדייר "{$a}" מושבת ב־registry.';
$string['cli_diag_tenantenabled'] = 'הדייר "{$a}" מופעל.';
$string['cli_diag_wwwrootinvalid'] = 'wwwroot ב־registry לא URL מלא (ערך: "{$a}").';
$string['cli_diag_wwwrootok'] = 'wwwroot תקין (בסיס אפקטיבי): {$a}';
$string['cli_diag_wwwrootgatewaystripped'] = 'ב־registry ה־wwwroot היה "{$a->from}" (רק נתיב gateway); התוסף מנרמל ל־"{$a->to}". עדיף לשמור בדייר את כתובת בסיס האתר האמיתית.';
$string['cli_diag_datarootnotabsolute'] = 'dataroot לא נתיב מוחלט (ערך: "{$a}").';
$string['cli_diag_datarootabsolute'] = 'dataroot מוחלט: {$a}';
$string['cli_diag_datarootmissing'] = 'תיקיית dataroot לא קיימת: {$a}';
$string['cli_diag_datarootnotwritable'] = 'אין כתיבה ל־dataroot למשתמש זה: {$a}';
$string['cli_diag_datarootok'] = 'dataroot קיים וניתן לכתיבה: {$a}';
$string['cli_diag_gatewayindexmissing'] = 'חסר gateway index.php: {$a} — שמור דייר או בנה מחדש registry.';
$string['cli_diag_gatewayindexok'] = 'gateway index.php תקין: {$a}';
$string['cli_diag_gatewaystuboutdated'] = 'תוכן index.php שונה מה־stub של התוסף — שמור דייר או בנה מחדש registry.';
$string['cli_diag_dbconnectok'] = 'חיבור למסד הצליח (מסד: {$a}).';
$string['cli_diag_dbskipped'] = 'דילוג על בדיקת DB (מנהל: {$a}); הסקריפט בודק רק mysqli/mariadb/auroramysql.';
$string['cli_diag_dbconnectfail'] = 'חיבור למסד נכשל: {$a}';
$string['cli_diag_hintweb'] = 'כתובת gateway צפויה בדפדפן: {$a}';
$string['cli_diag_summary_ok'] = 'כל הבדיקות הקריטיות עברו. אם עדיין דף לבן: בדוק SCRIPT_NAME, HTTPS/עוגיות, לוגי PHP/שרת, וב־Network בדפדפן.';
$string['cli_diag_summary_fail'] = 'שורות [ERR] למעלה מסבירות את הבעיה. תקן, הרץ שוב את הסקריפט.';
