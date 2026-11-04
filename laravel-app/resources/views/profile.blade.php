@extends('layouts.app', ['title' => 'Profil Akun'])

@section('content')
<section class="mx-auto max-w-3xl">
    <header class="mb-5">
        <span class="meeting-detail-eyebrow">Pengaturan akun</span>
        <h2 class="mt-1">Profil</h2>
        <p class="mu">Kelola foto profil dan kata sandi akun Anda.</p>
    </header>

    <section class="box mb-5">
        <h3 class="mt-0">Informasi akun</h3>
        <div class="flex flex-wrap items-center gap-4">
            @if($employee->profile_photo)
                <img src="{{ route('profile.photo') }}" alt="Foto profil {{ $employee->nama }}" class="profile-page-avatar">
            @else
                <span class="profile-page-avatar profile-nav-avatar-fallback" aria-hidden="true">{{ mb_substr($employee->nama, 0, 1) }}</span>
            @endif
            <div>
                <b>{{ $employee->nama }}</b>
                <p class="mu mb-0">{{ $employee->nip }} · {{ $employee->jabatan }}</p>
            </div>
        </div>
        <form method="post" action="{{ route('profile.photo.update') }}" enctype="multipart/form-data" class="mt-4">
            @csrf
            <label for="profile-photo">Foto profil (JPG, PNG, atau WEBP; maks. 5 MB)</label>
            <input id="profile-photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" required>
            <button class="btn pri mt-3">Simpan foto</button>
        </form>
    </section>

    <section class="box">
        <h3 class="mt-0">Ganti kata sandi</h3>
        <p class="mu">Masukkan kata sandi saat ini, lalu buat kata sandi baru minimal 8 karakter.</p>
        <form method="post" action="{{ route('profile.password.update') }}">
            @csrf
            <label for="current-password">Kata sandi saat ini</label>
            <input id="current-password" name="current_password" type="password" required autocomplete="current-password">
            <label for="new-password">Kata sandi baru</label>
            <input id="new-password" name="password" type="password" required minlength="8" autocomplete="new-password">
            <label for="new-password-confirmation">Ulangi kata sandi baru</label>
            <input id="new-password-confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password">
            <button class="btn pri mt-3">Ganti kata sandi</button>
        </form>
    </section>
</section>
@endsection
