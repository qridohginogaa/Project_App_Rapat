<?php

namespace App\Http\Controllers;

use App\Support\MeetingData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PortalController extends Controller
{
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

    private function isAdminMode(): bool
    {
        if (session()->has('admin_id')) {
            return true;
        }

        $employee = $this->employee();
        return $this->isSuperAdminMode()
            || (session('active_role') === 'admin' && $this->isEmployeeAdmin($employee));
    }

    private function isSuperAdminMode(): bool
    {
        return session()->has('admin_id') || $this->isEmployeeSuperAdmin($this->employee());
    }

    private function employee(): ?object
    {
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
        ];

        // Login via halaman admin khusus (session admin_id) = Super Admin penuh
        if (session()->has('admin_id')) {
            return array_fill_keys(array_keys($permissions), true);
        }

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
            ->orderBy('r.tanggal')
            ->orderBy('r.mulai')
            ->get();

        $byStatus = ['akan' => collect(), 'berlangsung' => collect(), 'selesai' => collect()];
        $waiting = collect();
        foreach ($meetings as $meeting) {
            $status = MeetingData::scheduleStatus($meeting);
            $byStatus[$status]->push($meeting);
            if ($employee && $status !== 'selesai' && !$meeting->status_kehadiran && $meeting->tanggal <= now()->addDays(7)->toDateString()) {
                $waiting->push($meeting);
            }
        }
        $byStatus['selesai'] = $byStatus['selesai']->sortByDesc(fn ($meeting) => $meeting->tanggal.$meeting->mulai)->values();
        $calendarEvents = $meetings->filter(fn ($meeting) => $employee || $this->isAdminMode() || MeetingData::scheduleStatus($meeting) !== 'selesai')
            ->map(fn ($meeting) => ['date' => $meeting->tanggal, 'title' => $meeting->judul, 'id' => (int) $meeting->id])
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
            'canCreateMeetings' => $this->isSuperAdminMode() || $permissions['buat_rapat'],
            'canUploadMaterials' => $this->isSuperAdminMode() || $permissions['materi'],
            'canAddRecordings' => $this->isSuperAdminMode() || $permissions['rekaman'],
            'canEditMinutes' => $this->isSuperAdminMode() || $permissions['notulen'],
            'canViewAttendance' => $this->isSuperAdminMode() || $permissions['lihat_daftar_hadir'],
            'canSeeMeetings' => (bool) ($employee || $this->isAdminMode()),
            'meetingsByStatus' => $byStatus,
            'waiting' => $waiting,
            'calendarEvents' => $calendarEvents,
            'rooms' => DB::table('ruang_rapat')->where('aktif', 1)->orderBy('nama')->get(),
            'employees' => DB::table('pegawai')->select('id', 'nip', 'nama', 'jabatan')->orderBy('nama')->get(),
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
                return back()->withErrors(['login' => 'NIP/username atau kata sandi tidak sesuai.'])
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

        $admin = DB::table('admin')->where('username', trim($data['identifier']))->first();
        if (!$admin || !password_verify($data['password'], $admin->password_hash)) {
            return back()->withErrors(['login' => 'NIP/username atau kata sandi tidak sesuai.'])
                ->onlyInput('identifier');
        }

        $request->session()->regenerate();
        $request->session()->forget(['employee_id', 'employee_password_hash', 'active_role']);
        $request->session()->put('admin_id', (int) $admin->id);

        return redirect()->intended(route('admin', 'rapat'));
    }

    public function loginForm()
    {
        if ($this->employee()) {
            return $this->redirectToDashboard();
        }
        if (session()->has('admin_id')) {
            return redirect()->route('admin', 'rapat');
        }

        return view('login');
    }

    public function employeeLogin(Request $request)
    {
        $request->merge(['identifier' => $request->input('nip')]);
        return $this->login($request);
    }

    public function adminLogin(Request $request)
    {
        $request->merge(['identifier' => $request->input('username')]);
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

    public function adminLogout(Request $request)
    {
        return $this->logout($request);
    }

    public function adminLoginForm()
    {
        return redirect()->route('login');
    }

    public function createMeeting(Request $request)
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 403);
        $permissions = $this->superAdminPermissions($employee);
        abort_unless($this->isSuperAdminMode() || $permissions['buat_rapat'], 403);

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
            'pemateri' => ['nullable', 'string', 'max:200'],
            'agenda' => ['nullable', 'string'],
            'notulen' => ['nullable', 'string'],
            'file_notulen' => ['nullable', 'file', 'mimes:pdf', 'max:15360'],
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
        abort_unless($this->isSuperAdminMode() || $permissions['notulen'] || (empty($data['notulen']) && !$request->hasFile('file_notulen')), 403);
        abort_unless($this->isSuperAdminMode() || $permissions['materi'] || !$request->hasFile('materi'), 403);
        abort_unless($this->isSuperAdminMode() || $permissions['rekaman'] || empty($data['link_rekaman']), 403);
        $room = !empty($data['room_id'])
            ? DB::table('ruang_rapat')->where('id', $data['room_id'])->where('aktif', 1)->first()
            : null;
        if (!empty($data['room_id']) && !$room) {
            return back()->withErrors(['room_id' => 'Pilih ruang rapat yang aktif.'])->withInput();
        }
        $file = $request->file('file_notulen');
        $filename = $file ? $this->storeUpload($file) : null;
        $material = $request->file('materi');
        $materialFilename = $material ? $this->storeUpload($material) : null;
        $participantIds = $request->boolean('semua_pegawai') ? [] : ($data['peserta'] ?? []);

        DB::transaction(function () use ($data, $room, $filename, $material, $materialFilename, $employee, $participantIds) {
            $meetingId = DB::table('rapat')->insertGetId([
                'judul' => $data['judul'],
                'tanggal' => $data['tanggal'],
                'mulai' => $data['mulai'],
                'selesai' => $data['selesai'],
                'tempat' => $room->nama ?? '',
                'room_id' => $room->id ?? null,
                'link_meeting' => $data['link_meeting'] ?? null,
                'moda' => $data['moda'],
                'pimpinan' => $data['pimpinan'] ?? '',
                'notulis' => $data['notulis'] ?? '',
                'pemateri' => $data['pemateri'] ?? '',
                'agenda' => $data['agenda'] ?? '',
                'notulen' => $data['notulen'] ?? '',
                'file_notulen' => $filename,
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
        });

        return redirect('/#pencarianPegawai')->with('success', 'Rapat berhasil ditambahkan. Kode presensi dikelola Pengelola Rapat.');
    }

    public function liveBoard(Request $request)
    {
        $rooms = DB::table('ruang_rapat')->where('aktif', 1)->orderBy('nama')->get();
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
            $room = DB::table('ruang_rapat')->where('id', (int) $roomId)->where('aktif', 1)->first();
            abort_unless($room !== null, 404);
        }

        $meetings = DB::table('rapat')
            ->whereDate('tanggal', now()->toDateString())
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
                    'started_at' => \Illuminate\Support\Carbon::parse($meeting->tanggal.' '.$meeting->mulai, config('app.timezone'))->toIso8601String(),
                    'end' => substr($meeting->selesai, 0, 5),
                    'place' => $meeting->tempat ?: 'Tempat belum ditentukan',
                    'mode' => $meeting->moda,
                    'attendee_count' => (int) ($attendeeCounts[$meeting->id] ?? 0),
                ];
            })->values(),
        ]);
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
            'canViewAttendance' => $isSuperAdmin || $permissions['lihat_daftar_hadir'],
            'canEditMinutes' => $isSuperAdmin || $permissions['notulen'],
            'canManageMaterials' => $isSuperAdmin || $permissions['materi'],
            'canManageRecordings' => $isSuperAdmin || $permissions['rekaman'],
        ];

        if ($tab === 'pegawai') {
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
        } elseif ($tab === 'ruang') {
            $data['rooms'] = DB::table('ruang_rapat')->orderByDesc('aktif')->orderBy('nama')->get();
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
            $data['meetingAttendees'] = DB::table('kehadiran as k')
                ->join('pegawai as p', 'p.id', '=', 'k.pegawai_id')
                ->whereIn('k.rapat_id', $data['meetings']->pluck('id'))
                ->where('k.status', 'hadir')
                ->select('k.rapat_id', 'p.nama', 'p.nip', 'k.waktu', 'k.waktu_pulang', 'k.alasan_pulang')
                ->orderBy('k.waktu')
                ->get()
                ->groupBy('rapat_id');
            $data['editMeeting'] = $request->filled('edit')
                ? DB::table('rapat')->where('id', $request->integer('edit'))->first()
                : null;
            $data['employees'] = DB::table('pegawai')->select('id', 'nip', 'nama', 'jabatan')->orderBy('nama')->get();
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
                ]);
                DB::table('pegawai')->insert([
                    'nama' => trim($data['nama']), 'nip' => $data['nip'],
                    'jabatan' => $data['jabatan'] ?: 'Pegawai',
                    'password_hash' => Hash::make($data['nip']),
                    'password_change_required' => true,
                ]);
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
                ];

                DB::table('manager_access_settings')->where('id', 1)->update($perms);
                return redirect()->route('admin', 'superadmin')->with('status', 'Hak akses global Pengelola Rapat berhasil diperbarui.');

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
                DB::table('super_admin_permissions')->where('pegawai_id', $id)->delete();
                DB::table('admin_pegawai')->where('pegawai_id', $id)->delete();
                return redirect()->route('admin', 'superadmin')->with('status', 'Role Pengelola Rapat pegawai berhasil dicabut.');

            case 'hapus_pegawai':
                abort_unless($this->isSuperAdminMode(), 403);
                DB::table('pegawai')->where('id', $id)->delete();
                return redirect()->route('admin', 'pegawai')->with('status', 'Pegawai berhasil dihapus.');

            case 'simpan_ruang':
                abort_unless($this->isSuperAdminMode(), 403);
                $data = $request->validate(['nama' => ['required', 'string', 'max:120', 'unique:ruang_rapat,nama']]);
                DB::table('ruang_rapat')->insert(['nama' => trim($data['nama'])]);
                return redirect()->route('admin', 'ruang')->with('status', 'Ruang rapat berhasil ditambahkan.');

            case 'toggle_ruang':
                abort_unless($this->isSuperAdminMode(), 403);
                DB::table('ruang_rapat')->where('id', $id)->update(['aktif' => DB::raw('1-aktif')]);
                return redirect()->route('admin', 'ruang');

            case 'simpan_rapat':
                abort_unless($this->isSuperAdminMode() || $permissions['buat_rapat'], 403);
                return $this->saveAdminMeeting($request, $id);

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
                ]);
                abort_unless($updated === 1, 409, 'Rapat sudah diselesaikan.');
                return redirect()->route('admin', 'rapat')->with('status', 'Rapat "'.$meeting->judul.'" telah ditandai sebagai selesai.');

            case 'hapus_rapat':
                abort_unless($this->isSuperAdminMode() || $permissions['buat_rapat'], 403);
                $meeting = DB::table('rapat')->where('id', $id)->first();
                abort_unless($meeting !== null, 404);
                foreach ([$meeting->file_notulen, $meeting->materi_rapat] as $file) {
                    if ($file) {
                        Storage::disk('meeting_uploads')->delete($file);
                    }
                }
                foreach (DB::table('berkas_rapat')->where('rapat_id', $id)->whereNotNull('nama_file')->pluck('nama_file') as $file) {
                    Storage::disk('meeting_uploads')->delete($file);
                }
                DB::table('rapat')->where('id', $id)->delete();
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
                foreach (['wajib_izin'] as $key) {
                    DB::table('pengaturan')->updateOrInsert(['kunci' => $key], ['nilai' => $request->boolean($key) ? '1' : '0']);
                }
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
        $canEditMinutes = $isSuperAdmin || $permissions['notulen'];
        $canManageMaterials = $isSuperAdmin || $permissions['materi'];
        $canManageRecordings = $isSuperAdmin || $permissions['rekaman'];
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
            'link_rekaman' => ['nullable', 'url', 'regex:#^https://(drive|docs)\.google\.com/|^https://(www\.)?youtube\.com/|^https://youtu\.be/#i'],
            'notulen' => ['nullable', 'string'],
            'file_notulen' => ['nullable', 'file', 'mimes:pdf', 'max:15360'],
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
        if ($room && !$room->aktif && (int) ($old->room_id ?? 0) !== (int) $room->id) {
            return back()->withErrors(['room_id' => 'Ruang nonaktif hanya dapat dipertahankan pada rapat yang sudah menggunakannya.'])->withInput();
        }
        $values = [
            'judul' => $data['judul'], 'tanggal' => $data['tanggal'], 'mulai' => $data['mulai'],
            'selesai' => $data['selesai'], 'tempat' => $room->nama ?? ($data['tempat'] ?? ''),
            'room_id' => $room->id ?? null, 'link_meeting' => $data['link_meeting'] ?? null,
            'moda' => $data['moda'], 'pimpinan' => $data['pimpinan'] ?? '',
            'notulis' => $data['notulis'] ?? '', 'pemateri' => $data['pemateri'] ?? '',
            'agenda' => $data['agenda'] ?? '',
            'link_rekaman' => $canManageRecordings ? ($data['link_rekaman'] ?? null) : ($old->link_rekaman ?? null),
            'notulen' => $canEditMinutes ? ($data['notulen'] ?? '') : ($old->notulen ?? ''),
        ];
        foreach ([
            'file_notulen' => ['column' => 'file_notulen', 'allowed' => $canEditMinutes],
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
        DB::transaction(function () use ($old, $id, $values, $participantIds) {
            if ($old) {
                DB::table('rapat')->where('id', $id)->update($values);
                $meetingId = $id;
            } else {
                $meetingId = DB::table('rapat')->insertGetId($values);
            }
            $this->saveMeetingParticipants($meetingId, $participantIds);
        });

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
        $option = $request->string('opsi_presensi')->toString();
        $isCheckout = str_ends_with($option, '_pulang');
        if (MeetingData::scheduleStatus($meeting) === 'selesai' && !$isCheckout) {
            return redirect()->route('admin', ['tab' => 'rapat', 'presensi' => $id])
                ->withErrors(['presensi' => 'Rapat sudah selesai; presensi hanya dapat ditinjau.']);
        }

        if (in_array($option, ['buat', 'perbarui'], true)) {
            $duration = $request->input('durasi', '30');
            if ($duration === 'selesai') {
                $expires = $meeting->tanggal.' '.$meeting->selesai;
            } else {
                $minutes = $duration === 'kustom' ? max(1, $request->integer('menit_kustom', 30)) : max(1, (int) $duration);
                $expires = now()->addMinutes($minutes)->toDateTimeString();
            }
            DB::table('rapat')->where('id', $id)->update([
                'kode_presensi' => strtoupper(trim((string) $request->input('kode'))) ?: MeetingData::code(),
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
                $minutes = max(1, $request->integer('menit', 15));
                DB::table('rapat')->where('id', $id)->update([
                    'presensi_kedaluwarsa' => $base->addMinutes($minutes)->toDateTimeString(),
                    'presensi_diperpanjang' => 1,
                ]);
            } else {
                return back()->withErrors(['presensi' => 'Batas perpanjangan presensi hanya satu kali.']);
            }
        } elseif ($option === 'buat_pulang') {
            $data = $request->validate([
                'kode' => ['nullable', 'string', 'max:20'],
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
                'presensi_pulang_kedaluwarsa' => $expires->copy()->addMinutes((int) $data['durasi']),
            ]);
        } elseif ($option === 'tutup_pulang') {
            DB::table('rapat')->where('id', $id)->update(['presensi_pulang_kedaluwarsa' => now()]);
        }

        return redirect()->route('admin', ['tab' => 'rapat', 'presensi' => $id])->with('status', 'Pengaturan presensi diperbarui.');
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
        $canEditMinutes = $canManageMeetingContent && ($isSuperAdmin || $permissions['notulen']);
        $canManageMaterials = $canManageMeetingContent && ($isSuperAdmin || $permissions['materi']);
        $canManageRecordings = $canManageMeetingContent && ($isSuperAdmin || $permissions['rekaman']);
        $canViewAttendance = !$employee && ($isSuperAdmin || $permissions['lihat_daftar_hadir']);
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
            'canEditMinutes' => $canEditMinutes,
            'canManageMaterials' => $canManageMaterials,
            'canManageRecordings' => $canManageRecordings,
            'canViewAttendance' => $canViewAttendance,
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

    public function meetingAction(Request $request, int $meeting)
    {
        $employee = $this->requireEmployeeOrAdmin();
        $record = DB::table('rapat')->where('id', $meeting)->first();
        abort_unless($record !== null, 404);
        $admin = $this->isAdminMode();
        $isSuperAdmin = $this->isSuperAdminMode();
        $attendanceOnly = $employee && MeetingData::scheduleStatus($record) === 'berlangsung';
        $action = $request->string('aksi')->toString();
        $permissions = $this->superAdminPermissions($employee ?: null);
        $allowedActionsDuringMeeting = ['hadir', 'absen_pulang'];
        if ($isSuperAdmin || $permissions['notulen']) {
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

        if ($action === 'hadir') {
            abort_unless($employee && !$attendance, 403);
            $data = $request->validate(['kode' => ['required', 'string', 'max:15']]);
            if (!MeetingData::attendanceOpen($record)) {
                return back()->withErrors(['presensi' => 'Presensi belum dibuka atau batas waktunya telah berakhir.']);
            }
            if (strtoupper(trim($data['kode'])) !== strtoupper(trim($record->kode_presensi))) {
                return back()->withErrors(['presensi' => 'Kode presensi tidak sesuai.']);
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
            $data = $request->validate(['kode' => ['required', 'string', 'max:20']]);
            if (!MeetingData::checkoutOpen($record)) {
                return back()->withErrors(['presensi_pulang' => 'Kode absen pulang belum dibuka atau waktunya sudah berakhir.']);
            }
            if (strtoupper(trim($data['kode'])) !== strtoupper(trim($record->kode_presensi_pulang))) {
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
                    && $attendance->status === 'hadir'
                    && !$attendance->waktu_pulang
                    && MeetingData::scheduleStatus($record) === 'selesai',
                403
            );
            $data = $request->validate(['alasan_pulang' => ['required', 'string', 'max:2000']]);
            DB::table('kehadiran')->where('id', $attendance->id)->whereNull('waktu_pulang')->update([
                'alasan_pulang' => trim($data['alasan_pulang']),
            ]);

            return back()->with('status', 'Keterangan absen pulang berhasil disimpan.');
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
            $canEdit = $isSuperAdmin || $permissions['notulen'];
            abort_unless($canEdit, 403);
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
        $registered = DB::table('rapat')->where('file_notulen', $filename)->orWhere('materi_rapat', $filename)->exists()
            || DB::table('berkas_rapat')->where('nama_file', $filename)->exists()
            || DB::table('kehadiran')->where('file', $filename)->exists();
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
}
