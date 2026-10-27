<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Dashboard Rapat' }} – Poltekkes Kemenkes Manado</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<header class="top">
    <div class="wrap flex items-center gap-3 flex-wrap py-3">
        <img src="{{ asset('assets/img/logo.png') }}" alt="Kemenkes Poltekkes Manado" class="logo">
        <div class="grow">
            <h1>DASBOARD RAPAT &amp; KEGIATAN</h1>
            <p class="mu">Politeknik Kesehatan Kemenkes Manado</p>
        </div>
        @if(session()->has('admin_id'))
            <a class="btn" href="{{ route('admin') }}">Dashboard Super Admin</a>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn">Keluar</button></form>
        @elseif(session()->has('employee_id'))
            @if(isset($employee) && $employee)
                <div class="employee-identity">
                    <b>{{ $employee->nama }}</b>
                    <span class="mu">
                        @if(!empty($isSuperAdmin))
                            (Super Admin)
                        @elseif(!empty($canReturnToEmployeeDashboard) || !empty($canAccessAdminMenu))
                            (Pengelola Rapat)
                        @else
                            {{ $employee->jabatan }}
                        @endif
                    </span>
                </div>
            @endif
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn">Keluar</button></form>
        @else
            <a class="btn" href="{{ route('login') }}">Login</a>
        @endif
    </div>
    <div class="bar"></div>
    @if((request()->routeIs('dashboard') || request()->routeIs('meetings.show')) && isset($employee) && $employee && $canAccessAdminMenu)
        <div class="wrap admin-menu">
            <details>
                <summary class="btn admin-menu-toggle" aria-label="Buka menu fitur" title="Menu fitur">
                    <span aria-hidden="true"><i></i><i></i><i></i></span>
                </summary>
                <nav class="tabs admin-menu-panel" aria-label="Fitur Pengelola Rapat">
                    <a href="{{ route('admin', 'rapat') }}">Rapat</a>
                    @if($canViewAttendance)
                        <a href="{{ route('admin', 'kehadiran') }}">Kehadiran &amp; Izin</a>
                    @endif
                    @if($canManageRooms)
                        <a href="{{ route('admin', 'ruang') }}">Ruang Rapat</a>
                    @endif
                    @if($canManageRoles)
                        <a href="{{ route('admin', 'pegawai') }}">Pegawai</a>
                        <a href="{{ route('admin', 'superadmin') }}">Hak Akses</a>
                        <a href="{{ route('admin', 'pengaturan') }}">Pengaturan</a>
                    @endif
                </nav>
            </details>
        </div>
    @endif
</header>
<main class="wrap py-5">
    @if(session('status'))
        <div class="ok mb-4">{{ session('status') }}</div>
    @endif
    @if(session('success'))
        <div class="ok mb-4">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="err mb-4">
            <ul class="my-1 p-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
