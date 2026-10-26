@extends('layouts.app', ['title' => 'Dashboard Rapat'])

@section('content')
<h2 class="mt-0">Jadwal rapat</h2>
<div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
    @foreach(['akan' => 'Akan datang', 'berlangsung' => 'Sedang berlangsung'] as $key => $label)
        <div class="stat"><b>{{ $meetingsByStatus[$key]->count() }}</b><span>{{ $label }}</span></div>
    @endforeach
    @if($canSeeMeetings)<div class="stat"><b>{{ $meetingsByStatus['selesai']->count() }}</b><span>Selesai</span></div>@endif
</div>

@foreach([
    'akan' => ['Rapat akan datang', 'Tidak ada rapat yang akan datang.'],
    'berlangsung' => ['Rapat sedang berlangsung', 'Tidak ada rapat yang sedang berlangsung.'],
    'selesai' => ['Rapat selesai', 'Belum ada rapat yang selesai.'],
] as $key => [$heading, $empty])
    @if($canSeeMeetings || $key !== 'selesai')
        <section class="mt-5">
            <h2>{{ $heading }} <span class="tag {{ $key === 'berlangsung' ? 'hadir' : ($key === 'akan' ? 'akan' : 'selesai') }}">{{ $meetingsByStatus[$key]->count() }}</span></h2>
            @if($key === 'selesai' && $meetingsByStatus[$key]->isNotEmpty())
                <label for="completed-meeting-search">Cari rapat selesai</label>
                <input id="completed-meeting-search" type="search" class="mb-3" placeholder="Cari judul, tanggal, atau tempat rapat..." data-completed-search>
                <p class="mu" data-completed-search-empty hidden>Tidak ada rapat selesai yang cocok dengan pencarian.</p>
            @endif
            @forelse($meetingsByStatus[$key] as $meeting)
                @php($scheduleStatus = \App\Support\MeetingData::scheduleStatus($meeting))
                @php($previewRoom = $meeting->room_id ? $rooms->firstWhere('id', $meeting->room_id) : null)
                @if(!$previewRoom && $meeting->tempat)
                    @php($meetingRoomName = mb_strtolower(trim(preg_replace('/\s+room$/i', '', $meeting->tempat))))
                    @php($previewRoom = $rooms->first(fn ($room) => mb_strtolower(trim(preg_replace('/\s+room$/i', '', $room->nama))) === $meetingRoomName))
                @endif
                <div class="mt {{ $scheduleStatus === 'berlangsung' ? 'hari' : $scheduleStatus }}{{ $key === 'selesai' && $loop->iteration > 3 ? ' hidden' : '' }}" @if($key === 'selesai')data-completed-meeting @if($loop->iteration > 3)hidden @endif @endif>
                    <a class="meeting-card-main {{ $canSeeMeetings ? '' : 'mt-static' }}" @if($canSeeMeetings) href="{{ route('meetings.show', $meeting->id) }}" @endif>
                        <div class="jam">{{ substr($meeting->mulai, 0, 5) }}<small>{{ substr($meeting->selesai, 0, 5) }}</small></div>
                        <div class="grow">
                            <b>{{ $meeting->judul }}</b>
                            <p class="mu">{{ \App\Support\MeetingData::date($meeting->tanggal) }} · {{ $meeting->tempat ?: 'Tempat belum ditentukan' }} · {{ $meeting->moda }} · 👥 {{ $meeting->jumlah_hadir }} hadir</p>
                        </div>
                    </a>
                    @if(\App\Support\MeetingData::attendanceOpen($meeting) && (!$employee || !$meeting->status_kehadiran))<span class="tag hadir">🟢 Presensi buka</span>@endif
                    @if($employee && $meeting->status_kehadiran === 'hadir' && !$meeting->waktu_pulang && \App\Support\MeetingData::checkoutOpen($meeting))<span class="tag hadir">🟢 Presensi pulang buka</span>@endif
                    @if($employee)<span class="tag {{ $meeting->status_kehadiran ?: 'kosong' }}">{{ $meeting->status_kehadiran === 'hadir' ? 'Hadir' : ($meeting->status_kehadiran === 'izin' ? 'Izin '.$meeting->status_izin : 'Belum presensi') }}</span>@endif
                    @if(!$employee && $scheduleStatus === 'berlangsung' && $previewRoom)
                        <a class="btn sm" href="{{ route('live-board', ['room_id' => $previewRoom->id]) }}">Preview Live Board</a>
                    @endif
                    @if($canEndMeetings && \App\Support\MeetingData::canManuallyEnd($meeting))
                        <form method="post" action="{{ route('meetings.action', $meeting->id) }}" onsubmit="return confirm('Selesaikan rapat ini sekarang dan buka presensi pulang selama 30 menit?')">
                            @csrf<input type="hidden" name="aksi" value="selesaikan_rapat">
                            <button class="btn sm red">Selesaikan Rapat</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="mu">{{ $empty }}</p>
            @endforelse
            @if($key === 'selesai' && $meetingsByStatus[$key]->count() > 3)
                <button class="btn mt-2" type="button" data-completed-toggle aria-expanded="false">
                    Tampilkan lainnya ({{ $meetingsByStatus[$key]->count() - 3 }})
                </button>
            @endif
        </section>
    @endif
@endforeach

@if($employee && $canCreateMeetings)
    <section class="mt-6">
        <h2>Tambah rapat</h2>
        <details class="box">
            <summary><b>Jadwalkan rapat / kegiatan baru</b></summary>
            <p class="mu mt-2">Kode presensi tetap dikelola Pengelola Rapat.</p>
            <form method="post" action="{{ route('meetings.create') }}" enctype="multipart/form-data">
                @csrf
                <label>Judul rapat / kegiatan</label><input name="judul" required value="{{ old('judul') }}">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label>Tanggal</label><input type="date" name="tanggal" required value="{{ old('tanggal', now()->toDateString()) }}"></div>
                    <div><label>Moda</label><select name="moda"><option>Luring</option><option>Daring</option><option>Hybrid</option></select></div>
                    <div>@include('partials.meeting-time-select', ['name' => 'mulai', 'label' => 'Mulai', 'selectedTime' => old('mulai', '09:00')])</div>
                    <div>@include('partials.meeting-time-select', ['name' => 'selesai', 'label' => 'Selesai', 'selectedTime' => old('selesai', '11:00')])</div>
                    <div><label>Ruang rapat</label><select name="room_id"><option value="">Tidak memilih ruang</option>@foreach($rooms as $room)<option value="{{ $room->id }}">{{ $room->nama }}</option>@endforeach</select></div>
                    <div><label>Tautan meeting HTTPS (opsional)</label><input type="url" name="link_meeting"></div>
                    <div><label>Pimpinan rapat (opsional)</label><input name="pimpinan" list="employees"></div>
                    <div><label>Notulis (opsional)</label><input name="notulis" list="employees"></div>
                </div>
                <datalist id="employees">@foreach($employees as $person)<option value="{{ $person->nama }}">@endforeach</datalist>
                @include('partials.meeting-participants', ['participantPrefix' => 'employee-meeting'])
                <label>Pemateri (opsional)</label><input name="pemateri">
                <label>Agenda (satu per baris)</label><textarea name="agenda" rows="3"></textarea>
                @if($canEditMinutes)
                    <label>Notulen (opsional)</label><textarea name="notulen" rows="4"></textarea>
                    <label>Berkas notulen PDF (maks. 15 MB)</label><input type="file" name="file_notulen" accept=".pdf,application/pdf">
                @endif
                @if($canUploadMaterials)
                    <label>Materi presentasi PDF/PPT/PPTX (maks. 20 MB)</label><input type="file" name="materi" accept=".pdf,.ppt,.pptx,.odp">
                @endif
                @if($canAddRecordings)
                    <label>Tautan hasil rekaman Google Drive / YouTube (opsional)</label><input type="url" name="link_rekaman">
                @endif
                <button class="btn pri mt-3">Simpan rapat</button>
            </form>
        </details>
    </section>
@endif

<script>
    (function () {
        var search = document.querySelector('[data-completed-search]');
        var button = document.querySelector('[data-completed-toggle]');
        var meetings = Array.from(document.querySelectorAll('[data-completed-meeting]'));
        var emptyMessage = document.querySelector('[data-completed-search-empty]');
        if (!meetings.length) return;

        function update() {
            var query = search ? search.value.trim().toLocaleLowerCase() : '';
            var matchingCount = 0;
            meetings.forEach(function (meeting, index) {
                var matches = !query || meeting.textContent.toLocaleLowerCase().includes(query);
                var visible = matches && (query || index < 3 || (button && button.getAttribute('aria-expanded') === 'true'));
                meeting.hidden = !visible;
                meeting.classList.toggle('hidden', !visible);
                if (visible && query) matchingCount++;
            });
            if (emptyMessage) emptyMessage.hidden = !query || matchingCount > 0;
            if (button) button.hidden = Boolean(query);
        }

        if (search) search.addEventListener('input', update);
        if (button) {
            button.addEventListener('click', function () {
                var expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', String(!expanded));
                button.textContent = expanded ? 'Tampilkan lainnya (' + (meetings.length - 3) + ')' : 'Tampilkan lebih sedikit';
                update();
            });
        }
        update();
    })();
</script>

@if($employee && $waiting->isNotEmpty())
    <section class="box mt-5">
        <b>Menunggu konfirmasi kehadiran Anda ({{ $waiting->count() }})</b>
        @foreach($waiting as $meeting)
            <p class="mt-2"><a href="{{ route('meetings.show', $meeting->id) }}">{{ $meeting->judul }}</a> <span class="mu">· {{ \App\Support\MeetingData::date($meeting->tanggal) }}</span></p>
        @endforeach
    </section>
@endif

@if(!$employee)
<section class="mt-6" aria-labelledby="live-board-links-title">
    <h2 id="live-board-links-title">Live Board Rapat</h2>
    <div class="flex flex-wrap gap-2">
        <a class="btn pri" href="{{ route('live-board') }}">Semua ruang</a>
        @foreach($rooms as $room)
            <a class="btn" href="{{ route('live-board', ['room_id' => $room->id]) }}">Live Board {{ $room->nama }}</a>
        @endforeach
    </div>
</section>
@endif

<section class="box calendar-box grid grid-cols-1 gap-4 mt-6 lg:grid-cols-2" aria-labelledby="calendar-title">
    <div class="calendar-clock">
        <span class="mu">Waktu Manado (WITA)</span>
        <time id="manado-clock" class="calendar-time">--:--:--</time>
        <span id="manado-date" class="mu"></span>
    </div>
    <div class="calendar-content">
        <div class="calendar-heading flex flex-wrap justify-between items-center gap-3">
            <div><h2 id="calendar-title" class="mt-0">Kalender rapat</h2><span id="calendar-month" class="mu"></span></div>
            <div class="flex gap-2">
                <button class="btn sm" id="calendar-prev" type="button" aria-label="Bulan sebelumnya">‹</button>
                <button class="btn sm" id="calendar-today" type="button">Hari ini</button>
                <button class="btn sm" id="calendar-next" type="button" aria-label="Bulan berikutnya">›</button>
            </div>
        </div>
        <div id="calendar-grid" class="calendar-grid" aria-live="polite"></div>
        <div id="calendar-events" class="calendar-events" aria-live="polite">Pilih tanggal bertanda untuk melihat rapat.</div>
    </div>
</section>
<script>
(function () {
    var events = @js($calendarEvents);
    var canOpen = @js($canSeeMeetings);
    var grid = document.getElementById('calendar-grid');
    var monthLabel = document.getElementById('calendar-month');
    var eventList = document.getElementById('calendar-events');
    var dayLabels = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    var parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    var values = {};
    parts.forEach(function (part) { if (part.type !== 'literal') values[part.type] = part.value; });
    var today = values.year + '-' + values.month + '-' + values.day;
    var shown = new Date(Date.UTC(Number(values.year), Number(values.month) - 1, 1));
    var grouped = {};
    events.forEach(function (event) { (grouped[event.date] ||= []).push(event); });

    function renderCalendar() {
        var year = shown.getUTCFullYear();
        var month = shown.getUTCMonth();
        monthLabel.textContent = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'Asia/Makassar' }).format(shown);
        grid.replaceChildren();
        dayLabels.forEach(function (label) {
            var cell = document.createElement('span');
            cell.className = 'calendar-weekday';
            cell.textContent = label;
            grid.appendChild(cell);
        });
        var offset = new Date(Date.UTC(year, month, 1)).getUTCDay();
        for (var blank = 0; blank < offset; blank++) grid.appendChild(document.createElement('span'));
        var days = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
        for (var day = 1; day <= days; day++) {
            var key = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
            var dayEvents = grouped[key] || [];
            var cell = document.createElement(dayEvents.length && canOpen ? 'button' : 'span');
            cell.className = 'calendar-day' + (key === today ? ' is-today' : '') + (dayEvents.length ? ' has-events' : '');
            cell.textContent = day;
            if (dayEvents.length && canOpen) {
                cell.type = 'button';
                cell.addEventListener('click', function (selected, selectedEvents) {
                    return function () {
                        eventList.replaceChildren();
                        var heading = document.createElement('b');
                        heading.textContent = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'Asia/Makassar' }).format(new Date(selected + 'T12:00:00+08:00'));
                        eventList.appendChild(heading);
                        selectedEvents.forEach(function (event) {
                            var link = document.createElement('a');
                            link.href = @js(route('meetings.show', ['meeting' => '__ID__'])).replace('__ID__', encodeURIComponent(event.id));
                            link.textContent = event.title;
                            eventList.appendChild(link);
                        });
                    };
                }(key, dayEvents));
            } else if (dayEvents.length) {
                cell.setAttribute('aria-label', dayEvents.length + ' rapat');
            }
            grid.appendChild(cell);
        }
    }

    function updateClock() {
        var now = new Date();
        document.getElementById('manado-clock').textContent = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Makassar', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(now);
        document.getElementById('manado-date').textContent = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Makassar', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(now);
    }
    document.getElementById('calendar-prev').addEventListener('click', function () { shown.setUTCMonth(shown.getUTCMonth() - 1); renderCalendar(); });
    document.getElementById('calendar-next').addEventListener('click', function () { shown.setUTCMonth(shown.getUTCMonth() + 1); renderCalendar(); });
    document.getElementById('calendar-today').addEventListener('click', function () { shown = new Date(Date.UTC(Number(values.year), Number(values.month) - 1, 1)); renderCalendar(); });
    renderCalendar();
    updateClock();
    window.setInterval(updateClock, 1000);
})();
</script>
@endsection
