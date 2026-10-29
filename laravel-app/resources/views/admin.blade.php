@extends('layouts.app', ['title' => 'Pengelola Rapat'])

@section('content')
@php
    $tabs = ['rapat' => 'Rapat', 'ruang' => 'Ruang Rapat', 'kehadiran' => 'Kehadiran & Izin', 'pegawai' => 'Pegawai', 'superadmin' => 'Hak Akses', 'pengaturan' => 'Pengaturan'];
@endphp
@if($canReturnToEmployeeDashboard || $isSuperAdmin)
    <a class="btn admin-back-link mb-3" href="{{ route('dashboard') }}"><span aria-hidden="true">←</span> Kembali ke Dashboard</a>
@endif
<nav class="tabs admin-menu-panel flex flex-wrap gap-2 mb-4">
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
    <div class="employee-management">
    <header class="employee-page-intro">
        <span>Administrasi akun</span>
        <h2>Data Pegawai</h2>
        <p>Kelola informasi pegawai, hak akses, dan pengaturan kata sandi.</p>
    </header>
    @if(session('password_employee'))
        <div class="employee-password-notice">
            <b>Kata sandi baru untuk {{ session('password_employee.nama') }}</b>
            <p>Salin dan berikan langsung kepada pegawai. Nilai ini hanya ditampilkan sekali.</p>
            <div class="flex flex-wrap items-center gap-2">
                <input id="new-password" type="password" value="{{ session('password_employee.password') }}" readonly>
                <button class="btn sm" type="button" onclick="var i=document.getElementById('new-password');i.type=i.type==='password'?'text':'password'">Lihat / sembunyikan</button>
                <button class="btn sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('new-password').value)">Salin</button>
            </div>
        </div>
    @endif
    <details class="employee-add-panel">
        <summary><span class="employee-add-icon" aria-hidden="true">+</span><span><b>Tambah pegawai</b><small>Daftarkan akun pegawai baru.</small></span><span class="employee-add-chevron" aria-hidden="true">⌄</span></summary>
        <form method="post" action="{{ route('admin.action') }}" class="employee-add-form">@csrf
            <input type="hidden" name="aksi" value="tambah_pegawai">
            <div class="employee-add-grid">
                <div><label>Nama lengkap</label><input name="nama" required placeholder="Nama sesuai identitas"></div>
                <div><label>NIP (18 digit)</label><input name="nip" required inputmode="numeric" maxlength="18" placeholder="Masukkan NIP"></div>
                <div><label>Jabatan / unit</label><input name="jabatan" placeholder="Jabatan atau unit kerja"></div>
            </div>
            <div class="employee-add-footer"><p>Kata sandi awal dibuat dari NIP dan wajib diganti saat login pertama.</p><button class="btn pri">Simpan pegawai</button></div>
        </form>
    </details>
    <div class="employee-list-heading">
        <div><h3>Daftar pegawai</h3><p>Atur jabatan dan akses langsung pada setiap baris.</p></div>
        <span class="tag akan">{{ $employees->count() }} pegawai</span>
    </div>
    <label class="employee-search">
        <span aria-hidden="true">⌕</span>
        <input id="employee-search" type="search" placeholder="Cari nama, NIP, atau jabatan..." autocomplete="off" aria-label="Cari pegawai">
    </label>
    <p id="employee-count" class="employee-count" aria-live="polite">Menampilkan {{ $employees->count() }} pegawai</p>
    <div class="tw employee-table-wrap"><table class="employee-table">
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
    </div>
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
    @php($pendingLeaveCount = $leaveRequests->where('status_izin', 'menunggu')->count())
    <div class="attendance-page">
    <header class="attendance-intro">
        <span>Administrasi rapat</span>
        <h2>Kehadiran &amp; Izin</h2>
        <p>Tinjau catatan presensi dan kelola pengajuan izin pegawai.</p>
    </header>
    <div class="attendance-overview">
        <article class="attendance-stat attendance-stat-teal">
            <span>Rapat selesai</span>
            <b>{{ $completedMeetings->count() }}</b>
            <small>Catatan presensi tersedia</small>
        </article>
        <article class="attendance-stat attendance-stat-lime">
            <span>Total pengajuan izin</span>
            <b>{{ $leaveRequests->count() }}</b>
            <small>Pengajuan pegawai</small>
        </article>
        <article class="attendance-stat attendance-stat-cyan">
            <span>Perlu ditinjau</span>
            <b>{{ $pendingLeaveCount }}</b>
            <small>Izin menunggu keputusan</small>
        </article>
    </div>
    <section class="attendance-section">
    <div class="attendance-section-heading">
        <div><h2>Presensi rapat selesai</h2><p>Periksa kehadiran dan catatan absen pulang per rapat.</p></div>
        <div class="attendance-section-actions">
            <span class="tag selesai">{{ $completedMeetings->count() }} rapat</span>
            @if($completedMeetings->count() > 4)
                <button class="btn attendance-meetings-more" type="button" data-completed-attendance-toggle aria-expanded="false" aria-controls="completed-attendance-meetings">
                    Tampilkan lainnya <span>+{{ $completedMeetings->count() - 4 }}</span>
                </button>
            @endif
        </div>
    </div>
    @php($completedAttendanceIndex = 0)
    <div id="completed-attendance-meetings">
    @forelse($completedMeetings as $completedMeeting)
        @php($completedAttendanceIndex++)
        @php($completedAttendees = $completedMeetingAttendees->get($completedMeeting->id, collect()))
        @php($presentCount = $completedAttendees->count())
        <details class="{{ $completedAttendanceIndex > 4 ? 'attendance-meeting-card hidden' : 'attendance-meeting-card' }}" data-completed-attendance-meeting @if($completedAttendanceIndex > 4)hidden @endif>
            <summary>
                <span class="attendance-meeting-icon" aria-hidden="true">▦</span>
                <span class="attendance-meeting-info">
                <span class="attendance-meeting-title">{{ $completedMeeting->judul }}</span>
                <span class="attendance-meeting-meta">{{ \App\Support\MeetingData::date($completedMeeting->tanggal) }}</span>
                </span>
                <span class="tag hadir">{{ $presentCount }} hadir</span>
                <span class="attendance-meeting-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="tw attendance-meeting-table"><table><thead><tr><th>Nama</th><th>NIP</th><th>Absen masuk</th><th>Absen pulang</th><th>Keterangan tidak absen pulang</th></tr></thead><tbody>
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
        <p class="attendance-empty">Belum ada rapat yang selesai.</p>
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
                        ? 'Tampilkan lainnya +' + (meetings.length - 4)
                        : 'Tampilkan lebih sedikit';
                });
            })();
        </script>
    @endif
    </section>
    <section class="attendance-section">
    <div class="attendance-section-heading">
        <div><h2>Daftar izin rapat</h2><p>Lihat alasan izin dan tindak lanjuti pengajuan yang menunggu.</p></div>
        <span class="tag {{ $pendingLeaveCount ? 'izin' : 'hadir' }}">{{ $pendingLeaveCount }} menunggu</span>
    </div>
    @if($leaveRequests->isNotEmpty())
        <div class="attendance-leave-toolbar">
            <label class="attendance-leave-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" placeholder="Cari nama, rapat, atau alasan..." aria-label="Cari pengajuan izin" data-leave-search>
            </label>
            <div class="attendance-leave-filters" role="group" aria-label="Filter status izin">
                <button type="button" class="is-active" data-leave-filter="semua" aria-pressed="true">Semua <span>{{ $leaveRequests->count() }}</span></button>
                <button type="button" data-leave-filter="menunggu" aria-pressed="false">Menunggu <span>{{ $pendingLeaveCount }}</span></button>
                <button type="button" data-leave-filter="disetujui" aria-pressed="false">Disetujui <span>{{ $leaveRequests->where('status_izin', 'disetujui')->count() }}</span></button>
                <button type="button" data-leave-filter="ditolak" aria-pressed="false">Ditolak <span>{{ $leaveRequests->where('status_izin', 'ditolak')->count() }}</span></button>
            </div>
        </div>
    @endif
    <div class="attendance-leave-grid">
        @php($leaveIndex = 0)
        @forelse($leaveRequests as $leave)
            @php($leaveIndex++)
            @php($leaveStatus = $leave->status_izin ?: 'menunggu')
            <article class="attendance-leave-card{{ $leaveStatus === 'menunggu' ? ' is-pending' : '' }}{{ $leaveIndex > 4 ? ' hidden' : '' }}" data-leave-card data-leave-status="{{ $leaveStatus }}" @if($leaveIndex > 4)hidden @endif>
                <div class="attendance-leave-card-top">
                    <span class="attendance-leave-kind">{{ $leave->jenis ?: 'Pengajuan izin' }}</span>
                    <span class="tag {{ $leaveStatus === 'disetujui' ? 'hadir' : ($leaveStatus === 'ditolak' ? 'selesai' : 'izin') }}">
                        {{ ucfirst($leaveStatus) }}
                    </span>
                </div>
                <div class="attendance-leave-person">
                    <span class="attendance-leave-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($leave->nama, 0, 1)) }}</span>
                    <div><h3>{{ $leave->nama }}</h3><p class="attendance-leave-nip">NIP {{ $leave->nip }}</p></div>
                </div>
                <div class="attendance-leave-meeting">
                    <span>Rapat</span>
                    <b>{{ $leave->judul }}</b>
                    <small>{{ \App\Support\MeetingData::date($leave->tanggal) }}</small>
                </div>
                <div class="attendance-leave-reason">
                    <span>Keterangan izin</span>
                    <p>{{ $leave->alasan ?: 'Tidak ada keterangan.' }}</p>
                    @if($leave->file)
                        <a href="{{ route('files.show', ['filename' => $leave->file, 'download' => 1]) }}">Lihat bukti izin</a>
                    @endif
                </div>
                @if($leaveStatus === 'menunggu')
                    <div class="attendance-leave-actions">
                        <span>Perlu tindak lanjut</span>
                        @foreach(['setujui_izin' => 'Setujui', 'tolak_izin' => 'Tolak'] as $action => $label)
                            <form method="post" action="{{ route('admin.action') }}">@csrf<input type="hidden" name="aksi" value="{{ $action }}"><input type="hidden" name="id" value="{{ $leave->id }}"><button class="btn sm {{ $action === 'setujui_izin' ? 'ok' : 'red' }}">{{ $label }}</button></form>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <p class="attendance-empty">Belum ada pengajuan izin.</p>
        @endforelse
    </div>
    @if($leaveRequests->isNotEmpty())
        @if($leaveRequests->count() > 4)
            <button class="btn attendance-leave-more" type="button" data-leave-more aria-expanded="false">
                Tampilkan lainnya ({{ $leaveRequests->count() - 4 }})
            </button>
        @endif
        <p class="attendance-filter-empty" data-leave-filter-empty hidden>Tidak ada pengajuan izin yang cocok dengan pencarian atau filter.</p>
        <script>
            (function () {
                var page = document.querySelector('.attendance-page');
                var search = page.querySelector('[data-leave-search]');
                var cards = Array.from(page.querySelectorAll('[data-leave-card]'));
                var filters = Array.from(page.querySelectorAll('[data-leave-filter]'));
                var empty = page.querySelector('[data-leave-filter-empty]');
                var more = page.querySelector('[data-leave-more]');
                var activeFilter = 'semua';
                var expanded = false;

                function update() {
                    var query = search.value.trim().toLocaleLowerCase();
                    var visibleCount = 0;
                    cards.forEach(function (card) {
                        var matchesStatus = activeFilter === 'semua' || card.dataset.leaveStatus === activeFilter;
                        var matchesQuery = card.textContent.toLocaleLowerCase().includes(query);
                        var matches = matchesStatus && matchesQuery;
                        var visible = matches && (expanded || visibleCount < 4);
                        card.hidden = !visible;
                        card.classList.toggle('hidden', !visible);
                        if (visible) visibleCount++;
                    });
                    empty.hidden = visibleCount > 0;
                    if (more) {
                        var matchingCount = cards.filter(function (card) {
                            return (activeFilter === 'semua' || card.dataset.leaveStatus === activeFilter)
                                && card.textContent.toLocaleLowerCase().includes(query);
                        }).length;
                        more.hidden = matchingCount <= 4;
                        more.setAttribute('aria-expanded', String(expanded));
                        more.textContent = expanded
                            ? 'Tampilkan lebih sedikit'
                            : 'Tampilkan lainnya (' + (matchingCount - 4) + ')';
                    }
                }

                search.addEventListener('input', update);
                if (more) {
                    more.addEventListener('click', function () {
                        expanded = !expanded;
                        update();
                    });
                }
                filters.forEach(function (filter) {
                    filter.addEventListener('click', function () {
                        activeFilter = filter.dataset.leaveFilter;
                        expanded = false;
                        filters.forEach(function (item) {
                            var selected = item === filter;
                            item.classList.toggle('is-active', selected);
                            item.setAttribute('aria-pressed', String(selected));
                        });
                        update();
                    });
                });
            })();
        </script>
    @endif
    </section>
    </div>
@elseif($tab === 'rapat')
    @if(request()->has('edit'))
        @php($meeting = $editMeeting ?: (object) ['id' => 0, 'judul' => '', 'tanggal' => now()->toDateString(), 'mulai' => '09:00:00', 'selesai' => '11:00:00', 'tempat' => '', 'room_id' => null, 'link_meeting' => '', 'moda' => 'Luring', 'pimpinan' => '', 'notulis' => '', 'pemateri' => '', 'agenda' => '', 'link_rekaman' => '', 'notulen' => '', 'file_notulen' => '', 'materi_rapat' => ''])
        <section class="meeting-create-section admin-meeting-create">
            <div class="dashboard-section-heading"><h2>{{ $meeting->id ? 'Ubah rapat' : 'Tambah rapat' }}</h2></div>
            <div class="meeting-create-panel">
                <div class="meeting-create-content">
                    <div class="meeting-create-intro">
                        <div>
                            <h3>{{ $meeting->id ? 'Perbarui informasi rapat' : 'Siapkan jadwal rapat' }}</h3>
                            <p>Lengkapi detail utama, peserta, dan informasi pendukung rapat.</p>
                        </div>
                        <span>Kode presensi dikelola Pengelola Rapat</span>
                    </div>
                    <form method="post" action="{{ route('admin.action') }}" enctype="multipart/form-data" class="meeting-create-form">
                        @csrf
                        <input type="hidden" name="aksi" value="simpan_rapat"><input type="hidden" name="id" value="{{ $meeting->id }}">
                        <section class="meeting-form-section">
                            <h3>Informasi rapat</h3>
                            <p class="mu">Tentukan judul, waktu, dan lokasi atau moda rapat.</p>
                            <div class="meeting-form-grid">
                                <div class="meeting-form-field meeting-form-field-wide"><label>Judul rapat</label><input name="judul" value="{{ old('judul', $meeting->judul) }}" required placeholder="Contoh: Rapat koordinasi bulanan"></div>
                                <div class="meeting-form-field"><label>Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', $meeting->tanggal) }}" required></div>
                                <div class="meeting-form-field"><label>Moda</label><select name="moda">@foreach(['Luring','Daring','Hybrid'] as $mode)<option @selected(old('moda', $meeting->moda) === $mode)>{{ $mode }}</option>@endforeach</select></div>
                                <div class="meeting-form-field">@include('partials.meeting-time-select', ['name' => 'mulai', 'label' => 'Mulai', 'selectedTime' => old('mulai', substr($meeting->mulai, 0, 5))])</div>
                                <div class="meeting-form-field">@include('partials.meeting-time-select', ['name' => 'selesai', 'label' => 'Selesai', 'selectedTime' => old('selesai', substr($meeting->selesai, 0, 5))])</div>
                                <div class="meeting-form-field"><label>Ruang</label><select name="room_id"><option value="">Tidak memilih ruang</option>@foreach($rooms as $room)<option value="{{ $room->id }}" @selected((string) old('room_id', $meeting->room_id) === (string) $room->id)>{{ $room->nama }}{{ !$room->aktif ? ' (nonaktif)' : '' }}</option>@endforeach</select></div>
                                <div class="meeting-form-field"><label>Tempat lain</label><input name="tempat" value="{{ old('tempat', $meeting->tempat) }}"></div>
                                <div class="meeting-form-field"><label>Tautan meeting HTTPS</label><input type="url" name="link_meeting" value="{{ old('link_meeting', $meeting->link_meeting) }}" placeholder="https://"></div>
                                @if($canManageRecordings)
                                    <div class="meeting-form-field"><label>Tautan rekaman Google Drive / YouTube</label><input type="url" name="link_rekaman" value="{{ old('link_rekaman', $meeting->link_rekaman) }}" placeholder="https://"></div>
                                @endif
                            </div>
                        </section>
                        <section class="meeting-form-section">
                            <h3>Peserta dan petugas</h3>
                            <p class="mu">Tentukan pimpinan, notulis, pemateri, dan pegawai yang mengikuti rapat.</p>
                            <div class="meeting-form-grid">
                                <div class="meeting-form-field"><label>Pimpinan</label><input name="pimpinan" value="{{ old('pimpinan', $meeting->pimpinan) }}"></div>
                                <div class="meeting-form-field"><label>Notulis</label><input name="notulis" value="{{ old('notulis', $meeting->notulis) }}"></div>
                                <div class="meeting-form-field"><label>Pemateri</label><input name="pemateri" value="{{ old('pemateri', $meeting->pemateri) }}"></div>
                            </div>
                            @include('partials.meeting-participants', ['participantPrefix' => 'admin-meeting'])
                        </section>
                        <section class="meeting-form-section">
                            <h3>Agenda dan dokumen</h3>
                            <p class="mu">Tambahkan agenda, notulen, atau materi pendukung.</p>
                            <div class="meeting-form-grid">
                                <div class="meeting-form-field meeting-form-field-wide"><label>Agenda</label><textarea name="agenda" rows="4">{{ old('agenda', $meeting->agenda) }}</textarea></div>
                                @if($canEditMinutes)
                                    <div class="meeting-form-field meeting-form-field-wide"><label>Notulen</label><textarea name="notulen" rows="5">{{ old('notulen', $meeting->notulen) }}</textarea></div>
                                    <div class="meeting-form-field meeting-form-field-wide"><label>PDF notulen <span>(maks. 15 MB)</span></label><input type="file" name="file_notulen" accept=".pdf"></div>
                                    @if($meeting->file_notulen)<label class="meeting-form-field-wide meeting-remove-file"><input type="checkbox" name="hapus_file_notulen" value="1" style="width:auto"> Hapus PDF saat menyimpan</label>@endif
                                @endif
                                @if($canManageMaterials)
                                    <div class="meeting-form-field meeting-form-field-wide"><label>Unggah materi <span>(maks. 20 MB)</span></label><input type="file" name="materi_rapat" accept=".pdf,.ppt,.pptx,.doc,.docx,.zip"></div>
                                    @if($meeting->materi_rapat)<label class="meeting-form-field-wide meeting-remove-file"><input type="checkbox" name="hapus_materi_rapat" value="1" style="width:auto"> Hapus materi lama saat menyimpan</label>@endif
                                @endif
                            </div>
                        </section>
                        <div class="meeting-form-actions">
                            <p>Periksa kembali informasi sebelum menyimpan.</p>
                            <div class="flex flex-wrap gap-2">
                                <button class="btn pri">Simpan rapat</button>
                                <a class="btn" href="{{ route('admin', 'rapat') }}">Batal</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>
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
        <section class="admin-meeting-page">
        @php($meetingStatuses = $meetings->map(fn ($meeting) => \App\Support\MeetingData::scheduleStatus($meeting)))
        @php($upcomingMeetingCount = $meetingStatuses->filter(fn ($status) => $status === 'akan')->count())
        @php($ongoingMeetingCount = $meetingStatuses->filter(fn ($status) => $status === 'berlangsung')->count())
        @php($completedMeetingCount = $meetingStatuses->filter(fn ($status) => $status === 'selesai')->count())
        @php($completedMeetingIndex = 0)
        <header class="admin-meeting-heading">
            <div>
                <span class="admin-meeting-eyebrow">Pusat pengelolaan</span>
                <h2>Rapat &amp; Kegiatan</h2>
                <p>Kelola jadwal, pantau kehadiran, dan temukan informasi rapat dengan mudah.</p>
            </div>
            <div class="admin-meeting-heading-actions">
                @if($canCreateMeetings)
                    <a class="btn pri admin-meeting-add" href="{{ route('admin', ['tab' => 'rapat', 'edit' => 0]) }}"><span aria-hidden="true">+</span> Buat rapat baru</a>
                @endif
            </div>
        </header>
        <div class="admin-meeting-stats" aria-label="Ringkasan rapat">
            <div class="admin-meeting-stat admin-meeting-stat-all"><span class="admin-meeting-stat-icon" aria-hidden="true">▦</span><span class="admin-meeting-stat-copy"><small>Total rapat</small><b>{{ $meetings->count() }}</b></span></div>
            <div class="admin-meeting-stat admin-meeting-stat-upcoming"><span class="admin-meeting-stat-icon" aria-hidden="true">◷</span><span class="admin-meeting-stat-copy"><small>Akan datang</small><b>{{ $upcomingMeetingCount }}</b></span></div>
            <div class="admin-meeting-stat admin-meeting-stat-ongoing"><span class="admin-meeting-stat-icon" aria-hidden="true">●</span><span class="admin-meeting-stat-copy"><small>Berlangsung</small><b>{{ $ongoingMeetingCount }}</b></span></div>
            <div class="admin-meeting-stat admin-meeting-stat-completed"><span class="admin-meeting-stat-icon" aria-hidden="true">✓</span><span class="admin-meeting-stat-copy"><small>Selesai</small><b>{{ $completedMeetingCount }}</b></span></div>
        </div>
        <div class="admin-meeting-toolbar">
            <div class="admin-meeting-search">
                <span aria-hidden="true">⌕</span>
                <input id="admin-completed-meeting-search" type="search" placeholder="Cari judul, tanggal, atau tempat rapat..." aria-label="Cari rapat" data-admin-completed-search>
            </div>
            <div class="admin-meeting-filters" role="group" aria-label="Filter status rapat">
                <button type="button" class="is-active" data-admin-meeting-filter="semua" aria-pressed="true">Semua <span>{{ $meetings->count() }}</span></button>
                <button type="button" data-admin-meeting-filter="akan" aria-pressed="false">Akan datang <span>{{ $upcomingMeetingCount }}</span></button>
                <button type="button" data-admin-meeting-filter="berlangsung" aria-pressed="false">Berlangsung <span>{{ $ongoingMeetingCount }}</span></button>
                <button type="button" data-admin-meeting-filter="selesai" aria-pressed="false">Selesai <span>{{ $completedMeetingCount }}</span></button>
            </div>
            @if($completedMeetingCount > 4)
                <button class="btn admin-meeting-toggle" type="button" data-admin-completed-toggle aria-expanded="false">
                    Tampilkan {{ $completedMeetingCount - 4 }} rapat selesai lainnya
                </button>
            @endif
        </div>
        <div class="admin-meeting-empty" data-admin-meeting-empty hidden>
            <span aria-hidden="true">⌕</span>
            <b>Rapat tidak ditemukan</b>
            <p>Coba ubah kata pencarian atau pilih filter status yang lain.</p>
        </div>
        @if($meetings->isEmpty())
            <div class="admin-meeting-empty">
                <span aria-hidden="true">▦</span>
                <b>Belum ada jadwal rapat</b>
                <p>Rapat yang sudah dibuat akan tampil di bagian ini.</p>
                @if($canCreateMeetings)<a class="btn pri" href="{{ route('admin', ['tab' => 'rapat', 'edit' => 0]) }}">Buat rapat pertama</a>@endif
            </div>
        @else
            <div class="admin-meeting-list" id="admin-meetings">
            @foreach($meetings as $meeting)
                @php($scheduleStatus = \App\Support\MeetingData::scheduleStatus($meeting))
                @php($isCompletedMeeting = $scheduleStatus === 'selesai')
                @if($isCompletedMeeting)@php($completedMeetingIndex++)@endif
                @php($collapseCompletedMeeting = $isCompletedMeeting && $completedMeetingIndex > 4)
                @php($attendees = $meetingAttendees->get($meeting->id, collect()))
                <article class="admin-meeting-card" data-admin-meeting-card data-meeting-status="{{ $scheduleStatus }}" @if($isCompletedMeeting)data-admin-completed-meeting data-completed-index="{{ $completedMeetingIndex }}" @endif @if($collapseCompletedMeeting)hidden @endif>
                    <div class="admin-meeting-card-main">
                        <div class="admin-meeting-date-icon" aria-hidden="true"><span>◷</span></div>
                        <div class="admin-meeting-card-content">
                            <div class="admin-meeting-card-title-row">
                                <h3>{{ $meeting->judul }}</h3>
                                <span class="admin-meeting-status admin-meeting-status-{{ $scheduleStatus }}"><span></span>{{ $scheduleStatus === 'akan' ? 'Akan datang' : ucfirst($scheduleStatus) }}</span>
                            </div>
                            <div class="admin-meeting-card-meta">
                                <span><b aria-hidden="true">▣</b>{{ \App\Support\MeetingData::date($meeting->tanggal) }}</span>
                                <span><b aria-hidden="true">◷</b>{{ substr($meeting->mulai, 0, 5) }}–{{ substr($meeting->selesai, 0, 5) }} WITA</span>
                                <span><b aria-hidden="true">⌖</b>{{ $meeting->tempat ?: 'Tempat belum ditentukan' }}</span>
                            </div>
                            <div class="admin-meeting-card-actions">
                                @if($canViewAttendance)
                                    <a class="btn sm admin-meeting-attendance" href="{{ route('admin', ['tab' => 'rapat', 'presensi' => $meeting->id]) }}">{{ $isSuperAdmin ? (\App\Support\MeetingData::attendanceOpen($meeting) ? 'Kode '.$meeting->kode_presensi : 'Atur presensi') : 'Lihat presensi' }}</a>
                                @endif
                                @if($canCreateMeetings)
                                    <a class="btn sm" href="{{ route('admin', ['tab' => 'rapat', 'edit' => $meeting->id]) }}">Ubah</a>
                                    <form method="post" action="{{ route('admin.action') }}" onsubmit="return confirm('Hapus rapat ini?')">@csrf<input type="hidden" name="aksi" value="hapus_rapat"><input type="hidden" name="id" value="{{ $meeting->id }}"><button class="btn sm red">Hapus</button></form>
                                @endif
                                @if($canEndMeetings && \App\Support\MeetingData::canManuallyEnd($meeting))
                                    <form method="post" action="{{ route('admin.action') }}" onsubmit="return confirm('Tandai rapat ini sebagai selesai sekarang? Tindakan ini tidak dapat dibatalkan.')">@csrf<input type="hidden" name="aksi" value="selesaikan_rapat"><input type="hidden" name="id" value="{{ $meeting->id }}"><button class="btn sm admin-meeting-end">✓ Selesaikan</button></form>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="admin-meeting-attendance-details">
                        <details class="admin-meeting-details">
                            <summary>Absen masuk <span>{{ $attendees->count() }} pegawai</span></summary>
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
                        <details class="admin-meeting-details">
                            <summary>Daftar absen pulang <span>{{ $attendees->whereNotNull('waktu_pulang')->count() }} pegawai</span></summary>
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
                    </div>
                </article>
            @endforeach
            </div>
        @endif
        @if($meetings->isNotEmpty())
            <script>
                (function () {
                    var page = document.querySelector('.admin-meeting-page');
                    var search = page.querySelector('[data-admin-completed-search]');
                    var cards = Array.from(page.querySelectorAll('[data-admin-meeting-card]'));
                    var filters = Array.from(page.querySelectorAll('[data-admin-meeting-filter]'));
                    var button = page.querySelector('[data-admin-completed-toggle]');
                    var emptyMessage = page.querySelector('[data-admin-meeting-empty]');
                    var activeStatus = 'semua';
                    var expanded = false;

                    function update() {
                        var query = search.value.trim().toLocaleLowerCase();
                        var visibleCount = 0;
                        var matchingCompletedCount = 0;
                        cards.forEach(function (card) {
                            var matchesStatus = activeStatus === 'semua' || card.dataset.meetingStatus === activeStatus;
                            var matchesQuery = card.textContent.toLocaleLowerCase().includes(query);
                            var matches = matchesStatus && matchesQuery;
                            var limited = card.dataset.meetingStatus === 'selesai'
                                && Number(card.dataset.completedIndex) > 4
                                && !expanded
                                && !query;
                            var visible = matches && !limited;
                            card.hidden = !visible;
                            if (visible) visibleCount++;
                            if (matches && card.dataset.meetingStatus === 'selesai') matchingCompletedCount++;
                        });
                        emptyMessage.hidden = visibleCount > 0;
                        if (button) {
                            var canExpand = (activeStatus === 'semua' || activeStatus === 'selesai') && !query && matchingCompletedCount > 4;
                            button.hidden = !canExpand;
                            button.setAttribute('aria-expanded', String(expanded));
                            button.textContent = expanded
                                ? 'Tampilkan lebih sedikit rapat selesai'
                                : 'Tampilkan ' + (matchingCompletedCount - 4) + ' rapat selesai lainnya';
                        }
                    }

                    search.addEventListener('input', update);
                    filters.forEach(function (filter) {
                        filter.addEventListener('click', function () {
                            activeStatus = filter.dataset.adminMeetingFilter;
                            expanded = false;
                            filters.forEach(function (item) {
                                var selected = item === filter;
                                item.classList.toggle('is-active', selected);
                                item.setAttribute('aria-pressed', String(selected));
                            });
                            update();
                        });
                    });
                    if (button) {
                        button.addEventListener('click', function () {
                            expanded = !expanded;
                            update();
                        });
                    }
                    update();
                })();
            </script>
        @endif
        </section>
    @endif

@elseif($tab === 'pengaturan')
    <form method="post" action="{{ route('admin.action') }}" class="box">@csrf<input type="hidden" name="aksi" value="simpan_pengaturan">
        <b>Aturan presensi &amp; kehadiran</b>
        <label><input type="checkbox" name="wajib_izin" value="1" style="width:auto" @checked(\App\Support\MeetingData::setting('wajib_izin', '1') === '1')> Wajib surat izin bila tidak hadir</label>
        <button class="btn pri mt-3">Simpan</button>
    </form>
@endif
@endsection
