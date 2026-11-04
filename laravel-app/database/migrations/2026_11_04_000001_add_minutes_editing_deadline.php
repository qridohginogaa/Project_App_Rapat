<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rapat') && !Schema::hasColumn('rapat', 'batas_notulen_hari')) {
            Schema::table('rapat', function (Blueprint $table) {
                $table->unsignedSmallInteger('batas_notulen_hari')->default(7);
            });
        }

        if (Schema::hasTable('pengaturan')) {
            DB::table('pengaturan')->insertOrIgnore([
                'kunci' => 'batas_notulen_default_hari',
                'nilai' => '7',
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rapat') && Schema::hasColumn('rapat', 'batas_notulen_hari')) {
            Schema::table('rapat', function (Blueprint $table) {
                $table->dropColumn('batas_notulen_hari');
            });
        }

        if (Schema::hasTable('pengaturan')) {
            DB::table('pengaturan')->where('kunci', 'batas_notulen_default_hari')->delete();
        }
    }
};
