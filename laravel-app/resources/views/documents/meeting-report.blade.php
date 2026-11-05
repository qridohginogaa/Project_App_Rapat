<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22mm 18mm 20mm; }
        body { color: #172b3a; font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.48; }
        h1, h2, h3, p { margin: 0; }
        .institution { margin-bottom: 18pt; text-align: center; }
        .institution strong { display: block; font-size: 12pt; letter-spacing: .3pt; }
        .institution span { display: block; margin-top: 3pt; font-size: 10pt; }
        .title { margin-bottom: 16pt; text-align: center; }
        .title h1 { font-size: 16pt; letter-spacing: 1pt; }
        .title h2 { margin-top: 5pt; font-size: 12pt; }
        .metadata, .data-table, .signatures { width: 100%; border-collapse: collapse; }
        .metadata { margin-bottom: 13pt; }
        .metadata td { padding: 3pt 5pt; vertical-align: top; }
        .metadata td:first-child { width: 30%; font-weight: bold; }
        h3 { margin: 13pt 0 6pt; color: #075e59; font-size: 11pt; }
        .minutes { white-space: pre-wrap; overflow-wrap: anywhere; }
        .data-table { margin-top: 7pt; font-size: 8pt; }
        .data-table th, .data-table td { border: .6pt solid #8a9aa7; padding: 5pt 4pt; vertical-align: top; }
        .data-table th { background: #e6f3f1; color: #123d3b; text-align: left; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .place-date { margin-top: 17pt; text-align: right; }
        .signatures { margin-top: 12pt; table-layout: fixed; }
        .signatures td { width: 50%; padding: 5pt 12pt; text-align: center; vertical-align: top; }
        .qr { display: block; width: 90px; height: 90px; margin: 8pt auto 4pt; }
        .signer-name { font-weight: bold; text-decoration: underline; }
        .page-break { page-break-before: always; }
        .verification-note { margin-top: 10pt; color: #546575; font-size: 8pt; text-align: center; }
        .footer { position: fixed; right: 0; bottom: -13mm; left: 0; color: #617283; font-size: 8pt; text-align: center; }
    </style>
</head>
<body>
    <div class="footer">
        Pindai QR untuk memeriksa status verifikasi tanda tangan digital
    </div>
    <header class="institution">
        <strong>KEMENTERIAN KESEHATAN REPUBLIK INDONESIA</strong>
        <span>POLTEKKES KEMENKES MANADO</span>
    </header>
    <div class="title">
        <h1>NOTULEN</h1>
        <h2>{{ mb_strtoupper($meeting->judul) }}</h2>
    </div>

    <table class="metadata">
        <tbody>
            <tr><td>Hari dan Tanggal</td><td>{{ \App\Support\MeetingData::date($meeting->tanggal) }}</td></tr>
            <tr><td>Waktu</td><td>{{ substr($meeting->mulai, 0, 5) }}–{{ substr($meeting->selesai, 0, 5) }} WITA</td></tr>
            <tr><td>Tempat</td><td>{{ $meeting->tempat ?: 'Belum ditentukan' }} ({{ $meeting->moda }}) @if($meeting->link_meeting) · {{ $meeting->link_meeting }} @endif</td></tr>
            <tr><td>Pimpinan Rapat</td><td>{{ $meeting->pimpinan }}</td></tr>
            <tr><td>Agenda</td><td>{{ $meeting->agenda ?: 'Belum ditentukan' }}</td></tr>
            @if($meeting->pemateri)<tr><td>Pemateri</td><td>{{ $meeting->pemateri }}</td></tr>@endif
            <tr><td>Notulis</td><td>{{ $meeting->notulis }}</td></tr>
            <tr><td>Peserta</td><td>{{ count($attendanceRows) }} orang berdasarkan presensi yang tercatat</td></tr>
        </tbody>
    </table>

    <h3>Pokok Pembahasan</h3>
    <div class="minutes">{{ $meeting->notulen }}</div>
    <h3>Tanya Jawab dan Penegasan</h3>
    <p>Catatan tanya jawab dan penegasan sebagaimana tercantum dalam pokok pembahasan.</p>
    <h3>Arahan dan Rencana Tindak Lanjut</h3>
    <table class="data-table">
        <thead><tr><th>No.</th><th>Arahan/Rencana Tindak Lanjut</th><th>Penanggung Jawab</th><th>Waktu / Status</th></tr></thead>
        <tbody>
            @forelse($followUps as $index => $task)
                <tr><td>{{ $index + 1 }}</td><td>{{ $task->tugas }}</td><td>{{ $task->nama_petugas ?: ($task->pegawai_nama ?: 'Belum ditentukan') }}</td><td>{{ $task->deadline ? \App\Support\MeetingData::date($task->deadline).' · ' : '' }}{{ $task->status }}</td></tr>
            @empty
                <tr><td colspan="4">Belum ada tindak lanjut yang dicatat.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="place-date">Dibuat di Manado<br>Pada tanggal {{ \App\Support\MeetingData::date($meeting->tanggal) }}</p>
    <table class="signatures">
        <tr><td>Mengetahui,<br>Pimpinan Rapat</td><td>Notulis</td></tr>
        <tr>
            @foreach($signatures as $signature)
                <td>
                    <img class="qr" src="{{ $signature->qr_code }}" alt="QR verifikasi tanda tangan {{ $signature->pegawai_nama }}">
                    <div class="signer-name">{{ $signature->pegawai_nama }}</div>
                </td>
            @endforeach
        </tr>
    </table>
    <p class="verification-note">
        Pindai QR pada kolom pimpinan atau notulis untuk melihat status verifikasi dan informasi persetujuan terbaru.
    </p>

    <section class="page-break">
        <div class="title">
            <h1>DAFTAR HADIR</h1>
            <h2>{{ mb_strtoupper($meeting->judul) }}</h2>
        </div>
        <table class="metadata">
            <tbody>
                <tr><td>Hari dan Tanggal</td><td>{{ \App\Support\MeetingData::date($meeting->tanggal) }}</td></tr>
                <tr><td>Waktu</td><td>{{ substr($meeting->mulai, 0, 5) }}–{{ substr($meeting->selesai, 0, 5) }} WITA</td></tr>
                <tr><td>Tempat</td><td>{{ $meeting->tempat ?: 'Belum ditentukan' }} ({{ $meeting->moda }})</td></tr>
                <tr><td>Jumlah Peserta</td><td>{{ count($attendanceRows) }} orang berdasarkan presensi yang tercatat</td></tr>
            </tbody>
        </table>
        <table class="data-table">
            <thead><tr><th>No.</th><th>Nama Dosen/Pegawai/Tamu</th><th>NIP/NIM</th><th>Jabatan / Instansi</th><th>Waktu Isi</th></tr></thead>
            <tbody>
                @forelse($attendanceRows as $index => $attendee)
                    <tr><td>{{ $index + 1 }}</td><td>{{ $attendee->nama }} ({{ $attendee->jenis }})</td><td>{{ $attendee->identitas }}</td><td>{{ $attendee->unit }}</td><td>{{ $attendee->waktu ? \Illuminate\Support\Carbon::parse($attendee->waktu, config('app.timezone'))->format('H:i').' WITA' : '—' }}</td></tr>
                @empty
                    <tr><td colspan="5">Belum ada presensi yang tercatat.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</body>
</html>
