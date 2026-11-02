<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kehadiran_tamu') && !Schema::hasColumn('kehadiran_tamu', 'waktu_pulang')) {
            Schema::table('kehadiran_tamu', function (Blueprint $table) {
                $table->timestamp('waktu_pulang')->nullable()->after('waktu');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kehadiran_tamu') && Schema::hasColumn('kehadiran_tamu', 'waktu_pulang')) {
            Schema::table('kehadiran_tamu', function (Blueprint $table) {
                $table->dropColumn('waktu_pulang');
            });
        }
    }
};
