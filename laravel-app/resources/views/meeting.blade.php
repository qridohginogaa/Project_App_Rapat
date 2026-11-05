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
                @if($scheduleStatus === 'berlangsung' && \App\Support\MeetingData::overtimeMinutes($meeting) > 0)
                    <span class="tag overtime">Lewat jadwal {{ \App\Support\MeetingData::overtimeMinutes($meeting) }} menit</span>
                @elseif($scheduleStatus === 'selesai' && (int) ($meeting->overtime_minutes ?? 0) > 0)
                    <span class="tag overtime">Overtime {{ $meeting->overtime_minutes }} menit</span>
                @endif
            </div>
            <div class="meeting-detail-meta">
                <span><b aria-hidden="true">▣</b>{{ \App\Support\MeetingData::date($meeting->tanggal) }}</span>
                <span><b aria-hidden="true">◷</b>{{ substr($meeting->mulai, 0, 5) }}–{{ substr($meeting->selesai, 0, 5) }} WITA</span>
                <span><b aria-hidden="true">⌖</b>{{ $meeting->tempat ?: 'Tempat belum ditentukan' }}</span>
                <span><b aria-hidden="true">◇</b>{{ $meeting->moda }}</span>
            </div>
        </div>
        @if($showMeetingAdminTools || $canExportMeetingReport || $meeting->link_meeting)
            <div class="meeting-detail-hero-actions">
                @if($meeting->link_meeting)<a class="btn pri sm" href="{{ $meeting->link_meeting }}" target="_blank" rel="noopener">Buka tautan meeting <span aria-hidden="true">↗</span></a>@endif
                @if($canExportMeetingReport)
                    <div class="meeting-detail-report-actions">
                        <a class="btn sm" href="{{ route('meetings.report.canonical', \App\Support\MeetingData::slug($meeting)) }}">Unduh PDF Notulen + Daftar Hadir</a>
                        @if($isSuperAdmin)<a class="btn sm meeting-detail-manage" href="{{ route('admin', ['tab' => 'rapat', 'presensi' => $meeting->id]) }}">Atur presensi</a>@endif
                    </div>
                @endif
                @if($showMeetingAdminTools)<a class="btn sm meeting-detail-manage" href="{{ route('admin', ['tab' => 'rapat', 'presensi' => $meeting->id]) }}">Kelola presensi</a>@endif
                @if($showMeetingAdminTools && \App\Support\MeetingData::canManuallyEnd($meeting))
                    <form method="post" action="{{ route('meetings.action', $meeting->id) }}" onsubmit="return confirm('Selesaikan rapat ini sekarang dan buka presensi pulang selama 30 menit?')">
                        @csrf<input type="hidden" name="aksi" value="selesaikan_rapat">
                        <button class="btn sm red">Akhiri Rapat</button>
                    </form>
                @endif
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
        <div class="meeting-detail-section-heading"><span aria-hidden="true">♧</span><div><h3>Peserta rapat</h3><p>Pegawai yang wajib mengikuti rapat ini.</p></div><b class="meeting-detail-section-count">{{ $meetingParticipants->isEmpty() ? 'Semua pegawai' : $meetingParticipants->count().' pegawai' }}</b></div>
    @if($meetingParticipants->isEmpty())
        <p class="meeting-detail-all-employees">Rapat ini terbuka untuk semua pegawai.</p>
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

@if($employee)
    @php($attendanceAction = $attendance && $attendance->status === 'hadir' ? 'absen_pulang' : 'hadir')
    @php($attendanceDisabled = $scheduleStatus === 'akan' || ($attendanceAction === 'hadir' && $scheduleStatus !== 'berlangsung') || ($attendance && $attendance->status === 'hadir' && $attendance->waktu_pulang))
    <section class="meeting-attendance-panel">
        <div class="meeting-attendance-panel-heading">
            <span class="meeting-attendance-panel-icon" aria-hidden="true">✓</span>
            <div>
                <h2>Presensi rapat</h2>
                <p>Pegawai: <b>{{ $employee->nama }}</b></p>
            </div>
        </div>
        <div class="meeting-attendance-main">
        @if($attendance && $attendance->status === 'hadir')
            <div class="meeting-attendance-state">
                <span class="tag hadir">Hadir</span>
                <p>Absen hadir tercatat pada {{ substr($attendance->waktu, 11, 5) }} WITA.</p>
            </div>
            @if($attendance->waktu_pulang)
                <div class="meeting-attendance-state">
                    <span class="tag hadir">Sudah absen pulang</span>
                    <p>Absen pulang tercatat pada {{ substr($attendance->waktu_pulang, 11, 5) }} WITA.</p>
                </div>
            @endif
        @elseif($attendance)
            <div class="meeting-attendance-state">
                <span class="tag {{ $attendance->status }}">{{ $attendance->status === 'izin' ? 'Izin '.$attendance->status_izin : ucfirst($attendance->status) }}</span>
                <p>Status presensi Anda sudah tercatat.</p>
            </div>
        @endif

        @if(!$attendance || ($attendance->status === 'hadir' && !$attendance->waktu_pulang))
            @if($attendanceAction === 'absen_pulang')
                <p class="meeting-attendance-hint">
                    Masukkan kode absen pulang dari Super Admin pada kolom yang sama.
                    @if(\App\Support\MeetingData::checkoutOpen($meeting))
                        Kode aktif sampai {{ substr($meeting->presensi_pulang_kedaluwarsa, 11, 5) }} WITA.
                    @else
                        Kode absen pulang belum dibuka.
                    @endif
                </p>
            @elseif($scheduleStatus === 'akan')
                <p class="meeting-attendance-hint">Kolom presensi selalu tersedia. Masukkan kode hadir dari Super Admin saat rapat berlangsung.</p>
            @elseif($scheduleStatus === 'berlangsung')
                <p class="meeting-attendance-hint">
                    Masukkan kode hadir dari Super Admin.
                    @if(\App\Support\MeetingData::attendanceOpen($meeting))
                        Kode aktif sampai {{ substr($meeting->presensi_kedaluwarsa, 11, 5) }} WITA.
                    @else
                        Kode hadir belum dibuka.
                    @endif
                </p>
            @else
                <p class="meeting-attendance-hint">Rapat sudah selesai. Absen hadir tidak dapat dicatat.</p>
            @endif
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}">
                @csrf
                <input type="hidden" name="aksi" value="{{ $attendanceAction }}">
                <label for="meeting-attendance-code">Kode presensi hadir / pulang</label>
                <input id="meeting-attendance-code" name="kode" autocomplete="off" maxlength="20" required>
                <button class="btn pri meeting-attendance-submit" @disabled($attendanceDisabled)>
                    {{ $attendanceAction === 'absen_pulang' ? 'Kirim & catat absen pulang' : 'Kirim & catat absen hadir' }}
                </button>
            </form>
        @endif
        </div>
        @if($attendance && $attendance->status === 'hadir' && !$attendance->waktu_pulang)
            <aside class="meeting-attendance-reason">
                <p><b>Perlu pulang lebih awal?</b><br>Catat alasan jika belum sempat melakukan absen pulang.</p>
                @if($attendance->alasan_pulang)
                    <p class="meeting-attendance-saved-reason">Alasan tersimpan: {{ $attendance->alasan_pulang }}</p>
                @endif
                <details @if($errors->has('alasan_pulang')) open @endif>
                    <summary class="btn sm meeting-attendance-reason-toggle">{{ $attendance->alasan_pulang ? 'Ubah alasan' : 'Isi alasan pulang lebih awal' }}</summary>
                    <form method="post" action="{{ route('meetings.action', $meeting->id) }}">
                        @csrf
                        <input type="hidden" name="aksi" value="simpan_alasan_pulang">
                        <label for="meeting-attendance-reason-input">Alasan pulang lebih awal / belum absen pulang</label>
                        <textarea id="meeting-attendance-reason-input" name="alasan_pulang" required maxlength="2000" rows="3">{{ old('alasan_pulang', $attendance->alasan_pulang) }}</textarea>
                        <button class="btn pri sm mt-2">Simpan alasan</button>
                    </form>
                </details>
            </aside>
        @endif
    </section>
@endif

@if($employeeAttendanceOnly)
    @if($canViewAttendance)
        <h2>Daftar absen masuk ({{ $attendees->count() }})</h2>
        <div class="tw"><table><thead><tr><th>#</th><th>Nama</th><th>NIP</th><th>Waktu masuk</th></tr></thead><tbody>
            @forelse($attendees as $index => $person)<tr><td>{{ $index + 1 }}</td><td>{{ $person->nama }}</td><td>{{ $person->nip }}</td><td>{{ substr($person->waktu, 11, 5) }}</td></tr>
            @empty<tr><td colspan="4" class="mu">Belum ada yang hadir.</td></tr>@endforelse
        </tbody></table></div>
        <h2>Daftar absen pulang</h2>
        <div class="tw"><table><thead><tr><th>Nama</th><th>NIP</th><th>Waktu pulang</th><th>Status / keterangan</th></tr></thead><tbody>
            @forelse($attendees as $person)
                <tr>
                    <td>{{ $person->nama }}</td><td>{{ $person->nip }}</td>
                    <td>{{ $person->waktu_pulang ? substr($person->waktu_pulang, 11, 5).' WITA' : '—' }}</td>
                    <td>
                        @if($person->waktu_pulang)
                            <span class="tag hadir">Sudah Absen Pulang</span>
                        @elseif($person->alasan_pulang)
                            <span class="tag selesai">Pulang lebih awal</span><br>
                            <small class="mu">Alasan: {{ $person->alasan_pulang }}</small>
                        @elseif($scheduleStatus === 'selesai')
                            <span class="tag selesai">Belum Absen Pulang</span><br>
                            <small class="mu">Alasan: Belum diisi pegawai.</small>
                        @else
                            <span class="tag akan">Menunggu rapat selesai</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="mu">Belum ada pegawai yang absen masuk.</td></tr>
            @endforelse
        </tbody></table></div>
    @endif
@else
    @if($showMeetingAdminTools)
        <h2>Pengelolaan kode presensi <span class="tag hadir">Khusus Super Admin</span></h2>
        @if(!$isSuperAdmin)
            <div class="box">
                <p class="mu">Pembuatan dan pengelolaan kode presensi hanya dapat dilakukan oleh Super Admin.</p>
            </div>
        @else
        <div class="box">
            <p class="mu">Kode hadir dan kode pulang dibuat terpisah oleh Super Admin. Pegawai memasukkan kode yang sesuai pada satu kolom presensi.</p>
            @if($scheduleStatus === 'dibatalkan')
                <p class="tag dibatalkan">Rapat dibatalkan. Kode presensi tidak dapat dibuka atau diubah.</p>
            @else
            <h3>Absen masuk</h3>
            @if(\App\Support\MeetingData::attendanceOpen($meeting))
                <p>Kode aktif · berlaku sampai {{ substr($meeting->presensi_kedaluwarsa, 11, 5) }} WITA</p>
                <div class="attendance-code-copy" data-attendance-code-copy>
                    <input type="text" value="{{ $meeting->kode_presensi }}" readonly aria-label="Kode presensi aktif">
                    <button class="btn sm" type="button" data-copy-attendance-code>Salin kode</button>
                    <span role="status" aria-live="polite" data-copy-attendance-status></span>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach([15,30] as $minutes)<form method="post" action="{{ route('meetings.action', $meeting->id) }}">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="opsi_presensi" value="tambah_waktu"><input type="hidden" name="menit" value="{{ $minutes }}"><button class="btn sm ok">+ {{ $minutes }} menit</button></form>@endforeach
                    <form method="post" action="{{ route('meetings.action', $meeting->id) }}">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="opsi_presensi" value="tutup"><button class="btn sm red">Tutup presensi</button></form>
                </div>
            @elseif($meeting->kode_presensi)
                <p class="mu">Kode sebelumnya: {{ $meeting->kode_presensi }} (berakhir {{ substr($meeting->presensi_kedaluwarsa, 11, 5) }}).</p>
            @else
                <p class="mu">Presensi belum dibuka.</p>
            @endif
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}" class="mt-3">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="opsi_presensi" value="buat">
                <label>Kode presensi (kosongkan untuk kode otomatis)</label><input name="kode" maxlength="20">
                <label>Batas waktu pengisian</label><select name="durasi"><option value="15">15 menit</option><option value="30" selected>30 menit</option><option value="60">1 jam</option><option value="selesai">Sampai rapat selesai</option></select>
                <button class="btn pri mt-3">Buka / perbarui kode</button>
            </form>
            <hr class="my-4">
            <h3>Absen pulang</h3>
            @if(\App\Support\MeetingData::checkoutOpen($meeting))
                <p>Kode absen pulang aktif · berlaku sampai {{ substr($meeting->presensi_pulang_kedaluwarsa, 11, 5) }} WITA.</p>
                <div class="attendance-code-copy" data-attendance-code-copy>
                    <input type="text" value="{{ $meeting->kode_presensi_pulang }}" readonly aria-label="Kode absen pulang aktif">
                    <button class="btn sm" type="button" data-copy-attendance-code>Salin kode</button>
                    <span role="status" aria-live="polite" data-copy-attendance-status></span>
                </div>
                <form method="post" action="{{ route('meetings.action', $meeting->id) }}">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="opsi_presensi" value="tutup_pulang"><button class="btn sm red">Tutup kode absen pulang</button></form>
            @elseif($meeting->kode_presensi_pulang)
                <p class="mu">Kode absen pulang sebelumnya sudah berakhir.</p>
            @else
                <p class="mu">Kode absen pulang belum dibuat.</p>
            @endif
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}" class="mt-3">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="opsi_presensi" value="buat_pulang">
                <label>Kode absen pulang (kosongkan untuk kode otomatis)</label><input name="kode" maxlength="20">
                <label>Batas waktu setelah rapat selesai</label><select name="durasi"><option value="15">15 menit</option><option value="30" selected>30 menit</option><option value="60">1 jam</option></select>
                <button class="btn pri mt-3">Buat kode absen pulang</button>
            </form>
            @endif
        </div>
        @endif
    @endif

    @if($canViewAttendance)
        <h2>Daftar absen masuk ({{ $attendees->count() }})</h2>
        <div class="tw"><table><thead><tr><th>#</th><th>Nama</th><th>NIP</th><th>Waktu</th></tr></thead><tbody>
            @forelse($attendees as $index => $person)<tr><td>{{ $index + 1 }}</td><td>{{ $person->nama }}</td><td>{{ $person->nip }}</td><td>{{ substr($person->waktu, 11, 5) }}</td></tr>
            @empty<tr><td colspan="4" class="mu">Belum ada yang hadir.</td></tr>@endforelse
        </tbody></table></div>
        <h2>Daftar absen pulang</h2>
        <div class="tw"><table><thead><tr><th>Nama</th><th>NIP</th><th>Waktu pulang</th><th>Status / keterangan</th></tr></thead><tbody>
            @forelse($attendees as $person)
                <tr>
                    <td>{{ $person->nama }}</td><td>{{ $person->nip }}</td>
                    <td>{{ $person->waktu_pulang ? substr($person->waktu_pulang, 11, 5).' WITA' : '—' }}</td>
                    <td>
                        @if($person->waktu_pulang)
                            <span class="tag hadir">Sudah Absen Pulang</span>
                        @elseif($scheduleStatus === 'selesai')
                            <span class="tag selesai">Belum Absen Pulang</span><br>
                            <small class="mu">Keterangan / alasan: {{ $person->alasan_pulang ?: 'Belum diisi pegawai (alasan pulang duluan)' }}</small>
                        @else
                            <span class="tag akan">Menunggu rapat selesai</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="mu">Belum ada pegawai yang absen masuk.</td></tr>
            @endforelse
        </tbody></table></div>
    @endif

    @if($employee && !$attendance)
        <h2>Ajukan izin</h2>
        <form method="post" action="{{ route('meetings.action', $meeting->id) }}" enctype="multipart/form-data" class="box">@csrf<input type="hidden" name="aksi" value="izin">
            <label>Jenis izin</label><select name="jenis"><option>Sakit</option><option>Dinas</option><option>Keperluan lain</option></select>
            <label>Alasan</label><textarea name="alasan" required></textarea>
            <label>Surat izin (JPG/PNG/PDF, maks. 10 MB)</label><input type="file" name="surat" accept=".jpg,.jpeg,.png,.pdf" @required(\App\Support\MeetingData::setting('wajib_izin', '1') === '1')>
            <button class="btn mt-3">Kirim pengajuan izin</button>
        </form>
    @endif

@endif

@if($canViewAttendance)
    <section class="meeting-detail-section">
        <div class="meeting-detail-section-heading"><span aria-hidden="true">♧</span><div><h3>Presensi tamu &amp; mahasiswa</h3><p>Peserta nonpegawai yang hadir dalam rapat.</p></div><b class="meeting-detail-section-count">{{ $guestAttendees->count() }} peserta</b></div>
        <div class="meeting-detail-content-box tw"><table><thead><tr><th>Nama</th><th>Jenis peserta</th><th>Asal</th><th>NIM</th><th>Absen masuk</th><th>Absen pulang</th></tr></thead><tbody>
            @forelse($guestAttendees as $guest)
                <tr><td>{{ $guest->nama }}</td><td>{{ ucfirst($guest->jenis) }}</td><td>{{ $guest->asal }}</td><td>{{ $guest->nim ?: '—' }}</td><td>{{ substr($guest->waktu, 11, 5) }} WITA</td><td>{{ $guest->waktu_pulang ? substr($guest->waktu_pulang, 11, 5).' WITA' : 'Belum absen pulang' }}</td></tr>
            @empty
                <tr><td colspan="6" class="mu">Belum ada presensi tamu atau mahasiswa.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
@endif

<section class="meeting-detail-section">
    <div class="meeting-detail-section-heading"><span aria-hidden="true">▤</span><div><h3>Materi presentasi</h3><p>Dokumen dan bahan pendukung rapat.</p></div></div>
    <div class="box meeting-detail-content-box">
        @if($meeting->materi_rapat)<p><a href="{{ route('files.show', ['filename' => $meeting->materi_rapat, 'download' => 1]) }}">⬇ Materi rapat</a></p>@endif
        @if($materials->isNotEmpty())
            <div class="tw"><table><thead><tr><th>Materi</th><th>Ditambahkan oleh</th><th>Waktu</th></tr></thead><tbody>
                @foreach($materials as $material)<tr><td><a href="{{ route('files.show', ['filename' => $material->nama_file, 'download' => 1]) }}">⬇ {{ $material->nama_asli ?: $material->nama_file }}</a></td><td>{{ $material->pengunggah ?: 'Pegawai' }}</td><td>{{ $material->dibuat_pada }}</td></tr>@endforeach
            </tbody></table></div>
        @else<p class="mu">Belum ada materi tambahan.</p>@endif
        @if($canManageMaterials)
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}" enctype="multipart/form-data" class="mt-3">@csrf<input type="hidden" name="aksi" value="tambah_materi">
                <label>Tambah materi (PDF, PPT, dokumen, spreadsheet, gambar, atau ZIP; maks. 20 MB)</label><input type="file" name="materi" accept=".pdf,.ppt,.pptx,.odp,.doc,.docx,.xls,.xlsx,.ods,.zip,.jpg,.jpeg,.png" required>
                <button class="btn pri sm mt-2">Tambah materi</button>
            </form>
        @endif
    </div>
</section>

<section class="meeting-detail-section">
    <div class="meeting-detail-section-heading"><span aria-hidden="true">▶</span><div><h3>Hasil rekaman</h3><p>Tautan dan file rekaman rapat.</p></div></div>
    <div class="box meeting-detail-content-box">
        @if($meeting->link_rekaman)<p><a href="{{ $meeting->link_rekaman }}" target="_blank" rel="noopener">▶ Rekaman rapat</a></p>@endif
        @if($recordings->isNotEmpty())
            <div class="tw"><table><thead><tr><th>Rekaman</th><th>Ditambahkan oleh</th><th>Waktu</th></tr></thead><tbody>
                @foreach($recordings as $recording)<tr><td>@if($recording->tautan)<a href="{{ $recording->tautan }}" target="_blank" rel="noopener">▶ Buka tautan</a>@endif @if($recording->nama_file)<a href="{{ route('files.show', ['filename' => $recording->nama_file, 'download' => 1]) }}">⬇ {{ $recording->nama_asli ?: $recording->nama_file }}</a>@endif</td><td>{{ $recording->pengunggah }}</td><td>{{ $recording->dibuat_pada }}</td></tr>@endforeach
            </tbody></table></div>
        @else<p class="mu">Belum ada rekaman tambahan.</p>@endif
        @if($canManageRecordings)
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}" enctype="multipart/form-data" class="mt-3">@csrf<input type="hidden" name="aksi" value="tambah_rekaman">
                <label>Tautan Google Drive / YouTube</label><input type="url" name="tautan_rekaman">
                <label>Atau video (MP4/WEBM/MOV/M4V/MKV, maks. 100 MB)</label><input type="file" name="rekaman" accept="video/mp4,video/webm,video/quicktime,.m4v,.mkv">
                <button class="btn pri sm mt-2">Tambah rekaman</button>
            </form>
        @endif
    </div>
</section>

<section class="meeting-detail-section">
    <div class="meeting-detail-section-heading"><span aria-hidden="true">▧</span><div><h3>Notulen rapat</h3><p>Catatan hasil pembahasan rapat.</p></div></div>
    @if($isAssignedMinuteTaker)
        <p class="mu">Anda ditugaskan sebagai notulis rapat ini. Anda dapat membaca dan mengedit notulen sampai {{ $minutesDeadline->format('d-m-Y H:i') }} WITA.</p>
    @endif
    @if($meeting->notulen)<div class="box meeting-detail-content-box meeting-detail-agenda">{{ $meeting->notulen }}</div>@endif
    @if($meeting->file_notulen)<p><a class="btn sm meeting-detail-download" href="{{ route('files.show', ['filename' => $meeting->file_notulen, 'download' => 1]) }}">Unduh PDF notulen</a></p>@endif
    @if(($isAdmin || $isAssignedMinuteTaker) && !$isSuperAdmin && $canEditMinutes)
        <p class="mu">Batas pengisian notulen: {{ $minutesDeadline->format('d-m-Y H:i') }} WITA.</p>
    @elseif(($isAdmin || $isAssignedMinuteTaker) && !$isSuperAdmin && !$canEditMinutes)
        <p class="mu">Batas pengisian notulen telah berakhir. Hubungi Pengelola Rapat atau Super Admin untuk perubahan.</p>
    @endif
    @if($canEditMinutes)
        <div class="meeting-minutes-simulation-header">
            <span class="meeting-minutes-simulation-icon" aria-hidden="true">✎</span>
            <div>
                <span class="meeting-minutes-simulation-label">SIMULASI PENGISIAN</span>
                <h4>Simulasi Pengisian Notulen Rapat</h4>
                <p>Gunakan draf otomatis sebagai contoh, lengkapi pokok pembahasan dan tindak lanjut, lalu simpan notulen.</p>
            </div>
        </div>
        <details class="box meeting-detail-content-box meeting-detail-minutes-editor" open>
            <summary><b>Isi / tambah / ubah notulen</b></summary>
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}" enctype="multipart/form-data" class="mt-3">@csrf<input type="hidden" name="aksi" value="simpan_notulen">
                <textarea id="minutes" name="notulen" rows="10">{{ $meeting->notulen }}</textarea>
                <button class="btn sm mt-2" type="button" data-minutes-draft="{{ $minutesDraft }}" onclick="if(confirm('Isi kolom dengan draf notulen?'))document.getElementById('minutes').value=this.dataset.minutesDraft">Masukkan draf otomatis</button>
                <label>Berkas PDF (maks. 15 MB)</label><input type="file" name="file_notulen" accept=".pdf">
                @if($meeting->file_notulen)<label><input type="checkbox" name="hapus_file_notulen" value="1" style="width:auto"> Hapus berkas PDF</label>@endif
                <button class="btn pri mt-3">Simpan notulen</button>
            </form>
        </details>
    @endif
    @if(!$meeting->notulen && !$meeting->file_notulen && !$canEditMinutes)
        <div class="meeting-detail-empty">Notulen belum tersedia.</div>
    @endif
</section>
</div>
@endsection
