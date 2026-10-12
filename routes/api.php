<?php

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
use App\Http\Middleware\ApiTokenAuth;

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

    // Read-only for everyone
    Route::get('/calendar',      [CalendarController::class, 'index']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);

    // Notifications (the bell)
    Route::get('/notifications',             [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
});

/* ───────── Admin, OJT coordinator, supervisor ───────── */
Route::middleware($staff)->group(function () {
    Route::get('/today',                [AttendanceController::class, 'today']);
    Route::get('/admin/all-attendance', [AttendanceController::class, 'allAttendance']);
    Route::get('/admin/users',          [AttendanceController::class, 'allUsers']);

    // online attendance review + NFC card requests
    Route::get('/supervisor/pending',    [OnlineSubmissionController::class, 'pending']);
    Route::post('/supervisor/validate',  [OnlineSubmissionController::class, 'validate']);
    Route::get('/supervisor/nfc-requests', [NfcRequestController::class, 'index']);
    Route::post('/supervisor/nfc-requests/{id}/review', [NfcRequestController::class, 'review']);

    Route::patch('/users/{id}/settings', [AttendanceController::class, 'updateSettings']);

    // announcements
    Route::post('/announcements',        [AnnouncementController::class, 'store']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);
});

/* ───────── Admin + OJT coordinator: MOV review ───────── */
Route::middleware($coordinator)->group(function () {
    Route::get('/coordinator/movs',              [MovController::class, 'index']);
    Route::post('/coordinator/movs/{id}/review', [MovController::class, 'review']);
});

/* ───────── Edit a profile: admin, coordinator, or the person themselves ───────── */
Route::patch('/admin/users/{id}',      [AttendanceController::class, 'updateUser'])
    ->middleware(ApiTokenAuth::class . ':admin,ojt_coordinator,self');
Route::post('/admin/users/{id}/photo', [AttendanceController::class, 'updatePhoto'])
    ->middleware(ApiTokenAuth::class . ':admin,self');

/* ───────── Admin only ───────── */
Route::middleware($adminOnly)->group(function () {
    Route::post('/admin/users',          [AttendanceController::class, 'addUser']);
    Route::delete('/admin/users/{id}',   [AttendanceController::class, 'deleteUser']);

    Route::post('/calendar',        [CalendarController::class, 'store']);
    Route::put('/calendar/{id}',    [CalendarController::class, 'update']);
    Route::delete('/calendar/{id}', [CalendarController::class, 'destroy']);
});