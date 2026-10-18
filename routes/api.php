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
use App\Http\Controllers\AnnouncementHistoryController;

// Who may call what (see app/Http/Middleware/ApiTokenAuth.php)
$anyUser     = ApiTokenAuth::class;                                          // any signed-in user
$staff       = ApiTokenAuth::class . ':admin,ojt_coordinator,supervisor';
$coordinator = ApiTokenAuth::class . ':admin,ojt_coordinator';
$adminOnly   = ApiTokenAuth::class . ':admin';

/* ───────── Public (no sign-in needed) ───────── */
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::post('/login/google', [AuthController::class, 'googleLogin'])->middleware('throttle:20,1');
Route::post('/tap',   [AttendanceController::class, 'tap']);                  // NFC kiosk

Route::post('/recovery/verify',    [RecoveryController::class, 'verify'])->middleware('throttle:6,1');
Route::post('/recovery/reset',     [RecoveryController::class, 'reset'])->middleware('throttle:6,1');
Route::post('/recovery/send-code', [RecoveryController::class, 'sendCode'])->middleware('throttle:3,1');

/* ───────── Any signed-in user (interns only get their own data) ───────── */
Route::middleware($anyUser)->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // Account security (My Profile) — each call also needs the current password
    Route::post('/account/recovery-code',            [RecoveryController::class, 'show'])->middleware('throttle:10,1');
    Route::post('/account/recovery-code/regenerate', [RecoveryController::class, 'regenerate'])->middleware('throttle:10,1');
    Route::post('/account/change-password',          [RecoveryController::class, 'changePassword'])->middleware('throttle:10,1');

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
    Route::get('/today',                [AttendanceController::class, 'today']);
    Route::get('/admin/all-attendance', [AttendanceController::class, 'allAttendance']);
    Route::get('/admin/users',          [AttendanceController::class, 'allUsers']);

    Route::patch('/users/{id}/settings', [AttendanceController::class, 'updateSettings']);

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
    Route::post('/supervisor/dtr-certifications',           [SupervisorController::class, 'certify']);
    Route::delete('/supervisor/dtr-certifications/{id}',    [SupervisorController::class, 'uncertify']);
});

/* ───────── Admin + OJT coordinator: reviews and final decisions ───────── */
Route::middleware($coordinator)->group(function () {
    // MOV review
    Route::get('/coordinator/movs',              [MovController::class, 'index']);
    Route::post('/coordinator/movs/{id}/review', [MovController::class, 'review']);

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

    // forgotten time-outs sent by interns
    Route::get('/coordinator/time-corrections',              [TimeCorrectionController::class, 'index']);
    Route::post('/coordinator/time-corrections/{id}/review', [TimeCorrectionController::class, 'review']);

    // WFH requests
    Route::get('/coordinator/work-mode-requests',              [WorkModeController::class, 'index']);
    Route::post('/coordinator/work-mode-requests/{id}/review', [WorkModeController::class, 'review']);

    // requirement due dates + reminders
    Route::put('/coordinator/requirement-deadlines',          [RequirementController::class, 'save']);
    Route::get('/coordinator/requirement-deadlines/missing',  [RequirementController::class, 'missingCounts']);
    Route::post('/coordinator/requirement-deadlines/remind',  [RequirementController::class, 'remind']);
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

    Route::post('/calendar',        [CalendarController::class, 'store']);
    Route::put('/calendar/{id}',    [CalendarController::class, 'update']);
    Route::delete('/calendar/{id}', [CalendarController::class, 'destroy']);
});