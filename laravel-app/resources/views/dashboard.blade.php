@extends('layouts.app', ['title' => 'Dashboard Rapat'])

@section('content')
<div class="dashboard-intro">
    <section class="dashboard-hero">
        <p>Poltekkes Kemenkes Manado</p>
        <h2>Jadwal rapat</h2>
        <span>Pantau rapat yang akan datang dan sedang berlangsung.</span>
    </section>
    <div class="calendar-clock dashboard-intro-clock">
        <span class="mu">Waktu Manado (WITA)</span>
        <time id="manado-clock" class="calendar-time">--:--:--</time>
        <span id="manado-date" class="mu"></span>
    </div>
</div>
@foreach([
    'akan' => ['Rapat akan datang', 'Tidak ada rapat yang akan datang.'],
    'berlangsung' => ['Rapat sedang berlangsung', 'Tidak ada rapat yang sedang berlangsung.'],
    'selesai' => ['Rapat selesai', 'Belum ada rapat yang selesai.'],
] as $key => [$heading, $empty])
    @if($canSeeMeetings || $key !== 'selesai')
        <section class="dashboard-section mt-5">
            <div class="dashboard-section-heading">
                <h2>{{ $heading }}</h2>
                <span class="tag {{ $key === 'berlangsung' ? 'hadir' : ($key === 'akan' ? 'akan' : 'selesai') }}">{{ $meetingsByStatus[$key]->count() }}</span>
            </div>
            @if($key === 'selesai' && $meetingsByStatus[$key]->isNotEmpty())
                <input id="completed-meeting-search" type="search" class="dashboard-search mb-3" aria-label="Cari rapat selesai" placeholder="Cari rapat selesai..." data-completed-search>
                <p class="mu" data-completed-search-empty hidden>Tidak ada rapat selesai yang cocok dengan pencarian.</p>
            @endif
            @if($key === 'akan')
                <div class="upcoming-slider" data-upcoming-slider aria-label="Rapat yang akan datang">
                    <div class="upcoming-slider-track" data-slider-track aria-live="off">
            @elseif($key === 'selesai')
                <div class="completed-meetings-grid">
            @endif
            @forelse($meetingsByStatus[$key] as $meeting)
                @php($scheduleStatus = \App\Support\MeetingData::scheduleStatus($meeting))
                @php($previewRoom = $meeting->room_id ? $rooms->firstWhere('id', $meeting->room_id) : null)
                @if(!$previewRoom && $meeting->tempat)
                    @php($meetingRoomName = mb_strtolower(trim(preg_replace('/\s+room$/i', '', $meeting->tempat))))
                    @php($previewRoom = $rooms->first(fn ($room) => mb_strtolower(trim(preg_replace('/\s+room$/i', '', $room->nama))) === $meetingRoomName))
                @endif
                <div class="mt {{ $scheduleStatus === 'berlangsung' ? 'hari' : $scheduleStatus }}{{ $key === 'akan' ? ' upcoming-slide' : '' }}{{ $key === 'selesai' ? ' completed-meeting-card' : '' }}{{ $key === 'selesai' && $loop->iteration > 3 ? ' hidden' : '' }}" @if($key === 'akan')data-upcoming-slide @endif @if($key === 'selesai')data-completed-meeting @if($loop->iteration > 3)hidden @endif @endif>
                    <a class="meeting-card-main {{ $canSeeMeetings ? '' : 'mt-static' }}" @if($canSeeMeetings) href="{{ route('meetings.show', $meeting->id) }}" @endif>
                        <div class="jam">{{ substr($meeting->mulai, 0, 5) }}<small>{{ substr($meeting->selesai, 0, 5) }}</small></div>
                        <div class="grow">
                            <b>{{ $meeting->judul }}</b>
                            <p class="mu">{{ \App\Support\MeetingData::date($meeting->tanggal) }} · {{ $meeting->tempat ?: 'Tempat belum ditentukan' }} · {{ $meeting->moda }} · 👥 {{ $meeting->jumlah_hadir }} pegawai hadir · {{ $meeting->jumlah_tamu }} tamu</p>
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
                <p class="dashboard-empty">{{ $empty }}</p>
            @endforelse
            @if($key === 'akan')
                    </div>
                    @if($meetingsByStatus[$key]->count() > 1)
                        <div class="upcoming-slider-controls">
                            <button class="btn sm" type="button" data-slider-prev aria-label="Rapat sebelumnya">‹</button>
                            <div class="upcoming-slider-dots" aria-label="Pilih rapat">
                                @foreach($meetingsByStatus[$key] as $index => $meeting)
                                    <button type="button" data-slider-dot="{{ $index }}" aria-label="Tampilkan rapat {{ $index + 1 }}" aria-pressed="{{ $index === 0 ? 'true' : 'false' }}"></button>
                                @endforeach
                            </div>
                            <button class="btn sm" type="button" data-slider-next aria-label="Rapat berikutnya">›</button>
                        </div>
                    @endif
                </div>
            @elseif($key === 'selesai')
                </div>
            @endif
            @if($key === 'selesai' && $meetingsByStatus[$key]->count() > 3)
                <button class="btn mt-2" type="button" data-completed-toggle aria-expanded="false">
                    Tampilkan lainnya ({{ $meetingsByStatus[$key]->count() - 3 }})
                </button>
            @endif
        </section>
    @endif
@endforeach

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

<section class="box calendar-box mt-6" aria-labelledby="calendar-title"
    data-calendar-events='@json($calendarEvents)'
    data-calendar-holidays='@json($indonesianHolidays)'
    data-calendar-can-open="{{ $canSeeMeetings ? 'true' : 'false' }}"
    data-meeting-url="{{ route('meetings.show', ['meeting' => '__ID__']) }}">
    <div class="calendar-content">
        <div class="calendar-heading flex flex-wrap justify-between items-center gap-3">
            <div>
                <h2 id="calendar-title" class="mt-0">Kalender rapat</h2>
                <span id="calendar-month" class="mu"></span>
            </div>
            <div class="flex gap-2">
                <button class="btn sm" id="calendar-prev" type="button" aria-label="Bulan sebelumnya">‹</button>
                <button class="btn sm" id="calendar-today" type="button">Hari ini</button>
                <button class="btn sm" id="calendar-next" type="button" aria-label="Bulan berikutnya">›</button>
            </div>
        </div>
        <div id="calendar-grid" class="calendar-grid" aria-live="polite"></div>
        <div class="calendar-legend" aria-label="Keterangan kalender">
            <span><i class="calendar-legend-holiday"></i>Libur nasional</span>
            <span><i class="calendar-legend-collective"></i>Cuti bersama</span>
            <span><i class="calendar-legend-meeting"></i>Jadwal rapat</span>
        </div>
        <div id="calendar-events" class="calendar-events" aria-live="polite">Pilih tanggal bertanda untuk melihat hari libur atau rapat.</div>
        <small class="calendar-source"><span data-calendar-source-year></span> Rujukan: <a href="https://setneg.go.id/baca/index/inilah_skb_3_menteri_libur_nasional_dan_cuti_bersama_2026" target="_blank" rel="noopener noreferrer">SKB 2026</a> · <a href="https://setneg.go.id/baca/index/inilah_skb_3_menteri_libur_nasional_dan_cuti_bersama_2027" target="_blank" rel="noopener noreferrer">SKB 2027</a></small>
    </div>
</section>
<script>
(function () {
    var calendar = document.querySelector('.calendar-box');
    var events = JSON.parse(calendar.dataset.calendarEvents);
    var holidaysByYear = JSON.parse(calendar.dataset.calendarHolidays);
    var canOpen = calendar.dataset.calendarCanOpen === 'true';
    var meetingUrl = calendar.dataset.meetingUrl;
    var grid = document.getElementById('calendar-grid');
    var monthLabel = document.getElementById('calendar-month');
    var eventList = document.getElementById('calendar-events');
    var sourceYear = document.querySelector('[data-calendar-source-year]');
    var dayLabels = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    var parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    var values = {};
    parts.forEach(function (part) { if (part.type !== 'literal') values[part.type] = part.value; });
    var today = values.year + '-' + values.month + '-' + values.day;
    var shown = new Date(Date.UTC(Number(values.year), Number(values.month) - 1, 1));
    var grouped = {};
    var groupedHolidays = {};
    events.forEach(function (event) { (grouped[event.date] ||= []).push(event); });
    Object.keys(holidaysByYear).forEach(function (holidayYear) {
        holidaysByYear[holidayYear].forEach(function (holiday) {
            (groupedHolidays[holiday.date] ||= []).push(holiday);
        });
    });

    function renderCalendar() {
        var year = shown.getUTCFullYear();
        var month = shown.getUTCMonth();
        var holidays = holidaysByYear[year] || [];
        sourceYear.textContent = holidays.length
            ? 'Hari libur nasional dan cuti bersama ' + year + ' mengikuti SKB 3 Menteri.'
            : 'Data hari libur nasional resmi belum tersedia untuk tahun ' + year + '.';
        monthLabel.textContent = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'Asia/Makassar' }).format(shown);
        grid.replaceChildren();
        dayLabels.forEach(function (label) {
            var cell = document.createElement('span');
            cell.className = 'calendar-weekday';
            cell.textContent = label;
            grid.appendChild(cell);
        });
        var offset = new Date(Date.UTC(year, month, 1)).getUTCDay();
        var days = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
        for (var blank = 0; blank < offset; blank++) grid.appendChild(document.createElement('span'));
        for (var day = 1; day <= days; day++) {
            var key = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
            var dayEvents = grouped[key] || [];
            var dayHolidays = groupedHolidays[key] || [];
            var hasVisibleMeetings = dayEvents.length && canOpen;
            var hasSelectableItems = dayHolidays.length || hasVisibleMeetings;
            var cell = document.createElement(hasSelectableItems ? 'button' : 'span');
            cell.className = 'calendar-day'
                + (key === today ? ' is-today' : '')
                + (hasVisibleMeetings ? ' has-events' : '')
                + (dayHolidays.some(function (holiday) { return holiday.type === 'national'; }) ? ' has-national-holiday' : '')
                + (dayHolidays.some(function (holiday) { return holiday.type === 'collective'; }) ? ' has-collective-leave' : '');
            cell.textContent = day;
            if (hasSelectableItems) {
                cell.type = 'button';
                cell.setAttribute('aria-label', [
                    new Intl.DateTimeFormat('id-ID', { dateStyle: 'full', timeZone: 'UTC' }).format(new Date(key + 'T12:00:00Z')),
                    dayHolidays.map(function (holiday) { return holiday.title; }).join(', '),
                    hasVisibleMeetings ? dayEvents.length + ' rapat' : ''
                ].filter(Boolean).join('; '));
                if (dayHolidays.length) cell.title = dayHolidays.map(function (holiday) { return holiday.title; }).join(', ');
                cell.addEventListener('click', function (selected, selectedEvents, selectedHolidays) {
                    return function () {
                        eventList.replaceChildren();
                        var heading = document.createElement('b');
                        heading.textContent = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'Asia/Makassar' }).format(new Date(selected + 'T12:00:00+08:00'));
                        eventList.appendChild(heading);
                        selectedHolidays.forEach(function (holiday) {
                            var item = document.createElement('span');
                            item.className = 'calendar-holiday-item ' + (holiday.type === 'collective' ? 'is-collective' : 'is-national');
                            item.textContent = (holiday.type === 'collective' ? 'Cuti bersama: ' : 'Libur nasional: ') + holiday.title;
                            eventList.appendChild(item);
                        });
                        if (canOpen) selectedEvents.forEach(function (event) {
                            var link = document.createElement('a');
                            link.href = meetingUrl.replace('__ID__', encodeURIComponent(event.id));
                            link.textContent = event.title;
                            eventList.appendChild(link);
                        });
                    };
                }(key, dayEvents, dayHolidays));
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
