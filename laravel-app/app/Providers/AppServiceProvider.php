<?php

namespace App\Providers;

use App\Support\MeetingData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.app', function ($view) {
            $pendingSignatureCount = 0;
            $profileEmployee = null;
            $employeeId = session('employee_id');
            $sessionPasswordHash = session('employee_password_hash');

            if ($employeeId && is_string($sessionPasswordHash)) {
                $employee = DB::table('pegawai')->where('id', $employeeId)->first();
                if ($employee && $employee->password_hash && hash_equals($employee->password_hash, $sessionPasswordHash)) {
                    $profileEmployee = $employee;
                    $pendingSignatureCount = MeetingData::pendingSignatureNotificationCount((int) $employee->id);
                }
            }

            $view->with([
                'pendingSignatureCount' => $pendingSignatureCount,
                'profileEmployee' => $profileEmployee,
            ]);
        });
    }
}
