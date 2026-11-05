@extends('layouts.app', ['title' => $meeting->judul])

@section('content')
@php($scheduleStatus = \App\Support\MeetingData::scheduleStatus($meeting))
<div class="meeting-detail-page">
    <header class="meeting-detail-hero">
        <div class="meeting-detail-hero-main">
            <span class="meeting-detail-eyebrow">Informasi rapat</span>
            <div class="meeting-detail-title-row">
                <h2>{{ $meeting->judul }}</h2>
                <span class="meeting-detail-status meeting-detail-status-{{ $scheduleStatus }}"><span></span>{{ $scheduleStatus === 'akan' ? 'Akan datang' : ucfirst($scheduleStatus) }}</span>
            </div>
            <div class="meeting-detail-meta">
                <span><b aria-hidden="true">▣</b>{{ \App\Support\MeetingData::date($meeting->tanggal) }}</span>
                <span><b aria-hidden="true">◷</b>{{ substr($meeting->mulai, 0, 5) }}–{{ substr($meeting->selesai, 0, 5) }} WITA</span>
                <span><b aria-hidden="true">⌖</b>{{ $meeting->tempat ?: 'Tempat belum ditentukan' }}</span>
                <span><b aria-hidden="true">◇</b>{{ $meeting->moda }}</span>
            </div>
        </div>
        @if($meeting->link_meeting)
            <div class="meeting-detail-hero-actions">
                <a class="btn pri sm" href="{{ $meeting->link_meeting }}" target="_blank" rel="noopener">Buka tautan meeting <span aria-hidden="true">↗</span></a>
            </div>
        @endif
    </header>

    <section class="meeting-detail-info-grid">
        <article class="box meeting-detail-info-card"><span class="meeting-detail-info-icon" aria-hidden="true">♙</span><div><p class="mu">Pimpinan rapat</p><b>{{ $meeting->pimpinan ?: 'Belum ditentukan' }}</b></div></article>
        <article class="box meeting-detail-info-card"><span class="meeting-detail-info-icon" aria-hidden="true">✎</span><div><p class="mu">Notulis</p><b>{{ $meeting->notulis ?: 'Belum ditentukan' }}</b></div></article>
        @if($meeting->pemateri)<article class="box meeting-detail-info-card"><span class="meeting-detail-info-icon" aria-hidden="true">✦</span><div><p class="mu">Pemateri</p><b>{{ $meeting->pemateri }}</b></div></article>@endif
    </section>

    <section class="box meeting-detail-section">
        <div class="meeting-detail-section-heading"><span aria-hidden="true">☷</span><div><h3>Agenda rapat</h3><p>Pokok pembahasan dan rencana kegiatan.</p></div></div>
        <div class="meeting-detail-agenda">{{ $meeting->agenda ?: 'Agenda belum ditambahkan.' }}</div>
    </section>

    <section class="box meeting-detail-section">
        <div class="meeting-detail-section-heading">
            <span aria-hidden="true">♧</span>
            <div>
                <h3>Peserta rapat</h3>
                <p>Pegawai yang wajib mengikuti rapat ini.</p>
            </div>
            <b class="meeting-detail-section-count">{{ $meetingParticipants->isEmpty() ? 'Semua pegawai' : $meetingParticipants->count().' pegawai' }}</b>
        </div>
        @if($meetingParticipants->isEmpty())
            <p class="meeting-detail-all-employees">Rapat ini terbuka untuk semua pegawai dan tidak memiliki daftar peserta khusus.</p>
        @else
            <details class="meeting-participants-disclosure">
                <summary>
                    <span>Lihat daftar peserta</span>
                    <span class="meeting-participants-summary-count">{{ $meetingParticipants->count() }} orang</span>
                    <span class="meeting-participants-chevron" aria-hidden="true">⌄</span>
                </summary>
                <ol class="meeting-detail-participants">
                    @foreach($meetingParticipants as $person)
                        <li><span class="meeting-participant-number">{{ sprintf('%02d', $loop->iteration) }}</span><span>{{ $person->nama }}</span></li>
                    @endforeach
                </ol>
            </details>
        @endif
    </section>
</div>
@endsection
