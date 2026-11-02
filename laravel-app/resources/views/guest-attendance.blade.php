@extends('layouts.app', ['title' => 'Presensi Tamu & Mahasiswa'])

@section('content')
<div class="guest-attendance-page">
    <header class="guest-attendance-hero">
        <span class="guest-attendance-eyebrow">Poltekkes Kemenkes Manado</span>
        <h2>Presensi Tamu &amp; Mahasiswa</h2>
        <p>Gunakan satu formulir ini untuk mencatat absen masuk dan absen pulang.</p>
        <div class="guest-attendance-steps">
            <span><b>1</b>Pilih rapat dan isi identitas</span>
            <span><b>2</b>Masukkan kode presensi yang diberikan panitia</span>
        </div>
    </header>

    <form class="guest-attendance-form" method="post" action="{{ route('guest-attendance.store') }}">
        @csrf
        <div class="guest-attendance-form-heading">
            <span>Formulir presensi</span>
            <small>Kolom bertanda * wajib diisi.</small>
        </div>

        @if(session('status') === 'Absen masuk tamu/mahasiswa berhasil dicatat.')
            <p class="guest-attendance-notice">Absen masuk sudah tercatat. Rapat dan identitas Anda tetap terisi untuk memudahkan absen pulang; masukkan kode pulang saat diberikan panitia.</p>
        @endif

        @if($meetings->isEmpty())
            <p class="guest-attendance-notice">Belum ada rapat yang membuka presensi. Formulir dapat diisi, tetapi pengiriman akan tersedia setelah presensi rapat dibuka.</p>
        @endif

        <label for="guest-meeting">Rapat yang dihadiri <span>*</span></label>
        <select id="guest-meeting" name="meeting_id" required @disabled($meetings->isEmpty())>
            @if($meetings->isEmpty())
                <option value="">Belum ada rapat yang membuka presensi</option>
            @else
                <option value="">Pilih rapat</option>
                @foreach($meetings as $meeting)
                    @php($checkInOpen = \App\Support\MeetingData::scheduleStatus($meeting) === 'berlangsung' && \App\Support\MeetingData::attendanceOpen($meeting))
                    <option value="{{ $meeting->id }}" @selected($selectedMeetingId === (string) $meeting->id)>
                        {{ $meeting->judul }} — {{ \App\Support\MeetingData::date($meeting->tanggal) }} ({{ $checkInOpen ? 'Absen masuk dibuka' : 'Absen pulang dibuka' }})
                    </option>
                @endforeach
            @endif
        </select>

        <label for="guest-type">Jenis peserta <span>*</span></label>
        <select id="guest-type" name="jenis" required>
            <option value="tamu" @selected(old('jenis', 'tamu') === 'tamu')>Tamu / undangan</option>
            <option value="mahasiswa" @selected(old('jenis') === 'mahasiswa')>Mahasiswa</option>
        </select>

        <label for="guest-name">Nama lengkap <span>*</span></label>
        <input id="guest-name" name="nama" value="{{ old('nama') }}" autocomplete="name" maxlength="150" required>

        <label for="guest-origin">Asal instansi / program studi <span>*</span></label>
        <input id="guest-origin" name="asal" value="{{ old('asal') }}" maxlength="200" placeholder="Contoh: Poltekkes Kemenkes Manado" required>

        <div class="guest-student-id" data-guest-student-id @if(old('jenis') !== 'mahasiswa') hidden @endif>
            <label for="guest-nim">NIM <span>*</span></label>
            <input id="guest-nim" name="nim" value="{{ old('nim') }}" maxlength="30" autocomplete="off" @required(old('jenis') === 'mahasiswa')>
        </div>

        <div class="guest-attendance-code-box">
            <span class="guest-attendance-code-icon" aria-hidden="true">✓</span>
            <div>
                <label for="guest-code">Kode presensi hadir / pulang <span>*</span></label>
                <p>Gunakan satu kolom ini untuk absen hadir dan pulang. Masukkan kode sesuai tahap presensi.</p>
            </div>
            <input id="guest-code" name="kode" autocomplete="off" maxlength="20" required>
        </div>

        <div class="guest-attendance-honeypot" aria-hidden="true">
            <label for="guest-website">Website</label>
            <input id="guest-website" name="website" tabindex="-1" autocomplete="off">
        </div>
        <button class="btn pri guest-attendance-submit" type="submit" @disabled($meetings->isEmpty())>Kirim presensi</button>
    </form>
</div>
<script>
    (function () {
        var type = document.getElementById('guest-type');
        var studentId = document.querySelector('[data-guest-student-id]');
        var nim = document.getElementById('guest-nim');
        if (!type || !studentId || !nim) return;
        function updateStudentField() {
            var isStudent = type.value === 'mahasiswa';
            studentId.hidden = !isStudent;
            nim.required = isStudent;
        }
        type.addEventListener('change', updateStudentField);
        updateStudentField();
    })();
</script>
@endsection
