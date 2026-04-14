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
$string['shortcode_help'] = 'מזהה קצר וייחודי: מופיע בנתיב ה־URL (לפי קידומת הנתיב בהגדרות), ב־CLI (MOODLE_TENANT), וב־SetEnv ב־Apache. מומלץ אותיות, מספרים וקו תחתון בלבד.';
$string['host'] = 'מארח HTTP (אופציונלי)';
$string['host_help'] = 'ניתוב חלופי לפי Host: ערך מדויק של HTTP Host (למשל school1.example.com). השאר ריק אם משתמשים רק בכתובות מסוג .../multitenancy/קוד. מארח אתר האב לא צריך להיות זהה למארח דייר.';
$string['wwwroot'] = 'כתובת wwwroot של הדייר';
$string['wwwroot_help'] = 'עקיפה אופציונלית לכתובת הציבורית המלאה של הדייר. אם ריק: נגזר מ־wwwroot של האתר + קידומת הנתיב + קוד הדייר (למשל https://dev.moodle/multitenancy/user01).';
$string['tenanturlpath'] = 'כתובת לפי נתיב';
$string['pathprefix'] = 'קידומת נתיב לדיירים';
$string['pathprefix_desc'] = 'קטע הנתיב לפני קוד הדייר, למשל /multitenancy נותן https://yoursite/multitenancy/user01. חייב להתחיל ב־/. אחרי שינוי יש לבנות מחדש את registry.';
$string['routingmode'] = 'מצב ניתוב URL';
$string['routingmode_desc'] = 'מצב stub (ברירת מחדל): קבצי PHP קטנים תחת multitenancy/קוד/index.php. בכניסה לכתובת הזו נשלחת עוגיית דייר והדפדפן מופנה לדף הבית של האתר (אותו wwwroot כמו האב); אז Moodle טוען את מסד הדייר בנתיבים כמו / ו־/course/.... ללא Apache או .htaccess. מצב rewrite: wwwroot מלא; דורש rewrite (ראה htaccess-snippet.txt).';
$string['routingmode_stub'] = 'קבצי stub של התוסף + עוגייה (ללא Apache / .htaccess)';
$string['routingmode_rewrite'] = 'wwwroot לפי נתיב + rewrite ב־Apache (מתקדם)';
$string['leaveparent'] = 'יציאה מסשן דייר (מחיקת עוגייה, אתר האב)';
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
$string['configsnippet_desc'] = 'הוסף ב־config.php לפני require_once(__DIR__ . \'/lib/setup.php\'). אל תשתמש ב־$CFG->dirroot לפני setup. דוגמה: if (!defined(\'MULTITENANCY_REGISTRY_DIR\')) { define(\'MULTITENANCY_REGISTRY_DIR\', $CFG->dataroot . \'/multitenancy\'); } require_once(__DIR__ . \'/local/multitenancy/bootstrap.php\'); local_multitenancy_bootstrap($CFG); דיירים מזוהים לפי נתיב ב־URL (wwwroot + קידומת + קוד), לפי MOODLE_TENANT (CLI או SetEnv), או לפי שדה Host האופציונלי. אם SCRIPT_NAME לא כולל את הנתיב המלא, ראה local/multitenancy/apache-rewrite.example.txt';
