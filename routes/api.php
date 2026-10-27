<?php
//api.php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OnlineSubmissionController;
use App\Http\Controllers\MovController;
use App\Http\Controllers\NfcRequestController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\TimeCorrectionController;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\SupervisorController;
use App\Http\Controllers\WorkModeController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AnnouncementHistoryController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\CardSecurityController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\QuarterlyReportController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\ImportController;
use App\Http\Middleware\LogActivity;

// Who may call what (see app/Http/Middleware/ApiTokenAuth.php)
$anyUser     = ApiTokenAuth::class;                                          // any signed-in user
$staff       = ApiTokenAuth::class . ':admin,ojt_coordinator,supervisor';
$coordinator = ApiTokenAuth::class . ':admin,ojt_coordinator';
$adminOnly   = ApiTokenAuth::class . ':admin';

// Every change a signed-in user makes is written to the activity log (app/Http/Middleware/LogActivity.php)
Route::middleware(LogActivity::class)->group(function () use ($anyUser, $staff, $coordinator, $adminOnly) {

/* ───────── Public (no sign-in needed) ───────── */
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::post('/login/google', [AuthController::class, 'googleLogin'])->middleware('throttle:20,1');
// NFC kiosk. Rate limit: 240 taps a minute per address is far above a real queue of interns,
// but stops anyone from flooding the kiosk route (saved offline taps are sent one by one).
Route::post('/tap',   [AttendanceController::class, 'tap'])->middleware('throttle:240,1');
Route::post('/tap/confirm', [AttendanceController::class, 'confirmCardWrite'])->middleware('throttle:240,1');   // phone / ACR122U kiosk wrote the new card code
Route::get('/kiosk/info', [AccountController::class, 'kioskInfo'])->middleware('throttle:30,1');   // kiosk screen: its name + who can tap

Route::post('/recovery/verify',    [RecoveryController::class, 'verify'])->middleware('throttle:6,1');
Route::post('/recovery/reset',     [RecoveryController::class, 'reset'])->middleware('throttle:6,1');
Route::post('/recovery/send-code', [RecoveryController::class, 'sendCode'])->middleware('throttle:3,1');

/* ───────── Any signed-in user (interns only get their own data) ───────── */
Route::middleware($anyUser)->group(function () {
    Route::get('/my-evaluation', [EvaluationController::class, 'mine']);   // intern: was the evaluation submitted?
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // live dashboards: "has anything changed?" (a tiny fingerprint, asked every few seconds)
    Route::get('/live', [LiveController::class, 'show']);

    // Account security (My Profile) — each call also needs the current password
    Route::post('/account/recovery-code',            [RecoveryController::class, 'show'])->middleware('throttle:10,1');
    Route::post('/account/recovery-code/regenerate', [RecoveryController::class, 'regenerate'])->middleware('throttle:10,1');
    Route::post('/account/change-password',          [RecoveryController::class, 'changePassword'])->middleware('throttle:10,1');
    // Data Privacy Act consent (asked once, on first sign-in)
    Route::post('/account/privacy-consent',          [AuthController::class, 'privacyConsent']);
    // first sign-in after the Excel import: set your own password
    Route::post('/account/first-password',           [AuthController::class, 'firstPassword'])->middleware('throttle:10,1');

    // Intern
    Route::get('/intern/attendance',     [AttendanceController::class, 'internAttendance']);
    Route::post('/intern/submit-online', [OnlineSubmissionController::class, 'submit']);
    Route::get('/intern/my-online',      [OnlineSubmissionController::class, 'mine']);
    Route::get('/intern/nfc-request',    [NfcRequestController::class, 'mine']);
    Route::post('/intern/nfc-request',   [NfcRequestController::class, 'store']);
    Route::post('/intern/submit-mov',    [MovController::class, 'submit']);
    Route::get('/intern/my-movs',        [MovController::class, 'myMovs']);

    // remove your own pending submission
    Route::delete('/intern/submit-online/{id}', [OnlineSubmissionController::class, 'destroy']);
    Route::delete('/intern/submit-mov/{id}',    [MovController::class, 'destroy']);

    // forgot to tap out: ask the coordinator to set the time out
    Route::get('/intern/time-corrections',  [TimeCorrectionController::class, 'mine']);
    Route::post('/intern/time-corrections', [TimeCorrectionController::class, 'store']);

    // DTR certification status (interns see only their own)
    Route::get('/dtr-certifications', [SupervisorController::class, 'certifications']);

    // work arrangement (WFH) requests
    Route::get('/intern/work-mode-requests',         [WorkModeController::class, 'mine']);
    Route::post('/intern/work-mode-requests',        [WorkModeController::class, 'store']);
    Route::delete('/intern/work-mode-requests/{id}', [WorkModeController::class, 'destroy']);

    // requirement due dates (everyone can read them)
    Route::get('/requirement-deadlines', [RequirementController::class, 'index']);
    // reports the coordinator asks for (interns see only theirs)
    Route::get('/reports', [ReportController::class, 'index']);

    // Read-only for everyone
    Route::get('/calendar',      [CalendarController::class, 'index']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);

    // Notifications (the bell)
    Route::get('/notifications',             [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications',      [NotificationController::class, 'clear']);
});

/* ───────── Admin, OJT coordinator, supervisor ───────── */
Route::middleware($staff)->group(function () {
    // intern evaluation by the HTE supervisor: filled in the system, or the signed paper form uploaded
    Route::get('/evaluations/criteria', [EvaluationController::class, 'criteria']);
    Route::get('/evaluations',          [EvaluationController::class, 'index']);
    Route::post('/evaluations',         [EvaluationController::class, 'store']);
    Route::delete('/evaluations/{id}',  [EvaluationController::class, 'destroy']);

    Route::get('/today',                [AttendanceController::class, 'today']);
    Route::get('/admin/all-attendance', [AttendanceController::class, 'allAttendance']);
    Route::get('/admin/users',          [AttendanceController::class, 'allUsers']);

    Route::patch('/users/{id}/settings', [AttendanceController::class, 'updateSettings']);
    // DTR type (2 or 4 taps): the HTE decides, so the intern's supervisor sets it (admin / coordinator can too)
    Route::patch('/supervisor/interns/{id}/dtr-mode', [AccountController::class, 'setDtrMode']);

    // an intern's requirement checklist (shown in their profile under Interns)
    Route::get('/staff/interns/{id}/requirements', [RequirementController::class, 'internRequirements']);
    Route::get('/staff/reports/overdue', [ReportController::class, 'overdue']);   // "Needs attention": reports past due

    // announcements
    Route::post('/announcements',        [AnnouncementController::class, 'store']);
    // removing keeps a copy in the history; from there it can be deleted for good
    Route::get('/announcements/history',          [AnnouncementHistoryController::class, 'index']);
    Route::delete('/announcements/history/{id}',  [AnnouncementHistoryController::class, 'destroy']);
    Route::delete('/announcements/{id}',          [AnnouncementHistoryController::class, 'remove']);
});

/* ───────── HTE supervisor: first review of their own interns + DTR certification ───────── */
Route::middleware(ApiTokenAuth::class . ':supervisor')->group(function () {
    Route::get('/supervisor/online',                        [SupervisorController::class, 'online']);
    Route::post('/supervisor/online/{id}/review',           [SupervisorController::class, 'reviewOnline']);
    Route::get('/supervisor/overtime',                      [SupervisorController::class, 'overtime']);
    Route::post('/supervisor/overtime/{id}/review',         [SupervisorController::class, 'reviewOvertime']);
    Route::get('/supervisor/time-corrections',              [SupervisorController::class, 'corrections']);
    Route::post('/supervisor/time-corrections/{id}/review', [SupervisorController::class, 'reviewCorrection']);
    // work arrangement (WFH / back onsite): the supervisor's decision is final
    Route::get('/supervisor/work-mode-requests',              [WorkModeController::class, 'index']);
    Route::post('/supervisor/work-mode-requests/{id}/review', [WorkModeController::class, 'review']);
    Route::post('/supervisor/dtr-certifications',           [SupervisorController::class, 'certify']);
    Route::delete('/supervisor/dtr-certifications/{id}',    [SupervisorController::class, 'uncertify']);
});

/* ───────── Admin + OJT coordinator: reviews and final decisions ───────── */
Route::middleware($coordinator)->group(function () {
    // quarterly report (Unit Performance Review Report)
    Route::get('/coordinator/quarterly-report', [QuarterlyReportController::class, 'show']);
    Route::put('/coordinator/quarterly-report', [QuarterlyReportController::class, 'save']);

    // copied / tampered card alerts (one-time card codes): the admin and the OJT coordinators
    Route::get('/card-flags',               [CardSecurityController::class, 'index']);
    Route::post('/card-flags/{id}/resolve', [CardSecurityController::class, 'resolve']);

    // assign an intern's HTE supervisor (from the OJT coordinator: this is her job)
    Route::patch('/coordinator/interns/{id}/supervisor', [AttendanceController::class, 'assignSupervisor']);

    // holidays / non-working days on the calendar (the OJT coordinator sets these; the admin can too)
    Route::post('/calendar',        [CalendarController::class, 'store']);
    Route::put('/calendar/{id}',    [CalendarController::class, 'update']);
    Route::delete('/calendar/{id}', [CalendarController::class, 'destroy']);

    // MOV review
    Route::get('/coordinator/movs',              [MovController::class, 'index']);
    Route::post('/coordinator/movs/{id}/review', [MovController::class, 'review']);
    Route::post('/coordinator/movs/{id}/undo',   [MovController::class, 'undoReview']);   // take back a review made by mistake

    // online attendance review + history
    Route::get('/supervisor/pending',   [OnlineSubmissionController::class, 'pending']);
    Route::get('/supervisor/history',   [OnlineSubmissionController::class, 'history']);
    Route::post('/supervisor/validate', [OnlineSubmissionController::class, 'validate']);

    // NFC card requests
    Route::get('/supervisor/nfc-requests', [NfcRequestController::class, 'index']);
    Route::post('/supervisor/nfc-requests/{id}/review', [NfcRequestController::class, 'review']);

    // overtime (time after 5:00 PM) needs the coordinator's approval to count
    Route::get('/coordinator/overtime',               [OvertimeController::class, 'index']);
    Route::post('/coordinator/overtime/{id}/review',  [OvertimeController::class, 'review']);

    // (time corrections and work arrangement are reviewed by the HTE supervisor only — see the supervisor group)

    // requirement due dates + reminders
    Route::put('/coordinator/requirement-deadlines',          [RequirementController::class, 'save']);
    Route::get('/coordinator/requirement-deadlines/missing',  [RequirementController::class, 'missingCounts']);
    Route::get('/coordinator/requirement-deadlines/missing-list', [RequirementController::class, 'missingList']);
    Route::post('/coordinator/requirement-deadlines/remind',  [RequirementController::class, 'remind']);

    // reports (weekly / monthly): the coordinator decides when one is needed and for whom
    Route::post('/coordinator/reports',               [ReportController::class, 'store']);
    Route::put('/coordinator/reports/{id}',           [ReportController::class, 'update']);
    Route::delete('/coordinator/reports/{id}',        [ReportController::class, 'destroy']);
    Route::get('/coordinator/reports/{id}/missing',   [ReportController::class, 'missingList']);
    Route::post('/coordinator/reports/{id}/remind',   [ReportController::class, 'remind']);
});

/* ───────── Edit a profile (details or photo): admin only ───────── */
Route::patch('/admin/users/{id}',      [AttendanceController::class, 'updateUser'])
    ->middleware($adminOnly);
Route::post('/admin/users/{id}/photo', [AttendanceController::class, 'updatePhoto'])
    ->middleware($adminOnly);

/* ───────── Admin only ───────── */
Route::middleware($adminOnly)->group(function () {
    Route::post('/admin/users',          [AttendanceController::class, 'addUser']);
    Route::delete('/admin/users/{id}',   [AttendanceController::class, 'deleteUser']);
    // ID cards are printed outside MYTRACK; the admin marks them as printed here
    Route::post('/admin/nfc-requests/printed', [NfcRequestController::class, 'markPrinted']);
    Route::post('/admin/nfc-requests/{id}/link',  [NfcRequestController::class, 'link']);    // save the card UID (in advance)
    Route::post('/admin/nfc-requests/{id}/claim', [NfcRequestController::class, 'claim']);   // intern picked it up: card becomes active

    // (calendar events moved to the admin + coordinator group: the OJT coordinator sets the calendar)

    // import interns from the coordinator's Excel list (check first, then create)
    Route::post('/admin/users/import/preview', [ImportController::class, 'preview']);
    Route::post('/admin/users/import',         [ImportController::class, 'import']);

    // graduated / inactive accounts: no sign-in and no taps, records stay
    Route::patch('/admin/users/active-batch', [AccountController::class, 'setActiveBatch']);   // end of term (before {id})
    Route::patch('/admin/users/{id}/active', [AccountController::class, 'setActive']);

    // kiosks: each has its own key; an HTE kiosk only accepts that HTE's interns
    Route::get('/admin/kiosks',         [AccountController::class, 'kiosks']);
    Route::post('/admin/kiosks',        [AccountController::class, 'addKiosk']);
    Route::patch('/admin/kiosks/{id}',  [AccountController::class, 'updateKiosk']);
    Route::delete('/admin/kiosks/{id}', [AccountController::class, 'removeKiosk']);

    // activity log
    Route::get('/admin/activity', [AccountController::class, 'activity']);

    // database backups (also: php artisan mytrack:backup)
    Route::get('/admin/backups',         [BackupController::class, 'index']);
    Route::post('/admin/backups',        [BackupController::class, 'store']);
    Route::get('/admin/backups/{name}',  [BackupController::class, 'download']);


    // ID card settings (valid date, coordinator, signature, office contact): saved for every computer
    Route::get('/admin/settings/id-card', [AccountController::class, 'idCardSettings']);
    Route::put('/admin/settings/id-card', [AccountController::class, 'saveIdCardSettings']);
});

});   // end of the activity-log group