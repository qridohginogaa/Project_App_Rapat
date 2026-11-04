<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rapat', 'cancelled_at')) {
            Schema::table('rapat', function (Blueprint $table) {
                $table->dateTime('cancelled_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rapat', 'cancelled_at')) {
            Schema::table('rapat', function (Blueprint $table) {
                $table->dropColumn('cancelled_at');
            });
        }
    }
};
