<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manager_access_settings') && !Schema::hasColumn('manager_access_settings', 'kelola_grup_undangan')) {
            Schema::table('manager_access_settings', function (Blueprint $table) {
                $table->boolean('kelola_grup_undangan')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('manager_access_settings') && Schema::hasColumn('manager_access_settings', 'kelola_grup_undangan')) {
            Schema::table('manager_access_settings', function (Blueprint $table) {
                $table->dropColumn('kelola_grup_undangan');
            });
        }
    }
};
