<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pegawai') && !Schema::hasColumn('pegawai', 'profile_photo')) {
            Schema::table('pegawai', function (Blueprint $table) {
                $table->string('profile_photo', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pegawai') && Schema::hasColumn('pegawai', 'profile_photo')) {
            Schema::table('pegawai', function (Blueprint $table) {
                $table->dropColumn('profile_photo');
            });
        }
    }
};
