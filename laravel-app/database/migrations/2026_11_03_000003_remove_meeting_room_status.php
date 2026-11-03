<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ruang_rapat') && Schema::hasColumn('ruang_rapat', 'aktif')) {
            DB::table('ruang_rapat')->update(['aktif' => true]);
        }
    }

    public function down(): void
    {
        // Room status is retained for compatibility; all rooms stay available.
    }
};
