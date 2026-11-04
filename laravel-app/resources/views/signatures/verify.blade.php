<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $valid ? 'Notulen Rapat Terverifikasi' : ($pending ? 'Surat Belum Terverifikasi' : 'Persetujuan Tidak Terverifikasi') }} – Poltekkes Kemenkes Manado</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen antialiased">
<main class="signature-verify-page">
    <div class="signature-verify-shell">
        <header class="signature-verify-brand">
            <img src="{{ asset('assets/img/logo.png') }}" alt="Logo Poltekkes Kemenkes Manado">
            <span>Poltekkes Kemenkes Manado</span>
        </header>

        <section class="signature-verify-card">
            <div class="signature-verify-banner {{ $valid ? 'is-valid' : ($pending ? 'is-pending' : 'is-invalid') }}">
                <span class="signature-verify-mark" aria-hidden="true">{{ $valid ? '✓' : ($pending ? '…' : '!') }}</span>
                <div>
                    <h1>{{ $valid ? 'Notulen Rapat Terverifikasi' : ($pending ? 'Surat Belum Terverifikasi' : 'Persetujuan Tidak Terverifikasi') }}</h1>
                    <p>
                        @if($valid)
                            Disetujui dan ditandatangani {{ $signature->peran === 'pimpinan' ? 'pimpinan rapat' : 'notulis' }}, tercatat dalam sistem.
                        @elseif($pending)
                            Notulen ini belum terverifikasi karena persetujuan tanda tangan digital dari pimpinan rapat dan notulis belum lengkap.
                        @else
                            QR tidak cocok dengan persetujuan aktif. Dokumen mungkin berubah atau QR berasal dari versi lama.
                        @endif
                    </p>
                </div>
            </div>

            <div class="signature-verify-details">
                @if($signature)
                    <div class="signature-verify-detail">
                        <span>{{ $signature->peran === 'pimpinan' ? 'NAMA PIMPINAN' : 'NAMA NOTULIS' }}</span>
                        <strong>{{ $signature->pegawai_nama }}</strong>
                        <small>{{ $signature->pegawai_jabatan ?: 'Pegawai' }}@if($signature->pegawai_nip) · NIP {{ $signature->pegawai_nip }}@endif</small>
                    </div>
                    <div class="signature-verify-detail">
                        <span>TANGGAL KONFIRMASI TANDA TANGAN</span>
                        <strong>
                            @if($signature->disetujui_pada)
                                {{ \Illuminate\Support\Carbon::parse($signature->disetujui_pada, config('app.timezone'))->locale('id')->translatedFormat('j F Y, H:i') }} WITA
                            @else
                                Belum disetujui
                            @endif
                        </strong>
                    </div>
                    <div class="signature-verify-detail">
                        <span>RAPAT YANG DIKONFIRMASI</span>
                        <strong>{{ $signature->judul }}</strong>
                        <small>{{ \App\Support\MeetingData::date($signature->tanggal) }} · {{ $signature->tempat ?: 'Tempat belum ditentukan' }}</small>
                    </div>
                @endif
                <div class="signature-verify-detail signature-verify-code">
                    <span>KODE VERIFIKASI</span>
                    <strong>{{ $verificationCode }}</strong>
                </div>
            </div>

            <p class="signature-verify-disclaimer">Verifikasi persetujuan internal aplikasi. Halaman ini bukan sertifikat tanda tangan elektronik BSrE.</p>
        </section>
        <a class="signature-verify-back" href="{{ route('dashboard') }}">Kembali ke Dashboard</a>
    </div>
</main>
</body>
</html>
