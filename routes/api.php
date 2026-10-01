<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OnlineSubmissionController;
use App\Http\Controllers\MovController;
use App\Http\Controllers\NfcRequestController;

// Auth
Route::post('/login', [AuthController::class, 'login']);
Route::get('/me',     [AuthController::class, 'me']);

// NFC Terminal
Route::post('/tap',   [AttendanceController::class, 'tap']);
Route::get('/today',  [AttendanceController::class, 'today']);

// Intern
Route::get('/intern/attendance',     [AttendanceController::class, 'internAttendance']);
Route::post('/intern/submit-online', [OnlineSubmissionController::class, 'submit']);

// Supervisor
Route::get('/supervisor/pending',    [OnlineSubmissionController::class, 'pending']);
Route::post('/supervisor/validate',  [OnlineSubmissionController::class, 'validate']);

// Admin
Route::post('/admin/users/{id}/photo', [AttendanceController::class, 'updatePhoto']);
Route::get('/admin/all-attendance', [AttendanceController::class, 'allAttendance']);
Route::get('/admin/users', [AttendanceController::class, 'allUsers']);
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