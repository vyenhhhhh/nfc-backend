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

// Auth
Route::post('/login', [AuthController::class, 'login']);
Route::get('/me',     [AuthController::class, 'me']);

// NFC Terminal
Route::post('/tap',   [AttendanceController::class, 'tap']);
Route::get('/today',  [AttendanceController::class, 'today']);

// Intern
Route::get('/intern/attendance',     [AttendanceController::class, 'internAttendance']);
Route::post('/intern/submit-online', [OnlineSubmissionController::class, 'submit']);
Route::get('/intern/my-online',      [OnlineSubmissionController::class, 'mine']);   // <-- new

// Supervisor
Route::get('/supervisor/pending',    [OnlineSubmissionController::class, 'pending']);
Route::post('/supervisor/validate',  [OnlineSubmissionController::class, 'validate']);

// Admin
Route::post('/admin/users/{id}/photo', [AttendanceController::class, 'updatePhoto']);
Route::get('/admin/all-attendance', [AttendanceController::class, 'allAttendance']);
Route::get('/admin/users', [AttendanceController::class, 'allUsers']);
Route::post('/admin/users', [AttendanceController::class, 'addUser']);
Route::delete('/admin/users/{id}', [AttendanceController::class, 'deleteUser']);
Route::patch('/admin/users/{id}', [AttendanceController::class, 'updateUser']);
Route::get('/intern/nfc-request', [NfcRequestController::class, 'mine']);
Route::post('/intern/nfc-request', [NfcRequestController::class, 'store']);
Route::get('/supervisor/nfc-requests', [NfcRequestController::class, 'index']);
Route::post('/supervisor/nfc-requests/{id}/review', [NfcRequestController::class, 'review']);

// Intern MOV submissions
Route::post('/intern/submit-mov', [MovController::class, 'submit']);
Route::get('/intern/my-movs',     [MovController::class, 'myMovs']);

// Coordinator MOV review
Route::get('/coordinator/movs',          [MovController::class, 'index']);
Route::post('/coordinator/movs/{id}/review', [MovController::class, 'review']);

// Update intern work settings (admin, supervisor, coordinator)
Route::patch('/users/{id}/settings', [AttendanceController::class, 'updateSettings']);

// Calendar
Route::get('/calendar', [CalendarController::class, 'index']);
Route::post('/calendar', [CalendarController::class, 'store']);
Route::put('/calendar/{id}', [CalendarController::class, 'update']);
Route::delete('/calendar/{id}', [CalendarController::class, 'destroy']);

// Announcements (coordinator / admin post, interns read)
Route::get('/announcements', [AnnouncementController::class, 'index']);
Route::post('/announcements', [AnnouncementController::class, 'store']);
Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);

// Notifications (the bell)
Route::get('/notifications', [NotificationController::class, 'index']);
Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);