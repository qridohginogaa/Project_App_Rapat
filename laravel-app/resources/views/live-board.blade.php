<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Live Board{{ $selectedRoom ? ' '.$selectedRoom->nama : ' Semua Ruang' }} – Poltekkes Kemenkes Manado</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="live-board-page{{ $selectedRoom ? ' live-board-room' : '' }}">
    <header class="live-board-header">
        <div class="live-board-brand">
            <a class="logo-home-link" href="{{ route('dashboard') }}" aria-label="Kembali ke Dashboard" title="Kembali ke Dashboard">
                <img src="{{ asset('assets/img/logo.png') }}" alt="Kemenkes Poltekkes Manado" class="logo">
            </a>
            <div>
                <p class="live-board-kicker">POLTEKKES KEMENKES MANADO</p>
                <h1>Live Board{{ $selectedRoom ? ' · '.$selectedRoom->nama : ' · Semua Ruang' }}</h1>
                <p class="mu live-board-description">Informasi rapat yang sedang berlangsung, lama rapat, dan jumlah presensi</p>
            </div>
        </div>
        <div class="live-board-actions">
            <time id="live-board-clock">--:--:-- WITA</time>
            <button id="live-board-fullscreen" class="btn sm" type="button" aria-label="Tampilkan layar penuh">Tampilkan layar penuh</button>
        </div>
    </header>
    <main class="live-board-content" data-live-board data-endpoint="{{ route('live-board.data', $selectedRoom ? ['room_id' => $selectedRoom->id] : []) }}">
        <div class="live-board-status" role="status" aria-live="polite">
            <span class="live-board-indicator" aria-hidden="true"></span>
            <span id="live-board-updated">Memuat data rapat...</span>
        </div>
        <div id="live-board-meetings" class="live-board-meetings" aria-live="polite"></div>
    </main>
</body>
</html>
