<?php

use App\Http\Controllers\Api\AdminAttendanceController;
use App\Http\Controllers\Api\AdminDeviceController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\VerifyRegisteredDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Rute Publik
Route::post('/login', [AuthController::class, 'login']);

// Rute Terproteksi untuk Pengguna Biasa
Route::middleware(['auth:sanctum', VerifyRegisteredDevice::class])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/my-devices', [AuthController::class, 'listMyDevices']);

    Route::post('/attendance/scan', [AttendanceController::class, 'scan'])->name('attendance.scan');
    Route::get('/attendance/history', [AttendanceController::class, 'getHistory'])->name('attendance.history');
    Route::get('/attendance/statistics', [AttendanceController::class, 'getStatistics'])->name('attendance.statistics');

    // Rute API lainnya yang memerlukan autentikasi dan verifikasi perangkat
    Route::get('/protected-data', function() {
        return response()->json(['data' => 'This is some protected data for registered & approved devices.']);
    });
});

// Rute Terproteksi untuk Admin
Route::prefix('admin')
    ->name('admin.') // Memberi nama prefix untuk semua rute admin, misal: admin.devices.list
    ->middleware(['auth:sanctum', 'is.admin', 'verified.device'])
    ->group(function () {
        // Manajemen Perangkat Pengguna oleh Admin (dari setup awal)
        Route::get('/devices', [AdminDeviceController::class, 'listAllDevices'])->name('devices.list');
        Route::get('/devices/{userDevice}', [AdminDeviceController::class, 'showDevice'])->name('devices.show'); // {userDevice} akan di-resolve oleh Route Model Binding
        Route::post('/devices/{userDevice}/approve', [AdminDeviceController::class, 'approveDevice'])->name('devices.approve');
        Route::post('/devices/{userDevice}/reject', [AdminDeviceController::class, 'rejectDevice'])->name('devices.reject');
        Route::post('/devices/{userDevice}/revoke', [AdminDeviceController::class, 'revokeDevice'])->name('devices.revoke');
        Route::post('/devices/register-for-user', [AdminDeviceController::class, 'registerDeviceForUser'])->name('devices.register');

        // Manajemen Absensi oleh Admin
        Route::get('/attendance/reports', [AdminAttendanceController::class, 'viewReports'])->name('attendance.reports');
        Route::post('/attendance/{attendance}/correct', [AdminAttendanceController::class, 'makeCorrection'])->name('attendance.correction'); // {attendance} akan di-resolve
        Route::get('/attendance/settings', [AdminAttendanceController::class, 'getSettings'])->name('attendance.settings.index');
        Route::put('/attendance/settings', [AdminAttendanceController::class, 'updateSettings'])->name('attendance.settings.update');

        // Manajemen QR Code oleh Admin (diasumsikan di AdminAttendanceController)
        Route::post('/attendance/qr/generate', [AdminAttendanceController::class, 'generateDailyQr'])->name('qrcodes.generate.daily');
        Route::get('/attendance/qr/active', [AdminAttendanceController::class, 'getActiveQrCode'])->name('qrcodes.active');
        // Anda bisa menambahkan CRUD untuk QR Code jika diperlukan (list, show, update, delete)

        // Manajemen Jadwal Kerja oleh Admin (diasumsikan di AdminAttendanceController)
        Route::get('/attendance/schedules', [AdminAttendanceController::class, 'listWorkSchedules'])->name('work-schedules.list');
        Route::post('/attendance/schedules', [AdminAttendanceController::class, 'createWorkSchedule'])->name('work-schedules.create');
        Route::get('/attendance/schedules/{workSchedule}', [AdminAttendanceController::class, 'showWorkSchedule']) // Metode showWorkSchedule perlu dibuat di controller
        ->name('work-schedules.show'); // {workSchedule} akan di-resolve
        Route::put('/attendance/schedules/{workSchedule}', [AdminAttendanceController::class, 'updateWorkSchedule'])->name('work-schedules.update');
        Route::delete('/attendance/schedules/{workSchedule}', [AdminAttendanceController::class, 'deleteWorkSchedule']) // Metode deleteWorkSchedule perlu dibuat
        ->name('work-schedules.delete');

        // Penugasan Jadwal Kerja ke Pengguna oleh Admin
        Route::get('/attendance/users/{user}/schedule-assignments', [AdminAttendanceController::class, 'listUserScheduleAssignments']) // Metode ini perlu dibuat
        ->name('users.schedule-assignments.list');
        Route::post('/attendance/user-schedules', [AdminAttendanceController::class, 'assignScheduleToUser'])->name('work-schedules.assign');
        // Endpoint untuk update atau delete assignment
        Route::put('/attendance/schedule-assignments/{userWorkScheduleAssignment}', [AdminAttendanceController::class, 'updateScheduleAssignment'])->name('schedule-assignments.update');
        Route::delete('/attendance/schedule-assignments/{userWorkScheduleAssignment}', [AdminAttendanceController::class, 'deleteScheduleAssignment'])->name('schedule-assignments.delete');


        // Contoh rute admin lainnya
        // Route::get('/dashboard-summary', [AdminDashboardController::class, 'summary']);
    });

// Fallback Route untuk menangani endpoint API yang tidak ditemukan
Route::fallback(function(){
    return response()->json(['message' => 'Endpoint tidak ditemukan.'], 404);
});
