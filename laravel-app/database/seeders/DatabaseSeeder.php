<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Siladen Room', 'Bunaken Room', 'Dihaga Room'] as $name) {
            DB::table('ruang_rapat')->insertOrIgnore(['nama' => $name]);
        }

        foreach (['wajib_foto' => '1', 'wajib_izin' => '1', 'folder_drive' => ''] as $key => $value) {
            DB::table('pengaturan')->insertOrIgnore(['kunci' => $key, 'nilai' => $value]);
        }

    }
}
