@extends('layouts.app', ['title' => 'Login'])

@section('content')
<section class="mx-auto" style="max-width:480px">
    <h2 class="mt-0">Login</h2>
    <p class="mu">Gunakan NIP pegawai untuk masuk. Akun Super Admin menggunakan akun pegawai dengan role Super Admin.</p>
    <form method="post" action="{{ route('login.submit') }}" class="box">
        @csrf
        <label class="mt-0" for="login-identifier">NIP</label>
        <input id="login-identifier" name="identifier" type="text" value="{{ old('identifier') }}" required autofocus autocomplete="username">
        <label for="login-password">Kata sandi</label>
        <input id="login-password" name="password" type="password" required autocomplete="current-password">
        <div class="flex items-center gap-2 mt-3">
            <button class="btn pri">Login</button>
            <a class="btn" href="{{ route('dashboard') }}">Kembali ke dashboard</a>
        </div>
    </form>
</section>
@endsection
