@extends('layouts.app', ['title' => 'Pengelola Rapat'])

@section('content')
@php
    $tabs = ['rapat' => 'Rapat', 'ruang' => 'Ruang Rapat', 'kehadiran' => 'Kehadiran & Izin', 'pegawai' => 'Pegawai', 'superadmin' => 'Hak Akses', 'pengaturan' => 'Pengaturan'];
@endphp
@if($canReturnToEmployeeDashboard && in_array($tab, ['rapat', 'kehadiran'], true))
    <a class="btn mb-3" href="{{ route('dashboard') }}">← Kembali ke Dashboard</a>
@endif
<nav class="tabs flex flex-wrap gap-2 mb-4">
    @foreach($tabs as $key => $label)
        @php
            $visible = true;
            if ($key === 'ruang') $visible = $canManageRooms;
            elseif ($key === 'kehadiran') $visible = $canViewAttendance;
            elseif (in_array($key, ['pegawai', 'superadmin', 'pengaturan'], true)) $visible = $canManageRoles;
        @endphp
        @if($visible)<a href="{{ route('admin', $key) }}" class="{{ $tab === $key ? 'on' : '' }}">{{ $label }}</a>@endif
    @endforeach
</nav>

@if($tab === 'pegawai')
    <p class="mu">Kata sandi lama tidak dapat dilihat karena disimpan sebagai hash. Atur ulang kata sandi melalui kolom pegawai terkait.</p>
    @if(session('password_employee'))
        <div class="box mt-3" style="background:#fff7ed;border-color:#fed7aa">
            <b>Kata sandi baru untuk {{ session('password_employee.nama') }}</b>
            <p>Salin dan berikan langsung kepada pegawai. Nilai ini hanya ditampilkan sekali.</p>
            <div class="flex flex-wrap items-center gap-2">
                <input id="new-password" type="password" value="{{ session('password_employee.password') }}" readonly>
                <button class="btn sm" type="button" onclick="var i=document.getElementById('new-password');i.type=i.type==='password'?'text':'password'">Lihat / sembunyikan</button>
                <button class="btn sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('new-password').value)">Salin</button>
            </div>
        </div>
    @endif
    <details class="box mt-3">
        <summary><b>Tambah pegawai</b></summary>
        <form method="post" action="{{ route('admin.action') }}" class="mt-3">@csrf
            <input type="hidden" name="aksi" value="tambah_pegawai">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label>Nama lengkap</label><input name="nama" required></div>
                <div><label>NIP (18 digit)</label><input name="nip" required inputmode="numeric" maxlength="18"></div>
                <div><label>Jabatan / unit</label><input name="jabatan"></div>
            </div>
            <p class="mu">Kata sandi awal dibuat dari NIP. Pegawai wajib menggantinya saat login pertama.</p>
            <button class="btn pri mt-3">+ Tambah pegawai</button>
        </form>
    </details>
    <label for="employee-search" class="mt-4">Cari pegawai</label>
    <input id="employee-search" type="search" placeholder="Ketik nama, NIP, atau jabatan" autocomplete="off">
    <p id="employee-count" class="mu mt-2" aria-live="polite">{{ $employees->count() }} pegawai</p>
    <div class="tw"><table>
        <thead><tr><th>Nama</th><th>NIP</th><th>Jabatan</th><th>Role</th><th>Atur ulang kata sandi</th><th></th></tr></thead>
        <tbody id="employee-rows">
        @foreach($employees as $person)
            <tr data-employee-row>
                <td>{{ $person->nama }}</td><td>{{ $person->nip }}</td>
                <td>
                    <form method="post" action="{{ route('admin.action') }}" class="flex flex-wrap items-center gap-2">
                        @csrf<input type="hidden" name="aksi" value="ubah_jabatan"><input type="hidden" name="id" value="{{ $person->id }}">
                        <input name="jabatan" value="{{ $person->jabatan }}" required maxlength="150" aria-label="Jabatan baru untuk {{ $person->nama }}">
                        <button class="btn sm">Simpan jabatan</button>
                    </form>
                </td>
                <td>
                    @if($canManageRoles)
                        <form method="post" action="{{ route('admin.action') }}">
                            @csrf<input type="hidden" name="aksi" value="atur_role"><input type="hidden" name="id" value="{{ $person->id }}">
                            <select name="role" onchange="this.form.submit()" class="btn sm" style="cursor:pointer;padding:5px 10px;border-radius:8px;background:#fff;{{ $person->is_admin ? 'border-color:var(--teal-d);font-weight:600;color:var(--teal-d);' : '' }}" aria-label="Pilih role untuk {{ $person->nama }}">
                                <option value="pegawai" {{ (!$person->is_admin && !$person->is_super_admin) ? 'selected' : '' }}>Pegawai</option>
                                <option value="admin" {{ ($person->is_admin && !$person->is_super_admin) ? 'selected' : '' }}>Pengelola Rapat</option>
                                <option value="super_admin" {{ $person->is_super_admin ? 'selected' : '' }}>Super Admin</option>
                            </select>
                        </form>
                    @else
                        <span class="tag {{ ($person->is_admin || $person->is_super_admin) ? 'hadir' : '' }}">
                            {{ $person->is_super_admin ? 'Super Admin' : ($person->is_admin ? 'Pengelola Rapat' : 'Pegawai') }}
                        </span>
                    @endif
                </td>
                <td>
                    <form method="post" action="{{ route('admin.action') }}" class="flex flex-wrap items-center gap-2">
                        @csrf<input type="hidden" name="aksi" value="atur_password_pegawai"><input type="hidden" name="id" value="{{ $person->id }}">
                        <input name="password_pegawai" type="password" required minlength="8" placeholder="Min. 8 karakter" aria-label="Kata sandi baru untuk {{ $person->nama }}">
                        <button class="btn sm">Simpan baru</button>
                    </form>
                    <small class="mu">
                        @if($person->password_change_required)
                            Wajib ganti saat login.
                        @elseif($person->password_hash)
                            Kata sandi aktif; pengaturan ulang hanya oleh Pengelola Rapat.
                        @else
                            Kata sandi belum diatur.
                        @endif
                    </small>
                </td>
                <td><form method="post" action="{{ route('admin.action') }}" onsubmit="return confirm('Hapus pegawai ini dan data kehadirannya?')">@csrf<input type="hidden" name="aksi" value="hapus_pegawai"><input type="hidden" name="id" value="{{ $person->id }}"><button class="btn sm red">Hapus</button></form></td>
            </tr>
        @endforeach
        <tr id="employee-empty" hidden><td colspan="6" class="mu">Pegawai tidak ditemukan.</td></tr>
        </tbody>
    </table></div>
    <script>
    (function () {
        var input = document.getElementById('employee-search');
        var rows = Array.from(document.querySelectorAll('[data-employee-row]'));
        var empty = document.getElementById('employee-empty');
        var count = document.getElementById('employee-count');
        input.addEventListener('input', function () {
            var query = input.value.trim().toLocaleLowerCase();
            var visible = 0;
            rows.forEach(function (row) {
                var matches = row.textContent.toLocaleLowerCase().includes(query);
                row.hidden = !matches;
                if (matches) visible++;
            });
            empty.hidden = visible > 0;
            count.textContent = visible + ' pegawai';
        });
    })();
    </script>

@elseif($tab === 'superadmin')
    <h2>Hak Akses</h2>
    <h3 class="mt-4">Daftar Super Admin</h3>
    <p class="mu">Super Admin memiliki akses penuh ke seluruh fitur. Atur role akun melalui tabel Pegawai.</p>
    <div class="tw"><table>
        <thead>
            <tr>
                <th>Nama</th>
                <th>NIP</th>
                <th>Hak akses</th>
            </tr>
        </thead>
        <tbody>
        @forelse($superAdmins as $person)
            <tr>
                <td>{{ $person->nama }}</td>
                <td>{{ $person->nip }}</td>
                <td><span class="tag akan" style="background:#7c3aed;color:#fff;">Akses penuh</span></td>
            </tr>
        @empty
            <tr><td colspan="3" class="mu">Belum ada akun Super Admin.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <h3 class="mt-5">Daftar Pengelola Rapat</h3>
    <p class="mu">Daftar ini hanya menampilkan akun dengan role Pengelola Rapat. Super Admin ditampilkan pada tabel terpisah di atas.</p>
    <div class="tw"><table>
        <thead><tr><th>Nama</th><th>NIP</th><th>Jabatan</th><th>Role</th></tr></thead>
        <tbody>
            @forelse($meetingManagers as $person)
                <tr>
                    <td>{{ $person->nama }}</td>
                    <td>{{ $person->nip }}</td>
                    <td>{{ $person->jabatan }}</td>
                    <td><span class="tag hadir">Pengelola Rapat</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="mu">Belum ada akun dengan role Pengelola Rapat.</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <h3 class="mt-5">Hak Akses Pengelola Rapat</h3>
    <p class="mu">Centang fitur yang dapat digunakan oleh semua akun dengan role Pengelola Rapat.</p>
    <form method="post" action="{{ route('admin.action') }}" class="box mt-3">
        @csrf
        <input type="hidden" name="aksi" value="atur_hak_akses_admin">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
            <label class="flex items-center gap-2 text-sm" style="cursor:pointer;">
                <input type="checkbox" name="buat_rapat" value="1" @checked($managerAccessSettings->buat_rapat) style="width:auto;margin:0;">
                <span>Buat Rapat</span>
            </label>
            <label class="flex items-center gap-2 text-sm" style="cursor:pointer;">
                <input type="checkbox" name="notulen" value="1" @checked($managerAccessSettings->notulen) style="width:auto;margin:0;">
                <span>Kelola Notulen</span>
            </label>
            <label class="flex items-center gap-2 text-sm" style="cursor:pointer;">
                <input type="checkbox" name="materi" value="1" @checked($managerAccessSettings->materi) style="width:auto;margin:0;">
                <span>Materi Presentasi</span>
            </label>
            <label class="flex items-center gap-2 text-sm" style="cursor:pointer;">
                <input type="checkbox" name="rekaman" value="1" @checked($managerAccessSettings->rekaman) style="width:auto;margin:0;">
                <span>Tautan Rekaman</span>
            </label>
            <label class="flex items-center gap-2 text-sm" style="cursor:pointer;">
                <input type="checkbox" name="lihat_daftar_hadir" value="1" @checked($managerAccessSettings->lihat_daftar_hadir) style="width:auto;margin:0;">
                <span>Presensi &amp; Kehadiran</span>
            </label>
        </div>
        <button class="btn pri mt-3">Simpan Hak Akses</button>
    </form>

@elseif($tab === 'ruang')
    <h2>Master ruang rapat</h2>
    <p class="mu">Ruang nonaktif tetap tersedia untuk rapat lama.</p>
    <form method="post" action="{{ route('admin.action') }}" class="box">@csrf<input type="hidden" name="aksi" value="simpan_ruang">
        <label class="mt-0">Nama ruang rapat</label>
        <div class="flex flex-wrap items-center gap-2"><input class="grow" name="nama" required maxlength="120" placeholder="Contoh: Ruang Manado"><button class="btn pri">+ Tambah ruang</button></div>
    </form>
    <div class="tw"><table><thead><tr><th>Nama ruang</th><th>Status</th><th></th></tr></thead><tbody>
        @foreach($rooms as $room)<tr><td>{{ $room->nama }}</td><td>{{ $room->aktif ? 'Aktif' : 'Nonaktif' }}</td><td><form method="post" action="{{ route('admin.action') }}">@csrf<input type="hidden" name="aksi" value="toggle_ruang"><input type="hidden" name="id" value="{{ $room->id }}"><button class="btn sm">{{ $room->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></td></tr>@endforeach
    </tbody></table></div>

@elseif($tab === 'kehadiran')
    <p class="mu">Tinjau presensi rapat yang selesai dan pengajuan izin pegawai.</p>
    <h2>Presensi rapat selesai</h2>
    @if($completedMeetings->count() > 4)
        <button class="btn mb-3" type="button" data-completed-attendance-toggle aria-expanded="false" aria-controls="completed-attendance-meetings">
            Tampilkan rapat selesai lainnya ({{ $completedMeetings->count() - 4 }})
        </button>
    @endif
    @php($completedAttendanceIndex = 0)
    <div id="completed-attendance-meetings">
    @forelse($completedMeetings as $completedMeeting)
        @php($completedAttendanceIndex++)
        @php($completedAttendees = $completedMeetingAttendees->get($completedMeeting->id, collect()))
        @php($presentCount = $completedAttendees->count())
        <details class="{{ $completedAttendanceIndex > 4 ? 'box mt-2 hidden' : 'box mt-2' }}" data-completed-attendance-meeting @if($completedAttendanceIndex > 4)hidden @endif>
            <summary>{{ $completedMeeting->judul }} · {{ \App\Support\MeetingData::date($completedMeeting->tanggal) }} · {{ $presentCount }} hadir</summary>
            <div class="tw mt-2"><table><thead><tr><th>Nama</th><th>NIP</th><th>Absen masuk</th><th>Absen pulang</th><th>Keterangan tidak absen pulang</th></tr></thead><tbody>
                @forelse($completedAttendees as $person)
                    <tr>
                        <td>{{ $person->nama }}</td><td>{{ $person->nip }}</td>
                        <td>{{ $person->waktu ? substr($person->waktu, 11, 5).' WITA' : '—' }}</td>
                        <td>{{ $person->waktu_pulang ? substr($person->waktu_pulang, 11, 5).' WITA' : '—' }}</td>
                        <td>
                            @if($person->waktu_pulang)<span class="tag hadir">Sudah Absen Pulang</span>@else<span class="tag selesai">Belum Absen Pulang</span><br><small class="mu">Keterangan / alasan: {{ $person->alasan_pulang ?: 'Belum diisi pegawai (Alasan pulang duluan)' }}</small>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mu">Belum ada catatan kehadiran atau izin.</td></tr>
                @endforelse
            </tbody></table></div>
        </details>
    @empty
        <p class="mu">Belum ada rapat yang selesai.</p>
    @endforelse
    </div>
    @if($completedMeetings->count() > 4)
        <script>
            (function () {
                var button = document.querySelector('[data-completed-attendance-toggle]');
                var meetings = document.querySelectorAll('[data-completed-attendance-meeting]');
                button.addEventListener('click', function () {
                    var expanded = button.getAttribute('aria-expanded') === 'true';
                    meetings.forEach(function (meeting) {
                        meeting.hidden = expanded;
                        meeting.classList.toggle('hidden', expanded);
                    });
                    button.setAttribute('aria-expanded', String(!expanded));
                    button.textContent = expanded
                        ? 'Tampilkan rapat selesai lainnya (' + (meetings.length - 4) + ')'
                        : 'Tampilkan lebih sedikit rapat selesai';
                });
            })();
        </script>
    @endif
    <h2 class="mt-5">Daftar izin rapat</h2>
    <div class="tw"><table><thead><tr><th>Rapat</th><th>Pegawai</th><th>Status izin</th><th>Keterangan / alasan</th><th>Aksi</th></tr></thead><tbody>
        @forelse($leaveRequests as $leave)
            <tr>
                <td>{{ $leave->judul }}<br><small class="mu">{{ \App\Support\MeetingData::date($leave->tanggal) }}</small></td>
                <td>{{ $leave->nama }}<br><small class="mu">NIP {{ $leave->nip }}</small></td>
                <td><span class="tag izin">Izin {{ $leave->status_izin ?: 'menunggu' }}</span></td>
                <td>{{ $leave->jenis }}: {{ $leave->alasan }} @if($leave->file)<br><a href="{{ route('files.show', ['filename' => $leave->file, 'download' => 1]) }}">Lihat bukti izin</a>@endif</td>
                <td>
                    @if($leave->status_izin === 'menunggu')
                        <div class="flex flex-wrap gap-2">
                            @foreach(['setujui_izin' => 'Setujui', 'tolak_izin' => 'Tolak'] as $action => $label)
                                <form method="post" action="{{ route('admin.action') }}">@csrf<input type="hidden" name="aksi" value="{{ $action }}"><input type="hidden" name="id" value="{{ $leave->id }}"><button class="btn sm">{{ $label }}</button></form>
                            @endforeach
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="mu">Belum ada pengajuan izin.</td></tr>
        @endforelse
    </tbody></table></div>
@elseif($tab === 'rapat')
    @if(request()->has('edit'))
        @php($meeting = $editMeeting ?: (object) ['id' => 0, 'judul' => '', 'tanggal' => now()->toDateString(), 'mulai' => '09:00:00', 'selesai' => '11:00:00', 'tempat' => '', 'room_id' => null, 'link_meeting' => '', 'moda' => 'Luring', 'pimpinan' => '', 'notulis' => '', 'pemateri' => '', 'agenda' => '', 'link_rekaman' => '', 'notulen' => '', 'file_notulen' => '', 'materi_rapat' => ''])
        <h2>{{ $meeting->id ? 'Ubah' : 'Tambah' }} rapat</h2>
        <form method="post" action="{{ route('admin.action') }}" enctype="multipart/form-data" class="box">@csrf
            <input type="hidden" name="aksi" value="simpan_rapat"><input type="hidden" name="id" value="{{ $meeting->id }}">
            <label>Judul</label><input name="judul" value="{{ old('judul', $meeting->judul) }}" required>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label>Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', $meeting->tanggal) }}" required></div>
                <div><label>Moda</label><select name="moda">@foreach(['Luring','Daring','Hybrid'] as $mode)<option @selected(old('moda', $meeting->moda) === $mode)>{{ $mode }}</option>@endforeach</select></div>
                <div>@include('partials.meeting-time-select', ['name' => 'mulai', 'label' => 'Mulai', 'selectedTime' => old('mulai', substr($meeting->mulai, 0, 5))])</div>
                <div>@include('partials.meeting-time-select', ['name' => 'selesai', 'label' => 'Selesai', 'selectedTime' => old('selesai', substr($meeting->selesai, 0, 5))])</div>
                <div><label>Ruang</label><select name="room_id"><option value="">Tidak memilih ruang</option>@foreach($rooms as $room)<option value="{{ $room->id }}" @selected((string) old('room_id', $meeting->room_id) === (string) $room->id)>{{ $room->nama }}{{ !$room->aktif ? ' (nonaktif)' : '' }}</option>@endforeach</select></div>
                <div><label>Tempat lain</label><input name="tempat" value="{{ old('tempat', $meeting->tempat) }}"></div>
                <div><label>Tautan meeting HTTPS</label><input type="url" name="link_meeting" value="{{ old('link_meeting', $meeting->link_meeting) }}"></div>
                <div><label>Tautan rekaman Google Drive</label><input type="url" name="link_rekaman" value="{{ old('link_rekaman', $meeting->link_rekaman) }}"></div>
                <div><label>Pimpinan</label><input name="pimpinan" value="{{ old('pimpinan', $meeting->pimpinan) }}"></div>
                <div><label>Notulis</label><input name="notulis" value="{{ old('notulis', $meeting->notulis) }}"></div>
                <div><label>Pemateri</label><input name="pemateri" value="{{ old('pemateri', $meeting->pemateri) }}"></div>
            </div>
            @include('partials.meeting-participants', ['participantPrefix' => 'admin-meeting'])
            <label>Agenda</label><textarea name="agenda" rows="4">{{ old('agenda', $meeting->agenda) }}</textarea>
            <label>Notulen</label><textarea name="notulen" rows="5">{{ old('notulen', $meeting->notulen) }}</textarea>
            <label>PDF notulen (maks. 15 MB)</label><input type="file" name="file_notulen" accept=".pdf">
            @if($meeting->file_notulen)<label><input type="checkbox" name="hapus_file_notulen" value="1" style="width:auto"> Hapus PDF saat menyimpan</label>@endif
            <label>Unggah materi (maks. 20 MB)</label><input type="file" name="materi_rapat" accept=".pdf,.ppt,.pptx,.doc,.docx,.zip">
            @if($meeting->materi_rapat)<label><input type="checkbox" name="hapus_materi_rapat" value="1" style="width:auto"> Hapus materi lama saat menyimpan</label>@endif
            <div class="flex flex-wrap gap-2 mt-3"><button class="btn pri">Simpan rapat</button><a class="btn" href="{{ route('admin', 'rapat') }}">Batal</a></div>
        </form>
    @else
        @if($attendanceMeeting)
            <section class="box mb-4">
                <h2 class="mt-0">Presensi: {{ $attendanceMeeting->judul }}</h2>
                @if(!$isSuperAdmin)
                    <p class="mu">Pembuatan dan pengelolaan kode presensi hanya dapat dilakukan oleh Super Admin.</p>
                @else
                @if(\App\Support\MeetingData::scheduleStatus($attendanceMeeting) === 'selesai')
                    <p class="mu">Rapat sudah selesai. Presensi hanya dapat ditinjau.</p>
                @else
                    <h3>Absen masuk</h3>
                    @if(\App\Support\MeetingData::attendanceOpen($attendanceMeeting))
                        <p>Kode aktif <b>{{ $attendanceMeeting->kode_presensi }}</b>, berlaku sampai {{ substr($attendanceMeeting->presensi_kedaluwarsa, 11, 5) }} WITA.</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach([15,30] as $minutes)<form method="post" action="{{ route('admin.action') }}">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="id" value="{{ $attendanceMeeting->id }}"><input type="hidden" name="opsi_presensi" value="tambah_waktu"><input type="hidden" name="menit" value="{{ $minutes }}"><button class="btn sm ok">+ {{ $minutes }} menit</button></form>@endforeach
                            <form method="post" action="{{ route('admin.action') }}">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="id" value="{{ $attendanceMeeting->id }}"><input type="hidden" name="opsi_presensi" value="tutup"><button class="btn sm red">Tutup presensi</button></form>
                        </div>
                    @endif
                    <form method="post" action="{{ route('admin.action') }}" class="mt-3">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="id" value="{{ $attendanceMeeting->id }}"><input type="hidden" name="opsi_presensi" value="buat">
                        <label>Kode presensi (kosongkan untuk otomatis)</label><input name="kode" maxlength="20">
                        <label>Batas waktu</label><select name="durasi"><option value="15">15 menit</option><option value="30" selected>30 menit</option><option value="60">1 jam</option><option value="selesai">Sampai rapat selesai</option></select>
                        <button class="btn pri mt-3">Buka / perbarui presensi</button>
                    </form>
                @endif
                <hr class="my-4">
                <h3>Absen pulang</h3>
                @if(\App\Support\MeetingData::checkoutOpen($attendanceMeeting))
                    <p>Kode absen pulang aktif <b>{{ $attendanceMeeting->kode_presensi_pulang }}</b>, berlaku sampai {{ substr($attendanceMeeting->presensi_pulang_kedaluwarsa, 11, 5) }} WITA.</p>
                    <form method="post" action="{{ route('admin.action') }}">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="id" value="{{ $attendanceMeeting->id }}"><input type="hidden" name="opsi_presensi" value="tutup_pulang"><button class="btn sm red">Tutup kode absen pulang</button></form>
                @elseif($attendanceMeeting->kode_presensi_pulang)
                    <p class="mu">Kode absen pulang sebelumnya sudah berakhir.</p>
                @else
                    <p class="mu">Kode absen pulang belum dibuat.</p>
                @endif
                <form method="post" action="{{ route('admin.action') }}" class="mt-3">@csrf<input type="hidden" name="aksi" value="atur_presensi"><input type="hidden" name="id" value="{{ $attendanceMeeting->id }}"><input type="hidden" name="opsi_presensi" value="buat_pulang">
                    <label>Kode absen pulang (kosongkan untuk otomatis)</label><input name="kode" maxlength="20">
                    <label>Batas waktu setelah rapat selesai</label><select name="durasi"><option value="15">15 menit</option><option value="30" selected>30 menit</option><option value="60">1 jam</option></select>
                    <button class="btn pri mt-3">Buat kode absen pulang</button>
                </form>
                @endif
                <h3 class="mt-4">Daftar absen masuk ({{ $attendancePeople->count() }})</h3>
                <div class="tw"><table><thead><tr><th>Nama</th><th>NIP</th><th>Waktu</th></tr></thead><tbody>@foreach($attendancePeople as $person)<tr><td>{{ $person->nama }}</td><td>{{ $person->nip }}</td><td>{{ substr($person->waktu, 11, 5) }} WITA</td></tr>@endforeach</tbody></table></div>
                <h3 class="mt-4">Daftar absen pulang</h3>
                <div class="tw"><table><thead><tr><th>Nama</th><th>NIP</th><th>Waktu pulang</th><th>Status / keterangan</th></tr></thead><tbody>
                    @forelse($attendancePeople as $person)
                        <tr><td>{{ $person->nama }}</td><td>{{ $person->nip }}</td><td>{{ $person->waktu_pulang ? substr($person->waktu_pulang, 11, 5).' WITA' : '—' }}</td>
                            <td>@if($person->waktu_pulang)<span class="tag hadir">Sudah Absen Pulang</span>@elseif(\App\Support\MeetingData::scheduleStatus($attendanceMeeting) === 'selesai')<span class="tag selesai">Belum Absen Pulang</span><br><small class="mu">Keterangan / alasan: {{ $person->alasan_pulang ?: 'Belum diisi pegawai (Alasan pulang duluan)' }}</small>@else<span class="tag akan">Menunggu rapat selesai</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="mu">Belum ada pegawai yang absen masuk.</td></tr>
                    @endforelse
                </tbody></table></div>
            </section>
        @endif
        @if($canCreateMeetings)<p><a class="btn pri" href="{{ route('admin', ['tab' => 'rapat', 'edit' => 0]) }}">+ Tambah rapat</a></p>@endif
        @php($completedMeetingCount = $meetings->filter(fn ($meeting) => \App\Support\MeetingData::scheduleStatus($meeting) === 'selesai')->count())
        @php($completedMeetingIndex = 0)
        @if($completedMeetingCount > 0)
            <label for="admin-completed-meeting-search">Cari rapat selesai</label>
            <input id="admin-completed-meeting-search" type="search" class="mb-3" placeholder="Cari judul, tanggal, atau tempat rapat..." data-admin-completed-search>
            <p class="mu" data-admin-completed-search-empty hidden>Tidak ada rapat selesai yang cocok dengan pencarian.</p>
        @endif
        @if($completedMeetingCount > 4)
            <button class="btn mb-3" type="button" data-admin-completed-toggle aria-expanded="false" aria-controls="admin-meetings">
                Tampilkan rapat selesai lainnya ({{ $completedMeetingCount - 4 }})
            </button>
        @endif
        <div class="tw"><table><thead><tr><th>Rapat</th><th>Waktu</th><th>Status</th><th>Presensi</th><th></th></tr></thead><tbody id="admin-meetings">
            @foreach($meetings as $meeting)
                @php($scheduleStatus = \App\Support\MeetingData::scheduleStatus($meeting))
                @php($isCompletedMeeting = $scheduleStatus === 'selesai')
                @if($isCompletedMeeting)@php($completedMeetingIndex++)@endif
                @php($collapseCompletedMeeting = $isCompletedMeeting && $completedMeetingIndex > 4)
                @php($attendees = $meetingAttendees->get($meeting->id, collect()))
                <tr @if($isCompletedMeeting)data-admin-completed-meeting data-admin-completed-search-row="{{ $meeting->id }}" @if($collapseCompletedMeeting)class="hidden" hidden @endif @endif><td><b>{{ $meeting->judul }}</b></td><td>{{ \App\Support\MeetingData::date($meeting->tanggal) }} {{ substr($meeting->mulai, 0, 5) }} · {{ $meeting->tempat ?: 'Tempat belum ditentukan' }}</td>
                <td>{{ ucfirst($scheduleStatus) }}</td><td>@if($canViewAttendance)<a class="btn sm" href="{{ route('admin', ['tab' => 'rapat', 'presensi' => $meeting->id]) }}">{{ $isSuperAdmin ? (\App\Support\MeetingData::attendanceOpen($meeting) ? 'Kode '.$meeting->kode_presensi : 'Atur kode') : 'Lihat presensi' }}</a>@endif</td>
                <td><div class="flex flex-wrap gap-2">@if($canCreateMeetings)<a class="btn sm" href="{{ route('admin', ['tab' => 'rapat', 'edit' => $meeting->id]) }}">Ubah</a>
                    <form method="post" action="{{ route('admin.action') }}" onsubmit="return confirm('Hapus rapat ini?')">@csrf<input type="hidden" name="aksi" value="hapus_rapat"><input type="hidden" name="id" value="{{ $meeting->id }}"><button class="btn sm red">Hapus</button></form>@endif
                    @if($canEndMeetings && \App\Support\MeetingData::canManuallyEnd($meeting))
                    <form method="post" action="{{ route('admin.action') }}" onsubmit="return confirm('Tandai rapat ini sebagai selesai sekarang? Tindakan ini tidak dapat dibatalkan.')">@csrf<input type="hidden" name="aksi" value="selesaikan_rapat"><input type="hidden" name="id" value="{{ $meeting->id }}"><button class="btn sm" style="background:#f59e0b;color:#fff;border-color:#f59e0b;">✓ Selesaikan</button></form>
                    @endif
                </div></td></tr>
                <tr @if($isCompletedMeeting)data-admin-completed-meeting @if($collapseCompletedMeeting)class="hidden" hidden @endif @endif><td colspan="5">
                    <details>
                        <summary>Absen masuk ({{ $attendees->count() }})</summary>
                        <div class="tw mt-2"><table>
                            <thead><tr><th>Nama</th><th>NIP</th><th>Waktu hadir</th></tr></thead>
                            <tbody>
                                @forelse($attendees as $person)
                                    <tr><td>{{ $person->nama }}</td><td>{{ $person->nip }}</td><td>{{ substr($person->waktu, 11, 5) }} WITA</td></tr>
                                @empty
                                    <tr><td colspan="3" class="mu">Belum ada pegawai yang tercatat hadir.</td></tr>
                                @endforelse
                            </tbody>
                        </table></div>
                    </details>
                    <details class="mt-2">
                        <summary>Daftar absen pulang</summary>
                        <div class="tw mt-2"><table>
                            <thead><tr><th>Nama</th><th>NIP</th><th>Waktu pulang</th><th>Status / keterangan</th></tr></thead>
                            <tbody>
                                @forelse($attendees as $person)
                                    <tr><td>{{ $person->nama }}</td><td>{{ $person->nip }}</td>
                                        <td>{{ $person->waktu_pulang ? substr($person->waktu_pulang, 11, 5).' WITA' : '—' }}</td>
                                        <td>
                                            @if($person->waktu_pulang)
                                                <span class="tag hadir">Sudah Absen Pulang</span>
                                            @elseif($scheduleStatus === 'selesai')
                                                <span class="tag selesai">Belum Absen Pulang</span><br><small class="mu">Keterangan / alasan: {{ $person->alasan_pulang ?: 'Belum diisi pegawai (Alasan pulang duluan)' }}</small>
                                            @else
                                                <span class="tag akan">Menunggu rapat selesai</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="mu">Belum ada pegawai yang absen masuk.</td></tr>
                                @endforelse
                            </tbody>
                        </table></div>
                    </details>
                </td></tr>
            @endforeach
        </tbody></table></div>
        @if($completedMeetingCount > 0)
            <script>
                (function () {
                    var search = document.querySelector('[data-admin-completed-search]');
                    var button = document.querySelector('[data-admin-completed-toggle]');
                    var rows = Array.from(document.querySelectorAll('[data-admin-completed-search-row]'));
                    var emptyMessage = document.querySelector('[data-admin-completed-search-empty]');

                    function update() {
                        var query = search.value.trim().toLocaleLowerCase();
                        var matchingCount = 0;
                        rows.forEach(function (row, index) {
                            var matches = !query || row.textContent.toLocaleLowerCase().includes(query);
                            var visible = matches && (query !== '' || index < 4 || (button && button.getAttribute('aria-expanded') === 'true'));
                            var detailsRow = row.nextElementSibling;
                            row.hidden = !visible;
                            row.classList.toggle('hidden', !visible);
                            if (detailsRow) {
                                detailsRow.hidden = !visible;
                                detailsRow.classList.toggle('hidden', !visible);
                            }
                            if (visible && query) matchingCount++;
                        });
                        emptyMessage.hidden = !query || matchingCount > 0;
                        if (button) button.hidden = Boolean(query);
                    }

                    search.addEventListener('input', update);
                    if (button) {
                        button.addEventListener('click', function () {
                            var expanded = button.getAttribute('aria-expanded') === 'true';
                            button.setAttribute('aria-expanded', String(!expanded));
                            button.textContent = expanded
                                ? 'Tampilkan rapat selesai lainnya (' + (rows.length - 4) + ')'
                                : 'Tampilkan lebih sedikit rapat selesai';
                            update();
                        });
                    }
                    update();
                })();
            </script>
        @endif
    @endif

@elseif($tab === 'pengaturan')
    <form method="post" action="{{ route('admin.action') }}" class="box">@csrf<input type="hidden" name="aksi" value="simpan_pengaturan">
        <b>Aturan presensi &amp; kehadiran</b>
        <label><input type="checkbox" name="wajib_izin" value="1" style="width:auto" @checked(\App\Support\MeetingData::setting('wajib_izin', '1') === '1')> Wajib surat izin bila tidak hadir</label>
        <button class="btn pri mt-3">Simpan</button>
    </form>
@endif
@endsection
