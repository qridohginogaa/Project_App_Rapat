<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Carbon;
use App\Support\MeetingData;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('meetings:finalize-expired', function () {
    $today = now()->toDateString();
    $cancelled = 0;
    $completed = 0;

    DB::table('rapat')
        ->whereDate('tanggal', '<', $today)
        ->whereNull('ended_at')
        ->whereNull('cancelled_at')
        ->orderBy('id')
        ->chunkById(100, function ($meetings) use (&$cancelled, &$completed) {
            foreach ($meetings as $meeting) {
                $deadline = Carbon::parse($meeting->tanggal, config('app.timezone'))
                    ->addDay()
                    ->startOfDay();

                if (!$meeting->started_at) {
                    $cancelled += DB::table('rapat')
                        ->where('id', $meeting->id)
                        ->whereNull('started_at')
                        ->whereNull('ended_at')
                        ->whereNull('cancelled_at')
                        ->update(['cancelled_at' => $deadline]);
                    continue;
                }

                $checkoutCode = $meeting->kode_presensi_pulang;
                $checkoutExpires = $meeting->presensi_pulang_kedaluwarsa;
                if (!MeetingData::checkoutOpen($meeting)) {
                    do {
                        $checkoutCode = MeetingData::code();
                    } while ($checkoutCode === $meeting->kode_presensi);
                    $checkoutExpires = $deadline->copy()->addMinutes(30);
                }

                $endedAt = $deadline;
                $completed += DB::table('rapat')
                    ->where('id', $meeting->id)
                    ->whereNotNull('started_at')
                    ->whereNull('ended_at')
                    ->whereNull('cancelled_at')
                    ->update([
                        'ended_at' => $endedAt,
                        'overtime_minutes' => MeetingData::overtimeMinutes(
                            (object) array_merge((array) $meeting, ['ended_at' => $endedAt])
                        ),
                        'kode_presensi_pulang' => $checkoutCode,
                        'presensi_pulang_kedaluwarsa' => $checkoutExpires,
                    ]);
            }
        });

    $this->info("Rapat dibatalkan: {$cancelled}; rapat diakhiri otomatis: {$completed}.");
})->purpose('Cancel unstarted meetings and finish active meetings after their scheduled day');

Schedule::command('meetings:finalize-expired')
    ->everyMinute()
    ->timezone(config('app.timezone'));
