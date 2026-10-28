<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin')) {
            DB::table('admin')->where('username', 'admin')->delete();
        }
    }

    public function down(): void
    {
        // Deleted credentials are intentionally not restored.
    }
};
