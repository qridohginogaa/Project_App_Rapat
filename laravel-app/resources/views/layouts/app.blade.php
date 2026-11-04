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
        <a class="logo-home-link" href="{{ route('dashboard') }}" aria-label="Kembali ke Dashboard" title="Kembali ke Dashboard">
            <img src="{{ asset('assets/img/logo.png') }}" alt="Kemenkes Poltekkes Manado" class="logo">
        </a>
        <div class="grow">
            <h1>DASHBOARD RAPAT</h1>
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
            @if($profileEmployee)
                @php($profileInitials = collect(preg_split('/\s+/', trim($profileEmployee->nama)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode(''))
                <a class="profile-nav-link" href="{{ route('profile') }}" aria-label="Buka profil akun {{ $profileEmployee->nama }}" title="Profil akun {{ $profileEmployee->nama }}">
                    @if($profileEmployee->profile_photo)
                        <img src="{{ route('profile.photo') }}" alt="" class="profile-nav-avatar">
                    @else
                        <span class="profile-nav-avatar profile-nav-avatar-fallback" aria-hidden="true">{{ mb_strtolower($profileInitials) }}</span>
                    @endif
                </a>
            @endif
            @if(isset($employee) && $employee)
                <a class="btn sm" href="{{ route('signatures.index') }}">
                    Tanda tangan
                    @if(($pendingSignatureCount ?? 0) > 0)<span class="signature-nav-count">{{ $pendingSignatureCount }}</span>@endif
                </a>
            @endif
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn">Keluar</button></form>
        @else
            <nav class="top-guest-actions" aria-label="Akses publik">
                @if(request()->routeIs('dashboard') && !($canSeeMeetings ?? false))
                    <a class="btn top-guest-attendance" href="{{ route('guest-attendance') }}">
                        Presensi Tamu &amp; Mahasiswa
                    </a>
                @elseif(request()->routeIs('meetings.canonical') && isset($meeting))
                    <a class="btn top-guest-attendance" href="{{ route('guest-attendance', ['meeting_id' => $meeting->id]) }}">
                        Presensi Tamu &amp; Mahasiswa
                    </a>
                @endif
                <a class="btn" href="{{ route('login') }}">Login</a>
            </nav>
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
                @if(!empty($canManageInviteGroups))
                    <a href="{{ route('admin', 'grup') }}">Grup Undangan</a>
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
