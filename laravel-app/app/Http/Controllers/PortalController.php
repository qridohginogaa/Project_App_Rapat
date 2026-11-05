<?php

namespace App\Http\Controllers;

use App\Support\MeetingData;
use App\Support\MeetingReportDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    private const DEFAULT_INVITE_GROUP = 'Lainnya / belum dikelompokkan';

    private function isEmployeeAdmin(?object $employee): bool
    {
        return $employee && DB::table('admin_pegawai')->where('pegawai_id', $employee->id)->exists();
    }

    private function isEmployeeSuperAdmin(?object $employee): bool
    {
        return $employee && DB::table('super_admin_permissions')
            ->where('pegawai_id', $employee->id)
            ->where('is_super_admin', 1)
            ->exists();
    }

    private function isLastSuperAdmin(int $employeeId): bool
    {
        return DB::table('super_admin_permissions')
            ->where('pegawai_id', $employeeId)
            ->where('is_super_admin', true)
            ->exists()
            && DB::table('super_admin_permissions')->where('is_super_admin', true)->count() <= 1;
    }

    private function isAdminMode(): bool
    {
        $employee = $this->employee();
        return $this->isSuperAdminMode()
            || (session('active_role') === 'admin' && $this->isEmployeeAdmin($employee));
    }

    private function isSuperAdminMode(): bool
    {
        return $this->isEmployeeSuperAdmin($this->employee());
    }

    private function employee(): ?object
    {
        session()->forget('admin_id');

        $id = session('employee_id');
        $hash = session('employee_password_hash');
        if (!$id || !is_string($hash)) {
            return null;
        }

        $employee = DB::table('pegawai')->where('id', $id)->first();
        if (!$employee || !$employee->password_hash || !hash_equals($employee->password_hash, $hash)) {
            session()->forget(['employee_id', 'employee_password_hash']);
            return null;
        }

        return $employee;
    }

    private function requireEmployeeOrAdmin(): object|false
    {
        $employee = $this->employee();
        abort_unless($employee || $this->isAdminMode(), 403);
        return $employee ?: false;
    }

    private function superAdminPermissions(?object $employee): array
    {
        $permissions = [
            'buat_rapat'        => false,
            'materi'            => false,
            'rekaman'           => false,
            'notulen'           => false,
            'lihat_daftar_hadir'=> false,
            'kelola_ruang'      => false,
            'kelola_grup_undangan' => false,
        ];

        if (!$employee || !$this->isEmployeeAdmin($employee)) {
            return $permissions;
        }

        $record = DB::table('super_admin_permissions')->where('pegawai_id', $employee->id)->first();
        if ($record && $record->is_super_admin) {
            return array_fill_keys(array_keys($permissions), true);
        }

        $managerAccess = DB::table('manager_access_settings')->where('id', 1)->first();
        if ($managerAccess) {
            foreach ($permissions as $key => $value) {
                if (isset($managerAccess->$key)) {
                    $permissions[$key] = (bool) $managerAccess->$key;
                }
            }
        }

        return $permissions;
    }

    private function employeeInviteList()
    {
        return DB::table('pegawai as p')
            ->leftJoin('pegawai_invite_groups as g', 'g.pegawai_id', '=', 'p.id')
            ->select('p.id', 'p.nip', 'p.nama', 'p.jabatan')
            ->selectRaw('COALESCE(NULLIF(g.group_name, \'\'), ?) as invite_group', [self::DEFAULT_INVITE_GROUP])
            ->orderBy('invite_group')
            ->orderBy('p.nama')
            ->get();
    }

    private function inviteGroups()
    {
        return DB::table('pegawai_invite_group_catalog')
            ->orderBy('group_name')
            ->get();
    }

    private function redirectToDashboard(string $anchor = ''): \Illuminate\Http\RedirectResponse
    {
        return redirect()->to(route('dashboard').($anchor ? '#'.$anchor : ''));
    }

    public function dashboard()
    {
        $employee = $this->employee();
        if ($employee && $employee->password_change_required) {
            return redirect()->route('employee.password');
        }

        $meetings = DB::table('rapat as r')
            ->leftJoin('kehadiran as k', function ($join) use ($employee) {
                $join->on('k.rapat_id', '=', 'r.id')->where('k.pegawai_id', '=', $employee->id ?? 0);
            })
            ->select('r.*', 'k.status as status_kehadiran', 'k.status_izin', 'k.waktu', 'k.waktu_pulang')
            ->selectSub(
                DB::table('kehadiran as hadir')->selectRaw('COUNT(*)')->whereColumn('hadir.rapat_id', 'r.id')->where('hadir.status', 'hadir'),
                'jumlah_hadir'
            )
            ->selectSub(
                DB::table('kehadiran_tamu')->selectRaw('COUNT(*)')->whereColumn('kehadiran_tamu.rapat_id', 'r.id'),
                'jumlah_tamu'
            )
            ->orderBy('r.tanggal')
            ->orderBy('r.mulai')
            ->get();
        MeetingData::assignSlugs($meetings);

        $byStatus = ['akan' => collect(), 'berlangsung' => collect(), 'selesai' => collect(), 'dibatalkan' => collect()];
        $waiting = collect();
        foreach ($meetings as $meeting) {
            $status = MeetingData::scheduleStatus($meeting);
            $byStatus[$status]->push($meeting);
            if ($employee && in_array($status, ['akan', 'berlangsung'], true) && !$meeting->status_kehadiran && $meeting->tanggal <= now()->addDays(7)->toDateString()) {
                $waiting->push($meeting);
            }
        }
        $byStatus['selesai'] = $byStatus['selesai']->sortByDesc(fn ($meeting) => $meeting->tanggal.$meeting->mulai)->values();
        $byStatus['dibatalkan'] = $byStatus['dibatalkan']->sortByDesc(fn ($meeting) => $meeting->tanggal.$meeting->mulai)->values();
        $calendarEvents = $meetings->filter(fn ($meeting) => $employee || $this->isAdminMode() || MeetingData::scheduleStatus($meeting) !== 'selesai')
            ->map(fn ($meeting) => ['date' => $meeting->tanggal, 'title' => $meeting->judul, 'id' => (int) $meeting->id, 'slug' => $meeting->meeting_slug])
            ->values();
        $permissions = $this->superAdminPermissions($employee);

        return view('dashboard', [
            'employee' => $employee,
            'isAdmin' => $this->isAdminMode(),
            'isSuperAdmin' => $this->isSuperAdminMode() || $this->isEmployeeSuperAdmin($employee),
            'canEndMeetings' => $this->isAdminMode(),
            'canSwitchRole' => $this->isEmployeeAdmin($employee),
            'canAccessAdminMenu' => $this->isSuperAdminMode()
                || ($this->isEmployeeAdmin($employee) && in_array(session('active_role'), ['admin', 'super_admin'], true)),
            'activeRole' => session('active_role', 'pegawai'),
            'canManageRoles' => $this->isSuperAdminMode(),
            'canManageRooms' => $this->isSuperAdminMode(),
            'canManageInviteGroups' => $permissions['kelola_grup_undangan'],
            'canCreateMeetings' => $this->isSuperAdminMode() || $permissions['buat_rapat'],
            'canUploadMaterials' => $this->isSuperAdminMode() || $permissions['materi'],
            'canAddRecordings' => $this->isSuperAdminMode() || $permissions['rekaman'],
            'canEditMinutes' => $this->isSuperAdminMode() || $permissions['notulen'],
            'canViewAttendance' => $this->isSuperAdminMode() || $permissions['lihat_daftar_hadir'],
            'canSeeMeetings' => (bool) ($employee || $this->isAdminMode()),
            'meetingsByStatus' => $byStatus,
            'waiting' => $waiting,
            'calendarEvents' => $calendarEvents,
            'indonesianHolidays' => config('indonesian_holidays'),
            'rooms' => DB::table('ruang_rapat')->orderBy('nama')->get(),
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);

        if (preg_match('/^\d{18}$/', $data['identifier'])) {
            $employee = DB::table('pegawai')->where('nip', $data['identifier'])->first();
            if (!$employee || !$employee->password_hash || !password_verify($data['password'], $employee->password_hash)) {
                return back()->withErrors(['login' => 'NIP atau kata sandi tidak sesuai.'])
                    ->onlyInput('identifier');
            }

            $request->session()->regenerate();
            $request->session()->forget('admin_id');
            $request->session()->put([
                'employee_id' => (int) $employee->id,
                'employee_password_hash' => $employee->password_hash,
                'active_role' => $this->isEmployeeSuperAdmin($employee)
                    ? 'super_admin'
                    : ($this->isEmployeeAdmin($employee) ? 'admin' : 'pegawai'),
                'can_switch_role' => $this->isEmployeeAdmin($employee),
            ]);

            if ($employee->password_change_required) {
                return redirect()->route('employee.password');
            }

            return $this->redirectToDashboard();
        }

        return back()->withErrors(['login' => 'NIP atau kata sandi tidak sesuai.'])
            ->onlyInput('identifier');
    }

    public function loginForm()
    {
        if ($this->employee()) {
            return $this->redirectToDashboard();
        }
        return view('login');
    }

    public function employeeLogin(Request $request)
    {
        $request->merge(['identifier' => $request->input('nip')]);
        return $this->login($request);
    }

    public function switchRole(Request $request)
    {
        $employee = $this->employee();
        abort_unless($this->isEmployeeAdmin($employee), 403);
        $data = $request->validate(['role' => ['required', 'in:pegawai,admin']]);
        $request->session()->put('active_role', $data['role']);

        return $this->redirectToDashboard()->with(
            'status',
            $data['role'] === 'admin' ? 'Fitur Pengelola Rapat diaktifkan.' : 'Mode Pegawai diaktifkan.'
        );
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('dashboard');
    }

    public function employeePasswordForm()
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);

        if (!$employee->password_change_required) {
            return $this->redirectToDashboard();
        }

        return view('employee-password', compact('employee'));
    }

    public function updateEmployeePassword(Request $request)
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);

        if (!$employee->password_change_required) {
            return $this->redirectToDashboard();
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $passwordHash = Hash::make($data['password']);
        DB::table('pegawai')->where('id', $employee->id)->update([
            'password_hash' => $passwordHash,
            'password_change_required' => false,
        ]);
        $request->session()->put('employee_password_hash', $passwordHash);

        return $this->redirectToDashboard()->with('status', 'Kata sandi berhasil diubah.');
    }

    public function employeeLogout(Request $request)
    {
        return $this->logout($request);
    }

    public function profile()
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);

        return view('profile', compact('employee'));
    }

    public function updateProfilePassword(Request $request)
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);
        if (!Hash::check($data['current_password'], $employee->password_hash)) {
            return back()->withErrors(['current_password' => 'Kata sandi saat ini tidak sesuai.'])
                ->withInput($request->except(['current_password', 'password', 'password_confirmation']));
        }

        $passwordHash = Hash::make($data['password']);
        DB::table('pegawai')->where('id', $employee->id)->update([
            'password_hash' => $passwordHash,
        ]);
        $request->session()->put('employee_password_hash', $passwordHash);

        return redirect()->route('profile')->with('status', 'Kata sandi berhasil diubah.');
    }

    public function updateProfilePhoto(Request $request)
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);

        $data = $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $filename = $this->storeUpload($data['profile_photo']);
        DB::table('pegawai')->where('id', $employee->id)->update([
            'profile_photo' => $filename,
        ]);

        if ($employee->profile_photo) {
            Storage::disk('meeting_uploads')->delete($employee->profile_photo);
        }

        return redirect()->route('profile')->with('status', 'Foto profil berhasil diperbarui.');
    }

    public function profilePhoto()
    {
        $employee = $this->employee();
        abort_unless($employee !== null && $employee->profile_photo, 404);

        $disk = Storage::disk('meeting_uploads');
        abort_unless($disk->exists($employee->profile_photo), 404);
        $mimeType = $disk->mimeType($employee->profile_photo);
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response()->file($disk->path($employee->profile_photo), [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function createMeeting(Request $request)
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);
        $permissions = $this->superAdminPermissions($employee);
        $isSuperAdmin = $this->isSuperAdminMode();
        abort_unless($isSuperAdmin || $permissions['buat_rapat'], 403);

        $data = $request->validate([
            'judul' => ['required', 'string', 'max:200'],
            'tanggal' => ['required', 'date'],
            'mulai' => ['required', 'date_format:H:i'],
            'selesai' => ['required', 'date_format:H:i'],
            'moda' => ['required', 'in:Luring,Daring,Hybrid'],
            'room_id' => ['nullable', 'integer', 'exists:ruang_rapat,id'],
            'link_meeting' => ['nullable', 'url', 'starts_with:https://'],
            'link_rekaman' => ['nullable', 'url', 'regex:#^https://(drive|docs)\.google\.com/|^https://(www\.)?youtube\.com/|^https://youtu\.be/#i'],
            'pimpinan' => ['nullable', 'string', 'max:200'],
            'notulis' => ['nullable', 'string', 'max:200'],
            'pimpinan_pegawai_id' => ['nullable', 'integer', 'exists:pegawai,id'],
            'notulis_pegawai_id' => ['nullable', 'integer', 'exists:pegawai,id'],
            'pemateri' => ['nullable', 'string', 'max:200'],
            'agenda' => ['nullable', 'string'],
            'batas_notulen_hari' => ['prohibited'],
            'notulen' => ['prohibited'],
            'file_notulen' => ['prohibited'],
            'materi' => ['nullable', 'file', 'mimes:pdf,ppt,pptx,odp,doc,docx,xls,xlsx,ods,zip,jpg,jpeg,png', 'max:20480'],
            'semua_pegawai' => ['sometimes', 'boolean'],
            'peserta' => ['nullable', 'array'],
            'peserta.*' => ['integer', 'distinct', 'exists:pegawai,id'],
        ], [
            'judul.required' => 'Judul rapat wajib diisi.',
            'tanggal.required' => 'Tanggal rapat wajib diisi.',
            'mulai.required' => 'Waktu mulai rapat wajib diisi.',
            'mulai.date_format' => 'Waktu mulai harus menggunakan format 24 jam HH:MM.',
            'selesai.required' => 'Waktu selesai rapat wajib diisi.',
            'selesai.date_format' => 'Waktu selesai harus menggunakan format 24 jam HH:MM.',
            'moda.required' => 'Moda rapat wajib dipilih.',
            'peserta.array' => 'Daftar pegawai undangan tidak valid.',
            'peserta.*.exists' => 'Salah satu pegawai undangan tidak ditemukan. Silakan pilih ulang.',
        ], [
            'judul' => 'judul rapat',
            'tanggal' => 'tanggal rapat',
            'mulai' => 'waktu mulai',
            'selesai' => 'waktu selesai',
            'moda' => 'moda rapat',
            'peserta' => 'pegawai undangan',
        ]);
        if ($data['selesai'] <= $data['mulai']) {
            return back()->withErrors(['selesai' => 'Waktu selesai harus setelah waktu mulai.'])->withInput();
        }
        if (!$request->boolean('semua_pegawai') && empty($data['peserta'])) {
            return back()->withErrors(['peserta' => 'Pilih rapat untuk semua pegawai atau centang minimal satu pegawai undangan.'])->withInput();
        }
        abort_unless($this->isSuperAdminMode() || $permissions['materi'] || !$request->hasFile('materi'), 403);
        abort_unless($this->isSuperAdminMode() || $permissions['rekaman'] || empty($data['link_rekaman']), 403);
        $room = !empty($data['room_id'])
            ? DB::table('ruang_rapat')->where('id', $data['room_id'])->first()
            : null;
        if (!empty($data['room_id']) && !$room) {
            return back()->withErrors(['room_id' => 'Pilih ruang rapat yang tersedia.'])->withInput();
        }
        $material = $request->file('materi');
        $materialFilename = $material ? $this->storeUpload($material) : null;
        $participantIds = $request->boolean('semua_pegawai') ? [] : ($data['peserta'] ?? []);

        $signerIds = array_values(array_filter([
            $data['pimpinan_pegawai_id'] ?? null,
            $data['notulis_pegawai_id'] ?? null,
        ]));
        $signers = $signerIds
            ? DB::table('pegawai')->whereIn('id', $signerIds)->get(['id', 'nama'])->keyBy('id')
            : collect();
        $meetingId = DB::transaction(function () use ($data, $room, $material, $materialFilename, $employee, $participantIds, $signers) {
            $meetingId = DB::table('rapat')->insertGetId([
                'judul' => $data['judul'],
                'tanggal' => $data['tanggal'],
                'mulai' => $data['mulai'],
                'selesai' => $data['selesai'],
                'tempat' => $room->nama ?? '',
                'room_id' => $room->id ?? null,
                'link_meeting' => $data['link_meeting'] ?? null,
                'moda' => $data['moda'],
                'pimpinan' => $signers[$data['pimpinan_pegawai_id'] ?? 0]->nama ?? ($data['pimpinan'] ?? ''),
                'pimpinan_pegawai_id' => $data['pimpinan_pegawai_id'] ?? null,
                'notulis' => $signers[$data['notulis_pegawai_id'] ?? 0]->nama ?? ($data['notulis'] ?? ''),
                'notulis_pegawai_id' => $data['notulis_pegawai_id'] ?? null,
                'pemateri' => $data['pemateri'] ?? '',
                'agenda' => $data['agenda'] ?? '',
                'link_rekaman' => $data['link_rekaman'] ?? null,
                'materi_rapat' => $materialFilename,
            ]);
            $this->saveMeetingParticipants($meetingId, $participantIds);
            if ($material) {
                DB::table('berkas_rapat')->insert([
                    'rapat_id' => $meetingId,
                    'kategori' => 'materi',
                    'nama_file' => $materialFilename,
                    'nama_asli' => basename($material->getClientOriginalName()),
                    'pengunggah' => $employee->nama,
                ]);
            }
            return $meetingId;
        });
        $this->syncMeetingSignatures(
            $meetingId,
            isset($data['pimpinan_pegawai_id']) ? (int) $data['pimpinan_pegawai_id'] : null,
            isset($data['notulis_pegawai_id']) ? (int) $data['notulis_pegawai_id'] : null,
            true
        );

        return redirect('/#pencarianPegawai')->with('success', 'Rapat berhasil ditambahkan. Kode presensi dikelola Super Admin.');
    }

    public function liveBoard(Request $request)
    {
        $rooms = DB::table('ruang_rapat')->orderBy('nama')->get();
        $roomId = $request->query('room_id');
        $selectedRoom = null;

        if ($roomId !== null) {
            abort_unless(filter_var($roomId, FILTER_VALIDATE_INT) !== false, 404);
            $selectedRoom = $rooms->firstWhere('id', (int) $roomId);
            abort_unless($selectedRoom !== null, 404);
        }

        return view('live-board', compact('rooms', 'selectedRoom'));
    }

    public function liveBoardData(Request $request)
    {
        $roomId = $request->query('room_id');
        $room = null;
        if ($roomId !== null) {
            abort_unless(filter_var($roomId, FILTER_VALIDATE_INT) !== false, 404);
            $room = DB::table('ruang_rapat')->where('id', (int) $roomId)->first();
            abort_unless($room !== null, 404);
        }

        $meetings = DB::table('rapat')
            ->when($room, fn ($query) => $query->where('room_id', $room->id))
            ->orderBy('mulai')
            ->get()
            ->filter(fn ($meeting) => MeetingData::scheduleStatus($meeting) === 'berlangsung')
            ->values();

        $attendeeCounts = $meetings->isEmpty()
            ? collect()
            : DB::table('kehadiran')
                ->whereIn('rapat_id', $meetings->pluck('id'))
                ->where('status', 'hadir')
                ->select('rapat_id')
                ->selectRaw('COUNT(*) as attendee_count')
                ->groupBy('rapat_id')
                ->pluck('attendee_count', 'rapat_id');

        return response()->json([
            'updated_at' => now()->toIso8601String(),
            'meetings' => $meetings->map(function ($meeting) use ($attendeeCounts) {
                return [
                    'id' => (int) $meeting->id,
                    'title' => $meeting->judul,
                    'date' => $meeting->tanggal,
                    'start' => substr($meeting->mulai, 0, 5),
                    'started_at' => \Illuminate\Support\Carbon::parse($meeting->started_at, config('app.timezone'))->toIso8601String(),
                    'end' => substr($meeting->selesai, 0, 5),
                    'scheduled_end_at' => \Illuminate\Support\Carbon::parse($meeting->tanggal.' '.$meeting->selesai, config('app.timezone'))->toIso8601String(),
                    'place' => $meeting->tempat ?: 'Tempat belum ditentukan',
                    'mode' => $meeting->moda,
                    'attendee_count' => (int) ($attendeeCounts[$meeting->id] ?? 0),
                ];
            })->values(),
        ]);
    }

    public function exportAttendance(int $meeting, string $type)
    {
        abort_unless($this->isAdminMode(), 403);
        $employee = $this->employee();
        $permissions = $this->superAdminPermissions($employee);
        abort_unless($this->isSuperAdminMode() || $permissions['lihat_daftar_hadir'], 403);

        $meetingRecord = DB::table('rapat')->where('id', $meeting)->first();
        abort_unless($meetingRecord && MeetingData::scheduleStatus($meetingRecord) === 'selesai', 404);

        if ($type === 'pegawai') {
            $headers = ['Nama', 'NIP', 'Absen masuk', 'Absen pulang', 'Keterangan absen pulang'];
            $attendees = DB::table('kehadiran as k')
                ->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
                ->where('k.rapat_id', $meeting)
                ->where('k.status', 'hadir')
                ->select('p.nama', 'p.nip', 'k.waktu', 'k.waktu_pulang', 'k.alasan_pulang')
                ->orderBy('k.waktu')
                ->get();
            $attendanceRows = $attendees->map(fn ($person) => [
                $person->nama,
                $person->nip,
                $person->waktu ? substr($person->waktu, 11, 5).' WITA' : '—',
                $person->waktu_pulang ? substr($person->waktu_pulang, 11, 5).' WITA' : '—',
                $person->waktu_pulang
                    ? 'Sudah Absen Pulang'
                    : ($person->alasan_pulang
                        ? 'Pulang lebih awal: '.$person->alasan_pulang
                        : 'Belum Absen Pulang; alasan belum diisi.'),
            ]);
            $worksheet = 'Presensi Pegawai';
        } else {
            $headers = ['Nama', 'Jenis peserta', 'Instansi / program studi', 'NIM', 'Absen masuk', 'Absen pulang'];
            $attendees = DB::table('kehadiran_tamu')
                ->where('rapat_id', $meeting)
                ->select('nama', 'jenis', 'asal', 'nim', 'waktu', 'waktu_pulang')
                ->orderBy('waktu')
                ->get();
            $attendanceRows = $attendees->map(fn ($guest) => [
                $guest->nama,
                ucfirst($guest->jenis),
                $guest->asal,
                $guest->nim ?: '—',
                $guest->waktu ? substr($guest->waktu, 11, 5).' WITA' : '—',
                $guest->waktu_pulang ? substr($guest->waktu_pulang, 11, 5).' WITA' : 'Belum absen pulang',
            ]);
            $worksheet = 'Presensi Tamu';
        }

        $rows = [
            ['Judul rapat', $meetingRecord->judul],
            ['Tanggal rapat', MeetingData::date($meetingRecord->tanggal)],
            ['Jenis presensi', $type === 'pegawai' ? 'Pegawai' : 'Tamu / mahasiswa'],
            [],
            $headers,
            ...$attendanceRows->all(),
        ];
        $xmlRows = collect($rows)->map(function (array $row) {
            $cells = collect($row)->map(fn ($value) => '<Cell><Data ss:Type="String">'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Data></Cell>')->implode('');
            return '<Row>'.$cells.'</Row>';
        })->implode('');
        $filename = Str::slug($meetingRecord->judul).'-presensi-'.$type.'.xls';

        return response()->streamDownload(function () use ($xmlRows, $worksheet) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">';
            echo '<Worksheet ss:Name="'.$worksheet.'"><Table>'.$xmlRows.'</Table></Worksheet></Workbook>';
        }, $filename, ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function exportMeetingReport(int $meeting)
    {
        $employee = $this->employee();
        $adminMode = $this->isAdminMode();
        abort_unless($employee !== null || $adminMode, 403);
        $permissions = $this->superAdminPermissions($employee);
        abort_unless($employee !== null || $this->isSuperAdminMode() || $permissions['lihat_daftar_hadir'], 403);

        $meetingRecord = DB::table('rapat')->where('id', $meeting)->first();
        abort_unless($meetingRecord !== null, 404);
        if (MeetingData::scheduleStatus($meetingRecord) !== 'selesai' || trim((string) $meetingRecord->notulen) === '') {
            return back()->withErrors(['notulen' => 'PDF final tersedia setelah rapat selesai dan notulen diisi.']);
        }

        $reportData = $this->meetingReportData($meetingRecord);
        $documentHash = $this->meetingReportHash($meetingRecord, $reportData);
        $this->resetStaleMeetingSignatures($meeting, $documentHash);
        $signatures = DB::table('meeting_signatures as s')
            ->join('pegawai as p', 'p.id', '=', 's.pegawai_id')
            ->where('s.rapat_id', $meeting)
            ->orderByRaw("CASE s.peran WHEN 'pimpinan' THEN 0 ELSE 1 END")
            ->get(['s.token', 'p.nama as pegawai_nama']);

        $pdf = MeetingReportDocument::render(
            $meetingRecord,
            $reportData['attendanceRows']->all(),
            $reportData['followUps']->all(),
            $signatures->all(),
            rtrim((string) config('app.url'), '/').'/signatures/verify'
        );
        $filename = (Str::slug($meetingRecord->judul) ?: 'rapat').'-notulen-daftar-hadir.pdf';

        return response()->streamDownload(
            fn () => print($pdf),
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    public function exportMeetingReportBySlug(string $meetingSlug)
    {
        $meeting = MeetingData::findBySlug($meetingSlug);
        abort_unless($meeting !== null, 404);

        return $this->exportMeetingReport((int) $meeting->id);
    }

    public function signatureInbox()
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);
        MeetingData::markCancelledSignatureNotificationsSeen((int) $employee->id);
        $requests = DB::table('meeting_signatures as s')
            ->join('rapat as r', 'r.id', '=', 's.rapat_id')
            ->where('s.pegawai_id', $employee->id)
            ->orderByDesc('r.tanggal')
            ->orderByDesc('r.mulai')
            ->get([
                'r.id as id',
                's.id as signature_id',
                's.rapat_id',
                'r.room_id',
                's.peran',
                's.status',
                's.disetujui_pada',
                's.document_hash',
                'r.judul',
                'r.tanggal',
                'r.mulai',
                'r.selesai',
                'r.tempat',
                'r.moda',
                'r.link_meeting',
                'r.pimpinan',
                'r.notulis',
                'r.agenda',
                'r.pemateri',
                'r.notulen',
                'r.started_at',
                'r.ended_at',
                'r.cancelled_at',
            ])
            ->map(function ($signature) {
                $signature->can_sign = trim((string) $signature->notulen) !== ''
                    && MeetingData::scheduleStatus($signature) === 'selesai';

                if ($signature->can_sign) {
                    $meeting = DB::table('rapat')->where('id', $signature->rapat_id)->first();
                    $documentHash = $this->meetingReportHash($meeting, $this->meetingReportData($meeting));
                    $this->resetStaleMeetingSignatures($signature->rapat_id, $documentHash);
                    $current = DB::table('meeting_signatures')->where('id', $signature->signature_id)->first();
                    $signature->status = $current->status;
                    $signature->disetujui_pada = $current->disetujui_pada;
                }

                return $signature;
            })
            ->values();

        return view('signatures.index', [
            'employee' => $employee,
            'signatures' => $requests,
            'pendingSignatureCount' => MeetingData::pendingSignatureNotificationCount((int) $employee->id),
        ]);
    }

    public function acceptMeetingSignature(Request $request, int $meeting, string $role)
    {
        abort_unless(in_array($role, ['pimpinan', 'notulis'], true), 404);
        $employee = $this->employee();
        abort_unless($employee !== null, 403);
        $meetingRecord = DB::table('rapat')->where('id', $meeting)->first();
        abort_unless($meetingRecord !== null, 404);
        abort_unless(
            (int) $meetingRecord->{$role.'_pegawai_id'} === (int) $employee->id,
            403
        );

        if (MeetingData::scheduleStatus($meetingRecord) !== 'selesai' || trim((string) $meetingRecord->notulen) === '') {
            return back()->withErrors(['tanda_tangan' => 'Notulen dapat disetujui setelah rapat selesai dan notulen diisi.']);
        }

        $reportData = $this->meetingReportData($meetingRecord);
        $documentHash = $this->meetingReportHash($meetingRecord, $reportData);
        $this->resetStaleMeetingSignatures($meeting, $documentHash);
        $signature = DB::table('meeting_signatures')
            ->where('rapat_id', $meeting)
            ->where('peran', $role)
            ->where('pegawai_id', $employee->id)
            ->first();
        abort_unless($signature !== null, 404);

        if ($signature->status !== 'disetujui') {
            DB::table('meeting_signatures')->where('id', $signature->id)->update([
                'status' => 'disetujui',
                'disetujui_pada' => now(),
                'document_hash' => $documentHash,
                'updated_at' => now(),
            ]);
        }

        return redirect()->route('signatures.index')->with('status', 'Persetujuan tanda tangan berhasil dicatat.');
    }

    public function verifyMeetingSignature(string $token)
    {
        $signature = DB::table('meeting_signatures as s')
            ->join('rapat as r', 'r.id', '=', 's.rapat_id')
            ->join('pegawai as p', 'p.id', '=', 's.pegawai_id')
            ->where('s.token', $token)
            ->first([
                's.peran',
                's.status',
                's.disetujui_pada',
                's.document_hash',
                's.rapat_id',
                'r.judul',
                'r.tanggal',
                'r.mulai',
                'r.selesai',
                'r.tempat',
                'r.moda',
                'r.link_meeting',
                'r.pimpinan',
                'r.notulis',
                'r.agenda',
                'r.pemateri',
                'r.notulen',
                'r.ended_at',
                'p.nama as pegawai_nama',
                'p.nip as pegawai_nip',
                'p.jabatan as pegawai_jabatan',
            ]);
        $verificationCode = implode('-', str_split(strtoupper(substr($token, 0, 12)), 4));
        if ($signature === null) {
            return response()->view('signatures.verify', [
                'signature' => null,
                'valid' => false,
                'pending' => false,
                'verificationCode' => $verificationCode,
            ]);
        }

        $meeting = DB::table('rapat')->where('id', $signature->rapat_id)->first();
        $reportData = $this->meetingReportData($meeting);
        $documentHash = $this->meetingReportHash($meeting, $reportData);
        $bothApproved = DB::table('meeting_signatures')
            ->where('rapat_id', $signature->rapat_id)
            ->whereIn('peran', ['pimpinan', 'notulis'])
            ->where('status', 'disetujui')
            ->where('document_hash', $documentHash)
            ->count() === 2;
        $valid = $signature->status === 'disetujui'
            && $signature->document_hash
            && hash_equals($signature->document_hash, $documentHash)
            && $bothApproved;
        $pending = $signature->status !== 'disetujui'
            || !$signature->document_hash
            || hash_equals((string) $signature->document_hash, $documentHash);

        return response()->view('signatures.verify', [
            'signature' => $signature,
            'valid' => $valid,
            'pending' => !$valid && $pending,
            'verificationCode' => $verificationCode,
        ]);
    }

    private function meetingReportData(object $meeting): array
    {
        $employees = DB::table('kehadiran as k')
            ->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
            ->where('k.rapat_id', $meeting->id)
            ->where('k.status', 'hadir')
            ->selectRaw("'Pegawai' as jenis")
            ->addSelect('p.nama', 'p.nip as identitas', 'p.jabatan as unit', 'k.waktu')
            ->get();
        $guests = DB::table('kehadiran_tamu')
            ->where('rapat_id', $meeting->id)
            ->select('jenis', 'nama', 'nim as identitas', 'asal as unit', 'waktu')
            ->get()
            ->map(function ($guest) {
                $guest->jenis = ucfirst($guest->jenis);
                $guest->identitas = $guest->identitas ?: '—';
                return $guest;
            });
        $attendanceRows = $employees->concat($guests)->sortBy('waktu')->values();
        $followUps = DB::table('penugasan as t')
            ->leftJoin('pegawai as p', 'p.id', '=', 't.pegawai_id')
            ->where('t.rapat_id', $meeting->id)
            ->select('t.tugas', 't.nama_petugas', 'p.nama as pegawai_nama', 't.deadline', 't.status')
            ->orderBy('t.id')
            ->get();

        return compact('attendanceRows', 'followUps');
    }

    private function meetingReportHash(object $meeting, array $reportData): string
    {
        $meetingFields = [];
        foreach ([
            'judul', 'tanggal', 'mulai', 'selesai', 'tempat', 'moda', 'link_meeting',
            'pimpinan', 'notulis', 'agenda', 'pemateri', 'notulen',
        ] as $field) {
            $meetingFields[$field] = $meeting->$field;
        }

        return hash('sha256', json_encode([
            'meeting' => $meetingFields,
            'attendance' => $reportData['attendanceRows']->all(),
            'follow_ups' => $reportData['followUps']->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function resetStaleMeetingSignatures(int $meeting, string $documentHash): void
    {
        foreach (DB::table('meeting_signatures')->where('rapat_id', $meeting)->where('status', 'disetujui')->get() as $signature) {
            if ($signature->document_hash !== $documentHash) {
                DB::table('meeting_signatures')->where('id', $signature->id)->update([
                    'token' => Str::random(64),
                    'status' => 'menunggu',
                    'disetujui_pada' => null,
                    'document_hash' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function syncMeetingSignatures(int $meeting, ?int $chairId, ?int $minuteTakerId, bool $documentChanged): void
    {
        foreach (['pimpinan' => $chairId, 'notulis' => $minuteTakerId] as $role => $employeeId) {
            $existing = DB::table('meeting_signatures')->where('rapat_id', $meeting)->where('peran', $role)->first();
            if (!$employeeId) {
                if ($existing) {
                    DB::table('meeting_signatures')->where('id', $existing->id)->delete();
                }
                continue;
            }

            if (!$existing || (int) $existing->pegawai_id !== $employeeId || $documentChanged) {
                DB::table('meeting_signatures')->updateOrInsert(
                    ['rapat_id' => $meeting, 'peran' => $role],
                    [
                        'pegawai_id' => $employeeId,
                        'token' => Str::random(64),
                        'status' => 'menunggu',
                        'disetujui_pada' => null,
                        'document_hash' => null,
                        'created_at' => $existing->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function admin(Request $request, ?string $tab = 'rapat')
    {
        abort_unless($this->isAdminMode(), 403);
        $employee = $this->employee();
        $isSuperAdmin = $this->isSuperAdminMode();
        $permissions = $this->superAdminPermissions($employee);

        $tab = $tab ?: 'rapat';
        if ($tab === 'ringkasan') {
            return redirect()->route('admin', 'rapat');
        }
        if (in_array($tab, ['superadmin', 'pegawai', 'pengaturan'], true)) {
            abort_unless($isSuperAdmin, 403);
        }
        if ($tab === 'grup') {
            abort_unless($isSuperAdmin || $permissions['kelola_grup_undangan'], 403);
        }
        if ($tab === 'ruang') {
            abort_unless($isSuperAdmin, 403);
        }
        if ($tab === 'kehadiran') {
            abort_unless($isSuperAdmin || $permissions['lihat_daftar_hadir'], 403);
        }
        $data = [
            'tab' => $tab,
            'employee' => $employee ?: null,
            'isSuperAdmin' => $isSuperAdmin,
            'canEndMeetings' => $this->isAdminMode(),
            'canReturnToEmployeeDashboard' => $employee
                && $this->isEmployeeAdmin($employee)
                && in_array(session('active_role'), ['admin', 'super_admin'], true),
            'canManageRoles' => $isSuperAdmin,
            'canCreateMeetings' => $isSuperAdmin || $permissions['buat_rapat'],
            'canManageRooms' => $isSuperAdmin,
            'canManageInviteGroups' => $isSuperAdmin || $permissions['kelola_grup_undangan'],
            'canViewAttendance' => $isSuperAdmin || $permissions['lihat_daftar_hadir'],
            'canEditMinutes' => $isSuperAdmin || $permissions['notulen'],
            'canManageMaterials' => $isSuperAdmin || $permissions['materi'],
            'canManageRecordings' => $isSuperAdmin || $permissions['rekaman'],
        ];

        if ($tab === 'pegawai') {
            $data['inviteGroups'] = $this->inviteGroups();
            $data['employees'] = DB::table('pegawai')
                ->select('pegawai.*')
                ->selectSub(
                    DB::table('super_admin_permissions')
                        ->selectRaw('1')
                        ->whereColumn('super_admin_permissions.pegawai_id', 'pegawai.id')
                        ->where('super_admin_permissions.is_super_admin', true)
                        ->limit(1),
                    'is_super_admin'
                )
                ->selectSub(
                    DB::table('admin_pegawai')
                        ->selectRaw('1')
                        ->whereColumn('admin_pegawai.pegawai_id', 'pegawai.id')
                        ->limit(1),
                    'is_admin'
                )
                ->orderBy('nama')
                ->get();
        } elseif ($tab === 'superadmin') {
            $data['superAdmins'] = DB::table('pegawai as p')
                ->join('super_admin_permissions as sap', 'sap.pegawai_id', '=', 'p.id')
                ->where('sap.is_super_admin', true)
                ->select('p.id', 'p.nama', 'p.nip')
                ->orderBy('p.nama')
                ->get();
            $data['meetingManagers'] = DB::table('pegawai as p')
                ->join('admin_pegawai as ap', 'ap.pegawai_id', '=', 'p.id')
                ->leftJoin('super_admin_permissions as sap', 'sap.pegawai_id', '=', 'p.id')
                ->where(function ($query) {
                    $query->whereNull('sap.pegawai_id')
                        ->orWhere('sap.is_super_admin', false);
                })
                ->select('p.nama', 'p.nip', 'p.jabatan', 'sap.is_super_admin')
                ->orderBy('p.nama')
                ->get();
            $data['managerAccessSettings'] = DB::table('manager_access_settings')->where('id', 1)->first();
        } elseif ($tab === 'grup') {
            $groupCounts = DB::table('pegawai_invite_groups')
                ->select('group_name')
                ->selectRaw('COUNT(*) as employee_count')
                ->groupBy('group_name');
            $data['inviteGroups'] = DB::table('pegawai_invite_group_catalog as groups')
                ->leftJoinSub($groupCounts, 'counts', 'counts.group_name', '=', 'groups.group_name')
                ->select('groups.id', 'groups.group_name')
                ->selectRaw('COALESCE(counts.employee_count, 0) as employee_count')
                ->orderBy('groups.group_name')
                ->get();
        } elseif ($tab === 'ruang') {
            $data['rooms'] = DB::table('ruang_rapat as rooms')
                ->select('rooms.*')
                ->selectSub(
                    DB::table('rapat')->selectRaw('COUNT(*)')->whereColumn('rapat.room_id', 'rooms.id'),
                    'meeting_count'
                )
                ->orderBy('rooms.nama')
                ->get();
        } elseif ($tab === 'kehadiran') {
            $data['completedMeetings'] = DB::table('rapat')->orderByDesc('tanggal')->orderByDesc('mulai')->get()
                ->filter(fn ($meeting) => MeetingData::scheduleStatus($meeting) === 'selesai')
                ->values();
            $data['completedMeetingAttendees'] = $data['completedMeetings']->isEmpty()
                ? collect()
                : DB::table('kehadiran as k')
                    ->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
                    ->whereIn('k.rapat_id', $data['completedMeetings']->pluck('id'))
                    ->where('k.status', 'hadir')
                    ->select(
                        'k.rapat_id',
                        'p.nama',
                        'p.nip',
                        'k.waktu',
                        'k.waktu_pulang',
                        'k.alasan_pulang'
                    )
                    ->orderBy('k.waktu')
                    ->get()
                    ->groupBy('rapat_id');
            $data['completedMeetingGuestAttendees'] = $data['completedMeetings']->isEmpty()
                ? collect()
                : DB::table('kehadiran_tamu')
                    ->whereIn('rapat_id', $data['completedMeetings']->pluck('id'))
                    ->select('rapat_id', 'nama', 'jenis', 'asal', 'nim', 'waktu', 'waktu_pulang')
                    ->orderBy('waktu')
                    ->get()
                    ->groupBy('rapat_id');
            $data['leaveRequests'] = DB::table('kehadiran as k')
                ->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
                ->join('rapat as r', 'r.id', '=', 'k.rapat_id')
                ->where('k.status', 'izin')
                ->select('k.id', 'r.judul', 'r.tanggal', 'p.nama', 'p.nip', 'k.jenis', 'k.alasan', 'k.file', 'k.status_izin', 'k.waktu')
                ->orderByDesc('r.tanggal')
                ->orderByDesc('k.waktu')
                ->get();
        } elseif ($tab === 'rapat') {
            $data['meetings'] = DB::table('rapat')->orderByDesc('tanggal')->orderByDesc('mulai')->get();
            MeetingData::assignSlugs($data['meetings']);
            $data['meetingAttendees'] = DB::table('kehadiran as k')
                ->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
                ->whereIn('k.rapat_id', $data['meetings']->pluck('id'))
                ->where('k.status', 'hadir')
                ->select('k.rapat_id', 'p.nama', 'p.nip', 'k.waktu', 'k.waktu_pulang', 'k.alasan_pulang')
                ->orderBy('k.waktu')
                ->get()
                ->groupBy('rapat_id');
            $data['guestMeetingAttendees'] = DB::table('kehadiran_tamu')
                ->whereIn('rapat_id', $data['meetings']->pluck('id'))
                ->orderBy('waktu')
                ->get()
                ->groupBy('rapat_id');
            $data['editMeeting'] = $request->filled('edit')
                ? DB::table('rapat')->where('id', $request->integer('edit'))->first()
                : null;
            $data['employees'] = $this->employeeInviteList();
            $data['inviteGroups'] = $this->inviteGroups();
            $data['participantIds'] = $data['editMeeting']
                ? DB::table('meeting_participants')->where('rapat_id', $data['editMeeting']->id)->pluck('pegawai_id')->map(fn ($id) => (string) $id)->all()
                : [];
            $data['allEmployees'] = $data['editMeeting'] && $data['participantIds'] === [];
            $data['rooms'] = DB::table('ruang_rapat')->orderBy('nama')->get();
            $data['attendanceMeeting'] = $request->filled('presensi')
                ? DB::table('rapat')->where('id', $request->integer('presensi'))->first()
                : null;
            $data['attendancePeople'] = $data['attendanceMeeting']
                ? DB::table('kehadiran as k')->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
                    ->where('k.rapat_id', $data['attendanceMeeting']->id)->where('k.status', 'hadir')
                    ->select('p.nama', 'p.nip', 'k.waktu', 'k.waktu_pulang', 'k.alasan_pulang')->orderBy('k.waktu')->get()
                : collect();
        }

        return view('admin', $data);
    }

    public function adminAction(Request $request)
    {
        abort_unless($this->isAdminMode(), 403);
        $permissions = $this->superAdminPermissions($this->employee());
        $action = $request->string('aksi')->toString();
        $id = $request->integer('id');
        $tab = $request->input('tab', 'rapat');

        switch ($action) {
            case 'tambah_pegawai':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate([
                    'nama' => ['required', 'string', 'max:150'],
                    'nip' => ['required', 'regex:/^\d{18}$/', 'unique:pegawai,nip'],
                    'jabatan' => ['nullable', 'string', 'max:150'],
                    'invite_group' => ['nullable', 'string', 'max:150', 'exists:pegawai_invite_group_catalog,group_name'],
                ]);
                DB::transaction(function () use ($data) {
                    $pegawaiId = DB::table('pegawai')->insertGetId([
                        'nama' => trim($data['nama']), 'nip' => $data['nip'],
                        'jabatan' => $data['jabatan'] ?: 'Pegawai',
                        'password_hash' => Hash::make($data['nip']),
                        'password_change_required' => true,
                    ]);
                    if (!empty($data['invite_group'])) {
                        DB::table('pegawai_invite_groups')->insert([
                            'pegawai_id' => $pegawaiId,
                            'group_name' => $data['invite_group'],
                        ]);
                    }
                });
                return redirect()->route('admin', 'pegawai')->with('status', 'Pegawai berhasil ditambahkan. Kata sandi awal menggunakan NIP dan wajib diganti saat login pertama.');

            case 'atur_password_pegawai':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate(['password_pegawai' => ['required', 'string', 'min:8']]);
                $employee = DB::table('pegawai')->where('id', $id)->first();
                abort_unless($employee !== null, 404);
                DB::table('pegawai')->where('id', $id)->update([
                    'password_hash' => Hash::make($data['password_pegawai']),
                    'password_change_required' => true,
                ]);
                return redirect()->route('admin', 'pegawai')->with('password_employee', [
                    'nama' => $employee->nama, 'password' => $data['password_pegawai'],
                ]);

            case 'ubah_jabatan':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate(['jabatan' => ['required', 'string', 'max:150']]);
                abort_unless(DB::table('pegawai')->where('id', $id)->exists(), 404);
                DB::table('pegawai')->where('id', $id)->update(['jabatan' => trim($data['jabatan'])]);
                return redirect()->route('admin', 'pegawai')->with('status', 'Jabatan pegawai berhasil diperbarui.');
            case 'atur_role':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate([
                    'role' => ['required', 'string', 'in:pegawai,admin,super_admin'],
                ]);
                $employee = DB::table('pegawai')->where('id', $id)->first();
                abort_unless($employee !== null, 404);

                if ($data['role'] === 'admin') {
                    if ($this->isLastSuperAdmin($id)) {
                        return back()->withErrors(['role' => 'Tidak dapat menurunkan Super Admin terakhir. Tetapkan Super Admin lain terlebih dahulu.']);
                    }
                    DB::table('super_admin_permissions')->where('pegawai_id', $id)->delete();
                    DB::table('admin_pegawai')->updateOrInsert(['pegawai_id' => $id]);
                    return redirect()->route('admin', 'pegawai')->with('status', "Role {$employee->nama} berhasil diubah menjadi Pengelola Rapat.");
                }

                if ($data['role'] === 'super_admin') {
                    DB::table('admin_pegawai')->updateOrInsert(['pegawai_id' => $id]);
                    DB::table('super_admin_permissions')->updateOrInsert(
                        ['pegawai_id' => $id],
                        [
                            'is_super_admin' => true,
                            'buat_rapat' => true,
                            'materi' => true,
                            'rekaman' => true,
                            'notulen' => true,
                            'lihat_daftar_hadir' => true,
                            'kelola_ruang' => true,
                        ]
                    );
                    return redirect()->route('admin', 'superadmin')->with('status', "{$employee->nama} ditingkatkan menjadi Super Admin.");
                }

                if ($this->isLastSuperAdmin($id)) {
                    return back()->withErrors(['role' => 'Tidak dapat menurunkan Super Admin terakhir. Tetapkan Super Admin lain terlebih dahulu.']);
                }
                DB::table('admin_pegawai')->where('pegawai_id', $id)->delete();
                DB::table('super_admin_permissions')->where('pegawai_id', $id)->delete();
                return redirect()->route('admin', 'pegawai')->with('status', "Role untuk {$employee->nama} berhasil diubah menjadi Pegawai.");

            case 'atur_role_super_admin':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate([
                    'super_admin' => ['required', 'boolean'],
                ]);
                $employeeExists = DB::table('pegawai')->where('id', $id)->exists();
                abort_unless($employeeExists, 404);
                if (filter_var($data['super_admin'], FILTER_VALIDATE_BOOLEAN)) {
                    DB::table('admin_pegawai')->updateOrInsert(['pegawai_id' => $id]);
                    DB::table('super_admin_permissions')->updateOrInsert(
                        ['pegawai_id' => $id],
                        [
                            'is_super_admin' => true,
                            'buat_rapat' => true,
                            'materi' => true,
                            'rekaman' => true,
                            'notulen' => true,
                            'lihat_daftar_hadir' => true,
                            'kelola_ruang' => true,
                        ]
                    );
                    return redirect()->route('admin', 'superadmin')->with('status', 'Pengelola Rapat ditingkatkan menjadi Super Admin.');
                }

                if ($this->isLastSuperAdmin($id)) {
                    return back()->withErrors(['super_admin' => 'Tidak dapat mencabut Super Admin terakhir. Tetapkan Super Admin lain terlebih dahulu.']);
                }
                DB::table('super_admin_permissions')->where('pegawai_id', $id)->delete();
                return redirect()->route('admin', 'superadmin')->with('status', 'Role Super Admin dicabut; akun tetap menjadi Pengelola Rapat.');

            case 'atur_role_admin':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate(['admin' => ['required', 'boolean']]);
                abort_unless(DB::table('pegawai')->where('id', $id)->exists(), 404);
                if (filter_var($data['admin'], FILTER_VALIDATE_BOOLEAN)) {
                    DB::table('super_admin_permissions')->where('pegawai_id', $id)->delete();
                    DB::table('admin_pegawai')->insertOrIgnore(['pegawai_id' => $id]);
                    return redirect()->route('admin', 'pegawai')->with('status', 'Role Pengelola Rapat diberikan. Pengguna dapat berpindah antara mode Pegawai dan Pengelola Rapat.');
                }
                DB::table('admin_pegawai')->where('pegawai_id', $id)->delete();
                return redirect()->route('admin', 'pegawai')->with('status', 'Role Pengelola Rapat dicabut.');

            case 'atur_hak_akses_admin':
                abort_unless($this->isSuperAdminMode(), 403);
                $perms = [
                    'buat_rapat'        => $request->boolean('buat_rapat'),
                    'materi'            => $request->boolean('materi'),
                    'rekaman'           => $request->boolean('rekaman'),
                    'notulen'           => $request->boolean('notulen'),
                    'lihat_daftar_hadir'=> $request->boolean('lihat_daftar_hadir'),
                    'kelola_grup_undangan' => $request->boolean('kelola_grup_undangan'),
                ];

                DB::table('manager_access_settings')->where('id', 1)->update($perms);
                return redirect()->route('admin', 'superadmin')->with('status', 'Hak akses global Pengelola Rapat berhasil diperbarui.');

            case 'tambah_grup_undangan':
                abort_unless($this->isSuperAdminMode() || $permissions['kelola_grup_undangan'], 403);
                $data = $request->validate([
                    'group_name' => ['required', 'string', 'max:150'],
                ]);
                $groupName = trim($data['group_name']);
                if (mb_strtolower($groupName) === 'pensiun') {
                    return back()->withErrors(['group_name' => 'Grup Pensiun tidak dapat ditambahkan.'])->withInput();
                }
                if (DB::table('pegawai_invite_group_catalog')->whereRaw('LOWER(group_name) = ?', [mb_strtolower($groupName)])->exists()) {
                    return back()->withErrors(['group_name' => 'Nama grup sudah tersedia.'])->withInput();
                }
                DB::table('pegawai_invite_group_catalog')->insert(['group_name' => $groupName]);
                return redirect()->route('admin', 'grup')->with('status', 'Grup undangan berhasil ditambahkan.');

            case 'hapus_grup_undangan':
                abort_unless($this->isSuperAdminMode() || $permissions['kelola_grup_undangan'], 403);
                $group = DB::table('pegawai_invite_group_catalog')->where('id', $id)->first();
                abort_unless($group !== null, 404);
                abort_unless($group->group_name !== self::DEFAULT_INVITE_GROUP, 422, 'Grup bawaan tidak dapat dihapus.');
                DB::transaction(function () use ($group) {
                    DB::table('pegawai_invite_groups')
                        ->where('group_name', $group->group_name)
                        ->update(['group_name' => self::DEFAULT_INVITE_GROUP]);
                    DB::table('pegawai_invite_group_catalog')->where('id', $group->id)->delete();
                });
                return redirect()->route('admin', 'grup')->with('status', 'Grup dihapus. Pegawai tetap tersimpan dan dipindahkan ke grup Lainnya / belum dikelompokkan.');

            case 'jadikan_super_admin':
                abort_unless($this->isSuperAdminMode(), 403);
                $pegawaiId = (int) $id;
                abort_unless(DB::table('pegawai')->where('id', $pegawaiId)->exists(), 404);
                // Tambahkan ke admin_pegawai jika belum ada
                if (!DB::table('admin_pegawai')->where('pegawai_id', $pegawaiId)->exists()) {
                    DB::table('admin_pegawai')->insert(['pegawai_id' => $pegawaiId]);
                }
                DB::table('super_admin_permissions')->updateOrInsert(
                    ['pegawai_id' => $pegawaiId],
                    [
                        'is_super_admin'    => true,
                        'buat_rapat'        => true,
                        'materi'            => true,
                        'rekaman'           => true,
                        'notulen'           => true,
                        'lihat_daftar_hadir'=> true,
                        'kelola_ruang'      => true,
                    ]
                );
                $emp = DB::table('pegawai')->where('id', $pegawaiId)->first();
                return redirect()->route('admin', 'superadmin')->with('status', $emp->nama.' kini menjadi Super Admin.');

            case 'atur_super_admin':
                // Legacy alias — tidak digunakan lagi, redirect ke superadmin tab
                abort_unless($this->isSuperAdminMode(), 403);
                return redirect()->route('admin', 'superadmin');

            case 'hapus_super_admin':
                abort_unless($this->isSuperAdminMode(), 403);
                if ($this->isLastSuperAdmin($id)) {
                    return back()->withErrors(['role' => 'Tidak dapat menghapus Super Admin terakhir. Tetapkan Super Admin lain terlebih dahulu.']);
                }
                DB::table('super_admin_permissions')->where('pegawai_id', $id)->delete();
                DB::table('admin_pegawai')->where('pegawai_id', $id)->delete();
                return redirect()->route('admin', 'superadmin')->with('status', 'Role Pengelola Rapat pegawai berhasil dicabut.');

            case 'hapus_pegawai':
                abort_unless($this->isSuperAdminMode(), 403);
                $employee = DB::table('pegawai')->where('id', $id)->first();
                abort_unless($employee !== null, 404);
                if ($this->isLastSuperAdmin($id)) {
                    return back()->withErrors(['pegawai' => 'Tidak dapat menghapus Super Admin terakhir. Tetapkan Super Admin lain terlebih dahulu.']);
                }
                $files = DB::table('kehadiran')->where('pegawai_id', $id)->whereNotNull('file')->pluck('file');
                DB::transaction(fn () => DB::table('pegawai')->where('id', $id)->delete());
                $this->deleteUnreferencedMeetingUploads($files);
                return redirect()->route('admin', 'pegawai')->with('status', 'Pegawai berhasil dihapus.');

            case 'simpan_ruang':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate(['nama' => ['required', 'string', 'max:120', 'unique:ruang_rapat,nama']]);
                DB::table('ruang_rapat')->insert(['nama' => trim($data['nama'])]);
                return redirect()->route('admin', 'ruang')->with('status', 'Ruang rapat berhasil ditambahkan.');

            case 'ubah_ruang':
                abort_unless($this->isSuperAdminMode(), 403);
                $room = DB::table('ruang_rapat')->where('id', $id)->first();
                abort_unless($room !== null, 404);
                $request->merge(['nama' => trim((string) $request->input('nama'))]);
                $data = $request->validate([
                    'nama' => ['required', 'string', 'max:120', 'unique:ruang_rapat,nama,'.$id],
                ]);
                DB::table('ruang_rapat')->where('id', $id)->update(['nama' => $data['nama']]);
                return redirect()->route('admin', 'ruang')->with('status', 'Nama ruang rapat berhasil diubah.');

            case 'hapus_ruang':
                abort_unless($this->isSuperAdminMode(), 403);
                $room = DB::table('ruang_rapat')->where('id', $id)->first();
                abort_unless($room !== null, 404);
                DB::transaction(function () use ($id) {
                    DB::table('rapat')->where('room_id', $id)->update(['room_id' => null]);
                    DB::table('ruang_rapat')->where('id', $id)->delete();
                });
                return redirect()->route('admin', 'ruang')->with('status', 'Ruang rapat berhasil dihapus. Lokasi pada data rapat lama tetap disimpan.');

            case 'simpan_rapat':
                abort_unless($this->isSuperAdminMode() || $permissions['buat_rapat'], 403);
                return $this->saveAdminMeeting($request, $id);

            case 'mulai_rapat':
                abort_unless($this->isAdminMode(), 403);
                $meeting = DB::table('rapat')->where('id', $id)->first();
                abort_unless($meeting !== null, 404);
                abort_unless(MeetingData::canManuallyStart($meeting), 409, 'Rapat sudah dimulai atau diselesaikan.');
                $updated = DB::table('rapat')->where('id', $id)
                    ->whereNull('started_at')
                    ->whereNull('ended_at')
                    ->whereNull('cancelled_at')
                    ->update(['started_at' => now()]);
                abort_unless($updated === 1, 409, 'Rapat sudah dimulai atau diselesaikan.');
                return redirect()->route('admin', 'rapat')->with('status', 'Rapat "'.$meeting->judul.'" berhasil dimulai.');

            case 'selesaikan_rapat':
                abort_unless($this->isAdminMode(), 403);
                $meeting = DB::table('rapat')->where('id', $id)->first();
                abort_unless($meeting !== null, 404);
                abort_unless(MeetingData::canManuallyEnd($meeting), 409, 'Rapat belum dimulai atau sudah diselesaikan.');
                $checkoutCode = MeetingData::code();
                while ($checkoutCode === $meeting->kode_presensi) {
                    $checkoutCode = MeetingData::code();
                }
                $endedAt = now();
                $updated = DB::table('rapat')->where('id', $id)->whereNull('ended_at')->update([
                    'ended_at' => $endedAt,
                    'kode_presensi_pulang' => $checkoutCode,
                    'presensi_pulang_kedaluwarsa' => $endedAt->copy()->addMinutes(30),
                    'overtime_minutes' => MeetingData::overtimeMinutes((object) array_merge((array) $meeting, ['ended_at' => $endedAt])),
                ]);
                abort_unless($updated === 1, 409, 'Rapat sudah diselesaikan.');
                return redirect()->route('admin', 'rapat')->with('status', 'Rapat "'.$meeting->judul.'" telah ditandai sebagai selesai.');

            case 'hapus_rapat':
                abort_unless($this->isSuperAdminMode() || $permissions['buat_rapat'], 403);
                $meeting = DB::table('rapat')->where('id', $id)->first();
                abort_unless($meeting !== null, 404);
                $files = collect([$meeting->file_notulen, $meeting->materi_rapat])
                    ->merge(DB::table('berkas_rapat')->where('rapat_id', $id)->whereNotNull('nama_file')->pluck('nama_file'))
                    ->merge(DB::table('kehadiran')->where('rapat_id', $id)->whereNotNull('file')->pluck('file'));
                DB::transaction(fn () => DB::table('rapat')->where('id', $id)->delete());
                $this->deleteUnreferencedMeetingUploads($files);
                return redirect()->route('admin', 'rapat')->with('status', 'Rapat berhasil dihapus.');

            case 'atur_presensi':
                abort_unless($this->isSuperAdminMode(), 403);
                return $this->manageAttendanceCode($request, $id);

            case 'setujui_izin':
            case 'tolak_izin':
                abort_unless($this->isSuperAdminMode() || $permissions['lihat_daftar_hadir'], 403);
                $updated = DB::table('kehadiran')->where('id', $id)
                    ->where('status', 'izin')
                    ->where('status_izin', 'menunggu')
                    ->update(['status_izin' => $action === 'setujui_izin' ? 'disetujui' : 'ditolak']);
                abort_unless($updated === 1, 409, 'Pengajuan izin sudah diproses atau tidak ditemukan.');
                return back()->with('status', 'Status izin diperbarui.');

            case 'hapus_kehadiran':
                abort_unless($this->isSuperAdminMode() || $permissions['lihat_daftar_hadir'], 403);
                DB::table('kehadiran')->where('id', $id)->delete();
                return back()->with('status', 'Catatan kehadiran dihapus.');

            case 'simpan_pengaturan':
                abort_unless($this->isSuperAdminMode(), 403);
                $settings = $request->validate([
                    'batas_notulen_default_hari' => ['required', 'integer', 'between:1,365'],
                ]);
                foreach (['wajib_izin'] as $key) {
                    DB::table('pengaturan')->updateOrInsert(['kunci' => $key], ['nilai' => $request->boolean($key) ? '1' : '0']);
                }
                DB::table('pengaturan')->updateOrInsert(
                    ['kunci' => 'batas_notulen_default_hari'],
                    ['nilai' => (string) $settings['batas_notulen_default_hari']]
                );
                return redirect()->route('admin', 'pengaturan')->with('status', 'Pengaturan disimpan.');

            default:
                return redirect()->route('admin', $tab)->withErrors(['aksi' => 'Aksi tidak dikenali.']);
        }
    }

    private function saveAdminMeeting(Request $request, int $id)
    {
        $employee = $this->employee();
        $isSuperAdmin = $this->isSuperAdminMode();
        $permissions = $this->superAdminPermissions($employee);
        abort_unless($isSuperAdmin || $permissions['buat_rapat'], 403);
        $canManageMaterials = $isSuperAdmin || $permissions['materi'];
        $canManageRecordings = $isSuperAdmin || $permissions['rekaman'];
        $canEditMinutes = $isSuperAdmin || $permissions['notulen'];
        abort_unless($canEditMinutes || (
            !$request->has('notulen')
            && !$request->hasFile('file_notulen')
            && !$request->boolean('hapus_file_notulen')
        ), 403);
        abort_unless($canManageMaterials || (
            !$request->hasFile('materi_rapat')
            && !$request->boolean('hapus_materi_rapat')
        ), 403);
        abort_unless($canManageRecordings || !$request->has('link_rekaman'), 403);

        $data = $request->validate([
            'judul' => ['required', 'string', 'max:200'],
            'tanggal' => ['required', 'date'],
            'mulai' => ['required', 'date_format:H:i'],
            'selesai' => ['required', 'date_format:H:i'],
            'room_id' => ['nullable', 'integer', 'exists:ruang_rapat,id'],
            'tempat' => ['nullable', 'string', 'max:200'],
            'link_meeting' => ['nullable', 'url', 'starts_with:https://'],
            'moda' => ['required', 'in:Luring,Daring,Hybrid'],
            'pimpinan' => ['nullable', 'string', 'max:200'],
            'notulis' => ['nullable', 'string', 'max:200'],
            'pemateri' => ['nullable', 'string', 'max:200'],
            'agenda' => ['nullable', 'string'],
            'batas_notulen_hari' => ['prohibited'],
            'pimpinan_pegawai_id' => ['nullable', 'integer', 'exists:pegawai,id'],
            'notulis_pegawai_id' => ['nullable', 'integer', 'exists:pegawai,id'],
            'link_rekaman' => ['nullable', 'url', 'regex:#^https://(drive|docs)\.google\.com/|^https://(www\.)?youtube\.com/|^https://youtu\.be/#i'],
            'notulen' => ['prohibited'],
            'file_notulen' => ['prohibited'],
            'hapus_file_notulen' => ['prohibited'],
            'materi_rapat' => ['nullable', 'file', 'mimes:pdf,ppt,pptx,doc,docx,zip', 'max:20480'],
            'semua_pegawai' => ['sometimes', 'boolean'],
            'peserta' => ['nullable', 'array'],
            'peserta.*' => ['integer', 'distinct', 'exists:pegawai,id'],
        ], [
            'judul.required' => 'Judul rapat wajib diisi.',
            'tanggal.required' => 'Tanggal rapat wajib diisi.',
            'mulai.required' => 'Waktu mulai rapat wajib diisi.',
            'mulai.date_format' => 'Waktu mulai harus menggunakan format 24 jam HH:MM.',
            'selesai.required' => 'Waktu selesai rapat wajib diisi.',
            'selesai.date_format' => 'Waktu selesai harus menggunakan format 24 jam HH:MM.',
            'moda.required' => 'Moda rapat wajib dipilih.',
            'peserta.array' => 'Daftar pegawai undangan tidak valid.',
            'peserta.*.exists' => 'Salah satu pegawai undangan tidak ditemukan. Silakan pilih ulang.',
        ], [
            'judul' => 'judul rapat',
            'tanggal' => 'tanggal rapat',
            'mulai' => 'waktu mulai',
            'selesai' => 'waktu selesai',
            'moda' => 'moda rapat',
            'peserta' => 'pegawai undangan',
        ]);
        if ($data['selesai'] <= $data['mulai']) {
            return back()->withErrors(['selesai' => 'Waktu selesai harus setelah waktu mulai.'])->withInput();
        }
        if (!$request->boolean('semua_pegawai') && empty($data['peserta'])) {
            return back()->withErrors(['peserta' => 'Pilih rapat untuk semua pegawai atau centang minimal satu pegawai undangan.'])->withInput();
        }
        $old = $id ? DB::table('rapat')->where('id', $id)->first() : null;
        abort_if($id && !$old, 404);
        $room = !empty($data['room_id']) ? DB::table('ruang_rapat')->where('id', $data['room_id'])->first() : null;
        $chairId = isset($data['pimpinan_pegawai_id']) ? (int) $data['pimpinan_pegawai_id'] : null;
        $minuteTakerId = isset($data['notulis_pegawai_id']) ? (int) $data['notulis_pegawai_id'] : null;
        $signerIds = array_values(array_filter([$chairId, $minuteTakerId]));
        $signers = $signerIds
            ? DB::table('pegawai')->whereIn('id', $signerIds)->get(['id', 'nama'])->keyBy('id')
            : collect();
        $values = [
            'judul' => $data['judul'], 'tanggal' => $data['tanggal'], 'mulai' => $data['mulai'],
            'selesai' => $data['selesai'], 'tempat' => $room->nama ?? ($data['tempat'] ?? ''),
            'room_id' => $room->id ?? null, 'link_meeting' => $data['link_meeting'] ?? null,
            'moda' => $data['moda'],
            'pimpinan' => $signers[$chairId ?? 0]->nama ?? (!$old || empty($old->pimpinan_pegawai_id) ? ($old->pimpinan ?? '') : ''),
            'pimpinan_pegawai_id' => $chairId,
            'notulis' => $signers[$minuteTakerId ?? 0]->nama ?? (!$old || empty($old->notulis_pegawai_id) ? ($old->notulis ?? '') : ''),
            'notulis_pegawai_id' => $minuteTakerId,
            'pemateri' => $data['pemateri'] ?? '',
            'agenda' => $data['agenda'] ?? '',
            'link_rekaman' => $canManageRecordings ? ($data['link_rekaman'] ?? null) : ($old->link_rekaman ?? null),
            'notulen' => $old->notulen ?? '',
        ];
        foreach ([
            'materi_rapat' => ['column' => 'materi_rapat', 'allowed' => $canManageMaterials],
        ] as $input => $filePermission) {
            $column = $filePermission['column'];
            if (!$filePermission['allowed']) {
                $values[$column] = $old->$column ?? null;
                continue;
            }
            if ($request->hasFile($input)) {
                if ($old?->$column) {
                    Storage::disk('meeting_uploads')->delete($old->$column);
                }
                $values[$column] = $this->storeUpload($request->file($input));
            } elseif ($request->boolean('hapus_'.$input) && $old?->$column) {
                Storage::disk('meeting_uploads')->delete($old->$column);
                $values[$column] = null;
            }
        }
        $participantIds = $request->boolean('semua_pegawai') ? [] : ($data['peserta'] ?? []);
        $reportChanged = !$old;
        if ($old) {
            foreach (['judul', 'tanggal', 'mulai', 'selesai', 'tempat', 'moda', 'link_meeting', 'pimpinan', 'notulis', 'agenda', 'pemateri'] as $field) {
                if ((string) ($old->$field ?? '') !== (string) ($values[$field] ?? '')) {
                    $reportChanged = true;
                    break;
                }
            }
        }
        $meetingId = DB::transaction(function () use ($old, $id, $values, $participantIds) {
            if ($old) {
                DB::table('rapat')->where('id', $id)->update($values);
                $meetingId = $id;
            } else {
                $meetingId = DB::table('rapat')->insertGetId($values);
            }
            $this->saveMeetingParticipants($meetingId, $participantIds);
            return $meetingId;
        });
        $this->syncMeetingSignatures($meetingId, $chairId, $minuteTakerId, $reportChanged);

        return redirect()->route('admin', 'rapat')->with('status', 'Data rapat berhasil disimpan.');
    }

    private function saveMeetingParticipants(int $meetingId, array $participantIds): void
    {
        DB::table('meeting_participants')->where('rapat_id', $meetingId)->delete();
        DB::table('meeting_participants')->insert(array_map(
            fn ($employeeId) => ['rapat_id' => $meetingId, 'pegawai_id' => (int) $employeeId],
            array_values(array_unique($participantIds))
        ));
    }

    private function manageAttendanceCode(Request $request, int $id)
    {
        $meeting = DB::table('rapat')->where('id', $id)->first();
        abort_unless($meeting !== null, 404);
        $data = $request->validate([
            'opsi_presensi' => ['required', 'in:buat,perbarui,tutup,tambah_waktu,buat_pulang,tutup_pulang'],
            'kode' => ['nullable', 'string', 'max:20'],
            'durasi' => ['nullable', 'in:15,30,60,selesai,kustom'],
            'menit_kustom' => ['nullable', 'integer', 'between:1,1440'],
            'menit' => ['nullable', 'integer', 'between:1,1440'],
        ]);
        $option = $data['opsi_presensi'];
        $isCheckout = str_ends_with($option, '_pulang');
        if (MeetingData::scheduleStatus($meeting) === 'dibatalkan') {
            return redirect()->route('admin', ['tab' => 'rapat', 'presensi' => $id])
                ->withErrors(['presensi' => 'Rapat dibatalkan; kode presensi tidak dapat dibuka atau diubah.']);
        }
        if (MeetingData::scheduleStatus($meeting) === 'selesai' && !$isCheckout) {
            return redirect()->route('admin', ['tab' => 'rapat', 'presensi' => $id])
                ->withErrors(['presensi' => 'Rapat sudah selesai; presensi hanya dapat ditinjau.']);
        }

        if (in_array($option, ['buat', 'perbarui'], true)) {
            $duration = $data['durasi'] ?? '30';
            if ($duration === 'selesai') {
                $expires = $meeting->tanggal.' '.$meeting->selesai;
            } else {
                $minutes = $duration === 'kustom' ? (int) ($data['menit_kustom'] ?? 30) : (int) $duration;
                $expires = now()->addMinutes($minutes)->toDateTimeString();
            }
            DB::table('rapat')->where('id', $id)->update([
                'kode_presensi' => strtoupper(trim($data['kode'] ?? '')) ?: MeetingData::code(),
                'presensi_kedaluwarsa' => $expires,
                'presensi_diperpanjang' => 0,
            ]);
        } elseif ($option === 'tutup') {
            DB::table('rapat')->where('id', $id)->update(['presensi_kedaluwarsa' => now()]);
        } elseif ($option === 'tambah_waktu') {
            if (!$meeting->presensi_diperpanjang) {
                $expiry = $meeting->presensi_kedaluwarsa
                    ? \Illuminate\Support\Carbon::parse($meeting->presensi_kedaluwarsa, config('app.timezone'))
                    : now();
                $base = $expiry->gt(now()) ? $expiry : now();
                $minutes = (int) ($data['menit'] ?? 15);
                DB::table('rapat')->where('id', $id)->update([
                    'presensi_kedaluwarsa' => $base->addMinutes($minutes)->toDateTimeString(),
                    'presensi_diperpanjang' => 1,
                ]);
            } else {
                return back()->withErrors(['presensi' => 'Batas perpanjangan presensi hanya satu kali.']);
            }
        } elseif ($option === 'buat_pulang') {
            $checkoutData = $request->validate([
                'durasi' => ['required', 'in:15,30,60'],
            ]);
            $code = strtoupper(trim($data['kode'] ?? '')) ?: MeetingData::code();
            while ($code === $meeting->kode_presensi) {
                $code = MeetingData::code();
            }
            $meetingEnd = \Illuminate\Support\Carbon::parse($meeting->tanggal.' '.$meeting->selesai);
            $expires = $meetingEnd->greaterThan(now()) ? $meetingEnd : now();
            DB::table('rapat')->where('id', $id)->update([
                'kode_presensi_pulang' => $code,
                'presensi_pulang_kedaluwarsa' => $expires->copy()->addMinutes((int) $checkoutData['durasi']),
            ]);
        } elseif ($option === 'tutup_pulang') {
            DB::table('rapat')->where('id', $id)->update(['presensi_pulang_kedaluwarsa' => now()]);
        }

        return redirect()->route('admin', ['tab' => 'rapat', 'presensi' => $id])->with('status', 'Pengaturan presensi diperbarui.');
    }

    private function guestAttendanceMeetings()
    {
        return DB::table('rapat')
            ->orderByDesc('tanggal')
            ->orderByDesc('mulai')
            ->get()
            ->filter(fn ($meeting) => (
                MeetingData::scheduleStatus($meeting) === 'berlangsung'
                && MeetingData::attendanceOpen($meeting)
            ) || (
                in_array(MeetingData::scheduleStatus($meeting), ['berlangsung', 'selesai'], true)
                && MeetingData::checkoutOpen($meeting)
            ))
            ->values();
    }

    public function guestAttendanceForm(Request $request)
    {
        return view('guest-attendance', [
            'meetings' => $this->guestAttendanceMeetings(),
            'selectedMeetingId' => (string) old('meeting_id', $request->query('meeting_id', '')),
        ]);
    }

    public function registerGuestAttendance(Request $request)
    {
        $data = $request->validate([
            'meeting_id' => ['required', 'integer', 'exists:rapat,id'],
            'jenis' => ['required', 'in:tamu,mahasiswa'],
            'nama' => ['required', 'string', 'max:150'],
            'asal' => ['required', 'string', 'max:200'],
            'nim' => ['nullable', 'required_if:jenis,mahasiswa', 'string', 'max:30'],
            'kode' => ['required', 'string', 'max:20'],
            'website' => ['nullable', 'string', 'max:0'],
        ]);
        $meetingId = (int) $data['meeting_id'];
        $record = DB::table('rapat')->where('id', $meetingId)->first();
        abort_unless($record !== null, 404);

        $normalize = static fn (string $value): string => Str::lower(trim((string) preg_replace('/\s+/', ' ', $value)));
        $identity = $data['jenis'] === 'mahasiswa'
            ? ['mahasiswa', $normalize($data['nim'])]
            : ['tamu', $normalize($data['nama']), $normalize($data['asal'])];
        $identityHash = hash_hmac('sha256', implode('|', $identity), (string) config('app.key'));

        $submittedCode = strtoupper(trim($data['kode']));
        if (
            in_array(MeetingData::scheduleStatus($record), ['berlangsung', 'selesai'], true)
            && MeetingData::checkoutOpen($record)
            && hash_equals(strtoupper(trim((string) $record->kode_presensi_pulang)), $submittedCode)
        ) {
            $attendance = DB::table('kehadiran_tamu')
                ->where('rapat_id', $meetingId)
                ->where('identitas_hash', $identityHash)
                ->first();

            if (!$attendance) {
                return back()->withErrors([
                    'kode' => 'Absen masuk belum ditemukan untuk rapat dan identitas ini. Pastikan rapat, jenis peserta, serta data identitas sama seperti saat absen masuk.',
                ])->withInput($request->except('kode'));
            }
            if ($attendance->waktu_pulang) {
                return back()->withErrors(['identitas' => 'Absen pulang untuk identitas ini sudah tercatat.'])->withInput($request->except('kode'));
            }

            $updated = DB::table('kehadiran_tamu')
                ->where('id', $attendance->id)
                ->whereNull('waktu_pulang')
                ->update(['waktu_pulang' => now()]);
            abort_unless($updated === 1, 409, 'Absen pulang sudah tercatat.');

            return redirect()->route('guest-attendance', ['meeting_id' => $meetingId])
                ->with('status', 'Absen pulang tamu/mahasiswa berhasil dicatat.');
        }

        if (
            MeetingData::scheduleStatus($record) !== 'berlangsung'
            || !MeetingData::attendanceOpen($record)
        ) {
            return back()->withErrors(['kode' => 'Presensi masuk belum dibuka atau batas waktunya telah berakhir.'])->withInput($request->except('kode'));
        }
        if (!hash_equals(strtoupper(trim((string) $record->kode_presensi)), $submittedCode)) {
            return back()->withErrors(['kode' => 'Kode presensi tidak sesuai.'])->withInput($request->except('kode'));
        }

        $inserted = DB::table('kehadiran_tamu')->insertOrIgnore([
            'rapat_id' => $meetingId,
            'jenis' => $data['jenis'],
            'nama' => trim($data['nama']),
            'asal' => trim($data['asal']),
            'nim' => isset($data['nim']) ? trim($data['nim']) : null,
            'identitas_hash' => $identityHash,
            'waktu' => now(),
        ]);

        if ($inserted !== 1) {
            return back()->withErrors(['identitas' => 'Presensi masuk untuk identitas ini sudah tercatat pada rapat tersebut.'])->withInput($request->except('kode'));
        }

        return redirect()->route('guest-attendance', ['meeting_id' => $meetingId])
            ->with('status', 'Absen masuk tamu/mahasiswa berhasil dicatat.')
            ->withInput($request->only(['meeting_id', 'jenis', 'nama', 'asal', 'nim']));
    }

    public function meeting(Request $request, int $meeting)
    {
        $employee = $this->requireEmployeeOrAdmin();
        $record = DB::table('rapat')->where('id', $meeting)->first();
        abort_unless($record !== null, 404);
        $admin = $this->isAdminMode();
        $attendance = $employee
            ? DB::table('kehadiran')->where('rapat_id', $meeting)->where('pegawai_id', $employee->id)->first()
            : null;
        $permissions = $this->superAdminPermissions($employee ?: null);
        $isSuperAdmin = $this->isSuperAdminMode();
        $employeeSuperAdmin = (bool) ($employee && $isSuperAdmin);
        $showMeetingAdminTools = $admin && !$employee;
        $canManageMeetingContent = $admin;
        $minutesDeadline = MeetingData::minutesDeadline($record);
        $isAssignedMinuteTaker = $employee && (int) ($record->notulis_pegawai_id ?? 0) === (int) $employee->id;
        $canEditMinutes = $isSuperAdmin || (($admin || $isAssignedMinuteTaker) && now()->lessThanOrEqualTo($minutesDeadline));
        $canManageMaterials = $canManageMeetingContent && ($isSuperAdmin || $permissions['materi']);
        $canManageRecordings = $canManageMeetingContent && ($isSuperAdmin || $permissions['rekaman']);
        $canViewAttendance = !$employee && ($isSuperAdmin || $permissions['lihat_daftar_hadir']);
        $canExportMeetingReport = (bool) $employee || ($admin && ($isSuperAdmin || $permissions['lihat_daftar_hadir']));
        $employeeAttendanceOnly = $employee
            && MeetingData::scheduleStatus($record) === 'berlangsung';
        $attendees = DB::table('kehadiran as k')->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
            ->where('k.rapat_id', $meeting)->where('k.status', 'hadir')
            ->select('p.nama', 'p.nip', 'k.waktu', 'k.waktu_pulang', 'k.alasan_pulang')->orderBy('k.waktu')->get();

        return view('meeting', [
            'meeting' => $record,
            'employee' => $employee ?: null,
            'isAdmin' => $admin,
            'isSuperAdmin' => $isSuperAdmin,
            'employeeSuperAdmin' => $employeeSuperAdmin,
            'showMeetingAdminTools' => $showMeetingAdminTools,
            'employeeAttendanceOnly' => $employeeAttendanceOnly,
            'attendance' => $attendance,
            'guestAttendees' => DB::table('kehadiran_tamu')
                ->where('rapat_id', $meeting)
                ->orderBy('waktu')
                ->get(),
            'canEditMinutes' => $canEditMinutes,
            'isAssignedMinuteTaker' => (bool) $isAssignedMinuteTaker,
            'minutesDeadline' => $minutesDeadline,
            'canManageMaterials' => $canManageMaterials,
            'canManageRecordings' => $canManageRecordings,
            'canViewAttendance' => $canViewAttendance,
            'canExportMeetingReport' => $canExportMeetingReport,
            'canAccessAdminMenu' => $isSuperAdmin
                || ($this->isEmployeeAdmin($employee) && in_array(session('active_role'), ['admin', 'super_admin'], true)),
            'canManageRoles' => $isSuperAdmin,
            'canManageRooms' => $isSuperAdmin,
            'attendees' => $attendees,
            'materials' => DB::table('berkas_rapat')->where('rapat_id', $meeting)->where('kategori', 'materi')->orderBy('dibuat_pada')->get(),
            'recordings' => DB::table('berkas_rapat')->where('rapat_id', $meeting)->where('kategori', 'rekaman')->orderBy('dibuat_pada')->get(),
            'meetingParticipants' => DB::table('meeting_participants as mp')
                ->join('pegawai as p', 'p.id', '=', 'mp.pegawai_id')
                ->where('mp.rapat_id', $meeting)
                ->select('p.id', 'p.nama', 'p.nip', 'p.jabatan')
                ->orderBy('p.nama')
                ->get(),
            'minutesDraft' => MeetingData::minutesDraft($record, $attendees),
        ]);
    }

    public function meetingBySlug(Request $request, string $meetingSlug)
    {
        $meeting = MeetingData::findBySlug($meetingSlug);
        abort_unless($meeting !== null, 404);

        if ($this->employee() || $this->isAdminMode()) {
            return $this->meeting($request, (int) $meeting->id);
        }

        return view('meeting-participants', [
            'meeting' => $meeting,
            'meetingParticipants' => DB::table('meeting_participants as mp')
                ->join('pegawai as p', 'p.id', '=', 'mp.pegawai_id')
                ->where('mp.rapat_id', $meeting->id)
                ->select('p.nama')
                ->orderBy('p.nama')
                ->get(),
        ]);
    }

    public function meetingParticipants(int $meeting)
    {
        $record = DB::table('rapat')->where('id', $meeting)->first();
        abort_unless($record !== null, 404);

        return redirect()->route('meetings.canonical', MeetingData::slug($record));
    }

    public function meetingAction(Request $request, int $meeting)
    {
        $employee = $this->requireEmployeeOrAdmin();
        $record = DB::table('rapat')->where('id', $meeting)->first();
        abort_unless($record !== null, 404);
        $admin = $this->isAdminMode();
        $isSuperAdmin = $this->isSuperAdminMode();
        $isAssignedMinuteTaker = $employee && (int) ($record->notulis_pegawai_id ?? 0) === (int) $employee->id;
        $canEditMinutes = $isSuperAdmin || (($admin || $isAssignedMinuteTaker) && MeetingData::minutesEditingOpen($record));
        $attendanceOnly = $employee && MeetingData::scheduleStatus($record) === 'berlangsung';
        $action = $request->string('aksi')->toString();
        $permissions = $this->superAdminPermissions($employee ?: null);
        $allowedActionsDuringMeeting = ['hadir', 'absen_pulang', 'simpan_alasan_pulang'];
        if ($canEditMinutes) {
            $allowedActionsDuringMeeting[] = 'simpan_notulen';
        }
        if ($isSuperAdmin || $permissions['materi']) {
            $allowedActionsDuringMeeting[] = 'tambah_materi';
        }
        if ($isSuperAdmin || $permissions['rekaman']) {
            $allowedActionsDuringMeeting[] = 'tambah_rekaman';
        }
        if ($admin) {
            $allowedActionsDuringMeeting[] = 'selesaikan_rapat';
        }
        abort_if($attendanceOnly && !in_array($action, $allowedActionsDuringMeeting, true), 403);
        $attendance = $employee
            ? DB::table('kehadiran')->where('rapat_id', $meeting)->where('pegawai_id', $employee->id)->first()
            : null;

        if ($action === 'mulai_rapat') {
            abort_unless($admin, 403);
            abort_unless(MeetingData::canManuallyStart($record), 409, 'Rapat sudah dimulai atau diselesaikan.');
            $updated = DB::table('rapat')->where('id', $meeting)
                ->whereNull('started_at')
                ->whereNull('ended_at')
                ->whereNull('cancelled_at')
                ->update(['started_at' => now()]);
            abort_unless($updated === 1, 409, 'Rapat sudah dimulai atau diselesaikan.');
            return back()->with('status', 'Rapat berhasil dimulai.');
        }

        if ($action === 'hadir') {
            abort_unless($employee && !$attendance, 403);
            if (MeetingData::scheduleStatus($record) !== 'berlangsung') {
                return back()->withErrors(['presensi' => 'Absen hadir hanya tersedia saat rapat berlangsung.']);
            }
            $data = $request->validate(['kode' => ['required', 'string', 'max:15']]);
            if (!MeetingData::attendanceOpen($record)) {
                return back()->withErrors(['presensi' => 'Kode presensi hadir belum dibuka atau masa berlakunya telah berakhir.']);
            }
            if (!hash_equals(strtoupper(trim((string) $record->kode_presensi)), strtoupper(trim($data['kode'])))) {
                return back()->withErrors(['presensi' => 'Kode presensi hadir tidak sesuai.']);
            }
            $sessionKey = 'attendance_device_'.$meeting;
            if (session()->has($sessionKey) && session($sessionKey) != $employee->id) {
                return back()->withErrors(['presensi' => 'Perangkat ini sudah digunakan untuk mengabsenkan pegawai lain pada rapat ini.']);
            }
            DB::table('kehadiran')->insert([
                'rapat_id' => $meeting, 'pegawai_id' => $employee->id, 'status' => 'hadir',
                'perangkat_id' => substr((string) $request->userAgent(), 0, 255),
            ]);
            session([$sessionKey => $employee->id]);
            return back()->with('status', 'Presensi hadir berhasil dicatat.');
        }

        if ($action === 'selesaikan_rapat') {
            abort_unless($admin, 403);
            abort_unless(MeetingData::canManuallyEnd($record), 409, 'Rapat belum dimulai atau sudah diselesaikan.');

            $checkoutCode = $record->kode_presensi_pulang;
            $checkoutExpires = $record->presensi_pulang_kedaluwarsa;
            if (!MeetingData::checkoutOpen($record)) {
                $checkoutCode = MeetingData::code();
                while ($checkoutCode === $record->kode_presensi) {
                    $checkoutCode = MeetingData::code();
                }
                $checkoutExpires = now()->addMinutes(30);
            }
            $endedAt = now();
            $updated = DB::table('rapat')->where('id', $meeting)->whereNull('ended_at')->update([
                'ended_at' => $endedAt,
                'kode_presensi_pulang' => $checkoutCode,
                'presensi_pulang_kedaluwarsa' => $checkoutExpires,
                'overtime_minutes' => MeetingData::overtimeMinutes((object) array_merge((array) $record, ['ended_at' => $endedAt])),
            ]);
            abort_unless($updated === 1, 409, 'Rapat sudah diselesaikan.');

            return redirect()->route('meetings.show', $meeting)
                ->with('status', 'Rapat selesai. Kode absen pulang '.$checkoutCode.' aktif selama 30 menit.');
        }

        if ($action === 'absen_pulang') {
            abort_unless(
                $employee
                    && $attendance
                    && $attendance->status === 'hadir'
                    && !$attendance->waktu_pulang,
                403
            );
            $scheduleStatus = MeetingData::scheduleStatus($record);
            if (
                !in_array($scheduleStatus, ['berlangsung', 'selesai'], true)
                || !MeetingData::checkoutOpen($record)
            ) {
                return back()->withErrors(['presensi_pulang' => 'Kode absen pulang belum dibuka atau masa berlakunya telah berakhir.']);
            }
            $data = $request->validate(['kode' => ['required', 'string', 'max:20']]);
            if (!hash_equals(strtoupper(trim((string) $record->kode_presensi_pulang)), strtoupper(trim($data['kode'])))) {
                return back()->withErrors(['presensi_pulang' => 'Kode absen pulang tidak sesuai.']);
            }
            $updated = DB::table('kehadiran')->where('id', $attendance->id)->whereNull('waktu_pulang')
                ->update(['waktu_pulang' => now()]);
            abort_unless($updated === 1, 409, 'Absen pulang sudah tercatat.');
            return back()->with('status', 'Absen pulang berhasil dicatat.');
        }

        if ($action === 'simpan_alasan_pulang') {
            abort_unless(
                $employee
                    && $attendance
                    && $attendance->status === 'hadir',
                403
            );
            if ($attendance->waktu_pulang) {
                return back()->withErrors([
                    'alasan_pulang' => 'Alasan tidak dapat disimpan karena absen pulang sudah tercatat.',
                ]);
            }
            $data = $request->validate(['alasan_pulang' => ['required', 'string', 'max:2000']]);
            $reason = trim($data['alasan_pulang']);
            $updated = DB::table('kehadiran')->where('id', $attendance->id)
                ->where('status', 'hadir')->whereNull('waktu_pulang')->update([
                'alasan_pulang' => $reason,
            ]);
            if ($updated !== 1) {
                $currentAttendance = DB::table('kehadiran')->where('id', $attendance->id)->first();
                if (
                    $currentAttendance
                    && $currentAttendance->status === 'hadir'
                    && !$currentAttendance->waktu_pulang
                    && $currentAttendance->alasan_pulang === $reason
                ) {
                    return back()->with('status', 'Alasan pulang lebih awal berhasil disimpan.');
                }

                return back()->withErrors([
                    'alasan_pulang' => $currentAttendance && $currentAttendance->waktu_pulang
                        ? 'Alasan tidak dapat disimpan karena absen pulang sudah tercatat.'
                        : 'Alasan pulang gagal disimpan. Silakan coba kembali.',
                ]);
            }

            return back()->with('status', 'Alasan pulang lebih awal berhasil disimpan.');
        }

        if ($action === 'izin') {
            abort_unless($employee && !$attendance && !$attendanceOnly, 403);
            $data = $request->validate([
                'jenis' => ['required', 'string', 'max:30'],
                'alasan' => ['required', 'string'],
                'surat' => [MeetingData::setting('wajib_izin', '1') === '1' ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            ]);
            $file = $request->file('surat');
            DB::table('kehadiran')->insert([
                'rapat_id' => $meeting, 'pegawai_id' => $employee->id, 'status' => 'izin',
                'jenis' => $data['jenis'], 'alasan' => $data['alasan'],
                'file' => $file ? $this->storeUpload($file) : null,
                'status_izin' => 'menunggu',
            ]);
            return back()->with('status', 'Pengajuan izin dikirim untuk persetujuan Pengelola Rapat.');
        }

        if ($action === 'batal') {
            abort_unless(
                $employee
                    && $attendance
                    && !$attendanceOnly
                    && !$attendance->waktu_pulang
                    && MeetingData::scheduleStatus($record) !== 'selesai',
                403
            );
            DB::table('kehadiran')->where('id', $attendance->id)->delete();
            return back()->with('status', 'Presensi dibatalkan.');
        }

        if ($action === 'simpan_notulen') {
            if (!$isSuperAdmin && ($admin || $isAssignedMinuteTaker) && !MeetingData::minutesEditingOpen($record)) {
                return back()->withErrors([
                    'notulen' => 'Batas waktu pengisian notulen telah berakhir. Hubungi Pengelola Rapat atau Super Admin.',
                ]);
            }
            abort_unless($canEditMinutes, 403);
            $data = $request->validate([
                'notulen' => ['nullable', 'string'],
                'file_notulen' => ['nullable', 'file', 'mimes:pdf', 'max:15360'],
            ]);
            $filename = $record->file_notulen;
            if ($request->boolean('hapus_file_notulen')) {
                if ($filename) {
                    Storage::disk('meeting_uploads')->delete($filename);
                }
                $filename = null;
            }
            if ($request->hasFile('file_notulen')) {
                if ($filename) {
                    Storage::disk('meeting_uploads')->delete($filename);
                }
                $filename = $this->storeUpload($request->file('file_notulen'));
            }
            DB::table('rapat')->where('id', $meeting)->update([
                'notulen' => $data['notulen'] ?? '', 'file_notulen' => $filename,
            ]);
            return back()->with('status', 'Notulen berhasil disimpan.');
        }

        if ($action === 'tambah_materi') {
            abort_unless($isSuperAdmin || $permissions['materi'], 403);
            $data = $request->validate(['materi' => ['required', 'file', 'mimes:pdf,ppt,pptx,odp,doc,docx,xls,xlsx,ods,zip,jpg,jpeg,png', 'max:20480']]);
            $file = $data['materi'];
            DB::table('berkas_rapat')->insert([
                'rapat_id' => $meeting, 'kategori' => 'materi', 'nama_file' => $this->storeUpload($file),
                'nama_asli' => basename($file->getClientOriginalName()), 'pengunggah' => $employee->nama ?? 'Admin',
            ]);
            return back()->with('status', 'Materi berhasil ditambahkan.');
        }

        if ($action === 'tambah_rekaman') {
            abort_unless($isSuperAdmin || $permissions['rekaman'], 403);
            $data = $request->validate([
                'tautan_rekaman' => ['nullable', 'url', 'starts_with:https://'],
                'rekaman' => ['nullable', 'file', 'mimes:mp4,webm,mov,m4v,mkv', 'max:102400'],
            ]);
            $link = $data['tautan_rekaman'] ?? '';
            if ($link && !preg_match('#^https://(drive|docs)\.google\.com/|^https://(www\.)?youtube\.com/|^https://youtu\.be/#i', $link)) {
                return back()->withErrors(['tautan_rekaman' => 'Tautan rekaman harus berasal dari Google Drive atau YouTube.']);
            }
            $file = $request->file('rekaman');
            if (!$file && !$link) {
                return back()->withErrors(['rekaman' => 'Masukkan tautan atau pilih berkas video rekaman.']);
            }
            DB::table('berkas_rapat')->insert([
                'rapat_id' => $meeting, 'kategori' => 'rekaman',
                'nama_file' => $file ? $this->storeUpload($file) : null,
                'nama_asli' => $file ? basename($file->getClientOriginalName()) : null,
                'tautan' => $link ?: null, 'pengunggah' => $employee->nama ?? 'Admin',
            ]);
            return back()->with('status', 'Rekaman berhasil ditambahkan.');
        }

        if ($action === 'atur_presensi') {
            abort_unless($isSuperAdmin, 403);
            return $this->manageAttendanceCode($request, $meeting);
        }

        if (in_array($action, ['tambah_tugas', 'update_tugas', 'hapus_tugas'], true)) {
            abort(410, 'Fitur penugasan rapat sudah tidak tersedia.');
        }

        return back()->withErrors(['aksi' => 'Aksi tidak dikenali.']);
    }

    public function file(string $filename)
    {
        $this->requireEmployeeOrAdmin();
        abort_unless(basename($filename) === $filename, 404);
        $isLeaveAttachment = DB::table('kehadiran')->where('file', $filename)->exists();
        abort_unless(!$isLeaveAttachment || $this->isAdminMode(), 403);
        $registered = DB::table('rapat')->where('file_notulen', $filename)->orWhere('materi_rapat', $filename)->exists()
            || DB::table('berkas_rapat')->where('nama_file', $filename)->exists()
            || $isLeaveAttachment;
        abort_unless($registered && Storage::disk('meeting_uploads')->exists($filename), 404);

        $download = request()->boolean('download');
        $filePath = Storage::disk('meeting_uploads')->path($filename);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        $response = $download
            ? response()->download($filePath, $filename, $headers)
            : response()->file($filePath, $headers);

        if (!$download) {
            $response->setContentDisposition('inline', $filename);
        }

        return $response;
    }

    private function storeUpload(\Illuminate\Http\UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        return $file->storeAs('', Str::random(32).'.'.$extension, 'meeting_uploads');
    }

    private function deleteUnreferencedMeetingUploads(iterable $filenames): void
    {
        foreach (collect($filenames)->filter()->unique() as $filename) {
            $isRegistered = DB::table('rapat')->where('file_notulen', $filename)->orWhere('materi_rapat', $filename)->exists()
                || DB::table('berkas_rapat')->where('nama_file', $filename)->exists()
                || DB::table('kehadiran')->where('file', $filename)->exists();

            if (!$isRegistered) {
                Storage::disk('meeting_uploads')->delete($filename);
            }
        }
    }
}
