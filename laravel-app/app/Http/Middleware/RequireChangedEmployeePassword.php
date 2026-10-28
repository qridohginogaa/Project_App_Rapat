<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireChangedEmployeePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (session()->has('employee_id')) {
            $employee = DB::table('pegawai')->where('id', session('employee_id'))->first();

            if ($employee && $employee->password_change_required) {
                return redirect()->route('employee.password');
            }
        }

        return $next($request);
    }
}
