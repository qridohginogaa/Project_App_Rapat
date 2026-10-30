<?php

// Mengimpor/memperbarui pegawai dari CSV (header: nip,nama,jabatan) yang dibaca dari stdin.
// Dijalankan di dalam container app lewat import-pegawai.sh:
//   bash /opt/rapat-deploy/import-pegawai.sh /root/pegawai.csv
// Pegawai baru: kata sandi awal = NIP dan wajib diganti saat login pertama.
// Pegawai yang sudah ada: nama dan jabatan diperbarui, kata sandi tidak diubah.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$laravel = getenv('LARAVEL_DIR') ?: '/var/www/html';
require $laravel.'/vendor/autoload.php';
$app = require $laravel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$handle = fopen('php://stdin', 'r');
$header = array_map('trim', fgetcsv($handle) ?: []);
if ($header !== ['nip', 'nama', 'jabatan']) {
    fwrite(STDERR, "Header CSV harus: nip,nama,jabatan\n");
    exit(1);
}

$created = $updated = $skipped = 0;
DB::transaction(function () use ($handle, &$created, &$updated, &$skipped) {
    while (($row = fgetcsv($handle)) !== false) {
        [$nip, $nama, $jabatan] = array_map('trim', $row + ['', '', '']);
        if (!preg_match('/^\d{18}$/', $nip) || $nama === '') {
            fwrite(STDERR, 'Dilewati: '.implode(',', $row)."\n");
            $skipped++;
            continue;
        }

        $data = [
            'nama' => mb_substr($nama, 0, 150),
            'jabatan' => mb_substr($jabatan !== '' ? $jabatan : '-', 0, 150),
        ];
        if (DB::table('pegawai')->where('nip', $nip)->exists()) {
            DB::table('pegawai')->where('nip', $nip)->update($data);
            $updated++;
            continue;
        }

        DB::table('pegawai')->insert($data + [
            'nip' => $nip,
            'password_hash' => Hash::make($nip),
            'password_change_required' => true,
        ]);
        $created++;
    }
});

echo "Impor selesai: {$created} dibuat, {$updated} diperbarui, {$skipped} dilewati.\n";
