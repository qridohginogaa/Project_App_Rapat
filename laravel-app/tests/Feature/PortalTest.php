<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_dashboard_shows_upcoming_meetings_to_visitors(): void
    {
        DB::table('rapat')->insert([
            'judul' => 'Rapat berikutnya',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Rapat berikutnya')
            ->assertSee('>Login</a>', false)
            ->assertDontSee('NIP / Username')
            ->assertDontSee('login-identifier')
            ->assertDontSee('Cari nama atau NIP')
            ->assertSee('Live Board Rapat')
            ->assertSee(route('live-board'))
            ->assertSee('Live Board Bunaken Room')
            ->assertSee('Live Board Dihaga Room')
            ->assertSee('Live Board Siladen Room');

        $dashboardHtml = $this->get('/')->getContent();
        $this->assertSame(1, substr_count($dashboardHtml, 'href="'.route('live-board').'"'));
        $this->assertLessThan(
            strpos($dashboardHtml, 'calendar-title'),
            strpos($dashboardHtml, 'Live Board Siladen Room')
        );
    }

    public function test_login_page_is_separate_from_the_dashboard(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('NIP / Username')
            ->assertSee('Kata sandi')
            ->assertSee('name="identifier"', false)
            ->assertSee('name="password"', false);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('NIP / Username')
            ->assertDontSee('login-identifier')
            ->assertSee('>Login</a>', false);
    }

    public function test_dashboard_collapses_completed_meetings_after_the_first_three(): void
    {
        foreach (range(1, 5) as $number) {
            DB::table('rapat')->insert([
                'judul' => 'Rapat selesai '.$number,
                'tanggal' => now()->subDays($number)->toDateString(),
                'mulai' => '09:00',
                'selesai' => '10:00',
                'moda' => 'Luring',
            ]);
        }

        $response = $this->withSession(['admin_id' => 1])->get('/');
        $html = $response->assertOk()
            ->assertSee('Cari rapat selesai')
            ->assertSee('data-completed-search', false)
            ->assertSee('Tampilkan lainnya (2)')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-completed-meeting hidden'));
        $this->assertStringContainsString('Tampilkan lebih sedikit', $html);
    }

    public function test_ongoing_meeting_in_a_room_has_a_room_live_board_preview_link(): void
    {
        $bunakenId = DB::table('ruang_rapat')->where('nama', 'Bunaken Room')->value('id');
        DB::table('rapat')->insert([
            'judul' => 'Rapat Bunaken Berlangsung',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->addMinutes(40)->format('H:i'),
            'tempat' => 'Bunaken',
            'moda' => 'Luring',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Preview Live Board')
            ->assertSee(route('live-board', ['room_id' => $bunakenId]));

        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Live Board',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->get('/')
            ->assertOk()
            ->assertDontSee('Preview Live Board')
            ->assertDontSee('Live Board Rapat')
            ->assertDontSee(route('live-board', ['room_id' => $bunakenId]));
    }

    public function test_admin_can_review_attendance_for_completed_meetings(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Hadir Rapat Selesai',
            'jabatan' => 'Pegawai',
        ]);
        $leaveEmployeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Izin Rapat Selesai',
            'jabatan' => 'Pegawai',
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Selesai Untuk Ditinjau',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->subDay()->setTime(9, 5),
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $leaveEmployeeId,
            'status' => 'izin',
            'jenis' => 'Sakit',
            'alasan' => 'Demam',
            'status_izin' => 'menunggu',
        ]);

        $attendancePage = $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Presensi rapat selesai')
            ->assertSee('Keterangan tidak absen pulang')
            ->assertSee('Rapat Selesai Untuk Ditinjau · '.\App\Support\MeetingData::date(now()->subDay()->toDateString()).' · 1 hadir')
            ->assertSee('Hadir Rapat Selesai')
            ->assertSee('123456789012345678')
            ->assertSee('Daftar izin rapat')
            ->assertSee('Izin Rapat Selesai')
            ->assertSee('Izin menunggu')
            ->assertSee('Sakit: Demam')
            ->assertSee('Setujui')
            ->assertSee('Tolak')
            ->assertDontSee('Catatan kehadiran &amp; izin')
            ->assertDontSee('Hapus');
        $this->assertSame(1, substr_count($attendancePage->getContent(), 'Izin Rapat Selesai'));

        $this->withSession(['admin_id' => 1])->get('/admin/rapat?presensi='.$meetingId)
            ->assertOk()
            ->assertSee('Rapat sudah selesai. Presensi hanya dapat ditinjau.')
            ->assertDontSee('Buka / perbarui presensi');
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $meetingId,
            'opsi_presensi' => 'buat',
        ])->assertRedirect('/admin/rapat?presensi='.$meetingId)
            ->assertSessionHasErrors('presensi');
    }

    public function test_meeting_managers_and_super_admin_can_confirm_pending_leave_requests(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Pengajuan Izin',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $approvedEmployeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Izin Disetujui',
            'jabatan' => 'Pegawai',
        ]);
        $rejectedEmployeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Izin Ditolak',
            'jabatan' => 'Pegawai',
        ]);
        $approvedLeaveId = DB::table('kehadiran')->insertGetId([
            'rapat_id' => $meetingId,
            'pegawai_id' => $approvedEmployeeId,
            'status' => 'izin',
            'jenis' => 'Sakit',
            'alasan' => 'Demam',
            'status_izin' => 'menunggu',
        ]);
        $rejectedLeaveId = DB::table('kehadiran')->insertGetId([
            'rapat_id' => $meetingId,
            'pegawai_id' => $rejectedEmployeeId,
            'status' => 'izin',
            'jenis' => 'Dinas',
            'alasan' => 'Tugas luar',
            'status_izin' => 'menunggu',
        ]);
        $managerId = DB::table('pegawai')->insertGetId([
            'nip' => '112233445566778899',
            'nama' => 'Pengelola Rapat Izin',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $managerId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $managerId,
            'is_super_admin' => false,
            'buat_rapat' => false,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
            'lihat_daftar_hadir' => true,
            'kelola_ruang' => false,
        ]);
        DB::table('manager_access_settings')->where('id', 1)->update(['lihat_daftar_hadir' => true]);
        $managerSession = [
            'employee_id' => $managerId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $managerId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($managerSession)->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Setujui')
            ->assertSee('Tolak');
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'setujui_izin',
            'id' => $approvedLeaveId,
        ])->assertRedirect('/admin/kehadiran');
        $this->assertDatabaseHas('kehadiran', ['id' => $approvedLeaveId, 'status_izin' => 'disetujui']);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'tolak_izin',
            'id' => $rejectedLeaveId,
        ])->assertRedirect('/admin/kehadiran');
        $this->assertDatabaseHas('kehadiran', ['id' => $rejectedLeaveId, 'status_izin' => 'ditolak']);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'tolak_izin',
            'id' => $approvedLeaveId,
        ])->assertStatus(409);
        $this->assertDatabaseHas('kehadiran', ['id' => $approvedLeaveId, 'status_izin' => 'disetujui']);
    }

    public function test_admin_meeting_list_collapses_completed_meetings_after_four(): void
    {
        foreach (range(1, 6) as $number) {
            DB::table('rapat')->insert([
                'judul' => 'Rapat selesai '.$number,
                'tanggal' => now()->subDays($number)->toDateString(),
                'mulai' => '09:00',
                'selesai' => '10:00',
                'moda' => 'Luring',
            ]);
        }
        DB::table('rapat')->insert([
            'judul' => 'Rapat mendatang tetap terlihat',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);

        $html = $this->withSession(['admin_id' => 1])->get('/admin/rapat')
            ->assertOk()
            ->assertSee('Cari rapat selesai')
            ->assertSee('data-admin-completed-search', false)
            ->assertSee('Tampilkan rapat selesai lainnya (2)')
            ->assertSee('Rapat mendatang tetap terlihat')
            ->getContent();

        $this->assertSame(4, substr_count($html, 'class="hidden" hidden'));
    }

    public function test_regular_employee_can_search_completed_meetings_from_the_dashboard(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Pencarian',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('rapat')->insert([
            'judul' => 'Rapat Arsip Pegawai',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->get('/')
            ->assertOk()
            ->assertSee('Rapat Arsip Pegawai')
            ->assertSee('Cari rapat selesai')
            ->assertSee('data-completed-search', false);
    }

    public function test_attendance_page_collapses_completed_meetings_after_four(): void
    {
        foreach (range(1, 6) as $number) {
            DB::table('rapat')->insert([
                'judul' => 'Presensi rapat selesai '.$number,
                'tanggal' => now()->subDays($number)->toDateString(),
                'mulai' => '09:00',
                'selesai' => '10:00',
                'moda' => 'Luring',
            ]);
        }

        $html = $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Tampilkan rapat selesai lainnya (2)')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="box mt-2 hidden" data-completed-attendance-meeting hidden'));
        $this->assertStringContainsString('<summary>Presensi rapat selesai 1', $html);
        $this->assertStringContainsString('Presensi rapat selesai 5', $html);
    }

    public function test_employee_can_sign_in_with_their_existing_password_hash(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Tes',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $this->post('/employee/login', [
            'nip' => '123456789012345678',
            'password' => 'password-tes',
        ])->assertRedirect('/');

        $this->assertSame(DB::table('pegawai')->where('nip', '123456789012345678')->value('id'), session('employee_id'));
        $this->get('/')
            ->assertOk()
            ->assertSee('Pegawai Tes')
            ->assertSee('Pegawai Tes</b>', false)
            ->assertDontSee('Pegawai masuk')
            ->assertDontSee('admin-menu-toggle')
            ->assertDontSee('Live Board Rapat')
            ->assertDontSee('Jam Mulai');
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Pegawai yang wajib mengikuti')
            ->assertDontSee('Rapat untuk semua pegawai');
    }

    public function test_employee_with_initial_password_must_change_it_before_using_meeting_features(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Baru',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('123456789012345678'),
            'password_change_required' => true,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Terbatas',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);

        $this->post('/employee/login', [
            'nip' => '123456789012345678',
            'password' => '123456789012345678',
        ])->assertRedirect(route('employee.password'));

        $this->assertSame($employeeId, session('employee_id'));
        $this->get(route('employee.password'))->assertOk()->assertSee('Buat kata sandi baru');
        $this->get('/meetings/'.$meetingId)->assertRedirect(route('employee.password'));
        $this->post('/meetings/'.$meetingId.'/actions', ['aksi' => 'hadir'])
            ->assertRedirect(route('employee.password'));
    }

    public function test_employee_can_finish_required_password_change_and_cannot_change_it_again(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Baru',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('123456789012345678'),
            'password_change_required' => true,
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->post(route('employee.password.update'), [
            'password' => 'sandi-baru-aman',
            'password_confirmation' => 'tidak-sama',
        ])->assertSessionHasErrors('password');

        $this->post(route('employee.password.update'), [
            'password' => 'sandi-baru-aman',
            'password_confirmation' => 'sandi-baru-aman',
        ])->assertRedirect('/');

        $employee = DB::table('pegawai')->where('id', $employeeId)->first();
        $this->assertFalse((bool) $employee->password_change_required);
        $this->assertTrue(password_verify('sandi-baru-aman', $employee->password_hash));
        $this->get('/')->assertOk()->assertSee('Pegawai Baru');
        $this->post(route('employee.password.update'), [
            'password' => 'sandi-lainnya',
            'password_confirmation' => 'sandi-lainnya',
        ])->assertRedirect('/');
        $this->assertTrue(password_verify('sandi-baru-aman', DB::table('pegawai')->where('id', $employeeId)->value('password_hash')));
    }

    public function test_admin_created_employee_starts_with_nip_password_and_must_change_it(): void
    {
        $nip = '123456789012345678';

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'tambah_pegawai',
            'nama' => 'Pegawai Baru',
            'nip' => $nip,
            'jabatan' => 'Dosen',
        ])->assertRedirect('/admin/pegawai');

        $employee = DB::table('pegawai')->where('nip', $nip)->first();
        $this->assertNotNull($employee);
        $this->assertTrue((bool) $employee->password_change_required);
        $this->assertTrue(password_verify($nip, $employee->password_hash));
    }

    public function test_admin_password_reset_requires_employee_to_change_password_again(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Tes',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('sandi-lama'),
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_password_pegawai',
            'id' => $employeeId,
            'password_pegawai' => 'sandi-reset',
        ])->assertRedirect('/admin/pegawai');

        $employee = DB::table('pegawai')->where('id', $employeeId)->first();
        $this->assertTrue((bool) $employee->password_change_required);
        $this->assertTrue(password_verify('sandi-reset', $employee->password_hash));
    }

    public function test_employee_login_rejects_invalid_credentials_without_revealing_account_state(): void
    {
        DB::table('pegawai')->insert([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Tes',
            'jabatan' => 'Dosen',
            'password_hash' => null,
        ]);

        $this->from('/')
            ->post('/employee/login', [
                'nip' => '123456789012345678',
                'password' => 'sandi-salah',
            ])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['login' => 'NIP/username atau kata sandi tidak sesuai.'])
            ->assertSessionHas('_old_input.identifier', '123456789012345678')
            ->assertSessionMissing('employee_id');
    }

    public function test_admin_can_sign_in_using_the_seeded_account(): void
    {
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'password'])
            ->assertRedirect('/admin/rapat');

        $this->assertTrue(session()->has('admin_id'));
        $this->get('/admin/pegawai')
            ->assertOk()
            ->assertSee('Cari pegawai')
            ->assertDontSee('Live Board Rapat');

        $this->get('/admin/rapat?edit=1')
            ->assertOk()
            ->assertSee('Jam Mulai')
            ->assertSee('Jam Selesai')
            ->assertSee('<label for="mulai-hour">Jam</label>', false)
            ->assertSee('<label for="mulai-minute">Menit</label>', false)
            ->assertSee('<label for="selesai-hour">Jam</label>', false)
            ->assertSee('<label for="selesai-minute">Menit</label>', false)
            ->assertSee('id="mulai-hour"', false)
            ->assertSee('id="mulai-minute"', false)
            ->assertSee('id="selesai-hour"', false)
            ->assertSee('id="selesai-minute"', false)
            ->assertSee('<option value="00" ', false)
            ->assertSee('<option value="23" ', false)
            ->assertSee('<option value="59" ', false)
            ->assertSee('type="hidden" name="mulai" value="09:00"', false)
            ->assertSee('type="hidden" name="selesai" value="11:00"', false)
            ->assertSee('Pegawai yang wajib mengikuti')
            ->assertSee('name="semua_pegawai"', false)
            ->assertSee('Rapat untuk semua pegawai')
            ->assertSee('Cari pegawai');
        $this->get('/admin/superadmin')
            ->assertOk()
            ->assertSee('Hak Akses')
            ->assertSee('Daftar Pengelola Rapat')
            ->assertSee('Daftar Super Admin')
            ->assertSee('Hak Akses Pengelola Rapat')
            ->assertSee('name="buat_rapat"', false);
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Rapat')
            ->assertDontSee('Ringkasan');
        $this->get('/admin/ringkasan')->assertRedirect('/admin/rapat');
    }

    public function test_admin_can_manage_employee_super_admin_role_and_job_title(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Diubah',
            'jabatan' => 'Staf',
        ]);

        $this->withSession(['admin_id' => 1])->get('/admin/pegawai')
            ->assertOk()
            ->assertSee('Simpan jabatan')
            ->assertSee('>Pengelola Rapat</option>', false)
            ->assertSee('>Super Admin</option>', false);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_role',
            'id' => $employeeId,
            'role' => 'admin',
        ])->assertRedirect('/admin/pegawai');

        $this->withSession(['admin_id' => 1])->get('/admin/superadmin')
            ->assertOk()
            ->assertSee('Hak Akses')
            ->assertSeeInOrder(['Daftar Super Admin', 'Daftar Pengelola Rapat', 'Hak Akses Pengelola Rapat'])
            ->assertSee('Daftar Pengelola Rapat')
            ->assertSee('Pegawai Diubah')
            ->assertSee('Pengelola Rapat');
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_role',
            'id' => $employeeId,
            'role' => 'super_admin',
        ])->assertRedirect('/admin/superadmin');
        $this->assertDatabaseHas('admin_pegawai', ['pegawai_id' => $employeeId]);
        $this->assertDatabaseHas('super_admin_permissions', [
            'pegawai_id' => $employeeId,
            'is_super_admin' => 1,
            'buat_rapat' => 1,
            'materi' => 1,
            'rekaman' => 1,
            'notulen' => 1,
            'lihat_daftar_hadir' => 1,
            'kelola_ruang' => 1,
        ]);
        $this->assertDatabaseHas('admin_pegawai', ['pegawai_id' => $employeeId]);
        $superAdminTables = $this->withSession(['admin_id' => 1])->get('/admin/superadmin')
            ->assertOk()
            ->assertSee('Pegawai Diubah')
            ->assertSee('Akses penuh')
            ->getContent();
        $managerTable = substr($superAdminTables, strpos($superAdminTables, '<h3 class="mt-5">Daftar Pengelola Rapat</h3>'));
        $managerTable = substr($managerTable, 0, strpos($managerTable, '<h3 class="mt-5">Hak Akses Pengelola Rapat</h3>'));
        $this->assertStringNotContainsString('Pegawai Diubah', $managerTable);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'ubah_jabatan',
            'id' => $employeeId,
            'jabatan' => 'Kepala Subbagian',
        ])->assertRedirect('/admin/pegawai');
        $this->assertDatabaseHas('pegawai', [
            'id' => $employeeId,
            'jabatan' => 'Kepala Subbagian',
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_role',
            'id' => $employeeId,
            'role' => 'pegawai',
        ])->assertRedirect('/admin/pegawai');
        $this->assertDatabaseMissing('super_admin_permissions', ['pegawai_id' => $employeeId]);
    }

    public function test_super_admin_role_correction_preserves_only_full_access_accounts(): void
    {
        $invalidEmployeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Admin Keliru',
            'jabatan' => 'Pegawai',
        ]);
        $superAdminId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Super Admin Valid',
            'jabatan' => 'Pegawai',
        ]);

        DB::table('super_admin_permissions')->insert([
            [
                'pegawai_id' => $invalidEmployeeId,
                'is_super_admin' => true,
                'buat_rapat' => false,
                'materi' => false,
                'rekaman' => false,
                'notulen' => false,
                'lihat_daftar_hadir' => false,
                'kelola_ruang' => false,
            ],
            [
                'pegawai_id' => $superAdminId,
                'is_super_admin' => true,
                'buat_rapat' => true,
                'materi' => true,
                'rekaman' => true,
                'notulen' => true,
                'lihat_daftar_hadir' => true,
                'kelola_ruang' => true,
            ],
        ]);

        $migration = require database_path('migrations/2026_10_20_000005_correct_super_admin_role_assignments.php');
        $migration->up();

        $this->assertDatabaseHas('super_admin_permissions', [
            'pegawai_id' => $invalidEmployeeId,
            'is_super_admin' => 0,
        ]);
        $this->assertDatabaseHas('super_admin_permissions', [
            'pegawai_id' => $superAdminId,
            'is_super_admin' => 1,
        ]);
    }

    public function test_admin_employee_can_switch_between_employee_and_admin_modes(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Admin',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_role_admin',
            'id' => $employeeId,
            'admin' => '1',
        ])->assertRedirect('/admin/pegawai');
        $this->assertDatabaseHas('admin_pegawai', ['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => false,
            'buat_rapat' => false,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
            'lihat_daftar_hadir' => true,
            'kelola_ruang' => false,
        ]);
        DB::table('manager_access_settings')->where('id', 1)->update(['lihat_daftar_hadir' => true]);

        $this->post('/login', [
            'identifier' => '123456789012345678',
            'password' => 'password-tes',
        ])->assertRedirect('/');

        $this->assertSame('admin', session('active_role'));
        $this->assertSame($employeeId, session('employee_id'));
        $this->get('/')
            ->assertOk()
            ->assertSee('Pegawai Admin')
            ->assertSee('(Pengelola Rapat)')
            ->assertSee('admin-menu-toggle')
            ->assertSee(route('admin', 'rapat'))
            ->assertDontSee('Dashboard Pengelola Rapat')
            ->assertDontSee('Beralih ke mode Pegawai');
        $this->get('/admin/rapat')
            ->assertOk()
            ->assertSee('Rapat')
            ->assertSee('Pegawai Admin')
            ->assertSee('(Pengelola Rapat)')
            ->assertSee('Kembali ke Dashboard')
            ->assertSee(route('dashboard'));
        $this->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Pegawai Admin')
            ->assertSee('(Pengelola Rapat)')
            ->assertSee('Kembali ke Dashboard');
        $this->get('/admin/pegawai')->assertForbidden();

        $this->post('/role/switch', ['role' => 'pegawai'])->assertRedirect('/');
        $this->assertSame('pegawai', session('active_role'));
        $this->get('/')->assertOk()->assertDontSee('admin-menu-toggle');

        $this->post('/role/switch', ['role' => 'admin'])->assertRedirect('/');
        $this->assertSame('admin', session('active_role'));
    }

    public function test_super_admin_can_end_meeting_and_automatically_open_checkout(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Super Admin Rapat',
            'jabatan' => 'Pimpinan',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => true,
            'buat_rapat' => false,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
            'lihat_daftar_hadir' => false,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Diakhiri Manual',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->subMinutes(5)->format('H:i'),
            'moda' => 'Luring',
            'kode_presensi' => 'MASUK8',
            'presensi_kedaluwarsa' => now()->addMinutes(20),
        ]);
        $attendanceId = DB::table('kehadiran')->insertGetId([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->subMinutes(10),
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'super_admin',
        ];

        $this->withSession($session)->get('/')
            ->assertOk()
            ->assertSee('Selesaikan Rapat');
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'selesaikan_rapat',
        ])->assertRedirect('/meetings/'.$meetingId);

        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $this->assertNotNull($meeting->ended_at);
        $this->assertNotEmpty($meeting->kode_presensi_pulang);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($meeting->presensi_pulang_kedaluwarsa)
            ->equalTo(\Illuminate\Support\Carbon::parse($meeting->ended_at)->addMinutes(30)));
        $this->assertSame('selesai', \App\Support\MeetingData::scheduleStatus($meeting));

        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Belum Absen Pulang')
            ->assertSee('Keterangan / alasan tidak mengisi absen pulang')
            ->assertSee('Alasan pulang duluan');
        $this->withSession(['admin_id' => 1])->get('/admin/rapat?presensi='.$meetingId)
            ->assertOk()
            ->assertSee('Kode absen pulang aktif')
            ->assertSee('Tutup kode absen pulang')
            ->assertSee('Buat kode absen pulang');
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_alasan_pulang',
            'alasan_pulang' => 'Ada keperluan keluarga.',
        ])->assertRedirect();
        $this->assertDatabaseHas('kehadiran', [
            'id' => $attendanceId,
            'alasan_pulang' => 'Ada keperluan keluarga.',
        ]);

        $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Belum Absen Pulang')
            ->assertSee('Ada keperluan keluarga.');
    }

    public function test_meeting_manager_can_end_a_meeting_manually(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Admin Bukan Super',
            'jabatan' => 'Admin',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Dapat Diakhiri Pengelola',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->subMinutes(10)->format('H:i'),
            'moda' => 'Luring',
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ])->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Selesaikan Rapat');
        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ])->get('/')
            ->assertOk()
            ->assertSee('Selesaikan Rapat');
        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ])->get('/admin/rapat')
            ->assertOk()
            ->assertSee('✓ Selesaikan');

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ])->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'selesaikan_rapat',
        ])->assertRedirect('/meetings/'.$meetingId);
        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $this->assertNotNull($meeting->ended_at);
        $this->assertNotEmpty($meeting->kode_presensi_pulang);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($meeting->presensi_pulang_kedaluwarsa)
            ->equalTo(\Illuminate\Support\Carbon::parse($meeting->ended_at)->addMinutes(30)));
    }

    public function test_meeting_from_a_past_date_cannot_be_manually_ended(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Admin Rapat Lama',
            'jabatan' => 'Admin',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Tanggal Lampau',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertDontSee('Selesaikan Rapat');
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'selesaikan_rapat',
        ])->assertStatus(409);

        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'ended_at' => null,
            'kode_presensi_pulang' => null,
        ]);
    }

    public function test_live_board_is_public_and_has_no_admin_navigation(): void
    {
        $this->get('/live-board')
            ->assertOk()
            ->assertSee('Live Board · Semua Ruang')
            ->assertSee('Kembali ke Dashboard');

        $this->getJson('/live-board/data')
            ->assertOk()
            ->assertJsonStructure(['updated_at', 'meetings']);
    }

    public function test_public_live_board_reports_attendance_count_without_personal_data(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Sedang Berlangsung',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(30)->format('H:i'),
            'selesai' => now()->addMinutes(30)->format('H:i'),
            'moda' => 'Luring',
        ]);
        $presentId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Hadir',
            'jabatan' => 'Dosen',
        ]);
        $excusedId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Pegawai Izin',
            'jabatan' => 'Dosen',
        ]);
        DB::table('kehadiran')->insert([
            [
                'rapat_id' => $meetingId,
                'pegawai_id' => $presentId,
                'status' => 'hadir',
                'status_izin' => null,
                'waktu' => now()->subMinutes(5),
            ],
            [
                'rapat_id' => $meetingId,
                'pegawai_id' => $excusedId,
                'status' => 'izin',
                'status_izin' => 'menunggu',
                'waktu' => now()->subMinutes(2),
            ],
        ]);

        $this->getJson('/live-board/data')
            ->assertOk()
            ->assertJsonPath('meetings.0.title', 'Rapat Sedang Berlangsung')
            ->assertJsonPath('meetings.0.attendee_count', 1)
            ->assertJsonPath('meetings.0.started_at', now()->subMinutes(30)->startOfMinute()->toIso8601String())
            ->assertJsonMissingPath('meetings.0.attendees')
            ->assertJsonMissingPath('meetings.0.name')
            ->assertJsonMissing(['name' => 'Pegawai Izin']);

        $this->withSession(['admin_id' => 1])
            ->get('/admin/rapat')
            ->assertOk()
            ->assertSee('Absen masuk (1)')
            ->assertSee('Daftar absen pulang')
            ->assertSee('Pegawai Hadir')
            ->assertSee('123456789012345678')
            ->assertDontSee('Pegawai Izin');
    }

    public function test_room_live_board_only_shows_meetings_for_the_selected_room(): void
    {
        $bunakenId = DB::table('ruang_rapat')->where('nama', 'Bunaken Room')->value('id');
        $dihagaId = DB::table('ruang_rapat')->where('nama', 'Dihaga Room')->value('id');

        foreach ([
            ['judul' => 'Rapat Bunaken Aktif', 'room_id' => $bunakenId],
            ['judul' => 'Rapat Dihaga Aktif', 'room_id' => $dihagaId],
        ] as $meeting) {
            DB::table('rapat')->insert([
                'judul' => $meeting['judul'],
                'tanggal' => now()->toDateString(),
                'mulai' => now()->subMinutes(20)->format('H:i'),
                'selesai' => now()->addMinutes(40)->format('H:i'),
                'room_id' => $meeting['room_id'],
                'tempat' => $meeting['room_id'] === $bunakenId ? 'Bunaken Room' : 'Dihaga Room',
                'moda' => 'Luring',
            ]);
        }

        $this->get('/live-board?room_id='.$bunakenId)
            ->assertOk()
            ->assertSee('Bunaken Room');

        $this->getJson('/live-board/data?room_id='.$bunakenId)
            ->assertOk()
            ->assertJsonCount(1, 'meetings')
            ->assertJsonPath('meetings.0.title', 'Rapat Bunaken Aktif');

        $this->getJson('/live-board/data')
            ->assertOk()
            ->assertJsonCount(2, 'meetings');

        $this->get('/live-board?room_id=999999')->assertNotFound();
        $this->getJson('/live-board/data?room_id=invalid')->assertNotFound();
    }

    public function test_meeting_detail_is_not_available_without_a_login(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat tertutup',
            'tanggal' => now()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);

        $this->get('/meetings/'.$meetingId)->assertForbidden();
    }

    public function test_employee_can_view_saved_minutes_during_an_ongoing_meeting_without_being_the_recorder(): void
    {
        \Illuminate\Support\Facades\Storage::fake('meeting_uploads');
        \Illuminate\Support\Facades\Storage::disk('meeting_uploads')->put('minutes-test.pdf', 'pdf content');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Peserta Rapat',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat dengan Notulen',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->addMinutes(40)->format('H:i'),
            'moda' => 'Luring',
            'notulis' => '',
            'notulen' => 'Ringkasan notulen yang sudah disimpan.',
            'file_notulen' => 'minutes-test.pdf',
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Notulen rapat')
            ->assertSee('Ringkasan notulen yang sudah disimpan.')
            ->assertSee(route('files.show', ['filename' => 'minutes-test.pdf', 'download' => 1]))
            ->assertDontSee('name="aksi" value="simpan_notulen"', false);
    }

    public function test_registered_meeting_file_can_be_viewed_or_downloaded(): void
    {
        \Illuminate\Support\Facades\Storage::fake('meeting_uploads');
        \Illuminate\Support\Facades\Storage::disk('meeting_uploads')->put('minutes-test.pdf', 'pdf content');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Pegawai Berkas',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('rapat')->insert([
            'judul' => 'Rapat Berkas',
            'tanggal' => now()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'file_notulen' => 'minutes-test.pdf',
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ];

        $this->withSession($session)->get('/files/minutes-test.pdf')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=minutes-test.pdf');
        $this->withSession($session)->get('/files/minutes-test.pdf?download=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=minutes-test.pdf');
    }

    public function test_employee_can_create_a_meeting_for_selected_employees(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Pembuat Rapat',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $inviteeIds = [];
        foreach ([
            ['nip' => '123456789012345678', 'nama' => 'Undangan Satu'],
            ['nip' => '123456789012345679', 'nama' => 'Undangan Dua'],
        ] as $invitee) {
            $inviteeIds[] = DB::table('pegawai')->insertGetId($invitee + ['jabatan' => 'Pegawai']);
        }
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('manager_access_settings')->where('id', 1)->update(['buat_rapat' => true]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->get('/')
            ->assertOk()
            ->assertSee('id="mulai-hour"', false)
            ->assertSee('<option value="09" selected>09</option>', false)
            ->assertSee('<option value="00" selected>00</option>', false)
            ->assertSee('<option value="11" selected>11</option>', false)
            ->assertSee('<option value="59" ', false)
            ->assertSee('type="hidden" name="mulai" value="09:00"', false)
            ->assertSee('type="hidden" name="selesai" value="11:00"', false);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->post('/meetings', [
            'judul' => 'Rapat Undangan Khusus',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'peserta' => $inviteeIds,
        ])->assertRedirect('/#pencarianPegawai');

        $meetingId = DB::table('rapat')->where('judul', 'Rapat Undangan Khusus')->value('id');
        $this->assertSame(
            $inviteeIds,
            DB::table('meeting_participants')->where('rapat_id', $meetingId)->orderBy('pegawai_id')->pluck('pegawai_id')->all()
        );
    }

    public function test_regular_employee_cannot_create_a_meeting_for_everyone(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Pembuat Rapat',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->post('/meetings', [
            'judul' => 'Rapat Semua Pegawai',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ])->assertForbidden();

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->get('/')        ->assertOk()
        ->assertDontSee('Jadwalkan rapat / kegiatan baru')
        ->assertDontSee('Rapat untuk semua pegawai')
        ->assertDontSee('Tambah materi (PDF, PPT')
        ->assertDontSee('Tambah rekaman')
        ->assertDontSee('Isi / tambah / ubah notulen');
    }

    public function test_admin_can_create_a_meeting_with_selected_participants(): void
    {
        $inviteeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Undangan Admin',
            'jabatan' => 'Pegawai',
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'simpan_rapat',
            'judul' => 'Rapat Pilihan Admin',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'peserta' => [$inviteeId],
        ])->assertRedirect('/admin/rapat');

        $meetingId = DB::table('rapat')->where('judul', 'Rapat Pilihan Admin')->value('id');
        $this->assertDatabaseHas('meeting_participants', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $inviteeId,
        ]);
    }

    public function test_admin_meeting_form_requires_all_employees_or_at_least_one_invitee(): void
    {
        $this->withSession(['admin_id' => 1])
            ->from('/admin/rapat?edit=0')
            ->followingRedirects()
            ->post('/admin/actions', [
                'aksi' => 'simpan_rapat',
                'judul' => 'Rapat Perlu Undangan',
                'tanggal' => now()->addDay()->toDateString(),
                'mulai' => '09:00',
                'selesai' => '10:00',
                'moda' => 'Luring',
            ])
            ->assertOk()
            ->assertSee('Pilih rapat untuk semua pegawai atau centang minimal satu pegawai undangan.')
            ->assertSee('value="Rapat Perlu Undangan"', false);
    }

    public function test_admin_can_create_a_meeting_for_all_employees_without_selecting_invitees(): void
    {
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'simpan_rapat',
            'judul' => 'Rapat Semua Pegawai',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'semua_pegawai' => '1',
        ])->assertRedirect('/admin/rapat');

        $meetingId = DB::table('rapat')->where('judul', 'Rapat Semua Pegawai')->value('id');
        $this->assertNotNull($meetingId);
        $this->assertSame(0, DB::table('meeting_participants')->where('rapat_id', $meetingId)->count());

        $this->withSession(['admin_id' => 1])->get('/admin/rapat?edit='.$meetingId)
            ->assertOk()
            ->assertSee('data-all-employees checked', false);
    }

    public function test_admin_can_set_global_manager_permissions_and_promote_employee_to_super_admin(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Super Admin Tes',
            'jabatan' => 'Pegawai',
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_hak_akses_admin',
            'buat_rapat' => '1',
            'rekaman' => '1',
            'lihat_daftar_hadir' => '1',
        ])->assertRedirect('/admin/superadmin');

        $this->assertDatabaseHas('manager_access_settings', [
            'id' => 1,
            'buat_rapat' => 1,
            'materi' => 0,
            'rekaman' => 1,
            'notulen' => 0,
            'lihat_daftar_hadir' => 1,
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_role',
            'id' => $employeeId,
            'role' => 'super_admin',
        ])->assertRedirect('/admin/superadmin');
        $this->assertDatabaseHas('super_admin_permissions', [
            'pegawai_id' => $employeeId,
            'is_super_admin' => 1,
        ]);

        $this->withSession(['admin_id' => 1])->get('/admin/superadmin')
            ->assertOk()
            ->assertSee('Pegawai Super Admin Tes')
            ->assertSee('Akses penuh');
    }

    public function test_global_attendance_permission_applies_to_every_meeting_manager(): void
    {
        DB::table('manager_access_settings')->where('id', 1)->update(['lihat_daftar_hadir' => true]);
        foreach (range(1, 2) as $number) {
            $employeeId = DB::table('pegawai')->insertGetId([
                'nip' => str_pad((string) $number, 18, '1', STR_PAD_LEFT),
                'nama' => 'Pengelola Global '.$number,
                'jabatan' => 'Koordinator',
                'password_hash' => Hash::make('password-tes'),
            ]);
            DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
            $this->withSession([
                'employee_id' => $employeeId,
                'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
                'active_role' => 'admin',
            ])->get('/admin/kehadiran')->assertOk();
        }
    }

    public function test_meeting_manager_cannot_create_or_manage_attendance_codes(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pengelola Rapat',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => false,
            'buat_rapat' => false,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
            'lihat_daftar_hadir' => true,
            'kelola_ruang' => false,
        ]);
        DB::table('manager_access_settings')->where('id', 1)->update(['lihat_daftar_hadir' => true]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Kode Khusus Super Admin',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $meetingId,
            'opsi_presensi' => 'buat',
            'kode' => 'RAHASIA1',
        ])->assertForbidden();
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'atur_presensi',
            'opsi_presensi' => 'buat_pulang',
            'durasi' => '30',
            'kode' => 'RAHASIA2',
        ])->assertForbidden();

        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'kode_presensi' => null,
            'kode_presensi_pulang' => null,
        ]);
        $this->withSession($session)->get('/admin/rapat?presensi='.$meetingId)
            ->assertOk()
            ->assertSee('Pembuatan dan pengelolaan kode presensi hanya dapat dilakukan oleh Super Admin.')
            ->assertSee('Daftar absen masuk')
            ->assertDontSee('Buka / perbarui presensi')
            ->assertDontSee('Buat kode absen pulang');
        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Pembuatan dan pengelolaan kode presensi hanya dapat dilakukan oleh Super Admin.')
            ->assertSee('Daftar absen masuk')
            ->assertDontSee('Buka / perbarui kode')
            ->assertDontSee('Buat kode absen pulang');
    }

    public function test_meeting_manager_cannot_manage_meeting_assignments(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pengelola Penugasan',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => false,
            'buat_rapat' => false,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
            'lihat_daftar_hadir' => false,
            'kelola_ruang' => false,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Penugasan',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $taskId = DB::table('penugasan')->insertGetId([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'nama_petugas' => 'Pengelola Penugasan',
            'tugas' => 'Tugas yang ditugaskan',
        ]);
        $managerSession = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($managerSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertDontSee('Penugasan rapat')
            ->assertDontSee('Penugasan Anda')
            ->assertDontSee('Tugas yang ditugaskan');
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'tambah_tugas',
            'tugas' => 'Tugas baru',
        ])->assertStatus(410);
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'update_tugas',
            'tugas_id' => $taskId,
            'status' => 'Selesai',
        ])->assertStatus(410);
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'hapus_tugas',
            'tugas_id' => $taskId,
        ])->assertStatus(410);
        $this->assertDatabaseHas('penugasan', ['id' => $taskId, 'status' => 'Belum Selesai']);

        $this->withSession(['admin_id' => 1])->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertDontSee('Penugasan rapat')
            ->assertDontSee('Tugas yang ditugaskan');
        $this->withSession(['admin_id' => 1])->get('/')
            ->assertOk()
            ->assertDontSee('Penugasan Anda yang belum selesai')
            ->assertDontSee('Tugas yang ditugaskan');
        $this->withSession(['admin_id' => 1])->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'tambah_tugas',
            'tugas' => 'Tugas oleh Super Admin',
        ])->assertStatus(410);
        $this->assertDatabaseHas('penugasan', ['id' => $taskId, 'status' => 'Belum Selesai']);
    }

    public function test_only_super_admin_can_access_and_manage_meeting_rooms(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pengelola Ruang',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => false,
            'buat_rapat' => false,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
            'lihat_daftar_hadir' => false,
            'kelola_ruang' => true,
        ]);
        $managerSession = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($managerSession)->get('/admin/rapat')
            ->assertOk()
            ->assertDontSee(route('admin', 'ruang'), false);
        $this->withSession($managerSession)->get('/admin/ruang')->assertForbidden();
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'simpan_ruang',
            'nama' => 'Ruang Khusus',
        ])->assertForbidden();
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'toggle_ruang',
            'id' => DB::table('ruang_rapat')->value('id'),
        ])->assertForbidden();

        $this->withSession(['admin_id' => 1])->get('/admin/ruang')
            ->assertOk()
            ->assertSee('Master ruang rapat');
        $roomCount = DB::table('ruang_rapat')->count();
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'simpan_ruang',
            'nama' => 'Ruang Super Admin',
        ])->assertRedirect('/admin/ruang');
        $this->assertSame($roomCount + 1, DB::table('ruang_rapat')->count());

        $this->withSession(['admin_id' => 1])->get('/admin/superadmin')
            ->assertOk()
            ->assertDontSee('name="kelola_ruang"', false);
    }

    public function test_super_admin_has_admin_level_meeting_access(): void
    {
        \Illuminate\Support\Facades\Storage::fake('meeting_uploads');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Pegawai Peserta',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Materi dan Notulen',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->addMinutes(40)->format('H:i'),
            'moda' => 'Luring',
            'notulis' => 'Notulis Lain',
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ];

        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen tanpa izin.',
        ])->assertForbidden();
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'tambah_materi',
            'materi' => \Illuminate\Http\UploadedFile::fake()->create('tanpa-izin.pdf', 100, 'application/pdf'),
        ])->assertForbidden();

        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => true,
            'buat_rapat' => false,
            'materi' => true,
            'rekaman' => true,
            'notulen' => true,
        ]);
        $superAdminSession = $session + ['active_role' => 'super_admin'];

        $this->withSession($superAdminSession)->get('/admin/rapat')->assertOk();
        $this->withSession($superAdminSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen dilengkapi oleh peserta rapat.',
        ])->assertRedirect();
        $this->assertSame('Notulen dilengkapi oleh peserta rapat.', DB::table('rapat')->where('id', $meetingId)->value('notulen'));

        $this->withSession($superAdminSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'tambah_materi',
            'materi' => \Illuminate\Http\UploadedFile::fake()->create('presentasi.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $this->withSession($superAdminSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'tambah_rekaman',
            'tautan_rekaman' => 'https://youtu.be/example',
        ])->assertRedirect();
        $this->withSession($superAdminSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'atur_presensi',
            'opsi_presensi' => 'buat',
        ])->assertRedirect('/admin/rapat?presensi='.$meetingId);

        $this->withSession($superAdminSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Daftar absen masuk (');
        DB::table('super_admin_permissions')->where('pegawai_id', $employeeId)->update(['lihat_daftar_hadir' => true]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now(),
        ]);

        $this->assertDatabaseHas('berkas_rapat', [
            'rapat_id' => $meetingId,
            'kategori' => 'materi',
            'pengunggah' => 'Pegawai Peserta',
        ]);
        $this->withSession($superAdminSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Pegawai yang wajib mengikuti')
            ->assertSee('Rapat ini untuk semua pegawai.')
            ->assertSee('Daftar absen masuk (1)')
            ->assertSee('Daftar absen pulang')
            ->assertSee('Pegawai Peserta')
            ->assertSee('Tambah materi (PDF, PPT, dokumen, spreadsheet, gambar, atau ZIP; maks. 20 MB)')
            ->assertSee('Isi / tambah / ubah notulen')
            ->assertSee('Tambah rekaman');
    }

    public function test_employee_can_record_attendance_with_the_meeting_code_without_nip_suffix(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Peserta Rapat',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Presensi',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subHour()->format('H:i'),
            'selesai' => now()->subMinutes(5)->format('H:i'),
            'moda' => 'Luring',
            'kode_presensi' => 'RAPAT7',
            'presensi_kedaluwarsa' => now()->addMinutes(20)->toDateTimeString(),
        ]);

        $session = ['employee_id' => $employeeId, 'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash')];
        $this->withSession($session)
            ->get('/')
            ->assertOk()
            ->assertSee('🟢 Presensi buka');

        $this->withSession($session)
            ->post('/meetings/'.$meetingId.'/actions', ['aksi' => 'hadir', 'kode' => 'RAPAT7'])
            ->assertRedirect();

        $this->assertDatabaseHas('kehadiran', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
        ]);
        $this->withSession($session)
            ->get('/')
            ->assertOk()
            ->assertDontSee('🟢 Presensi buka')
            ->assertDontSee('Presensi pulang buka');
    }

    public function test_employee_can_submit_checkout_code_after_meeting_ends(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Absen Pulang',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Dengan Absen Pulang',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'kode_presensi_pulang' => 'PULANG7',
            'presensi_pulang_kedaluwarsa' => now()->addMinutes(30),
        ]);
        $attendanceId = DB::table('kehadiran')->insertGetId([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->setTime(9, 10),
        ]);

        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ];
        $this->withSession($session)
            ->get('/')
            ->assertOk()
            ->assertSee('🟢 Presensi pulang buka');
        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Belum Absen Pulang')
            ->assertSee('Alasan pulang duluan');
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'absen_pulang',
            'kode' => 'PULANG7',
        ])->assertRedirect();
        $this->assertNotNull(DB::table('kehadiran')->where('id', $attendanceId)->value('waktu_pulang'));
        $this->withSession($session)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Presensi pulang buka');
    }

    public function test_employee_cannot_submit_checkout_code_before_meeting_ends(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Menunggu Pulang',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Belum Selesai',
            'tanggal' => now()->toDateString(),
            'mulai' => '00:00',
            'selesai' => '23:59',
            'moda' => 'Luring',
            'kode_presensi_pulang' => 'PULANG7',
            'presensi_pulang_kedaluwarsa' => now()->addHour(),
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->subHour(),
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'absen_pulang',
            'kode' => 'PULANG7',
        ])->assertForbidden();
    }

    public function test_admin_can_create_checkout_code_and_super_admin_can_monitor_checkout_status(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Belum Absen Pulang',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Pantau Absen Pulang',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'kode_presensi' => 'MASUK7',
            'presensi_kedaluwarsa' => now()->subHour(),
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->setTime(9, 5),
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $meetingId,
            'opsi_presensi' => 'buat_pulang',
            'durasi' => '30',
        ])->assertRedirect('/admin/rapat?presensi='.$meetingId);
        $this->assertNotSame('MASUK7', DB::table('rapat')->where('id', $meetingId)->value('kode_presensi_pulang'));

        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'buat_rapat' => false,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
            'lihat_daftar_hadir' => true,
        ]);
        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Daftar absen masuk (1)')
            ->assertSee('Daftar absen pulang')
            ->assertSee('Belum Absen Pulang')
            ->assertSee('Alasan pulang duluan');
        $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Rapat Pantau Absen Pulang')
            ->assertSee('Belum Absen Pulang')
            ->assertSee('Alasan pulang duluan');
    }
}
