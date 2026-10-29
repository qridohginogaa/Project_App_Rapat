@extends('layouts.app', ['title' => $meeting->judul])

@section('content')
@php($scheduleStatus = \App\Support\MeetingData::scheduleStatus($meeting))
<div class="meeting-detail-page">
    <a class="meeting-detail-back" href="{{ route('dashboard') }}"><span class="meeting-detail-back-icon" aria-hidden="true">←</span><span>Kembali ke Dashboard</span></a>
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
        @if($showMeetingAdminTools || $meeting->link_meeting)
            <div class="meeting-detail-hero-actions">
                @if($meeting->link_meeting)<a class="btn pri sm" href="{{ $meeting->link_meeting }}" target="_blank" rel="noopener">Buka tautan meeting <span aria-hidden="true">↗</span></a>@endif
                @if($showMeetingAdminTools)<a class="btn sm meeting-detail-manage" href="{{ route('admin', ['tab' => 'rapat', 'presensi' => $meeting->id]) }}">Kelola presensi</a>@endif
                @if($showMeetingAdminTools && \App\Support\MeetingData::canManuallyEnd($meeting))
                    <form method="post" action="{{ route('meetings.action', $meeting->id) }}" onsubmit="return confirm('Selesaikan rapat ini sekarang dan buka presensi pulang selama 30 menit?')">
                        @csrf<input type="hidden" name="aksi" value="selesaikan_rapat">
                        <button class="btn sm red">Selesaikan rapat</button>
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
        <div class="meeting-detail-participants">
            @foreach($meetingParticipants as $person)<span>{{ $person->nama }}</span>@endforeach
        </div>
    @endif
    </section>

@if($employeeAttendanceOnly)
    <h2>Presensi rapat</h2>
    <div class="box">
        <p>Pegawai: <b>{{ $employee->nama }}</b></p>
        @if($attendance)
            <span class="tag {{ $attendance->status }}">{{ $attendance->status === 'hadir' ? 'Hadir' : 'Izin '.$attendance->status_izin }}</span>
            <p>{{ $attendance->status === 'hadir' ? 'Presensi Anda sudah tercatat pada '.substr($attendance->waktu, 11, 5).' WITA.' : 'Status izin Anda sudah tercatat.' }}</p>
        @else
            @if(\App\Support\MeetingData::attendanceOpen($meeting))
                <p class="mu">Kode presensi aktif sampai {{ substr($meeting->presensi_kedaluwarsa, 11, 5) }} WITA.</p>
            @else
                <p class="mu">Menunggu kode presensi dibuat atau diberikan oleh Pengelola Rapat. Anda dapat mengisi kode di bawah setelah menerimanya.</p>
            @endif
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}">@csrf<input type="hidden" name="aksi" value="hadir">
                <label>Kode presensi</label><input name="kode" required maxlength="15" autocomplete="off">
                <button class="btn pri mt-3">Kirim &amp; catat hadir</button>
            </form>
        @endif
    </div>
    @if($attendance && $attendance->status === 'hadir' && \App\Support\MeetingData::checkoutOpen($meeting))
        <h2>Absen pulang</h2>
        <div class="box">
            @if($attendance->waktu_pulang)
                <span class="tag hadir">Sudah Absen Pulang</span>
                <p>Absen pulang tercatat pada {{ substr($attendance->waktu_pulang, 11, 5) }} WITA.</p>
            @else
                <span class="tag hadir">Absen pulang dibuka</span>
                <p class="mu">Kode absen pulang</p>
                <form method="post" action="{{ route('meetings.action', $meeting->id) }}">@csrf<input type="hidden" name="aksi" value="absen_pulang">
                    <label>Kode absen pulang</label><input name="kode" required maxlength="20" autocomplete="off">
                    <button class="btn pri mt-3">Kirim absen pulang</button>
                </form>
            @endif
        </div>
    @endif
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
@else
    @if($showMeetingAdminTools)
        <h2>Pengelolaan kode presensi <span class="tag hadir">Khusus Super Admin</span></h2>
        @if(!$isSuperAdmin)
            <div class="box">
                <p class="mu">Pembuatan dan pengelolaan kode presensi hanya dapat dilakukan oleh Super Admin.</p>
            </div>
        @else
        <div class="box">
            <h3>Absen masuk</h3>
            @if(\App\Support\MeetingData::attendanceOpen($meeting))
                <p>Kode aktif: <b>{{ $meeting->kode_presensi }}</b> · berlaku sampai {{ substr($meeting->presensi_kedaluwarsa, 11, 5) }} WITA</p>
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
                <p>Kode absen pulang aktif: <b>{{ $meeting->kode_presensi_pulang }}</b> · berlaku sampai {{ substr($meeting->presensi_pulang_kedaluwarsa, 11, 5) }} WITA.</p>
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

    @if($employee && $attendance && $attendance->status === 'hadir' && ($scheduleStatus === 'selesai' || \App\Support\MeetingData::checkoutOpen($meeting)))
        <h2>Absen pulang</h2>
        <div class="box">
            @if($attendance->waktu_pulang)
                <span class="tag hadir">Sudah Absen Pulang</span>
                <p>Absen pulang tercatat pada {{ substr($attendance->waktu_pulang, 11, 5) }} WITA.</p>
            @else
                @if($scheduleStatus === 'selesai')
                    <span class="tag selesai">Belum Absen Pulang</span>
                    <p class="mu">Alasan pulang duluan: silakan isi keterangan di bawah.</p>
                    <form method="post" action="{{ route('meetings.action', $meeting->id) }}" class="mt-3">
                        @csrf<input type="hidden" name="aksi" value="simpan_alasan_pulang">
                        <label>Keterangan / alasan tidak mengisi absen pulang</label>
                        <textarea name="alasan_pulang" required maxlength="2000" rows="3">{{ old('alasan_pulang', $attendance->alasan_pulang) }}</textarea>
                        <button class="btn sm mt-2">Simpan keterangan</button>
                    </form>
                @else
                    <span class="tag hadir">Absen pulang dibuka</span>
                    <p class="mu">Kode absen pulang</p>
                @endif
                @if(\App\Support\MeetingData::checkoutOpen($meeting))
                    <form method="post" action="{{ route('meetings.action', $meeting->id) }}">@csrf<input type="hidden" name="aksi" value="absen_pulang">
                        <label>Kode absen pulang</label><input name="kode" required maxlength="20" autocomplete="off">
                        <button class="btn pri mt-3">Kirim absen pulang</button>
                    </form>
                @else
                    <p class="mu">Kode absen pulang belum dibuka atau waktunya sudah berakhir. Hubungi Pengelola Rapat.</p>
                @endif
            @endif
        </div>
    @endif

    @if($employee && !$attendance)
        <h2>Ajukan izin</h2>
        <form method="post" action="{{ route('meetings.action', $meeting->id) }}" enctype="multipart/form-data" class="box">@csrf<input type="hidden" name="aksi" value="izin">
            <label>Jenis izin</label><select name="jenis"><option>Sakit</option><option>Dinas</option><option>Keperluan lain</option></select>
            <label>Alasan</label><textarea name="alasan" required></textarea>
            <label>Surat izin (JPG/PNG/PDF, maks. 10 MB)</label><input type="file" name="surat" accept=".jpg,.jpeg,.png,.pdf" @required(\App\Support\MeetingData::setting('wajib_izin', '1') === '1')>
            <button class="btn mt-3">Kirim pengajuan izin</button>
        </form>
    @elseif($employee && $attendance)
        <div class="box mt-3"><span class="tag {{ $attendance->status }}">{{ $attendance->status === 'hadir' ? 'Hadir' : 'Izin '.$attendance->status_izin }}</span>
            @if($attendance->status === 'hadir')<p>Tercatat hadir pada {{ substr($attendance->waktu, 11, 5) }} WITA.</p>@else<p>{{ $attendance->jenis }}: {{ $attendance->alasan }}</p>@endif
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}" onsubmit="return confirm('Batalkan catatan kehadiran?')">@csrf<input type="hidden" name="aksi" value="batal"><button class="btn sm">Batalkan catatan</button></form>
        </div>
    @endif

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
    @if($meeting->notulen)<div class="box meeting-detail-content-box meeting-detail-agenda">{{ $meeting->notulen }}</div>@endif
    @if($meeting->file_notulen)<p><a class="btn sm meeting-detail-download" href="{{ route('files.show', ['filename' => $meeting->file_notulen, 'download' => 1]) }}">Unduh PDF notulen</a></p>@endif
    @if($canEditMinutes)
        <details class="box meeting-detail-content-box meeting-detail-minutes-editor" @if(!$meeting->notulen && !$meeting->file_notulen) open @endif>
            <summary><b>Isi / tambah / ubah notulen</b></summary>
            <form method="post" action="{{ route('meetings.action', $meeting->id) }}" enctype="multipart/form-data" class="mt-3">@csrf<input type="hidden" name="aksi" value="simpan_notulen">
                <textarea id="minutes" name="notulen" rows="10">{{ $meeting->notulen }}</textarea>
                <button class="btn sm mt-2" type="button" onclick="if(confirm('Isi kolom dengan draf notulen?'))document.getElementById('minutes').value=@js($minutesDraft)">Masukkan draf otomatis</button>
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
