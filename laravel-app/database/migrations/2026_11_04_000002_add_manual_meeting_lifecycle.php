<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rapat', 'started_at')) {
            Schema::table('rapat', function (Blueprint $table) {
                $table->dateTime('started_at')->nullable();
            });
        }

        if (!Schema::hasColumn('rapat', 'overtime_minutes')) {
            Schema::table('rapat', function (Blueprint $table) {
                $table->unsignedInteger('overtime_minutes')->default(0);
            });
        }

        $today = now()->toDateString();
        DB::table('rapat')->orderBy('id')->chunkById(500, function ($meetings) use ($today) {
            foreach ($meetings as $meeting) {
                $scheduledStart = Carbon::parse($meeting->tanggal.' '.$meeting->mulai, config('app.timezone'));
                $scheduledEnd = Carbon::parse($meeting->tanggal.' '.$meeting->selesai, config('app.timezone'));
                $startedAt = $meeting->started_at;
                $endedAt = $meeting->ended_at;

                if (!$startedAt && $endedAt) {
                    $startedAt = $scheduledStart;
                } elseif (!$startedAt && $meeting->tanggal < $today) {
                    $startedAt = $scheduledStart;
                    $endedAt = $scheduledEnd;
                }

                $overtimeMinutes = $endedAt
                    && Carbon::parse($endedAt, config('app.timezone'))->greaterThan($scheduledEnd)
                    ? $scheduledEnd->diffInMinutes(Carbon::parse($endedAt, config('app.timezone')))
                    : 0;

                if ($startedAt || $endedAt || $overtimeMinutes) {
                    DB::table('rapat')->where('id', $meeting->id)->update([
                        'started_at' => $startedAt,
                        'ended_at' => $endedAt,
                        'overtime_minutes' => $overtimeMinutes,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $columns = array_filter(['started_at', 'overtime_minutes'], fn ($column) => Schema::hasColumn('rapat', $column));
        if ($columns) {
            Schema::table('rapat', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
