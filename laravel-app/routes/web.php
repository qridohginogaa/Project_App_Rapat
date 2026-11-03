<?php

use App\Http\Middleware\RequireChangedEmployeePassword;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
Route::get('/login', [PortalController::class, 'loginForm'])->name('login');
Route::post('/login', [PortalController::class, 'login'])->name('login.submit');
Route::post('/logout', [PortalController::class, 'logout'])->name('logout');
Route::post('/role/switch', [PortalController::class, 'switchRole'])->middleware(RequireChangedEmployeePassword::class)->name('role.switch');
Route::post('/employee/login', [PortalController::class, 'employeeLogin'])->name('employee.login');
Route::get('/employee/password', [PortalController::class, 'employeePasswordForm'])->name('employee.password');
Route::post('/employee/password', [PortalController::class, 'updateEmployeePassword'])->name('employee.password.update');
Route::post('/employee/logout', [PortalController::class, 'employeeLogout'])->name('employee.logout');
Route::post('/meetings', [PortalController::class, 'createMeeting'])->middleware(RequireChangedEmployeePassword::class)->name('meetings.create');
Route::get('/guest-attendance', [PortalController::class, 'guestAttendanceForm'])->name('guest-attendance');
Route::post('/guest-attendance', [PortalController::class, 'registerGuestAttendance'])->middleware('throttle:60,1')->name('guest-attendance.store');
Route::get('/meet-{meetingSlug}', [PortalController::class, 'meetingBySlug'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->where('meetingSlug', '\d{4}-\d{2}-[a-z0-9-]+-\d{2,}')
    ->name('meetings.canonical');
Route::get('/meet-{meetingSlug}/minutes-attendance/export', [PortalController::class, 'exportMeetingReportBySlug'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->where('meetingSlug', '\d{4}-\d{2}-[a-z0-9-]+-\d{2,}')
    ->name('meetings.report.canonical');
Route::get('/meetings/{meeting}/participants', [PortalController::class, 'meetingParticipants'])->whereNumber('meeting')->name('meetings.participants');
Route::get('/meetings/{meeting}', [PortalController::class, 'meeting'])->middleware(RequireChangedEmployeePassword::class)->name('meetings.show');
Route::get('/meetings/{meeting}/minutes-attendance/export', [PortalController::class, 'exportMeetingReport'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->whereNumber('meeting')
    ->name('meetings.report.export');
Route::get('/signatures', [PortalController::class, 'signatureInbox'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->name('signatures.index');
Route::post('/meetings/{meeting}/signatures/{role}/accept', [PortalController::class, 'acceptMeetingSignature'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->whereNumber('meeting')
    ->whereIn('role', ['pimpinan', 'notulis'])
    ->name('signatures.accept');
Route::get('/signatures/verify/{token}', [PortalController::class, 'verifyMeetingSignature'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('signatures.verify');
Route::post('/meetings/{meeting}/actions', [PortalController::class, 'meetingAction'])->middleware(RequireChangedEmployeePassword::class)->name('meetings.action');

Route::get('/admin/login', fn () => redirect()->route('login'))->name('admin.login');
Route::get('/admin/attendance/{meeting}/{type}/export', [PortalController::class, 'exportAttendance'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->whereNumber('meeting')
    ->whereIn('type', ['pegawai', 'tamu'])
    ->name('admin.attendance.export');
Route::get('/live-board', [PortalController::class, 'liveBoard'])->name('live-board');
Route::get('/live-board/data', [PortalController::class, 'liveBoardData'])->name('live-board.data');
Route::get('/admin/{tab?}', [PortalController::class, 'admin'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->where('tab', 'ringkasan|rapat|ruang|kehadiran|pegawai|grup|superadmin|pengaturan')
    ->name('admin');
Route::post('/admin/actions', [PortalController::class, 'adminAction'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->name('admin.action');

Route::get('/files/{filename}', [PortalController::class, 'file'])
    ->middleware(RequireChangedEmployeePassword::class)
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('files.show');
