<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('meeting_signatures', 'notification_seen_at')) {
            Schema::table('meeting_signatures', function (Blueprint $table) {
                $table->dateTime('notification_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('meeting_signatures', 'notification_seen_at')) {
            Schema::table('meeting_signatures', function (Blueprint $table) {
                $table->dropColumn('notification_seen_at');
            });
        }
    }
};
