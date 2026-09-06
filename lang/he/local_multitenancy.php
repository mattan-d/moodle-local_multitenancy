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
$string['manageusers'] = 'ניהול משתמשים (כל הדיירים)';
$string['manageusers_desc'] = 'צפייה במשתמשים מכל מסדי הנתונים של הדיירים במסך אחד. ניתן לסנן לפי דייר, שם, שם משתמש או דוא"ל.';
$string['backtotenants'] = 'חזרה לרשימת דיירים';
$string['alltenants'] = 'כל הדיירים';
$string['filtertenant'] = 'דייר';
$string['filtersearch'] = 'חיפוש';
$string['filtersearchplaceholder'] = 'שם משתמש, דוא"ל, שם או מספר זיהוי';
$string['filterincludesuspended'] = 'כלול משתמשים מושעים';
$string['clearfilters'] = 'נקה סינון';
$string['activefilters'] = 'סינון פעיל:';
$string['usersfound'] = 'נמצאו {$a} משתמשים';
$string['usersshowing'] = 'מציג {$a->from}–{$a->to} מתוך {$a->total} משתמשים';
$string['nousersfound'] = 'לא נמצאו משתמשים התואמים לסינון.';
$string['columntenant'] = 'דייר';
$string['columnusername'] = 'שם משתמש';
$string['columnauth'] = 'אימות';
$string['columnlastlogin'] = 'התחברות אחרונה';
$string['columnsiteadmin'] = 'מנהל מערכת';
$string['columnactions'] = 'פעולות';
$string['usertransfer_action'] = 'החלפת דייר';
$string['usertransfer_siteadmin_na'] = 'לא זמין';
$string['usertransfer_title'] = 'העברת משתמש לדייר אחר';
$string['usertransfer_intro'] = 'חשבון המשתמש ייווצר או יעודכן במסד הנתונים של הדייר היעד, ולאחר מכן יוסר (מחיקה רכה) מהדייר המקור. הרשמות לקורסים וקבצים לא מועתקים.';
$string['usertransfer_source_tenant'] = 'דייר נוכחי';
$string['usertransfer_target_tenant'] = 'דייר יעד';
$string['usertransfer_target_tenant_help'] = 'בחר לאיזה דייר להעביר את המשתמש.';
$string['usertransfer_user_details'] = 'פרטי משתמש';
$string['usertransfer_newpassword'] = 'סיסמה חדשה';
$string['usertransfer_newpassword_help'] = 'השאר ריק כדי לשמור על הסיסמה הקיימת. מלא סיסמה חדשה רק אם ברצונך לשנות אותה בדייר היעד.';
$string['usertransfer_same_tenant'] = 'דייר מקור ויעד חייבים להיות שונים.';
$string['usertransfer_invalid_user'] = 'משתמש לא תקין.';
$string['usertransfer_source_not_ready'] = 'מסד הנתונים של דייר המקור אינו מוכן.';
$string['usertransfer_target_not_ready'] = 'מסד הנתונים של דייר היעד אינו מוכן.';
$string['usertransfer_unsupported_db'] = 'סוג מסד הנתונים אינו נתמך להעברת משתמשים.';
$string['usertransfer_user_not_found'] = 'המשתמש לא נמצא בדייר המקור.';
$string['usertransfer_siteadmin_blocked'] = 'לא ניתן להעביר מנהלי אתר בין דיירים.';
$string['usertransfer_email_conflict'] = 'כתובת הדוא"ל כבר בשימוש על ידי משתמש אחר ({$a->username}) בדייר היעד.';
$string['usertransfer_db_connect'] = 'לא ניתן להתחבר למסד הנתונים של הדייר.';
$string['usertransfer_allocate_id'] = 'לא ניתן להקצות מזהה משתמש חדש בדייר היעד.';
$string['usertransfer_remove_failed'] = 'המשתמש הועתק לדייר היעד אך לא הוסר מהמקור.';
$string['usertransfer_partial'] = 'המשתמש הועבר ל־{$a->target}, אך ההסרה מהמקור נכשלה: {$a->error}';
$string['usertransfer_success'] = '{$a->user} הועבר מ־{$a->source} ל־{$a->target}.';
$string['usertransfer_no_targets'] = 'אין דייר אחר עם מסד נתונים מוכן.';
$string['usercreate_add'] = 'הוספת משתמש';
$string['usercreate_title'] = 'הוספת משתמש לדייר';
$string['usercreate_intro'] = 'יוצר חשבון חדש במסד הנתונים של הדייר שנבחר. המשתמש לא נוסף לאתר האב.';
$string['usercreate_tenant'] = 'דייר';
$string['usercreate_tenant_help'] = 'בחר באיזה מסד נתונים של דייר יישמר חשבון המשתמש.';
$string['usercreate_tenant_required'] = 'יש לבחור דייר.';
$string['usercreate_tenant_not_found'] = 'הדייר לא נמצא או אינו מופעל.';
$string['usercreate_tenant_not_ready'] = 'מסד הנתונים של הדייר אינו מוכן.';
$string['usercreate_no_tenants'] = 'אין דייר מופעל עם מסד נתונים מוכן.';
$string['usercreate_password_required'] = 'סיסמה נדרשת למשתמש חדש.';
$string['usercreate_invalid_username'] = 'שם משתמש נדרש.';
$string['usercreate_username_exists'] = 'שם המשתמש "{$a}" כבר קיים בדייר זה.';
$string['usercreate_email_conflict'] = 'כתובת הדוא"ל כבר בשימוש על ידי משתמש אחר ({$a->username}) בדייר זה.';
$string['usercreate_success'] = '{$a->user} נוצר ב־{$a->tenant}.';
$string['addtenant'] = 'הוספת דייר';
$string['edittenant'] = 'עריכת דייר';
$string['deletetenant'] = 'מחיקת דייר';
$string['shortcode'] = 'קוד דייר';
$string['shortcode_help'] = 'מזהה קצר וייחודי לשימוש ב־CLI (משתנה סביבה MOODLE_TENANT). מומלץ אותיות, מספרים וקו תחתון בלבד.';
$string['errorshortcodenotallowed'] = 'קוד הדייר חייב להיבחר מתוך רשימת קודי הדיירים המותרים.';
$string['manageallowedshortcodes'] = 'ניהול קודי דיירים מותרים';
$string['allowedshortcodes'] = 'קודי דיירים מותרים';
$string['allowedshortcodes_help'] = 'הזן קוד דייר אחד בכל שורה. קודים אלו יוצגו כ־dropdown במסך הוספת דייר.';
$string['allowedshortcodesdesc'] = 'הגדר כאן את רשימת קודי הדייר האפשריים. במסך "הוספת דייר", שדה קוד הדייר יוצג כרשימה נפתחת לפי רשימה זו.';
$string['allowedshortcodessaved'] = 'רשימת קודי הדיירים המותרים נשמרה.';
$string['errorallowedshortcodesinvalid'] = 'קוד דייר לא תקין "{$a}". מותר להשתמש רק באותיות, מספרים וקו תחתון.';
$string['host'] = 'מארח HTTP (Host)';
$string['host_help'] = 'לדייר לפי תת־דומיין: ערך Host מדויק (למשל school1.example.com). לדיירים לפי נתיב על אותו דומיין כמו האב, השתמש במארח־מקום ייחודי לכל דייר (למשל mattan.path.local) שלא מגיע בקשות אמיתיות — הזיהוי הוא דרך /local/multitenancy/users/{קוד}/ ועוגייה.';
$string['wwwroot'] = 'כתובת wwwroot של הדייר';
$string['wwwroot_help'] = 'כאן מודל צריך את אותו בסיס ציבורי כמו ב־config.php של האתר האב (למשל https://yoursite.example או https://yoursite.example/moodle). כתובת ה־gateway ‎https://…/local/multitenancy/users/{קוד}/‎ היא אופציונלית — אותו מידע כמו בסיס + קוד דייר, והתוסף מנרמל ממנה את הבסיס. לדייר לפי נתיב: מספיק רק בסיס האתר ואין כפילות מיותרת. לדייר לפי תת־דומיין: כתובת האתר המלאה של הדייר.';
$string['errorwwwrootgatewaymismatch'] = 'ב־wwwroot מופיע ‎/local/multitenancy/users/…‎ אבל הקטע בנתיב ("{$a->found}") לא תואם לקוד הדייר ("{$a->expected}").';
$string['gatewayurl'] = 'כתובת Gateway';
$string['gatewayexplain'] = 'לכל דייר מופעל נוצרת תיקייה local/multitenancy/users/{קוד דייר}/ כך שכתובות כמו .../users/mattan/ עובדות בלי rewrite ב־Apache. הכניסה מגדירה עוגיות. /local/multitenancy/leave.php מנקה עוגיות וחוזר לאתר האב. תיקיות ה־gateway מתעדכנות אוטומטית בשמירת דייר.';
$string['dataroot'] = 'נתיב moodledata של הדייר';
$string['dbhost'] = 'שרת מסד נתונים';
$string['dbname'] = 'שם מסד נתונים';
$string['dbuser'] = 'משתמש מסד';
$string['dbpass'] = 'סיסמת מסד';
$string['dbprefix'] = 'קידומת טבלאות';
$string['dbtype'] = 'סוג מסד';
$string['dblibrary'] = 'ספריית מסד';
$string['enabled'] = 'מופעל';
$string['showonlogin'] = 'הצג בדף התחברות';
$string['showonlogin_help'] = 'כאשר מסומן, הדייר יופיע ברשימת הדיירים בדף ההתחברות של האתר הראשי (/login/index.php).';
$string['tasksyncparentadmins'] = 'סנכרון מנהלי וההגדרות של האתר הראשי לדיירים';
$string['settingssynced'] = 'הגדרות האתר הראשי וחבילות השפה סונכרנו לדייר: {$a}';
$string['settingssyncfailed'] = 'סנכרון הגדרות האתר הראשי לדייר נכשל: {$a}';
$string['taskprovisiontenant'] = 'הקמת דייר (ריבוי דיירים)';
$string['tenantprovisionqueued'] = 'הדייר "{$a}" נשמר. ההקמה (מסד וקבצים) רצה ברקע — רענן את רשימת הדיירים בעוד מספר דקות. אל תפתח את ה־gateway עד שהסטטוס «הושלם».';
$string['columnprovision'] = 'הקמה';
$string['provisionstatus_pending'] = 'בתור';
$string['provisionstatus_processing'] = 'בתהליך';
$string['provisionstatus_complete'] = 'הושלם';
$string['provisionstatus_failed'] = 'נכשל';
$string['provisionstatus_unknown'] = '—';
$string['dboptions'] = 'dboptions (JSON)';
$string['dboptions_help'] = 'אובייקט JSON אופציונלי שמוזג ל־$CFG->dboptions עבור הדייר. השאר ריק לשימוש בברירות מחדל מ־config.php.';
$string['dboptionsexample'] = 'דוגמת JSON (אובייקט בלבד): {"dbpersist": false, "dbport": 3306}. השתמש בזוגות מפתח/ערך כמו ב־$CFG->dboptions. לא לעטוף ב־[] ולא להשאיר פסיק מיותר בסוף.';
$string['sortorder'] = 'סדר מיון';
$string['midurim'] = 'מידורים';
$string['midurim_help'] = 'תוכן HTML שמוצג בראש כל עמוד בזמן גלישה בדייר זה (באנר בסגנון Jumbotron). מוצג רק כשהדייר פעיל. נשמר אוטומטית עם הדייר.';
$string['autogeneratedtenantfields'] = 'Host, כתובת wwwroot ונתיב dataroot של הדייר נוצרים אוטומטית לפי קוד הדייר והגדרות האתר הראשי.';
$string['autogenerateddbfields'] = 'שרת/שם/משתמש/סיסמת DB, קידומת טבלאות, סוג מסד וספריית מסד נוצרים אוטומטית לפי קונפיג האתר הראשי וקוד הדייר. סכימת DB של הדייר נוצרת אוטומטית בזמן שמירה.';
$string['initdbfromparent'] = 'אתחול DB דייר מהאתר הראשי אם הוא ריק';
$string['initdbfromparent_help'] = 'כאשר מסומן, שמירת הדייר תעתיק אוטומטית את DB Moodle הראשי אל DB הדייר אם כרגע אין בו טבלאות.';
$string['copycoursesdata'] = 'להעתיק גם קורסים וכל נתוני הקורסים';
$string['copycoursesdata_help'] = 'מסומן: העתקה מלאה כולל כל הקורסים. לא מסומן: תועתק רק סביבת קורס החזית (front page), ונתונים המשויכים לקורסים אחרים ידולגו.';
$string['dbprovisioned'] = 'DB הדייר "{$a}" אותחל מתוך DB האתר הראשי.';
$string['dbprovisionednocourses'] = 'DB הדייר "{$a}" אותחל מתוך DB האתר הראשי ללא העתקת נתוני קורסים (מלבד קורס חזית).';
$string['dbprovisionskippednotempty'] = 'DB הדייר "{$a}" כבר מכיל טבלאות, לכן האתחול דולג.';
$string['dbprovisionskippedunsupported'] = 'אתחול DB אוטומטי נתמך כרגע רק לדרייברים ממשפחת mysql ול־pgsql (dbtype של הדייר: "{$a}").';
$string['dbprovisionfailed'] = 'אתחול DB אוטומטי לדייר נכשל: {$a}';
$string['dbnotinstalled'] = 'מסד הדייר "{$a}" עדיין אינו התקנת Moodle מושלמת אחרי העתקה/ניסיון אוטומטי. בדקו הרשאות PostgreSQL ל־CREATE DATABASE / TEMPLATE, והמתינו לניסיון האוטומטי הבא (או פתחו דיאגנוזה).';
$string['diagnose_autoretry'] = 'הוכנסה מחדש אוטומטית להקמה עבור {$a} דייר(ים) עם העתקת מסד לא שלמה. רעננו בעוד מספר דקות אחרי הרצת cron.';
$string['initdbfromparent'] = 'אתחול DB דייר מהאתר הראשי אם הוא ריק';
$string['initdbfromparent_help'] = 'מתבצע תמיד אוטומטית ביצירת דייר. נשמר לתאימות עם מטמון שפה ישן.';
$string['datarootautocreatefailed'] = 'לא ניתן ליצור אוטומטית את dataroot של הדייר: {$a}. צור ידנית וודא הרשאות כתיבה לשרת.';
$string['dbschemaautocreated'] = 'סכימת מסד הנתונים של הדייר קיימת ומוכנה: {$a}.';
$string['dbschemaautocreatefailed'] = 'לא ניתן היה להבטיח אוטומטית את סכימת מסד הנתונים של הדייר: {$a}';
$string['footertenantcontext'] = 'הקשר דייר פעיל: {$a->name} ({$a->code})';
$string['footertenantcurrent'] = 'דייר נוכחי:';
$string['footertenantmeta'] = 'מזהה דייר: {$a->id} · {$a->code}';
$string['footertenantmeta_code'] = 'קוד דייר: {$a}';
$string['footertenantregion'] = 'הקשר דייר פעיל';
$string['footerleavetenant'] = 'יציאה מהדייר';
$string['logintenantpicker_label'] = 'כניסה לאתר שלך';
$string['logintenantpicker_placeholder'] = 'בחר אתר…';
$string['logintenantpicker_title'] = 'כניסה לדייר';
$string['logintenantpicker_desc'] = 'בחר דייר כדי להמשיך להקשר ההתחברות שלו.';
$string['passwordunchanged'] = 'השאר ריק כדי לשמור על הסיסמה הקיימת';
$string['errorshortcodeexists'] = 'קוד הדייר כבר בשימוש.';
$string['errorhostexists'] = 'מארח זה כבר משויך לדייר אחר.';
$string['errordboptionsjson'] = 'JSON לא תקין עבור dboptions.';
$string['errorwwwrootinvalid'] = 'wwwroot חייב להיות כתובת מלאה שמתחילה ב־http:// או https:// (למשל https://yoursite.example/local/multitenancy/users/קוד-הדייר/).';
$string['errordatarootnotabsolute'] = 'dataroot חייב להיות נתיב מוחלט בשרת (למשל /var/moodledata/tenant1), לא שם קצר.';
$string['registrydirmissing'] = 'לא ניתן ליצור או לכתוב את רשימת הדיירים האוטומטית תחת moodledata ({dataroot}/multitenancy). ודא שלשרת האינטרנט יש הרשאת כתיבה ל־moodledata.';
$string['manage_recovery_hint'] = 'אם האתר נכנס ללולאת הפניות אחרי כניסה לדייר, נקה את עוגיית הדייר: {$a}';
$string['manage_provision_failed'] = 'הקמת הדייר "{$a->name}" ({$a->code}) נכשלה: {$a->error}';
$string['manage_provision_pending'] = '{$a} דייר(ים) עדיין בהקמה. אל תפתח את ה־gateway עד שהסטטוס «הושלם». הרץ cron במידת הצורך.';
$string['gateway_not_ready'] = 'לא מוכן (המתן ל«הושלם»)';
$string['diagnose'] = 'דיאגנוזה';
$string['diagnose_title'] = 'דיאגנוזה לדייר: {$a}';
$string['diagnose_intro'] = 'בדיקות לדייר <strong>{$a->name}</strong> (קוד <code>{$a->code}</code>, מסד <code>{$a->dbname}</code>). השתמש בזה כשה־gateway פותח מסך התקנה, ההתחברות נכשלת, או שהדפדפן מציג too many redirects.';
$string['diagnose_summary_ok'] = 'לא נמצאו בעיות קריטיות. אם עדיין יש לולאה בדפדפן — לחץ «יציאה מהדייר» לניקוי עוגיות ואז נסה שוב את ה־gateway.';
$string['diagnose_summary_warn'] = 'נמצאו אזהרות. עיין בעמודת «איך פותרים» לפני פתיחת ה־gateway.';
$string['diagnose_summary_fail'] = 'נמצאו בעיות קריטיות. אל תפתח את ה־gateway עד לתיקון — השתמש בעמודת התיקון וב«הקמה מחדש» במידת הצורך.';
$string['diagnose_col_level'] = 'רמה';
$string['diagnose_col_check'] = 'תוצאת בדיקה';
$string['diagnose_col_fix'] = 'איך פותרים';
$string['diagnose_level_ok'] = 'תקין';
$string['diagnose_level_warn'] = 'אזהרה';
$string['diagnose_level_error'] = 'שגיאה';
$string['diagnose_rerun'] = 'הרץ דיאגנוזה שוב';
$string['diagnose_reprovision'] = 'רוקן DB שבור והקם מחדש';
$string['diagnose_reprovision_confirm'] = 'לרוקן את מסד הדייר "{$a}" (אם Moodle לא מותקן שם) ולהכניס להקמה מחדש מהאתר הראשי? רשומת הדייר עצמה לא נמחקת.';
$string['diagnose_emptyfailed'] = 'לא ניתן לרוקן את מסד הדייר לפני הקמה מחדש: {$a}';
$string['diagnose_fix_generic'] = 'תקן את השגיאה למעלה והרץ דיאגנוזה שוב.';
$string['diagnose_fix_dbconnect'] = 'ודא host/user/password ושמסד הדייר קיים. בדוק רשת/firewall משרת ה־web.';
$string['diagnose_fix_reprovision'] = 'בדרך כלל אין צורך — ניהול דיירים / cron מנסים שוב אוטומטית. השתמשו ב«רוקן DB והקם מחדש» רק אם הניסיון האוטומטי ממשיך להיכשל.';
$string['diagnose_fix_moodlenotinstalled'] = 'במסד הדייר אין שורת גרסת Moodle. ריקון וניסיון אוטומטי אמורים לרוץ מניהול דיירים / cron. ודאו שלמשתמש ה־DB יש הרשאת CREATE DATABASE … WITH TEMPLATE ב־PostgreSQL.';
$string['diagnose_fix_redirectloop'] = 'הסטטוס «הושלם» אבל המסד אינו מותקן — נקו עוגיות ב«יציאה מהדייר»; ההקמה תנסה שוב אוטומטית.';
$string['diagnose_fix_pgdump'] = 'אופציונלי: התקינו pg_dump/psql לשרתי PostgreSQL מרוחקים. בדיירים על אותו שרת משתמשים אוטומטית ב־CREATE DATABASE WITH TEMPLATE.';
$string['cli_diag_provisionstatus'] = 'סטטוס הקמה במסד: {$a}';
$string['cli_diag_provisionerror'] = 'שגיאת הקמה אחרונה: {$a}';
$string['cli_diag_moodleinstalled'] = 'מסד הדייר נראה כמו Moodle מותקן (יש config.version): {$a}';
$string['cli_diag_moodlenotinstalled'] = 'מסד הדייר אינו Moodle מותקן (חסר config.version): {$a}';
$string['cli_diag_redirectlooprisk'] = 'סיכון גבוה ל־too many redirects בדייר "{$a}": סטטוס Complete + מסד ריק/שבור.';
$string['cli_diag_pgdumpmissing'] = 'חסרים כלי CLI להעתקת PostgreSQL: {$a}';
$string['cli_diag_clitoolsok'] = 'כלי dump ל־CLI זמינים עבור מנהל: {$a}';
$string['cli_diag_tenantdbrowmissing'] = 'הדייר "{$a}" לא נמצא בטבלת local_multitenancy_tenant.';
$string['cli_diag_hintleave'] = 'לניקוי עוגיות דייר דביקות: {$a}';
$string['registryupdated'] = 'הגדרות הדייר נשמרו בהצלחה.';
$string['registrynotwritten'] = 'לא ניתן לכתוב את רשימת הדיירים האוטומטית תחת moodledata ({dataroot}/multitenancy). בדוק הרשאות.';
$string['notenants'] = 'עדיין לא הוגדרו דיירים.';
$string['deleteconfirm'] = 'למחוק את הדייר "{$a}"? פעולה זו לא מוחקת את מסד הנתונים או קבצי ה־moodledata של הדייר.';
$string['tenantdeleted'] = 'הדייר נמחק.';
$string['configsnippet_title'] = 'הפעלה חד־פעמית בקובץ config.php';
$string['configsnippet_desc'] = '<p><strong>פעם אחת בלבד</strong> צריך להוסיף שתי שורות לקובץ <code>config.php</code> בשרת (אותו קובץ שבו מוגדרים מסד הנתונים ו־wwwroot של Moodle).</p>
<ol>
<li>פתחו את הקובץ <code>config.php</code> בשורש התקנת Moodle.</li>
<li>מצאו את השורה: <code>require_once(__DIR__ . \'/lib/setup.php\');</code></li>
<li>הדביקו <strong>מיד לפניה</strong> את שתי השורות הבאות:</li>
</ol>
<pre style="direction:ltr;text-align:left;background:#f5f5f5;padding:0.75em;overflow:auto;">require_once(__DIR__ . \'/local/multitenancy/bootstrap.php\');
local_multitenancy_bootstrap($CFG);</pre>
<p>אין צורך בהגדרות נוספות במסך זה. קובץ הרישום של הדיירים נוצר אוטומטית בתיקיית ה־moodledata.</p>
<p><em>למפתחים (אופציונלי):</em> בהרצת פקודות CLI על דייר ספציפי הגדירו משתנה סביבה <code>MOODLE_TENANT</code> עם קוד הדייר.</p>';
$string['cli_diag_help'] = 'אבחון gateway לריבוי דיירים (דף לבן).

הרץ משורש Moodle, למשל:
  cd /path/to/moodle
  php local/multitenancy/cli/diagnose_gateway.php --shortcode=CODE
  php local/multitenancy/cli/diagnose_gateway.php -s CODE

בודק: MULTITENANCY_REGISTRY_DIR, registry.php, רשומת דייר, wwwroot, dataroot, קובץ gateway, חיבור DB (mysql-family + pgsql), כתובת בדפדפן.

';
$string['cli_diag_invalidshortcode'] = 'קוד דייר לא תקין: {$a}';
$string['cli_diag_registrydirundefined'] = 'לא ניתן לחשב את תיקיית ה־registry (בדוק את $CFG->dataroot).';
$string['cli_diag_registrydirmissing'] = 'תיקיית רשימת הדיירים לא קיימת: {$a}. היא נוצרת אוטומטית תחת moodledata בשמירת דייר — בדוק הרשאות כתיבה.';
$string['cli_diag_registryfilenotfound'] = 'אין עדיין registry.php: {$a}. הוסף/שמור דייר בניהול דיירים (נוצר אוטומטית).';
$string['cli_diag_registryfilenotreadable'] = 'registry.php קיים אבל לא ניתן לקריאה: {$a}. תקן הרשאות (chmod/chown) ל־CLI ולמשתמש שרת האינטרנט.';
$string['cli_diag_registryfileok'] = 'קובץ registry תקין: {$a}';
$string['cli_diag_registryempty'] = 'registry.php ריק או לא מערך.';
$string['cli_diag_tenantnotinregistry'] = 'אין דייר עם הקוד "{$a}" ברשימה — הוסף ושמור את הדייר בניהול.';
$string['cli_diag_tenantdisabled'] = 'הדייר "{$a}" מושבת ב־registry.';
$string['cli_diag_tenantenabled'] = 'הדייר "{$a}" מופעל.';
$string['cli_diag_wwwrootinvalid'] = 'wwwroot ב־registry לא URL מלא (ערך: "{$a}").';
$string['cli_diag_wwwrootok'] = 'wwwroot תקין (בסיס ציבורי במודל מפורק מהערך השמור): {$a}';
$string['cli_diag_datarootnotabsolute'] = 'dataroot לא נתיב מוחלט (ערך: "{$a}").';
$string['cli_diag_datarootabsolute'] = 'dataroot מוחלט: {$a}';
$string['cli_diag_datarootmissing'] = 'תיקיית dataroot לא קיימת: {$a}';
$string['cli_diag_datarootnotwritable'] = 'אין כתיבה ל־dataroot למשתמש זה: {$a}';
$string['cli_diag_datarootok'] = 'dataroot קיים וניתן לכתיבה: {$a}';
$string['cli_diag_gatewayindexmissing'] = 'חסר gateway index.php: {$a} — שמור את הדייר שוב בניהול.';
$string['cli_diag_gatewayindexok'] = 'gateway index.php תקין: {$a}';
$string['cli_diag_gatewaystuboutdated'] = 'תוכן index.php שונה מה־stub של התוסף — פתח את ניהול דיירים (רענון אוטומטי) או שמור את הדייר שוב.';
$string['cli_diag_dbconnectok'] = 'חיבור למסד הצליח (מסד: {$a}).';
$string['cli_diag_dbskipped'] = 'דילוג על בדיקת DB (מנהל: {$a}); הסקריפט בודק רק mysqli/mariadb/auroramysql/pgsql.';
$string['cli_diag_dbconnectfail'] = 'חיבור למסד נכשל: {$a}';
$string['cli_diag_hintweb'] = 'כתובת gateway צפויה בדפדפן: {$a}';
$string['cli_diag_summary_ok'] = 'כל הבדיקות הקריטיות עברו. אם עדיין דף לבן: בדוק SCRIPT_NAME, HTTPS/עוגיות, לוגי PHP/שרת, וב־Network בדפדפן.';
$string['cli_diag_summary_fail'] = 'שורות [ERR] למעלה מסבירות את הבעיה. תקן, הרץ שוב את הסקריפט.';
