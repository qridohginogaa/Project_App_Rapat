<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Dashboard Rapat' }} – Poltekkes Kemenkes Manado</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen antialiased">
<header class="top bg-white/95 shadow-sm backdrop-blur">
    <div class="wrap mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-4 py-3">
        <img src="{{ asset('assets/img/logo.png') }}" alt="Kemenkes Poltekkes Manado" class="logo">
        <div class="grow">
            <h1>DASBOARD RAPAT &amp; KEGIATAN</h1>
            <p class="mu">Politeknik Kesehatan Kemenkes Manado</p>
        </div>
        @if(session()->has('employee_id'))
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
        <div class="admin-menu">
            <nav class="wrap mx-auto max-w-6xl px-4 tabs admin-menu-panel" aria-label="Fitur Pengelola Rapat">
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
        </div>
    @endif
</header>
<main class="wrap mx-auto max-w-6xl px-4 py-6 sm:py-8">
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
