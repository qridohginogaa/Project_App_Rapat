<?php

// Membuat akun pegawai Super Admin pertama (atau menaikkan pegawai yang sudah ada).
// Dijalankan di dalam container app lewat super-admin.sh:
//   bash /opt/rapat-deploy/super-admin.sh <NIP 18 digit> "Nama" ["Jabatan"]
// Kata sandi awal = NIP dan wajib diganti saat login pertama, sama seperti akun pegawai lain.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

[$script, $nip, $nama, $jabatan] = $argv + [null, null, null, 'Super Admin'];
if (!preg_match('/^\d{18}$/', (string) $nip) || trim((string) $nama) === '') {
    fwrite(STDERR, "Pemakaian: super-admin.sh <NIP 18 digit> \"Nama\" [\"Jabatan\"]\n");
    exit(1);
}

$laravel = getenv('LARAVEL_DIR') ?: '/var/www/html';
require $laravel.'/vendor/autoload.php';
$app = require $laravel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$created = DB::transaction(function () use ($nip, $nama, $jabatan) {
    $employee = DB::table('pegawai')->where('nip', $nip)->first();
    $id = $employee->id ?? DB::table('pegawai')->insertGetId([
        'nip' => $nip,
        'nama' => trim($nama),
        'jabatan' => trim($jabatan),
        'password_hash' => Hash::make($nip),
        'password_change_required' => true,
    ]);

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

    return $employee === null;
});

echo $created
    ? "Super Admin dibuat: {$nip}. Login dengan NIP sebagai kata sandi awal, lalu ganti kata sandi.\n"
    : "Pegawai {$nip} sudah ada dan kini menjadi Super Admin (kata sandi tidak diubah).\n";
