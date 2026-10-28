<?php

use App\Http\Middleware\RequireChangedEmployeePassword;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
Route::get('/login', [PortalController::class, 'loginForm'])->name('login');
Route::post('/login', [PortalController::class, 'login'])->name('login.submit');
Route::post('/logout', [PortalController::class, 'logout'])->name('logout');
Route::post('/role/switch', [PortalController::class, 'switchRole'])->name('role.switch');
Route::post('/employee/login', [PortalController::class, 'employeeLogin'])->name('employee.login');
Route::get('/employee/password', [PortalController::class, 'employeePasswordForm'])->name('employee.password');
Route::post('/employee/password', [PortalController::class, 'updateEmployeePassword'])->name('employee.password.update');
Route::post('/employee/logout', [PortalController::class, 'employeeLogout'])->name('employee.logout');
Route::post('/meetings', [PortalController::class, 'createMeeting'])->middleware(RequireChangedEmployeePassword::class)->name('meetings.create');
Route::get('/meetings/{meeting}', [PortalController::class, 'meeting'])->middleware(RequireChangedEmployeePassword::class)->name('meetings.show');
Route::post('/meetings/{meeting}/actions', [PortalController::class, 'meetingAction'])->middleware(RequireChangedEmployeePassword::class)->name('meetings.action');

Route::get('/admin/login', fn () => redirect()->route('login'))->name('admin.login');
Route::get('/live-board', [PortalController::class, 'liveBoard'])->name('live-board');
Route::get('/live-board/data', [PortalController::class, 'liveBoardData'])->name('live-board.data');
Route::get('/admin/{tab?}', [PortalController::class, 'admin'])->where('tab', 'ringkasan|rapat|ruang|kehadiran|pegawai|superadmin|pengaturan')->name('admin');
Route::post('/admin/actions', [PortalController::class, 'adminAction'])->name('admin.action');

Route::get('/files/{filename}', [PortalController::class, 'file'])->where('filename', '[A-Za-z0-9._-]+')->name('files.show');
