<?php

namespace Tests\Feature;

use App\Support\EmployeeRosterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private ?array $superAdminSession = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function withSession(array $data)
    {
        if (array_key_exists('admin_id', $data)) {
            unset($data['admin_id']);
            $data = array_merge($this->superAdminSession(), $data);
        }

        return parent::withSession($data);
    }

    private function withUntrustedSession(array $data)
    {
        return parent::withSession($data);
    }

    private function superAdminSession(): array
    {
        if ($this->superAdminSession !== null) {
            return $this->superAdminSession;
        }

        $passwordHash = Hash::make('super-admin-password');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '999999999999999999',
            'nama' => 'Super Admin Tes',
            'jabatan' => 'Administrator',
            'password_hash' => $passwordHash,
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => true,
        ]);

        return $this->superAdminSession = [
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
            'active_role' => 'super_admin',
            'can_switch_role' => true,
        ];
    }

    public function test_dashboard_shows_upcoming_meetings_to_visitors(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
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
            ->assertDontSee('NIP')
            ->assertDontSee('login-identifier')
            ->assertDontSee('Cari nama atau NIP')
            ->assertSee('Live Board Rapat')
            ->assertSee(route('live-board'))
            ->assertSee('Live Board Bunaken Room')
            ->assertSee('Live Board Dihaga Room')
            ->assertSee('Live Board Siladen Room');

        $dashboardHtml = $this->get('/')->getContent();
        $this->assertStringContainsString(
            'href="'.route('meetings.canonical', \App\Support\MeetingData::slug(DB::table('rapat')->where('id', $meetingId)->first())).'"',
            $dashboardHtml
        );
        $this->assertStringContainsString('data-meeting-url="'.url('/meet-__SLUG__').'"', $dashboardHtml);
        $this->assertStringContainsString('"slug":"'.\App\Support\MeetingData::slug(DB::table('rapat')->where('id', $meetingId)->first()).'"', $dashboardHtml);
        $this->assertSame(1, substr_count($dashboardHtml, 'href="'.route('live-board').'"'));
        $this->assertLessThan(
            strpos($dashboardHtml, 'calendar-title'),
            strpos($dashboardHtml, 'Live Board Siladen Room')
        );
    }

    public function test_visitor_can_open_a_meeting_and_see_only_its_invited_employee_names(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat dengan daftar peserta',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'agenda' => 'Agenda internal',
            'pimpinan' => 'Pimpinan Tes',
            'notulis' => 'Notulis Tes',
            'link_meeting' => 'https://zoom.us/j/123456789',
        ]);
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Nama Peserta Tes',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('meeting_participants')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
        ]);

        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $meetingSlug = \App\Support\MeetingData::slug($meeting);

        $this->get(route('meetings.canonical', $meetingSlug))
            ->assertOk()
            ->assertSee('Peserta rapat')
            ->assertSee('<details class="meeting-participants-disclosure">', false)
            ->assertSee('Lihat daftar peserta')
            ->assertSee('Nama Peserta Tes')
            ->assertSee('Rapat dengan daftar peserta')
            ->assertSee('Agenda internal')
            ->assertSee('Pimpinan Tes')
            ->assertSee('Notulis Tes')
            ->assertSee('href="https://zoom.us/j/123456789"', false)
            ->assertSee('Buka tautan meeting')
            ->assertSee('Presensi Tamu & Mahasiswa')
            ->assertSee(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertDontSee('Presensi rapat')
            ->assertDontSee('NIP');

        $this->get('/meetings/'.$meetingId.'/participants')
            ->assertRedirect(route('meetings.canonical', $meetingSlug));
    }

    public function test_meeting_links_use_monthly_room_sequence_and_keep_legacy_id_links(): void
    {
        $meetingDate = now()->startOfMonth()->addDays(5)->toDateString();
        $dihagaRoomId = DB::table('ruang_rapat')->where('nama', 'Dihaga Room')->value('id');
        $bunakenRoomId = DB::table('ruang_rapat')->where('nama', 'Bunaken Room')->value('id');
        $meetingIds = [];

        foreach ([$dihagaRoomId, $bunakenRoomId, $dihagaRoomId] as $index => $roomId) {
            $meetingIds[] = DB::table('rapat')->insertGetId([
                'judul' => 'Rapat Slug '.($index + 1),
                'tanggal' => $meetingDate,
                'mulai' => '09:00',
                'selesai' => '10:00',
                'tempat' => DB::table('ruang_rapat')->where('id', $roomId)->value('nama'),
                'room_id' => $roomId,
                'moda' => 'Luring',
            ]);
        }

        $expectedSlugs = [
            'meet-'.substr($meetingDate, 0, 7).'-dihaga-room-01',
            'meet-'.substr($meetingDate, 0, 7).'-bunaken-room-01',
            'meet-'.substr($meetingDate, 0, 7).'-dihaga-room-02',
        ];
        foreach ($meetingIds as $index => $meetingId) {
            $meeting = DB::table('rapat')->where('id', $meetingId)->first();
            $this->assertSame(substr($expectedSlugs[$index], 5), \App\Support\MeetingData::slug($meeting));
            $this->get('/'.$expectedSlugs[$index])->assertOk()->assertSee('Rapat Slug '.($index + 1));
        }

        $this->withSession($this->superAdminSession())
            ->get('/'.$expectedSlugs[0])
            ->assertOk()
            ->assertSee('Rapat Slug 1')
            ->assertSee('Presensi rapat');
    }

    public function test_super_admin_dashboard_shows_invite_group_navigation(): void
    {
        $this->withSession($this->superAdminSession())
            ->get('/')
            ->assertOk()
            ->assertSee('Grup Undangan')
            ->assertSee('href="'.route('admin', 'grup').'"', false);
    }

    public function test_dashboard_calendar_includes_official_national_holidays_and_collective_leave(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="calendar-title" class="mt-0">Kalender rapat</h2>', false)
            ->assertSee('Libur nasional')
            ->assertSee('Cuti bersama')
            ->assertSee('has-national-holiday')
            ->assertSee('has-collective-leave')
            ->assertSee('2026-03-19')
            ->assertSee('Hari Suci Nyepi (Tahun Baru Saka 1948)')
            ->assertSee('2027-12-26')
            ->assertSee('Isra Mikraj Nabi Muhammad saw.')
            ->assertSee('https://setneg.go.id/baca/index/inilah_skb_3_menteri_libur_nasional_dan_cuti_bersama_2026');
    }

    public function test_meeting_invite_picker_groups_employees_by_unit(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Keperawatan',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('pegawai_invite_groups')->insert([
            'pegawai_id' => $employeeId,
            'group_name' => 'Keperawatan',
        ]);
        DB::table('pegawai_invite_group_catalog')->insert(['group_name' => 'Keperawatan']);

        $this->withSession($this->superAdminSession())
            ->get('/admin/rapat?edit=0')
            ->assertOk()
            ->assertSee('Keperawatan')
            ->assertSee('Pilih semua pegawai dalam grup')
            ->assertSee('Kosongkan pilihan grup')
            ->assertSee('Pegawai Keperawatan');
    }

    public function test_employee_roster_import_only_adds_missing_employees_and_group_metadata(): void
    {
        $existingId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Nama Lama',
            'jabatan' => 'Jabatan Lama',
            'password_hash' => Hash::make('password-lama'),
        ]);

        $importer = new EmployeeRosterImporter;
        $records = [
            [
                'nip' => '123456789012345678',
                'nama' => 'Nama Baru Dari Sumber',
                'jabatan' => 'Jabatan Baru Dari Sumber',
                'group_name' => 'Keperawatan',
            ],
            [
                'nip' => '876543210987654321',
                'nama' => 'Pegawai Baru',
                'jabatan' => 'Dosen',
                'group_name' => 'Farmasi',
            ],
            [
                'nip' => '987654321098765432',
                'nama' => 'Pegawai Pensiun',
                'jabatan' => 'Pegawai',
                'group_name' => 'Pensiun',
            ],
        ];

        $this->assertSame([
            'employees_added' => 1,
            'employees_existing' => 1,
            'groups_added' => 2,
            'employees_skipped' => 1,
        ], $importer->import($records));

        $this->assertDatabaseHas('pegawai', [
            'id' => $existingId,
            'nip' => '123456789012345678',
            'nama' => 'Nama Lama',
            'jabatan' => 'Jabatan Lama',
        ]);
        $this->assertDatabaseHas('pegawai_invite_groups', [
            'pegawai_id' => $existingId,
            'group_name' => 'Keperawatan',
        ]);

        $newEmployee = DB::table('pegawai')->where('nip', '876543210987654321')->first();
        $this->assertSame('Pegawai Baru', $newEmployee->nama);
        $this->assertTrue((bool) $newEmployee->password_change_required);
        $this->assertTrue(Hash::check('876543210987654321', $newEmployee->password_hash));
        $this->assertDatabaseHas('pegawai_invite_groups', [
            'pegawai_id' => $newEmployee->id,
            'group_name' => 'Farmasi',
        ]);
        $this->assertDatabaseHas('pegawai_invite_group_catalog', [
            'group_name' => 'Farmasi',
        ]);
        $this->assertDatabaseMissing('pegawai', ['nip' => '987654321098765432']);

        $this->assertSame([
            'employees_added' => 0,
            'employees_existing' => 2,
            'groups_added' => 0,
            'employees_skipped' => 1,
        ], $importer->import($records));
    }

    public function test_dashboard_offers_guest_attendance_for_an_active_meeting(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat untuk presensi tamu',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(30)->format('H:i:s'),
            'selesai' => now()->addMinutes(30)->format('H:i:s'),
            'moda' => 'Luring',
            'started_at' => now()->subMinutes(30),
            'kode_presensi' => 'TAMU42',
            'presensi_kedaluwarsa' => now()->addMinutes(15),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Presensi Tamu & Mahasiswa')
            ->assertSee('class="btn top-guest-attendance"', false)
            ->assertSee(route('guest-attendance'));

        $this->get(route('guest-attendance'))
            ->assertOk()
            ->assertSee('Presensi Tamu & Mahasiswa')
            ->assertSee('Rapat untuk presensi tamu')
            ->assertSee('Mahasiswa')
            ->assertSee('Rapat yang dihadiri')
            ->assertSee('Kode presensi')
            ->assertSee('name="meeting_id"', false);

        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $this->get(route('meetings.canonical', \App\Support\MeetingData::slug($meeting)))
            ->assertOk()
            ->assertSee('Presensi Tamu & Mahasiswa')
            ->assertSee(route('guest-attendance', ['meeting_id' => $meetingId]));
        $this->get(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertOk()
            ->assertSee('<option value="'.$meetingId.'" selected>', false);
    }

    public function test_dashboard_hides_guest_attendance_link_for_logged_in_accounts(): void
    {
        $passwordHash = Hash::make('pegawai-password');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Tes',
            'jabatan' => 'Pegawai',
            'password_hash' => $passwordHash,
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
            'active_role' => 'pegawai',
        ])->get('/')
            ->assertOk()
            ->assertDontSee('Presensi Tamu & Mahasiswa')
            ->assertDontSee(route('guest-attendance'));

        $this->withSession(['admin_id' => 1])->get('/')
            ->assertOk()
            ->assertDontSee('Presensi Tamu & Mahasiswa')
            ->assertDontSee(route('guest-attendance'));
    }

    public function test_guest_attendance_form_stays_visible_when_no_meeting_is_open(): void
    {
        $this->get(route('guest-attendance'))
            ->assertOk()
            ->assertSee('Belum ada rapat yang membuka presensi')
            ->assertSee('name="nama"', false)
            ->assertSee('name="asal"', false)
            ->assertSee('name="jenis"', false)
            ->assertSee('name="kode"', false)
            ->assertSee('Kode presensi hadir / pulang')
            ->assertSee('name="meeting_id" required disabled', false)
            ->assertSee('type="submit" disabled', false);
    }

    public function test_guest_attendance_is_recorded_separately_and_rejects_duplicate_identity(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat presensi tamu',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(30)->format('H:i:s'),
            'selesai' => now()->addMinutes(30)->format('H:i:s'),
            'moda' => 'Luring',
            'started_at' => now()->subMinutes(30),
            'kode_presensi' => 'TAMU42',
            'presensi_kedaluwarsa' => now()->addMinutes(15),
        ]);

        $payload = [
            'meeting_id' => $meetingId,
            'jenis' => 'tamu',
            'nama' => '  Budi   Santoso ',
            'asal' => 'Poltekkes Manado',
            'kode' => 'tamu42',
            'website' => '',
        ];

        $this->post(route('guest-attendance.store'), $payload)
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertSessionHas('status', 'Absen masuk tamu/mahasiswa berhasil dicatat.');

        $this->assertDatabaseHas('kehadiran_tamu', [
            'rapat_id' => $meetingId,
            'jenis' => 'tamu',
            'nama' => 'Budi   Santoso',
            'asal' => 'Poltekkes Manado',
            'nim' => null,
        ]);
        $this->assertDatabaseCount('kehadiran', 0);

        $this->from(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->post(route('guest-attendance.store'), [
                ...$payload,
                'nama' => 'budi santoso',
                'asal' => ' poltekkes   manado ',
            ])
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertSessionHasErrors('identitas');

        $this->assertDatabaseCount('kehadiran_tamu', 1);
        $this->withSession(['admin_id' => 1])->get('/admin/rapat')
            ->assertOk()
            ->assertSee('Presensi tamu / mahasiswa')
            ->assertSee('Budi   Santoso')
            ->assertSee('Poltekkes Manado');
    }

    public function test_student_guest_attendance_requires_nim_and_rejects_wrong_code(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat mahasiswa',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(30)->format('H:i:s'),
            'selesai' => now()->addMinutes(30)->format('H:i:s'),
            'moda' => 'Luring',
            'kode_presensi' => 'TAMU42',
            'presensi_kedaluwarsa' => now()->addMinutes(15),
        ]);

        $payload = [
            'meeting_id' => $meetingId,
            'jenis' => 'mahasiswa',
            'nama' => 'Mahasiswa Tes',
            'asal' => 'Keperawatan',
            'kode' => 'SALAH',
        ];

        $this->from(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->post(route('guest-attendance.store'), $payload)
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertSessionHasErrors('nim');

        $this->from(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->post(route('guest-attendance.store'), [
                ...$payload,
                'nim' => '20260001',
            ])
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertSessionHasErrors('kode');

        $this->assertDatabaseCount('kehadiran_tamu', 0);
    }

    public function test_student_nim_prevents_duplicate_guest_attendance(): void
    {
        $startedAt = now()->subMinutes(30)->startOfMinute();
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat mahasiswa unik',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(30)->format('H:i:s'),
            'selesai' => now()->addMinutes(30)->format('H:i:s'),
            'moda' => 'Luring',
            'started_at' => $startedAt,
            'kode_presensi' => 'TAMU42',
            'presensi_kedaluwarsa' => now()->addMinutes(15),
        ]);
        $payload = [
            'meeting_id' => $meetingId,
            'jenis' => 'mahasiswa',
            'nama' => 'Mahasiswa Pertama',
            'asal' => 'Keperawatan',
            'nim' => '20260001',
            'kode' => 'TAMU42',
        ];

        $this->post(route('guest-attendance.store'), $payload)
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]));
        $this->from(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->post(route('guest-attendance.store'), [
                ...$payload,
                'nama' => 'Nama Berbeda',
                'asal' => 'Program Studi Berbeda',
            ])
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertSessionHasErrors('identitas');

        $this->assertDatabaseCount('kehadiran_tamu', 1);
    }

    public function test_guest_uses_the_same_form_to_record_check_in_and_check_out(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat presensi masuk dan pulang',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(30)->format('H:i:s'),
            'selesai' => now()->addMinutes(30)->format('H:i:s'),
            'moda' => 'Luring',
            'started_at' => now()->subMinutes(30),
            'kode_presensi' => 'MASUK42',
            'presensi_kedaluwarsa' => now()->addMinutes(15),
            'kode_presensi_pulang' => 'PULANG42',
            'presensi_pulang_kedaluwarsa' => now()->addHour(),
        ]);
        $payload = [
            'meeting_id' => $meetingId,
            'jenis' => 'mahasiswa',
            'nama' => 'Mahasiswa Pulang',
            'asal' => 'Keperawatan',
            'nim' => '20260042',
        ];

        $this->post(route('guest-attendance.store'), [...$payload, 'kode' => 'MASUK42'])
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertSessionHas('status', 'Absen masuk tamu/mahasiswa berhasil dicatat.');

        $this->get(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertOk()
            ->assertSee('Absen masuk sudah tercatat')
            ->assertSee('value="Mahasiswa Pulang"', false)
            ->assertSee('value="Keperawatan"', false)
            ->assertSee('value="20260042"', false)
            ->assertSee('value="'.$meetingId.'" selected', false);

        DB::table('rapat')->where('id', $meetingId)->update([
            'ended_at' => now(),
            'presensi_kedaluwarsa' => now()->subMinute(),
        ]);

        $this->get(route('guest-attendance'))
            ->assertOk()
            ->assertSee('Rapat presensi masuk dan pulang')
            ->assertSee('Absen pulang dibuka');

        $this->post(route('guest-attendance.store'), [...$payload, 'kode' => 'PULANG42'])
            ->assertRedirect(route('guest-attendance', ['meeting_id' => $meetingId]))
            ->assertSessionHas('status', 'Absen pulang tamu/mahasiswa berhasil dicatat.');

        $this->assertDatabaseHas('kehadiran_tamu', [
            'rapat_id' => $meetingId,
            'nim' => '20260042',
        ]);
        $this->assertNotNull(DB::table('kehadiran_tamu')->where('rapat_id', $meetingId)->value('waktu_pulang'));
        $this->assertDatabaseCount('kehadiran_tamu', 1);
    }

    public function test_login_page_is_separate_from_the_dashboard(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('>NIP</label>', false)
            ->assertSee('Kata sandi')
            ->assertSee('name="identifier"', false)
            ->assertSee('name="password"', false);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('NIP')
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
                'ended_at' => now()->subDays($number)->setTime(10, 0),
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
            'started_at' => now()->subMinutes(20),
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
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
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
        DB::table('kehadiran_tamu')->insert([
            'rapat_id' => $meetingId,
            'jenis' => 'mahasiswa',
            'nama' => 'Mahasiswa Presensi Terpisah',
            'asal' => 'Poltekkes Manado',
            'nim' => '20260099',
            'identitas_hash' => str_repeat('a', 64),
            'waktu' => now()->subDay()->setTime(9, 10),
        ]);

        $attendancePage = $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Presensi rapat selesai')
            ->assertSee('Buka rapat, lalu pilih jenis presensi pegawai atau tamu/mahasiswa.')
            ->assertSee('data-attendance-type-select', false)
            ->assertSee('Pegawai (1)')
            ->assertSee('Tamu / Mahasiswa (1)')
            ->assertSee('Instansi / program studi')
            ->assertSee('Keterangan tidak absen pulang')
            ->assertSee('Rapat Selesai Untuk Ditinjau')
            ->assertSee('1 pegawai')
            ->assertSee('1 tamu/mahasiswa')
            ->assertSee('Hadir Rapat Selesai')
            ->assertSee('123456789012345678')
            ->assertSee('Mahasiswa Presensi Terpisah')
            ->assertSee('Poltekkes Manado')
            ->assertSee('20260099')
            ->assertSee('Daftar izin rapat')
            ->assertSee('Izin Rapat Selesai')
            ->assertSee('Izin menunggu')
            ->assertSee('Sakit')
            ->assertSee('Demam')
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
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
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
                'started_at' => now()->subDay()->setTime(9, 0),
                'ended_at' => now()->subDay()->setTime(10, 0),
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
            ->assertSee('Cari judul, tanggal, atau tempat rapat...')
            ->assertSee('data-admin-completed-search', false)
            ->assertSee('Tampilkan 2 rapat selesai lainnya')
            ->assertSee('Rapat mendatang tetap terlihat')
            ->getContent();

        preg_match_all('/<article[^>]*data-completed-index="(?:5|6)"[^>]*\shidden(?:\s|>)[^>]*>/s', $html, $collapsedMeetings);
        $this->assertCount(2, $collapsedMeetings[0]);
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
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
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
                'started_at' => now()->subDays($number)->setTime(9, 0),
                'ended_at' => now()->subDays($number)->setTime(10, 0),
            ]);
        }

        $html = $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Tampilkan lainnya')
            ->assertSee('+2')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="attendance-meeting-card hidden" data-completed-attendance-meeting hidden'));
        $this->assertStringContainsString('Presensi rapat selesai 1', $html);
        $this->assertStringContainsString('Presensi rapat selesai 5', $html);
    }

    public function test_completed_meeting_attendance_can_be_exported_to_excel_for_each_attendance_type(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Rekap Presensi',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
        ]);
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Ekspor',
            'jabatan' => 'Staf',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->subDay()->setTime(9, 5),
            'alasan_pulang' => 'Keperluan <keluarga> & lainnya',
        ]);
        DB::table('kehadiran_tamu')->insert([
            'rapat_id' => $meetingId,
            'jenis' => 'mahasiswa',
            'nama' => 'Mahasiswa Ekspor',
            'asal' => 'Poltekkes Manado',
            'nim' => 'MHS001',
            'identitas_hash' => hash('sha256', 'MHS001'),
            'waktu' => now()->subDay()->setTime(9, 10),
        ]);

        $meetingManagerId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Pengelola Tanpa Akses Presensi',
            'jabatan' => 'Pengelola',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $meetingManagerId]);
        $this->withSession([
            'employee_id' => $meetingManagerId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $meetingManagerId)->value('password_hash'),
            'active_role' => 'admin',
        ])->get(route('admin.attendance.export', [$meetingId, 'pegawai']))
            ->assertForbidden();

        $this->get(route('admin.attendance.export', [$meetingId, 'pegawai']))
            ->assertForbidden();

        $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee(route('admin.attendance.export', [$meetingId, 'pegawai']))
            ->assertSee(route('admin.attendance.export', [$meetingId, 'tamu']))
            ->assertSee('Ekspor Excel pegawai')
            ->assertSee('Ekspor Excel tamu/mahasiswa');

        $employeeExport = $this->withSession(['admin_id' => 1])
            ->get(route('admin.attendance.export', [$meetingId, 'pegawai']));
        $employeeExport->assertOk()
            ->assertDownload('rapat-rekap-presensi-presensi-pegawai.xls')
            ->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');
        $this->assertStringContainsString('Nama', $employeeExport->streamedContent());
        $this->assertStringContainsString('Pegawai Ekspor', $employeeExport->streamedContent());
        $this->assertStringContainsString('Keperluan &lt;keluarga&gt; &amp; lainnya', $employeeExport->streamedContent());

        $guestExport = $this->withSession(['admin_id' => 1])
            ->get(route('admin.attendance.export', [$meetingId, 'tamu']));
        $guestExport->assertOk()->assertDownload('rapat-rekap-presensi-presensi-tamu.xls');
        $this->assertStringContainsString('Mahasiswa Ekspor', $guestExport->streamedContent());
        $this->assertStringContainsString('Poltekkes Manado', $guestExport->streamedContent());
        $this->assertStringContainsString('Belum absen pulang', $guestExport->streamedContent());
    }

    public function test_meeting_pdf_is_available_after_both_signers_approve_the_latest_minutes(): void
    {
        $chairPasswordHash = Hash::make('password-pimpinan');
        $chairId = DB::table('pegawai')->insertGetId([
            'nip' => '111111111111111111',
            'nama' => 'Pimpinan Tes',
            'jabatan' => 'Dosen',
            'password_hash' => $chairPasswordHash,
        ]);
        $minuteTakerPasswordHash = Hash::make('password-notulis');
        $minuteTakerId = DB::table('pegawai')->insertGetId([
            'nip' => '222222222222222222',
            'nama' => 'Notulis Tes',
            'jabatan' => 'Dosen',
            'password_hash' => $minuteTakerPasswordHash,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Format Contoh',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'tempat' => 'Ruang Bunaken',
            'moda' => 'Luring',
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
            'pimpinan_pegawai_id' => $chairId,
            'pimpinan' => 'Pimpinan Tes',
            'notulis_pegawai_id' => $minuteTakerId,
            'notulis' => 'Notulis Tes',
            'agenda' => 'Evaluasi layanan',
            'notulen' => 'Pembahasan <penting> & tindak lanjut.',
        ]);
        $attendeeId = DB::table('pegawai')->insertGetId([
            'nip' => '333333333333333333',
            'nama' => 'Pegawai Dokumen',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('password-pegawai'),
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $attendeeId,
            'status' => 'hadir',
            'waktu' => now()->subDay()->setTime(9, 5),
        ]);
        DB::table('kehadiran_tamu')->insert([
            'rapat_id' => $meetingId,
            'jenis' => 'mahasiswa',
            'nama' => 'Mahasiswa Dokumen',
            'asal' => 'Jurusan Kesehatan',
            'nim' => 'MHS002',
            'identitas_hash' => hash('sha256', 'MHS002'),
            'waktu' => now()->subDay()->setTime(9, 10),
        ]);
        DB::table('penugasan')->insert([
            'rapat_id' => $meetingId,
            'nama_petugas' => 'PIC Rapat',
            'tugas' => 'Menindaklanjuti evaluasi',
            'deadline' => now()->addDays(2)->toDateString(),
            'status' => 'Belum Selesai',
        ]);
        foreach ([
            ['pegawai_id' => $chairId, 'peran' => 'pimpinan', 'token' => str_repeat('a', 64)],
            ['pegawai_id' => $minuteTakerId, 'peran' => 'notulis', 'token' => str_repeat('b', 64)],
        ] as $signature) {
            DB::table('meeting_signatures')->insert([
                'rapat_id' => $meetingId,
                ...$signature,
                'status' => 'menunggu',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $meetingSlug = \App\Support\MeetingData::slug(DB::table('rapat')->where('id', $meetingId)->first());
        $route = route('meetings.report.canonical', $meetingSlug);
        $legacyRoute = route('meetings.report.export', $meetingId);
        $this->get($route)->assertForbidden();
        $this->get($legacyRoute)->assertForbidden();
        $employeeSession = [
            'employee_id' => $attendeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $attendeeId)->value('password_hash'),
            'active_role' => 'pegawai',
        ];
        $this->withSession($employeeSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee($route)
            ->assertSee('Unduh PDF Notulen + Daftar Hadir');
        $employeeDownload = $this->withSession($employeeSession)->get($route);
        $employeeDownload->assertOk()
            ->assertDownload('rapat-format-contoh-notulen-daftar-hadir.pdf')
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $employeeDownload->streamedContent());
        $reportHtml = view('documents.meeting-report', [
            'meeting' => DB::table('rapat')->where('id', $meetingId)->first(),
            'attendanceRows' => [],
            'followUps' => [],
            'signatures' => [
                (object) ['qr_code' => 'data:image/svg+xml;base64,', 'pegawai_nama' => 'Pimpinan Tes'],
                (object) ['qr_code' => 'data:image/svg+xml;base64,', 'pegawai_nama' => 'Notulis Tes'],
            ],
        ])->render();
        $this->assertStringNotContainsString('Belum dikonfirmasi', $reportHtml);
        $this->assertStringNotContainsString('Dokumen belum terverifikasi secara digital', $reportHtml);
        $this->assertStringNotContainsString('Disetujui', $reportHtml);
        $this->assertStringContainsString('Pindai QR untuk memeriksa status verifikasi tanda tangan digital', $reportHtml);
        $this->withSession($employeeSession)->get($legacyRoute)
            ->assertOk()
            ->assertDownload('rapat-format-contoh-notulen-daftar-hadir.pdf');
        $this->withSession(['admin_id' => 1])->get('/admin/rapat')
            ->assertOk()
            ->assertSee($route)
            ->assertSee('Unduh PDF Notulen + Daftar Hadir');
        $this->withSession(['admin_id' => 1])->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee($route);

        $pendingDownload = $this->withSession(['admin_id' => 1])->get($route);
        $pendingDownload->assertOk()
            ->assertDownload('rapat-format-contoh-notulen-daftar-hadir.pdf')
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pendingDownload->streamedContent());
        $this->withSession(['admin_id' => 1])->get($legacyRoute)
            ->assertOk()
            ->assertDownload('rapat-format-contoh-notulen-daftar-hadir.pdf');

        $chairSession = [
            'employee_id' => $chairId,
            'employee_password_hash' => $chairPasswordHash,
            'active_role' => 'pegawai',
        ];
        $minuteTakerSession = [
            'employee_id' => $minuteTakerId,
            'employee_password_hash' => $minuteTakerPasswordHash,
            'active_role' => 'pegawai',
        ];
        $meetingRecord = DB::table('rapat')->where('id', $meetingId)->first();
        $meetingDetailUrl = route('meetings.canonical', \App\Support\MeetingData::slug($meetingRecord));
        $this->get(route('signatures.verify', str_repeat('a', 64)))
            ->assertOk()
            ->assertSee('Surat Belum Terverifikasi')
            ->assertSee('belum terverifikasi karena persetujuan tanda tangan digital')
            ->assertSee('Pimpinan Tes')
            ->assertSee('TANGGAL KONFIRMASI TANDA TANGAN')
            ->assertSee('RAPAT YANG DIKONFIRMASI');
        $this->withSession($chairSession)->get(route('signatures.index'))
            ->assertOk()
            ->assertSee('Rapat Format Contoh')
            ->assertSee('PIMPINAN RAPAT')
            ->assertSee('href="'.$meetingDetailUrl.'"', false)
            ->assertSee('Terima & setujui');
        $this->withSession($chairSession)->get($meetingDetailUrl)
            ->assertOk()
            ->assertSee('Rapat Format Contoh')
            ->assertSee('Notulen rapat');
        $this->withSession($chairSession)->post(route('signatures.accept', [$meetingId, 'pimpinan']))
            ->assertRedirect(route('signatures.index'));
        $this->withSession($minuteTakerSession)->post(route('signatures.accept', [$meetingId, 'notulis']))
            ->assertRedirect(route('signatures.index'));

        $this->get(route('signatures.verify', str_repeat('a', 64)))
            ->assertOk()
            ->assertSee('Notulen Rapat Terverifikasi')
            ->assertSee('Pimpinan Tes')
            ->assertSee('NIP 111111111111111111')
            ->assertSee('TANGGAL KONFIRMASI TANDA TANGAN')
            ->assertSee('RAPAT YANG DIKONFIRMASI')
            ->assertSee('AAAA-AAAA-AAAA');

        $download = $this->withSession(['admin_id' => 1])->get($route);
        $download->assertOk()
            ->assertDownload('rapat-format-contoh-notulen-daftar-hadir.pdf')
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());

        DB::table('rapat')->where('id', $meetingId)->update([
            'notulen' => 'Notulen telah diperbarui setelah persetujuan.',
        ]);
        $this->get(route('signatures.verify', str_repeat('a', 64)))
            ->assertOk()
            ->assertSee('Persetujuan Tidak Terverifikasi')
            ->assertSee('QR tidak cocok dengan persetujuan aktif');
        $this->withSession($chairSession)->get(route('signatures.index'))
            ->assertOk()
            ->assertSee('Menunggu persetujuan');
    }

    public function test_new_admin_meeting_creates_signature_requests_for_selected_employees(): void
    {
        $chairId = DB::table('pegawai')->insertGetId([
            'nip' => '444444444444444444',
            'nama' => 'Pimpinan Baru',
            'jabatan' => 'Dosen',
        ]);
        $minuteTakerId = DB::table('pegawai')->insertGetId([
            'nip' => '555555555555555555',
            'nama' => 'Notulis Baru',
            'jabatan' => 'Dosen',
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'simpan_rapat',
            'id' => 0,
            'judul' => 'Rapat Dengan Penanda Tangan',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'pimpinan_pegawai_id' => $chairId,
            'notulis_pegawai_id' => $minuteTakerId,
            'semua_pegawai' => 1,
        ])->assertRedirect(route('admin', 'rapat'));

        $meetingId = DB::table('rapat')->where('judul', 'Rapat Dengan Penanda Tangan')->value('id');
        $this->assertNotNull($meetingId);
        $this->assertDatabaseHas('meeting_signatures', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $chairId,
            'peran' => 'pimpinan',
            'status' => 'menunggu',
        ]);
        $this->assertDatabaseHas('meeting_signatures', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $minuteTakerId,
            'peran' => 'notulis',
            'status' => 'menunggu',
        ]);
    }

    public function test_signature_notifications_show_before_meeting_is_ready_for_approval(): void
    {
        $passwordHash = Hash::make('password-penanda-tangan');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '666666666666666666',
            'nama' => 'Pegawai Penanda Tangan',
            'jabatan' => 'Dosen',
            'password_hash' => $passwordHash,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Sedang Berlangsung',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subHour()->format('H:i:s'),
            'selesai' => now()->addHour()->format('H:i:s'),
            'moda' => 'Luring',
            'pimpinan' => 'Pegawai Penanda Tangan',
            'notulis' => 'Pegawai Penanda Tangan',
            'pimpinan_pegawai_id' => $employeeId,
            'notulis_pegawai_id' => $employeeId,
            'notulen' => '',
        ]);

        foreach (['pimpinan', 'notulis'] as $index => $role) {
            DB::table('meeting_signatures')->insert([
                'rapat_id' => $meetingId,
                'pegawai_id' => $employeeId,
                'peran' => $role,
                'token' => str_repeat((string) ($index + 1), 64),
                'status' => 'menunggu',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
            'active_role' => 'pegawai',
        ];
        $this->withSession($session)->get(route('signatures.index'))
            ->assertOk()
            ->assertSee('2 notifikasi menunggu')
            ->assertSee('Rapat Sedang Berlangsung')
            ->assertSee('PIMPINAN RAPAT')
            ->assertSee('NOTULIS')
            ->assertSee('Menunggu rapat selesai')
            ->assertDontSee('Terima &amp; setujui');

        $this->withSession($session)->get('/')
            ->assertOk()
            ->assertSee('signature-nav-count')
            ->assertSee('>2</span>', false);
    }

    public function test_opening_signature_inbox_clears_cancelled_meeting_badges_but_keeps_pending_signatures(): void
    {
        $passwordHash = Hash::make('password-penanda-tangan');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '777777777777777777',
            'nama' => 'Pegawai Penanda Tangan',
            'jabatan' => 'Dosen',
            'password_hash' => $passwordHash,
        ]);
        $cancelledMeetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Dibatalkan',
            'tanggal' => now()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'pimpinan_pegawai_id' => $employeeId,
            'notulen' => 'Notulen rapat yang dibatalkan.',
            'cancelled_at' => now(),
        ]);
        $pendingMeetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Menunggu Tanda Tangan',
            'tanggal' => now()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'pimpinan_pegawai_id' => $employeeId,
            'notulen' => '',
        ]);

        foreach ([
            [$cancelledMeetingId, str_repeat('c', 64)],
            [$pendingMeetingId, str_repeat('d', 64)],
        ] as [$meetingId, $token]) {
            DB::table('meeting_signatures')->insert([
                'rapat_id' => $meetingId,
                'pegawai_id' => $employeeId,
                'peran' => 'pimpinan',
                'token' => $token,
                'status' => 'menunggu',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
            'active_role' => 'pegawai',
        ];

        $this->withSession($session)->get('/')
            ->assertOk()
            ->assertSee('signature-nav-count')
            ->assertSee('>2</span>', false);

        $this->withSession($session)->get(route('signatures.index'))
            ->assertOk()
            ->assertSee('Rapat Dibatalkan')
            ->assertSee('Rapat dibatalkan')
            ->assertSee('Rapat Menunggu Tanda Tangan')
            ->assertSee('1 notifikasi menunggu');
        $this->assertDatabaseHas('meeting_signatures', [
            'rapat_id' => $cancelledMeetingId,
        ]);
        $this->assertNotNull(DB::table('meeting_signatures')
            ->where('rapat_id', $cancelledMeetingId)
            ->value('notification_seen_at'));

        $this->withSession($session)->get('/')
            ->assertOk()
            ->assertSee('signature-nav-count')
            ->assertSee('>1</span>', false)
            ->assertDontSee('>2</span>', false);
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

    public function test_employee_can_change_password_from_profile_after_verifying_current_password(): void
    {
        $passwordHash = Hash::make('kata-sandi-lama');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Profil',
            'jabatan' => 'Dosen',
            'password_hash' => $passwordHash,
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
        ];

        $this->withSession($session)->get(route('profile'))
            ->assertOk()
            ->assertSee('Ganti kata sandi')
            ->assertSee('Foto profil')
            ->assertSee(route('profile'))
            ->assertSee('profile-nav-avatar-fallback')
            ->assertSee('>pp</span>', false)
            ->assertSee('Pegawai Profil');

        $this->withSession($session)->post(route('profile.password.update'), [
            'current_password' => 'sandi-salah',
            'password' => 'kata-sandi-baru',
            'password_confirmation' => 'kata-sandi-baru',
        ])->assertSessionHasErrors('current_password');

        $this->withSession($session)->post(route('profile.password.update'), [
            'current_password' => 'kata-sandi-lama',
            'password' => 'kata-sandi-baru',
            'password_confirmation' => 'kata-sandi-baru',
        ])->assertRedirect(route('profile'))
            ->assertSessionHas('status', 'Kata sandi berhasil diubah.');

        $updatedHash = DB::table('pegawai')->where('id', $employeeId)->value('password_hash');
        $this->assertTrue(Hash::check('kata-sandi-baru', $updatedHash));
        $this->assertSame($updatedHash, session('employee_password_hash'));
        $this->get(route('profile'))->assertOk();
    }

    public function test_employee_can_upload_and_view_private_profile_photo(): void
    {
        Storage::fake('meeting_uploads');
        $passwordHash = Hash::make('password-tes');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Foto',
            'jabatan' => 'Dosen',
            'password_hash' => $passwordHash,
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
        ];

        $this->withSession($session)->post(route('profile.photo.update'), [
            'profile_photo' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'foto-profil.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/qE0AAAAASUVORK5CYII=')
            ),
        ])->assertRedirect(route('profile'))
            ->assertSessionHas('status', 'Foto profil berhasil diperbarui.');

        $filename = DB::table('pegawai')->where('id', $employeeId)->value('profile_photo');
        $this->assertNotEmpty($filename);
        Storage::disk('meeting_uploads')->assertExists($filename);
        $this->withSession($session)->get(route('profile.photo'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->withSession($session)->get(route('profile'))
            ->assertOk()
            ->assertSee(route('profile.photo'));
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

    public function test_employee_with_required_password_change_cannot_access_admin_exports_or_files(): void
    {
        $passwordHash = Hash::make('kata-sandi-sementara');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Super Admin Belum Ganti Sandi',
            'jabatan' => 'Administrator',
            'password_hash' => $passwordHash,
            'password_change_required' => true,
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => true,
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
            'active_role' => 'super_admin',
        ];

        $this->withUntrustedSession($session)->get('/admin/superadmin')
            ->assertRedirect(route('employee.password'));
        $this->withUntrustedSession($session)->post('/admin/actions', [
            'aksi' => 'hapus_pegawai',
            'id' => 999,
        ])->assertRedirect(route('employee.password'));
        $this->withUntrustedSession($session)->get('/admin/attendance/1/pegawai/export')
            ->assertRedirect(route('employee.password'));
        $this->withUntrustedSession($session)->get('/meetings/1/minutes-attendance/export')
            ->assertRedirect(route('employee.password'));
        $this->withUntrustedSession($session)->get('/files/meeting-document.pdf')
            ->assertRedirect(route('employee.password'));
        $this->withUntrustedSession($session)->post('/role/switch', ['role' => 'pegawai'])
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

    public function test_deleting_meetings_or_employees_removes_unreferenced_leave_attachments(): void
    {
        Storage::fake('meeting_uploads');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Penghapus',
            'jabatan' => 'Dosen',
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat dengan lampiran izin',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $meetingAttachment = 'meeting-leave-proof.pdf';
        Storage::disk('meeting_uploads')->put($meetingAttachment, 'attachment');
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'izin',
            'file' => $meetingAttachment,
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'hapus_rapat',
            'id' => $meetingId,
        ])->assertRedirect('/admin/rapat');
        Storage::disk('meeting_uploads')->assertMissing($meetingAttachment);

        $secondMeetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat pegawai dengan lampiran',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '11:00',
            'selesai' => '12:00',
            'moda' => 'Luring',
        ]);
        $employeeAttachment = 'employee-leave-proof.pdf';
        Storage::disk('meeting_uploads')->put($employeeAttachment, 'attachment');
        DB::table('kehadiran')->insert([
            'rapat_id' => $secondMeetingId,
            'pegawai_id' => $employeeId,
            'status' => 'izin',
            'file' => $employeeAttachment,
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'hapus_pegawai',
            'id' => $employeeId,
        ])->assertRedirect('/admin/pegawai');
        Storage::disk('meeting_uploads')->assertMissing($employeeAttachment);
        $this->assertDatabaseHas('rapat', ['id' => $secondMeetingId]);
    }

    public function test_leave_attachments_are_only_accessible_to_meeting_managers(): void
    {
        Storage::fake('meeting_uploads');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Biasa',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat lampiran izin',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $filename = 'leave-proof-private.pdf';
        Storage::disk('meeting_uploads')->put($filename, 'private attachment');
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'izin',
            'file' => $filename,
        ]);
        $employeeSession = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ];

        $this->withSession($employeeSession)->get('/files/'.$filename)->assertForbidden();
        $this->withSession($this->superAdminSession())->get('/files/'.$filename)->assertOk();
    }

    public function test_meeting_manager_cannot_manage_employee_accounts(): void
    {
        $managerId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pengelola Biasa',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '876543210987654321',
            'nama' => 'Pegawai Target',
            'jabatan' => 'Staf',
            'password_hash' => Hash::make('sandi-lama'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $managerId]);
        $session = [
            'employee_id' => $managerId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $managerId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'tambah_pegawai',
            'nama' => 'Pegawai Tidak Diizinkan',
            'nip' => '111111111111111111',
            'jabatan' => 'Staf',
        ])->assertForbidden();
        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'atur_password_pegawai',
            'id' => $employeeId,
            'password_pegawai' => 'sandi-baru',
        ])->assertForbidden();
        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'ubah_jabatan',
            'id' => $employeeId,
            'jabatan' => 'Jabatan Baru',
        ])->assertForbidden();

        $this->assertDatabaseMissing('pegawai', ['nip' => '111111111111111111']);
        $this->assertTrue(password_verify('sandi-lama', DB::table('pegawai')->where('id', $employeeId)->value('password_hash')));
        $this->assertDatabaseHas('pegawai', ['id' => $employeeId, 'jabatan' => 'Staf']);
    }

    public function test_meeting_manager_cannot_edit_meeting_content_without_feature_permissions(): void
    {
        $managerId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pengelola Rapat',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $managerId]);
        DB::table('manager_access_settings')->where('id', 1)->update([
            'buat_rapat' => true,
            'materi' => false,
            'rekaman' => false,
            'notulen' => false,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Dengan Konten Terbatas',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'link_rekaman' => 'https://youtu.be/original',
            'notulen' => 'Notulen asli',
            'file_notulen' => 'original-minutes.pdf',
            'materi_rapat' => 'original-material.pdf',
        ]);
        $session = [
            'employee_id' => $managerId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $managerId)->value('password_hash'),
            'active_role' => 'admin',
        ];
        $requestData = [
            'aksi' => 'simpan_rapat',
            'id' => $meetingId,
            'judul' => 'Rapat Dengan Konten Terbatas',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'semua_pegawai' => '1',
        ];

        $this->withSession($session)->get('/admin/rapat?edit='.$meetingId)
            ->assertOk()
            ->assertDontSee('name="notulen"', false)
            ->assertDontSee('name="file_notulen"', false)
            ->assertDontSee('name="materi_rapat"', false)
            ->assertDontSee('name="link_rekaman"', false);

        foreach ([
            ['notulen' => 'Notulen diubah tanpa izin'],
            ['link_rekaman' => 'https://youtu.be/diubah'],
            ['file_notulen' => \Illuminate\Http\UploadedFile::fake()->create('minutes.pdf', 1, 'application/pdf')],
            ['materi_rapat' => \Illuminate\Http\UploadedFile::fake()->create('material.pdf', 1, 'application/pdf')],
        ] as $unauthorizedChanges) {
            $this->withSession($session)
                ->post('/admin/actions', array_merge($requestData, $unauthorizedChanges))
                ->assertForbidden();
        }

        $this->withSession($session)->post('/admin/actions', $requestData)
            ->assertRedirect('/admin/rapat');
        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'judul' => 'Rapat Dengan Konten Terbatas',
            'link_rekaman' => 'https://youtu.be/original',
            'notulen' => 'Notulen asli',
            'file_notulen' => 'original-minutes.pdf',
            'materi_rapat' => 'original-material.pdf',
        ]);
    }

    public function test_meetings_reject_end_times_that_are_not_after_start_times(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Super Admin Jadwal',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => true,
            'buat_rapat' => true,
            'materi' => true,
            'rekaman' => true,
            'notulen' => true,
            'lihat_daftar_hadir' => true,
            'kelola_ruang' => true,
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'super_admin',
        ];
        $schedule = [
            'judul' => 'Rapat Jadwal Tidak Valid',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '10:00',
            'selesai' => '09:00',
            'moda' => 'Luring',
            'semua_pegawai' => '1',
        ];

        $this->withSession($session)
            ->from('/')->post('/meetings', $schedule)
            ->assertSessionHasErrors('selesai');
        $this->withSession(['admin_id' => 1])
            ->from('/admin/rapat?edit=0')->post('/admin/actions', $schedule + ['aksi' => 'simpan_rapat'])
            ->assertSessionHasErrors('selesai');

        $this->assertDatabaseMissing('rapat', ['judul' => 'Rapat Jadwal Tidak Valid']);
    }

    public function test_rooms_are_always_available_for_meetings_regardless_of_legacy_status(): void
    {
        $roomId = DB::table('ruang_rapat')->insertGetId([
            'nama' => 'Ruang Lama Nonaktif',
            'aktif' => false,
        ]);
        $this->withSession(['admin_id' => 1])
            ->from('/admin/rapat?edit=0')
            ->post('/admin/actions', [
                'aksi' => 'simpan_rapat',
                'judul' => 'Rapat Ruang Nonaktif',
                'tanggal' => now()->addDay()->toDateString(),
                'mulai' => '09:00',
                'selesai' => '10:00',
                'moda' => 'Luring',
                'room_id' => $roomId,
                'semua_pegawai' => '1',
            ])
            ->assertRedirect('/admin/rapat');
        $this->assertDatabaseHas('rapat', [
            'judul' => 'Rapat Ruang Nonaktif',
            'room_id' => $roomId,
        ]);

        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Lama di Ruang Nonaktif',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'tempat' => 'Ruang Lama Nonaktif',
            'room_id' => $roomId,
            'moda' => 'Luring',
        ]);
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'simpan_rapat',
            'id' => $meetingId,
            'judul' => 'Rapat Lama Diperbarui',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'room_id' => $roomId,
            'semua_pegawai' => '1',
        ])->assertRedirect('/admin/rapat');
        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'judul' => 'Rapat Lama Diperbarui',
            'room_id' => $roomId,
        ]);
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
            ->assertSessionHasErrors(['login' => 'NIP atau kata sandi tidak sesuai.'])
            ->assertSessionHas('_old_input.identifier', '123456789012345678')
            ->assertSessionMissing('employee_id');
    }

    public function test_legacy_admin_username_cannot_sign_in(): void
    {
        $this->assertDatabaseMissing('admin', ['username' => 'admin']);

        DB::table('admin')->insert([
            'username' => 'admin',
            'password_hash' => Hash::make('password'),
        ]);
        (require database_path('migrations/2026_10_28_000000_remove_legacy_admin_account.php'))->up();
        $this->assertDatabaseMissing('admin', ['username' => 'admin']);

        DB::table('admin')->insert([
            'username' => 'admin',
            'password_hash' => Hash::make('password'),
        ]);

        $this->from('/login')
            ->post('/login', ['identifier' => 'admin', 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login' => 'NIP atau kata sandi tidak sesuai.'])
            ->assertSessionMissing('admin_id');

        $this->assertDatabaseHas('admin', ['username' => 'admin']);
    }

    public function test_legacy_admin_session_cannot_access_super_admin_features(): void
    {
        $this->withUntrustedSession(['admin_id' => 1])
            ->get('/admin/pegawai')
            ->assertForbidden()
            ->assertSessionMissing('admin_id');

        $this->withUntrustedSession(['admin_id' => 1])
            ->get('/login')
            ->assertOk()
            ->assertSee('Gunakan NIP pegawai untuk masuk.');
    }

    public function test_legacy_admin_session_cannot_bypass_required_password_change(): void
    {
        $passwordHash = Hash::make('kata-sandi-awal');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Wajib Ganti Sandi',
            'jabatan' => 'Pegawai',
            'password_hash' => $passwordHash,
            'password_change_required' => true,
        ]);

        $this->withUntrustedSession([
            'admin_id' => 1,
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
        ])->get('/meetings/1')
            ->assertRedirect('/employee/password');
    }

    public function test_last_super_admin_cannot_be_demoted_or_deleted(): void
    {
        $session = $this->superAdminSession();
        $employeeId = $session['employee_id'];

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'atur_role',
            'id' => $employeeId,
            'role' => 'admin',
        ])->assertSessionHasErrors('role');
        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'atur_role_super_admin',
            'id' => $employeeId,
            'super_admin' => '0',
        ])->assertSessionHasErrors('super_admin');
        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'hapus_super_admin',
            'id' => $employeeId,
        ])->assertSessionHasErrors('role');
        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'hapus_pegawai',
            'id' => $employeeId,
        ])->assertSessionHasErrors('pegawai');

        $this->assertDatabaseHas('super_admin_permissions', [
            'pegawai_id' => $employeeId,
            'is_super_admin' => 1,
        ]);
        $this->assertDatabaseHas('pegawai', ['id' => $employeeId]);
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
            ->assertSee('aria-label="Fitur Pengelola Rapat"', false)
            ->assertSee(route('admin', 'rapat'))
            ->assertDontSee('Dashboard Pengelola Rapat')
            ->assertDontSee('Beralih ke mode Pegawai');
        $this->get('/admin/rapat')
            ->assertOk()
            ->assertSee('Rapat')
            ->assertSee('Pegawai Admin')
            ->assertSee('(Pengelola Rapat)')
            ->assertSee('logo-home-link')
            ->assertSee('aria-label="Kembali ke Dashboard"', false)
            ->assertSee(route('dashboard'));
        $this->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Pegawai Admin')
            ->assertSee('(Pengelola Rapat)')
            ->assertSee('logo-home-link')
            ->assertSee('aria-label="Kembali ke Dashboard"', false);
        $this->get('/admin/pegawai')->assertForbidden();

        $this->post('/role/switch', ['role' => 'pegawai'])->assertRedirect('/');
        $this->assertSame('pegawai', session('active_role'));
        $this->get('/')->assertOk()->assertDontSee('admin-menu-toggle');

        $this->post('/role/switch', ['role' => 'admin'])->assertRedirect('/');
        $this->assertSame('admin', session('active_role'));
    }

    public function test_super_admin_employee_can_return_to_dashboard_from_every_admin_tab(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Super Admin Pegawai',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => true,
            'buat_rapat' => true,
            'materi' => true,
            'rekaman' => true,
            'notulen' => true,
            'lihat_daftar_hadir' => true,
            'kelola_ruang' => true,
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'super_admin',
        ];

        foreach ([$session, ['admin_id' => 1]] as $adminSession) {
            foreach (['rapat', 'ruang', 'kehadiran', 'pegawai', 'superadmin', 'pengaturan'] as $tab) {
                $this->withSession($adminSession)
                    ->get(route('admin', $tab))
                    ->assertOk()
                    ->assertSee('logo-home-link')
                    ->assertSee('aria-label="Kembali ke Dashboard"', false)
                    ->assertSee(route('dashboard'));
            }
        }
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
            'selesai' => now()->addMinutes(10)->format('H:i'),
            'moda' => 'Luring',
            'started_at' => now()->subMinutes(20),
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

        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Perlu pulang lebih awal?')
            ->assertSee('Isi alasan pulang lebih awal');
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_alasan_pulang',
            'alasan_pulang' => 'Ada keperluan keluarga.',
        ])->assertRedirect();
        $this->assertDatabaseHas('kehadiran', [
            'id' => $attendanceId,
            'alasan_pulang' => 'Ada keperluan keluarga.',
        ]);

        $this->withSession($session)->get('/')
            ->assertOk()
            ->assertSee('Akhiri Rapat');
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
            ->assertSee('Alasan pulang lebih awal / belum absen pulang')
            ->assertSee('Alasan tersimpan: Ada keperluan keluarga.');
        $this->withSession(['admin_id' => 1])->get('/admin/rapat?presensi='.$meetingId)
            ->assertOk()
            ->assertSee('Kode absen pulang aktif')
            ->assertSee('Tutup kode absen pulang')
            ->assertSee('Buat kode absen pulang');
        $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Pulang lebih awal')
            ->assertSee('Ada keperluan keluarga.');

        DB::table('kehadiran')->where('id', $attendanceId)->update(['waktu_pulang' => now()]);
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_alasan_pulang',
            'alasan_pulang' => 'Ada keperluan keluarga.',
        ])->assertRedirect()->assertSessionHasErrors([
            'alasan_pulang' => 'Alasan tidak dapat disimpan karena absen pulang sudah tercatat.',
        ]);
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
            'started_at' => now()->subMinutes(20),
        ]);

        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ])->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertDontSee('Selesaikan Rapat')
            ->assertSee('aria-label="Fitur Pengelola Rapat"', false);
        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ])->get('/')
            ->assertOk()
            ->assertSee('Akhiri Rapat');
        $this->withSession([
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ])->get('/admin/rapat')
            ->assertOk()
            ->assertSee('✓ Akhiri Rapat');

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

    public function test_super_admin_can_start_a_meeting_manually_from_the_dashboard(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Dimulai Manual',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $session = $this->superAdminSession();

        $this->withSession($session)->get('/')
            ->assertOk()
            ->assertSee('Mulai Rapat');
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'mulai_rapat',
        ])->assertRedirect();

        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $this->assertNotNull($meeting->started_at);
        $this->assertSame('berlangsung', \App\Support\MeetingData::scheduleStatus($meeting));

        $this->withSession($session)->get('/')
            ->assertOk()
            ->assertSee('Akhiri Rapat')
            ->assertDontSee('Mulai Rapat');
    }

    public function test_meeting_manager_can_start_a_meeting_and_regular_employee_cannot_change_lifecycle(): void
    {
        $managerId = DB::table('pegawai')->insertGetId([
            'nip' => '222222222222222222',
            'nama' => 'Pengelola Tes',
            'jabatan' => 'Pengelola',
            'password_hash' => Hash::make('password-manager'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $managerId]);
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '333333333333333333',
            'nama' => 'Pegawai Tes',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-employee'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Mulai Pengelola',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $managerSession = [
            'employee_id' => $managerId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $managerId)->value('password_hash'),
            'active_role' => 'admin',
        ];
        $employeeSession = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'pegawai',
        ];

        $this->withSession($managerSession)->get('/')
            ->assertOk()
            ->assertSee('Mulai Rapat');
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'mulai_rapat',
            'id' => $meetingId,
        ])->assertRedirect('/admin/rapat');
        $this->assertNotNull(DB::table('rapat')->where('id', $meetingId)->value('started_at'));

        $this->withSession($employeeSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'selesaikan_rapat',
        ])->assertForbidden();
        $unstartedMeetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Belum Dimulai',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '11:00',
            'selesai' => '12:00',
            'moda' => 'Luring',
        ]);
        $this->withSession($employeeSession)->post('/meetings/'.$unstartedMeetingId.'/actions', [
            'aksi' => 'mulai_rapat',
        ])->assertForbidden();
    }

    public function test_expired_meetings_are_cancelled_or_ended_at_midnight(): void
    {
        $timezone = config('app.timezone');
        $meetingDate = \Illuminate\Support\Carbon::parse('2026-11-04 00:00:00', $timezone);
        $deadline = $meetingDate->copy()->addDay()->startOfDay();
        $this->travelTo($deadline->copy()->addMinute());

        $startedMeetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Otomatis Selesai',
            'tanggal' => $meetingDate->toDateString(),
            'mulai' => '20:00',
            'selesai' => '22:00',
            'moda' => 'Luring',
            'started_at' => $meetingDate->copy()->setTime(20, 0),
        ]);
        $startedWithCheckoutId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Kode Pulang Aktif',
            'tanggal' => $meetingDate->toDateString(),
            'mulai' => '20:00',
            'selesai' => '23:30',
            'moda' => 'Luring',
            'started_at' => $meetingDate->copy()->setTime(20, 0),
            'kode_presensi_pulang' => 'PULANG8',
            'presensi_pulang_kedaluwarsa' => $deadline->copy()->addMinutes(15),
        ]);
        $unstartedMeetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Otomatis Dibatalkan',
            'tanggal' => $meetingDate->toDateString(),
            'mulai' => '23:00',
            'selesai' => '23:30',
            'moda' => 'Luring',
            'kode_presensi_pulang' => 'PULANG9',
            'presensi_pulang_kedaluwarsa' => $deadline->copy()->addMinutes(10),
        ]);

        \Illuminate\Support\Facades\Artisan::call('meetings:finalize-expired');

        $finished = DB::table('rapat')->where('id', $startedMeetingId)->first();
        $this->assertTrue(\Illuminate\Support\Carbon::parse($finished->ended_at, $timezone)->equalTo($deadline));
        $this->assertSame(120, (int) $finished->overtime_minutes);
        $this->assertNotEmpty($finished->kode_presensi_pulang);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($finished->presensi_pulang_kedaluwarsa, $timezone)
            ->equalTo($deadline->copy()->addMinutes(30)));
        $this->assertSame('selesai', \App\Support\MeetingData::scheduleStatus($finished));

        $finishedWithCheckout = DB::table('rapat')->where('id', $startedWithCheckoutId)->first();
        $this->assertTrue(\Illuminate\Support\Carbon::parse($finishedWithCheckout->ended_at, $timezone)->equalTo($deadline));
        $this->assertSame('PULANG8', $finishedWithCheckout->kode_presensi_pulang);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($finishedWithCheckout->presensi_pulang_kedaluwarsa, $timezone)
            ->equalTo($deadline->copy()->addMinutes(15)));

        $cancelled = DB::table('rapat')->where('id', $unstartedMeetingId)->first();
        $this->assertNull($cancelled->started_at);
        $this->assertNull($cancelled->ended_at);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($cancelled->cancelled_at, $timezone)->equalTo($deadline));
        $this->assertSame('dibatalkan', \App\Support\MeetingData::scheduleStatus($cancelled));
        $this->assertFalse(\App\Support\MeetingData::canManuallyStart($cancelled));
        $this->withSession($this->superAdminSession())->post('/meetings/'.$unstartedMeetingId.'/actions', [
            'aksi' => 'mulai_rapat',
        ])->assertStatus(409);
        $this->withSession($this->superAdminSession())->get('/')
            ->assertOk()
            ->assertSee('Rapat dibatalkan')
            ->assertSee('Rapat Otomatis Dibatalkan');
        $this->get(route('guest-attendance'))
            ->assertOk()
            ->assertDontSee('Rapat Otomatis Dibatalkan');
        $this->withSession($this->superAdminSession())->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $unstartedMeetingId,
            'opsi_presensi' => 'buat',
        ])->assertRedirect('/admin/rapat?presensi='.$unstartedMeetingId)
            ->assertSessionHasErrors('presensi');
    }

    public function test_ending_meeting_preserves_an_active_checkout_code_created_earlier(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Kode Pulang Terbit Lebih Awal',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->addMinutes(20)->format('H:i'),
            'moda' => 'Luring',
            'started_at' => now()->subMinutes(20),
            'kode_presensi_pulang' => 'PULANG8',
            'presensi_pulang_kedaluwarsa' => now()->addMinutes(35),
        ]);
        $expiresAt = DB::table('rapat')->where('id', $meetingId)->value('presensi_pulang_kedaluwarsa');

        $this->withSession(['admin_id' => 1])->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'selesaikan_rapat',
        ])->assertRedirect('/meetings/'.$meetingId);

        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'kode_presensi_pulang' => 'PULANG8',
            'presensi_pulang_kedaluwarsa' => $expiresAt,
        ]);
    }

    public function test_admin_can_finish_a_meeting_manually_and_record_overtime(): void
    {
        $this->travelTo(now()->setTime(12, 10));
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Lewat Jadwal Selesai',
            'tanggal' => now()->toDateString(),
            'mulai' => '10:00',
            'selesai' => '12:00',
            'moda' => 'Luring',
            'started_at' => now()->setTime(10, 0),
        ]);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'selesaikan_rapat',
            'id' => $meetingId,
        ])->assertRedirect('/admin/rapat');

        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $this->assertNotNull($meeting->ended_at);
        $this->assertNotEmpty($meeting->kode_presensi_pulang);
        $this->assertSame(10, (int) $meeting->overtime_minutes);
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
            ->assertSee('logo-home-link')
            ->assertSee('aria-label="Kembali ke Dashboard"', false)
            ->assertSee(route('dashboard'));

        $this->getJson('/live-board/data')
            ->assertOk()
            ->assertJsonStructure(['updated_at', 'meetings']);
    }

    public function test_public_live_board_reports_attendance_count_without_personal_data(): void
    {
        $startedAt = now()->subMinutes(30)->startOfMinute();
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Sedang Berlangsung',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(30)->format('H:i'),
            'selesai' => now()->addMinutes(30)->format('H:i'),
            'moda' => 'Luring',
            'started_at' => $startedAt,
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
            ->assertJsonPath('meetings.0.started_at', $startedAt->toIso8601String())
            ->assertJsonMissingPath('meetings.0.attendees')
            ->assertJsonMissingPath('meetings.0.name')
            ->assertJsonMissing(['name' => 'Pegawai Izin']);

        $this->withSession(['admin_id' => 1])
            ->get('/admin/rapat')
            ->assertOk()
            ->assertSee('Absen masuk')
            ->assertSee('1 pegawai')
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
                'started_at' => now()->subMinutes(20),
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

    public function test_meeting_remains_active_after_scheduled_end_until_manually_finished(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
        'nip' => '123456789012345678',
        'nama' => 'Pegawai Rapat',
        'jabatan' => 'Staf',
        'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
        'judul' => 'Rapat Hari Ini Sudah Selesai',
        'tanggal' => now()->toDateString(),
        'mulai' => now()->subMinutes(20)->format('H:i'),
        'selesai' => now()->subMinutes(5)->format('H:i'),
        'moda' => 'Luring',
        'started_at' => now()->subMinutes(20),
        ]);

        $this->withSession([
        'employee_id' => $employeeId,
        'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ])->get('/meetings/'.$meetingId)
        ->assertOk()
        ->assertSee('meeting-detail-status-berlangsung')
        ->assertSee('Lewat jadwal 5 menit');
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
            'started_at' => now()->subMinutes(20),
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

    public function test_assigned_minute_taker_can_read_and_edit_minutes_before_deadline(): void
    {
        $passwordHash = Hash::make('password-notulis');
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '765432109876543210',
            'nama' => 'Notulis Ditugaskan',
            'jabatan' => 'Dosen',
            'password_hash' => $passwordHash,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Khusus Notulis',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->addMinutes(40)->format('H:i'),
            'moda' => 'Luring',
            'notulis' => 'Notulis Ditugaskan',
            'notulis_pegawai_id' => $employeeId,
            'notulen' => 'Notulen yang dapat dibaca notulis.',
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => $passwordHash,
        ];

        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Anda ditugaskan sebagai notulis rapat ini.')
            ->assertSee('name="aksi" value="simpan_notulen"', false)
            ->assertSee('Notulen yang dapat dibaca notulis.')
            ->assertSee('Batas pengisian notulen:');
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulis memperbarui notulen.',
        ])->assertRedirect();
        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'notulen' => 'Notulis memperbarui notulen.',
        ]);
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
            'active_role' => 'admin',
        ])->get('/admin/rapat?edit=0')
            ->assertOk()
            ->assertSee('data-participant-picker', false)
            ->assertSee('class="participant-picker-heading"', false)
            ->assertSee('Rapat untuk semua pegawai')
            ->assertSee('Pegawai yang wajib mengikuti')
            ->assertSee('Pilih semua pegawai dalam grup')
            ->assertSee('data-participant-search', false)
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
        $this->withSession(['admin_id' => 1])->get('/admin/rapat?edit=0')
            ->assertOk()
            ->assertSee('Notulen diisi melalui halaman detail saat rapat berlangsung atau setelah rapat selesai.')
            ->assertDontSee('name="batas_notulen_hari"', false)
            ->assertSee('Pilih akun pegawai')
            ->assertSee('meeting-chair-picker-search')
            ->assertSee('meeting-minute-taker-picker-search')
            ->assertSee('role="combobox"', false)
            ->assertSee('data-signer-picker', false)
            ->assertDontSee('name="notulen"', false)
            ->assertDontSee('name="file_notulen"', false);

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
            ->assertSee('data-all-employees checked', false)
            ->assertDontSee('name="notulen"', false)
            ->assertDontSee('name="file_notulen"', false);

        DB::table('rapat')->where('id', $meetingId)->update(['notulen' => 'Notulen diisi setelah rapat.']);
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'simpan_rapat',
            'id' => $meetingId,
            'judul' => 'Rapat Semua Pegawai',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'semua_pegawai' => '1',
        ])->assertRedirect('/admin/rapat');
        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'notulen' => 'Notulen diisi setelah rapat.',
        ]);
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
            'kelola_grup_undangan' => '1',
        ])->assertRedirect('/admin/superadmin');

        $this->assertDatabaseHas('manager_access_settings', [
            'id' => 1,
            'buat_rapat' => 1,
            'materi' => 0,
            'rekaman' => 1,
            'notulen' => 0,
            'lihat_daftar_hadir' => 1,
            'kelola_grup_undangan' => 1,
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

    public function test_super_admin_sets_global_minutes_deadline_in_settings_only(): void
    {
        $session = $this->superAdminSession();
        $this->withSession($session)->get('/admin/pengaturan')
            ->assertOk()
            ->assertSee('Batas pengisian dan pengubahan notulen')
            ->assertSee('value="7"', false);

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'simpan_pengaturan',
            'batas_notulen_default_hari' => '14',
        ])->assertRedirect('/admin/pengaturan');
        $this->assertDatabaseHas('pengaturan', [
            'kunci' => 'batas_notulen_default_hari',
            'nilai' => '14',
        ]);

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'simpan_rapat',
            'judul' => 'Rapat Batas Notulen',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'semua_pegawai' => '1',
        ])->assertRedirect('/admin/rapat');

        $meetingId = DB::table('rapat')->where('judul', 'Rapat Batas Notulen')->value('id');
        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $this->assertSame(
            \Illuminate\Support\Carbon::parse($meeting->tanggal.' '.$meeting->selesai)->addDays(14)->toDateTimeString(),
            \App\Support\MeetingData::minutesDeadline($meeting)->toDateTimeString()
        );

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'simpan_rapat',
            'judul' => 'Rapat Batas Default',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '11:00',
            'selesai' => '12:00',
            'moda' => 'Luring',
            'semua_pegawai' => '1',
        ])->assertRedirect('/admin/rapat');
        $defaultMeetingId = DB::table('rapat')->where('judul', 'Rapat Batas Default')->value('id');
        $defaultMeeting = DB::table('rapat')->where('id', $defaultMeetingId)->first();
        $this->assertSame(
            \Illuminate\Support\Carbon::parse($defaultMeeting->tanggal.' '.$defaultMeeting->selesai)->addDays(14)->toDateTimeString(),
            \App\Support\MeetingData::minutesDeadline($defaultMeeting)->toDateTimeString()
        );

        $this->withSession($session)->from('/admin/rapat?edit=0')
            ->post('/admin/actions', [
                'aksi' => 'simpan_rapat',
                'judul' => 'Rapat Batas Tidak Diizinkan',
                'tanggal' => now()->addDay()->toDateString(),
                'mulai' => '13:00',
                'selesai' => '14:00',
                'moda' => 'Luring',
                'semua_pegawai' => '1',
                'batas_notulen_hari' => '2',
            ])->assertRedirect('/admin/rapat?edit=0')
            ->assertSessionHasErrors('batas_notulen_hari');
        $this->assertDatabaseMissing('rapat', ['judul' => 'Rapat Batas Tidak Diizinkan']);
    }

    public function test_meeting_managers_and_assigned_minute_taker_can_edit_until_deadline_but_super_admins_are_exempt(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        DB::table('pengaturan')->updateOrInsert(
            ['kunci' => 'batas_notulen_default_hari'],
            ['nilai' => '3']
        );
        $minuteTakerId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Notulis Rapat',
            'jabatan' => 'Dosen',
            'password_hash' => Hash::make('password-notulis'),
        ]);
        $managerId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345679',
            'nama' => 'Pengelola Rapat',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-manager'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $managerId]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Tenggat Notulen',
            'tanggal' => now()->subDays(2)->toDateString(),
            'mulai' => '08:00',
            'selesai' => '09:00',
            'moda' => 'Luring',
            'notulis_pegawai_id' => $minuteTakerId,
            'notulis' => 'Notulis Rapat',
            'batas_notulen_hari' => 30,
        ]);
        $minuteTakerSession = [
            'employee_id' => $minuteTakerId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $minuteTakerId)->value('password_hash'),
        ];
        $managerSession = [
            'employee_id' => $managerId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $managerId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($minuteTakerSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('name="aksi" value="simpan_notulen"', false)
            ->assertSee('Batas pengisian notulen:');
        $this->withSession($minuteTakerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen sebelum batas oleh Notulis.',
        ])->assertRedirect();
        $this->assertDatabaseHas('rapat', ['id' => $meetingId, 'notulen' => 'Notulen sebelum batas oleh Notulis.']);

        $this->withSession($managerSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Batas pengisian notulen:')
            ->assertSee('name="aksi" value="simpan_notulen"', false);
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen sebelum batas oleh Pengelola.',
        ])->assertRedirect();
        $this->assertDatabaseHas('rapat', ['id' => $meetingId, 'notulen' => 'Notulen sebelum batas oleh Pengelola.']);

        DB::table('rapat')->where('id', $meetingId)->update([
            'tanggal' => now()->subDays(5)->toDateString(),
            'selesai' => '09:00',
        ]);
        $this->withSession($minuteTakerSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Batas pengisian notulen telah berakhir.')
            ->assertDontSee('name="aksi" value="simpan_notulen"', false);
        $this->withSession($minuteTakerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen oleh pegawai setelah tenggat.',
        ])->assertRedirect()
            ->assertSessionHasErrors('notulen');

        $this->withSession($managerSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Batas pengisian notulen telah berakhir.')
            ->assertDontSee('name="aksi" value="simpan_notulen"', false);
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen diperbarui Pengelola setelah batas.',
        ])->assertRedirect()
            ->assertSessionHasErrors('notulen');
        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'notulen' => 'Notulen sebelum batas oleh Pengelola.',
        ]);

        $superAdminSession = $this->superAdminSession();
        $this->withSession($superAdminSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen diperbarui Super Admin setelah batas.',
        ])->assertRedirect();
        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'notulen' => 'Notulen diperbarui Super Admin setelah batas.',
        ]);
    }

    public function test_group_management_permission_controls_group_creation_and_deletion(): void
    {
        $managerPassword = Hash::make('manager-password');
        $managerId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pengelola Grup',
            'jabatan' => 'Pengelola',
            'password_hash' => $managerPassword,
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $managerId]);
        $managerSession = [
            'employee_id' => $managerId,
            'employee_password_hash' => $managerPassword,
            'active_role' => 'admin',
        ];

        $this->withSession($managerSession)->get('/admin/grup')->assertForbidden();
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'tambah_grup_undangan',
            'group_name' => 'Teknologi Laboratorium Medis',
        ])->assertForbidden();

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'atur_hak_akses_admin',
            'kelola_grup_undangan' => '1',
        ])->assertRedirect('/admin/superadmin');

        $this->withSession($managerSession)->get('/admin/grup')
            ->assertOk()
            ->assertSee('Grup jurusan &amp; unit', false)
            ->assertSee('Tambah grup');
        $this->withSession($managerSession)->get('/')
            ->assertOk()
            ->assertSee('Grup Undangan')
            ->assertSee('href="'.route('admin', 'grup').'"', false);
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'tambah_grup_undangan',
            'group_name' => 'Pensiun',
        ])->assertSessionHasErrors('group_name');
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'tambah_grup_undangan',
            'group_name' => 'Teknologi Laboratorium Medis',
        ])->assertRedirect('/admin/grup');

        $this->withSession(['admin_id' => 1])->get('/admin/rapat?edit=0')
            ->assertOk()
            ->assertSee('Teknologi Laboratorium Medis')
            ->assertSee('0 pegawai');
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'tambah_pegawai',
            'nama' => 'Dosen Laboratorium',
            'nip' => '987654321098765432',
            'jabatan' => 'Dosen',
            'invite_group' => 'Teknologi Laboratorium Medis',
        ])->assertRedirect('/admin/pegawai');
        $employeeId = DB::table('pegawai')->where('nip', '987654321098765432')->value('id');
        $groupId = DB::table('pegawai_invite_group_catalog')
            ->where('group_name', 'Teknologi Laboratorium Medis')
            ->value('id');

        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'hapus_grup_undangan',
            'id' => $groupId,
        ])->assertRedirect('/admin/grup');

        $this->assertDatabaseMissing('pegawai_invite_group_catalog', [
            'group_name' => 'Teknologi Laboratorium Medis',
        ]);
        $this->assertDatabaseHas('pegawai', ['id' => $employeeId, 'nama' => 'Dosen Laboratorium']);
        $this->assertDatabaseHas('pegawai_invite_groups', [
            'pegawai_id' => $employeeId,
            'group_name' => 'Lainnya / belum dikelompokkan',
        ]);
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

    public function test_attendance_code_options_and_duration_are_validated(): void
    {
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Validasi Kode',
            'tanggal' => now()->addDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
        ]);
        $session = $this->superAdminSession();

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $meetingId,
            'opsi_presensi' => 'unknown',
        ])->assertSessionHasErrors('opsi_presensi');

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $meetingId,
            'opsi_presensi' => 'buat',
            'durasi' => '999999999',
        ])->assertSessionHasErrors('durasi');

        $this->withSession($session)->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $meetingId,
            'opsi_presensi' => 'buat',
            'kode' => str_repeat('A', 21),
        ])->assertSessionHasErrors('kode');

        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'kode_presensi' => null,
        ]);
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
            ->assertSee('aria-label="Fitur Pengelola Rapat"', false)
            ->assertDontSee('Pembuatan dan pengelolaan kode presensi hanya dapat dilakukan oleh Super Admin.')
            ->assertDontSee('Daftar absen masuk')
            ->assertDontSee('Buka / perbarui kode')
            ->assertDontSee('Buat kode absen pulang');
    }

    public function test_meeting_manager_can_attend_as_employee_and_manage_meeting_from_menu(): void
    {
        \Illuminate\Support\Facades\Storage::fake('meeting_uploads');
        $this->travelTo(now()->setTime(10, 0));
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pengelola yang Ikut Rapat',
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
        DB::table('manager_access_settings')->where('id', 1)->update([
            'buat_rapat' => true,
            'materi' => true,
            'rekaman' => true,
            'notulen' => false,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Pengelola Ikut Hadir',
            'tanggal' => now()->toDateString(),
            'mulai' => '09:30',
            'selesai' => '10:30',
            'moda' => 'Luring',
            'started_at' => now()->setTime(9, 30),
            'kode_presensi' => 'HADIR7',
            'presensi_kedaluwarsa' => now()->addMinutes(20),
        ]);
        $managerSession = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'admin',
        ];

        $this->withSession($managerSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Presensi rapat')
            ->assertSee('Kode presensi hadir / pulang')
            ->assertSee('Kode aktif sampai')
            ->assertSee('name="kode"', false)
            ->assertSee(route('admin', 'rapat'), false)
            ->assertDontSee('Atur presensi')
            ->assertDontSee('Pengelolaan kode presensi')
            ->assertDontSee('Kelola presensi')
            ->assertDontSee('Selesaikan Rapat')
            ->assertDontSee('Daftar absen masuk')
            ->assertSee('Tambah materi (PDF, PPT, dokumen, spreadsheet, gambar, atau ZIP; maks. 20 MB)')
            ->assertSee('Tambah rekaman')
            ->assertSee('SIMULASI PENGISIAN')
            ->assertSee('Simulasi Pengisian Notulen Rapat')
            ->assertSee('Isi / tambah / ubah notulen')
            ->assertSee('meeting-detail-minutes-editor" open', false);

        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'hadir',
            'kode' => 'HADIR7',
        ])->assertRedirect();
        $this->assertDatabaseHas('kehadiran', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
        ]);
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'tambah_materi',
            'materi' => \Illuminate\Http\UploadedFile::fake()->create('materi.pdf', 100, 'application/pdf'),
        ])->assertRedirect();
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'tambah_rekaman',
            'tautan_rekaman' => 'https://youtu.be/rekaman-tes',
        ])->assertRedirect();
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen diperbarui oleh Pengelola Rapat.',
        ])->assertRedirect();
        $this->assertDatabaseHas('berkas_rapat', [
            'rapat_id' => $meetingId,
            'kategori' => 'materi',
            'pengunggah' => 'Pengelola yang Ikut Rapat',
        ]);
        $this->assertDatabaseHas('berkas_rapat', [
            'rapat_id' => $meetingId,
            'kategori' => 'rekaman',
            'tautan' => 'https://youtu.be/rekaman-tes',
            'pengunggah' => 'Pengelola yang Ikut Rapat',
        ]);
        $this->assertSame(
            'Notulen diperbarui oleh Pengelola Rapat.',
            DB::table('rapat')->where('id', $meetingId)->value('notulen')
        );
        $this->withSession($managerSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('meeting-detail-minutes-editor" open', false)
            ->assertSee('Notulen diperbarui oleh Pengelola Rapat.');
        $this->withSession($managerSession)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'simpan_notulen',
            'notulen' => 'Notulen diedit kembali oleh Pengelola Rapat.',
        ])->assertRedirect();
        $this->assertSame(
            'Notulen diedit kembali oleh Pengelola Rapat.',
            DB::table('rapat')->where('id', $meetingId)->value('notulen')
        );

        $this->withSession($managerSession)->get('/admin/rapat')
            ->assertOk();
        $this->withSession($managerSession)->get('/admin/rapat?edit='.$meetingId)
            ->assertOk()
            ->assertSee('Notulen diisi melalui halaman detail saat rapat berlangsung atau setelah rapat selesai.')
            ->assertDontSee('PDF notulen')
            ->assertSee('Unggah materi');
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
            'aksi' => 'ubah_ruang',
            'id' => DB::table('ruang_rapat')->value('id'),
            'nama' => 'Tidak Diizinkan',
        ])->assertForbidden();
        $this->withSession($managerSession)->post('/admin/actions', [
            'aksi' => 'hapus_ruang',
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

        $renamedRoomId = DB::table('ruang_rapat')->where('nama', 'Ruang Super Admin')->value('id');
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Ruang Uji',
            'tanggal' => '2026-11-20',
            'mulai' => '09:00',
            'selesai' => '10:00',
            'tempat' => 'Ruang Super Admin',
            'room_id' => $renamedRoomId,
            'moda' => 'Luring',
        ]);
        $meeting = DB::table('rapat')->where('id', $meetingId)->first();
        $previousSlug = \App\Support\MeetingData::slug($meeting);

        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'ubah_ruang',
            'id' => $renamedRoomId,
            'nama' => 'Ruang yang Diperbarui',
        ])->assertRedirect('/admin/ruang')->assertSessionHas('status', 'Nama ruang rapat berhasil diubah.');
        $this->assertDatabaseHas('ruang_rapat', ['id' => $renamedRoomId, 'nama' => 'Ruang yang Diperbarui']);
        $this->assertSame($previousSlug, \App\Support\MeetingData::slug(DB::table('rapat')->where('id', $meetingId)->first()));
        $this->withSession(['admin_id' => 1])->get('/meet-'.$previousSlug)->assertOk()->assertSee('Rapat Ruang Uji');

        $this->withSession(['admin_id' => 1])->get('/admin/ruang')
            ->assertOk()
            ->assertSee('Ruang yang Diperbarui')
            ->assertSee('Simpan nama')
            ->assertSee('1 rapat')
            ->assertDontSee('Status</th>')
            ->assertDontSee('Nonaktifkan')
            ->assertDontSee('Dipakai rapat');
        $this->withSession(['admin_id' => 1])->post('/admin/actions', [
            'aksi' => 'hapus_ruang',
            'id' => $renamedRoomId,
        ])->assertRedirect('/admin/ruang')->assertSessionHas('status', 'Ruang rapat berhasil dihapus. Lokasi pada data rapat lama tetap disimpan.');
        $this->assertDatabaseMissing('ruang_rapat', ['id' => $renamedRoomId]);
        $this->assertDatabaseHas('rapat', [
            'id' => $meetingId,
            'room_id' => null,
            'tempat' => 'Ruang Super Admin',
        ]);
        $this->assertSame($previousSlug, \App\Support\MeetingData::slug(DB::table('rapat')->where('id', $meetingId)->first()));

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
            'started_at' => now()->subMinutes(20),
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
        ])->assertForbidden();
        $this->withSession($superAdminSession)->post('/admin/actions', [
            'aksi' => 'atur_presensi',
            'id' => $meetingId,
            'opsi_presensi' => 'buat',
        ])->assertRedirect('/admin/rapat?presensi='.$meetingId);

        $this->withSession($superAdminSession)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Presensi rapat')
            ->assertSee('aria-label="Fitur Pengelola Rapat"', false)
            ->assertSee('Atur presensi')
            ->assertSee(route('admin', ['tab' => 'rapat', 'presensi' => $meetingId]), false)
            ->assertDontSee('Pengelolaan kode presensi')
            ->assertDontSee('Kelola presensi')
            ->assertDontSee('Selesaikan Rapat');
        $this->withSession($superAdminSession)
            ->get(route('admin', ['tab' => 'rapat', 'presensi' => $meetingId]))
            ->assertOk()
            ->assertSee('Presensi: Rapat Materi dan Notulen')
            ->assertSee('Buka / perbarui presensi');
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
            ->assertSee('Rapat ini terbuka untuk semua pegawai.')
            ->assertSee('Absen hadir tercatat pada')
            ->assertSee('aria-label="Fitur Pengelola Rapat"', false)
            ->assertDontSee('Daftar absen masuk (1)')
            ->assertDontSee('Pengelolaan kode presensi')
            ->assertDontSee('Kelola presensi')
            ->assertSee('Tambah materi (PDF, PPT, dokumen, spreadsheet, gambar, atau ZIP; maks. 20 MB)')
            ->assertSee('Isi / tambah / ubah notulen')
            ->assertSee('Tambah rekaman');
    }

    public function test_employee_can_record_attendance_using_the_super_admin_code(): void
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
            'selesai' => now()->addHour()->format('H:i'),
            'moda' => 'Luring',
            'started_at' => now()->subHour(),
            'kode_presensi' => 'RAPAT7',
            'presensi_kedaluwarsa' => now()->addMinutes(20)->toDateTimeString(),
        ]);

        $session = ['employee_id' => $employeeId, 'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash')];
        $this->withSession($session)
            ->get('/')
            ->assertOk()
            ->assertSee('🟢 Presensi buka');

        $this->withSession($session)
            ->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Kode presensi hadir / pulang')
            ->assertSee('name="aksi" value="hadir"', false)
            ->assertSee('name="kode"', false);

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

    public function test_employee_attendance_field_stays_visible_until_super_admin_opens_the_code(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Menunggu Kode',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Kode Menyusul',
            'tanggal' => now()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '11:00',
            'moda' => 'Luring',
            'started_at' => now()->setTime(9, 0),
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ];

        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Kode hadir belum dibuka.')
            ->assertSee('name="aksi" value="hadir"', false)
            ->assertSee('name="kode"', false);

        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'hadir',
            'kode' => 'KODE99',
        ])->assertRedirect()
            ->assertSessionHasErrors('presensi');

        $this->assertDatabaseMissing('kehadiran', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
        ]);
        DB::table('rapat')->where('id', $meetingId)->update([
            'kode_presensi' => 'KODE99',
            'presensi_kedaluwarsa' => now()->addMinutes(15),
        ]);
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'hadir',
            'kode' => 'SALAH',
        ])->assertRedirect()
            ->assertSessionHasErrors('presensi');
        $this->assertDatabaseMissing('kehadiran', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
        ]);
        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'hadir',
            'kode' => 'KODE99',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Presensi hadir berhasil dicatat.');

        $this->assertDatabaseHas('kehadiran', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
        ]);
    }

    public function test_employee_sees_attendance_panel_before_meeting_and_cannot_check_in_early(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '111111111111111111',
            'nama' => 'Pegawai Menunggu Rapat',
            'jabatan' => 'Pegawai',
            'password_hash' => Hash::make('password-tes'),
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Presensi Otomatis',
            'tanggal' => now()->toDateString(),
            'mulai' => '11:00',
            'selesai' => '12:00',
            'moda' => 'Luring',
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ];

        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Masukkan kode hadir dari Super Admin saat rapat berlangsung.')
            ->assertSee('name="kode"', false);

        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'hadir',
        ])->assertRedirect()
            ->assertSessionHasErrors('presensi');

        $this->assertDatabaseMissing('kehadiran', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
        ]);
    }

    public function test_super_admin_employee_can_record_their_own_attendance(): void
    {
        $employeeId = DB::table('pegawai')->insertGetId([
            'nip' => '123456789012345678',
            'nama' => 'Pegawai Super Admin Hadir',
            'jabatan' => 'Koordinator',
            'password_hash' => Hash::make('password-tes'),
        ]);
        DB::table('admin_pegawai')->insert(['pegawai_id' => $employeeId]);
        DB::table('super_admin_permissions')->insert([
            'pegawai_id' => $employeeId,
            'is_super_admin' => true,
            'buat_rapat' => true,
            'materi' => true,
            'rekaman' => true,
            'notulen' => true,
            'lihat_daftar_hadir' => true,
            'kelola_ruang' => true,
        ]);
        $meetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Super Admin Ikut Hadir',
            'tanggal' => now()->toDateString(),
            'mulai' => now()->subMinutes(20)->format('H:i'),
            'selesai' => now()->addMinutes(40)->format('H:i'),
            'moda' => 'Luring',
            'started_at' => now()->subMinutes(20),
            'kode_presensi' => 'HADIR7',
            'presensi_kedaluwarsa' => now()->addMinutes(20),
        ]);
        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
            'active_role' => 'super_admin',
        ];

        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Presensi rapat')
            ->assertSee('name="aksi" value="hadir"', false)
            ->assertDontSee('Pengelolaan kode presensi')
            ->assertDontSee('Kelola presensi');

        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'hadir',
            'kode' => 'HADIR7',
        ])->assertRedirect();

        $this->assertDatabaseHas('kehadiran', [
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
        ]);
        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Absen hadir tercatat pada')
            ->assertDontSee('name="aksi" value="hadir"', false);

        $completedMeetingId = DB::table('rapat')->insertGetId([
            'judul' => 'Rapat Selesai Super Admin',
            'tanggal' => now()->subDay()->toDateString(),
            'mulai' => '09:00',
            'selesai' => '10:00',
            'moda' => 'Luring',
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
            'kode_presensi_pulang' => 'PULANG9',
            'presensi_pulang_kedaluwarsa' => now()->addMinutes(20),
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $completedMeetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->subDay()->setTime(9, 15),
        ]);

        $this->withSession($session)->get('/meetings/'.$completedMeetingId)
            ->assertOk()
            ->assertSee('name="aksi" value="absen_pulang"', false)
            ->assertSee('Kode presensi hadir / pulang')
            ->assertSee('name="kode"', false)
            ->assertDontSee('Pengelolaan kode presensi')
            ->assertDontSee('Kelola presensi')
            ->assertDontSee('Selesaikan Rapat');
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
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
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
            ->assertSee('catat absen pulang')
            ->assertSee('Isi alasan pulang lebih awal')
            ->assertSee('Alasan pulang lebih awal / belum absen pulang');
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

    public function test_employee_can_submit_checkout_code_when_checkout_is_open(): void
    {
        $this->travelTo(now()->setTime(10, 0));
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
            'started_at' => now()->subHour(),
            'kode_presensi_pulang' => 'PULANG7',
            'presensi_pulang_kedaluwarsa' => now()->addHour()->toDateTimeString(),
        ]);
        DB::table('kehadiran')->insert([
            'rapat_id' => $meetingId,
            'pegawai_id' => $employeeId,
            'status' => 'hadir',
            'waktu' => now()->subHour(),
        ]);
        $this->assertTrue(\App\Support\MeetingData::checkoutOpen(DB::table('rapat')->where('id', $meetingId)->first()));

        $session = [
            'employee_id' => $employeeId,
            'employee_password_hash' => DB::table('pegawai')->where('id', $employeeId)->value('password_hash'),
        ];
        $this->withSession($session)->get('/meetings/'.$meetingId)
            ->assertOk()
            ->assertSee('Kode presensi hadir / pulang')
            ->assertSee('name="aksi" value="absen_pulang"', false)
            ->assertSee('name="kode"', false);

        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'absen_pulang',
            'kode' => 'SALAH',
        ])->assertRedirect()
            ->assertSessionHasErrors('presensi_pulang');
        $this->assertNull(DB::table('kehadiran')
            ->where('rapat_id', $meetingId)
            ->where('pegawai_id', $employeeId)
            ->value('waktu_pulang'));

        $this->withSession($session)->post('/meetings/'.$meetingId.'/actions', [
            'aksi' => 'absen_pulang',
            'kode' => 'PULANG7',
        ])->assertRedirect();
        $this->assertNotNull(DB::table('kehadiran')
            ->where('rapat_id', $meetingId)
            ->where('pegawai_id', $employeeId)
            ->value('waktu_pulang'));
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
            'started_at' => now()->subDay()->setTime(9, 0),
            'ended_at' => now()->subDay()->setTime(10, 0),
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
            ->assertDontSee('Daftar absen masuk')
            ->assertDontSee('Daftar absen pulang')
            ->assertSee('Belum Absen Pulang')
            ->assertSee('Alasan pulang lebih awal / belum absen pulang');
        $this->withSession(['admin_id' => 1])->get('/admin/kehadiran')
            ->assertOk()
            ->assertSee('Rapat Pantau Absen Pulang')
            ->assertSee('Belum Absen Pulang')
            ->assertSee('Alasan: Belum diisi pegawai.');
    }
}
