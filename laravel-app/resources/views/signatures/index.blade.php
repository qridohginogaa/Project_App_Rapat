@extends('layouts.app', ['title' => 'Tanda Tangan'])

@section('content')
<section class="signature-inbox-page">
    <header class="signature-inbox-heading">
        <div>
            <span class="meeting-detail-eyebrow">Persetujuan dokumen rapat</span>
            <h2>Fitur Tanda Tangan</h2>
            <p>Periksa notulen rapat yang ditugaskan kepada Anda. Persetujuan menggunakan akun pegawai dan tercatat bersama waktu persetujuan.</p>
        </div>
        <span class="signature-inbox-count">{{ $pendingSignatureCount }} notifikasi menunggu</span>
    </header>

    @forelse($signatures as $signature)
        <article class="signature-request-card">
            <div class="signature-request-main">
                <span class="signature-request-icon" aria-hidden="true">✎</span>
                <div>
                    <span class="signature-request-role">{{ $signature->peran === 'pimpinan' ? 'PIMPINAN RAPAT' : 'NOTULIS' }}</span>
                    <h3>{{ $signature->judul }}</h3>
                    <p>{{ \App\Support\MeetingData::date($signature->tanggal) }} · {{ substr($signature->mulai, 0, 5) }}–{{ substr($signature->selesai, 0, 5) }} WITA · {{ $signature->tempat ?: 'Tempat belum ditentukan' }}</p>
                    <a href="{{ route('meetings.show', $signature->rapat_id) }}">Lihat detail rapat</a>
                </div>
            </div>
            <div class="signature-request-action">
                @if(!$signature->can_sign)
                    @if(\App\Support\MeetingData::scheduleStatus($signature) !== 'selesai')
                        <span class="signature-pending-status">Menunggu rapat selesai</span>
                    @else
                        <span class="signature-pending-status">Menunggu notulen diisi</span>
                    @endif
                @elseif($signature->status === 'disetujui')
                    <span class="signature-approved-status">✓ Disetujui{{ $signature->disetujui_pada ? ' · '.\Illuminate\Support\Carbon::parse($signature->disetujui_pada, config('app.timezone'))->locale('id')->translatedFormat('j M Y H:i').' WITA' : '' }}</span>
                @else
                    <span class="signature-pending-status">Menunggu persetujuan</span>
                    <form method="post" action="{{ route('signatures.accept', [$signature->rapat_id, $signature->peran]) }}" onsubmit="return confirm('Saya telah memeriksa dan menyetujui notulen rapat ini. Lanjutkan?')">
                        @csrf
                        <button class="btn pri sm">Terima &amp; setujui</button>
                    </form>
                @endif
            </div>
        </article>
    @empty
        <div class="signature-inbox-empty">
            <span aria-hidden="true">✓</span>
            <h3>Belum ada tugas tanda tangan</h3>
            <p>Penugasan pimpinan rapat atau notulis akan muncul di sini.</p>
        </div>
    @endforelse
</section>
@endsection
