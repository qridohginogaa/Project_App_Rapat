<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class MeetingData
{
    public static function status(array|object $meeting): string
    {
        $status = self::scheduleStatus($meeting);
        return $status === 'berlangsung' ? 'hari' : $status;
    }

    public static function scheduleStatus(array|object $meeting): string
    {
        if (data_get($meeting, 'ended_at')) {
            return 'selesai';
        }
        $timezone = config('app.timezone');
        $starts = \Illuminate\Support\Carbon::parse(data_get($meeting, 'tanggal').' '.data_get($meeting, 'mulai'), $timezone);
        $ends = \Illuminate\Support\Carbon::parse(data_get($meeting, 'tanggal').' '.data_get($meeting, 'selesai'), $timezone);

        return now()->lt($starts) ? 'akan' : (now()->lt($ends) ? 'berlangsung' : 'selesai');
    }

    public static function attendanceOpen(array|object $meeting): bool
    {
        $expires = data_get($meeting, 'presensi_kedaluwarsa');
        return !empty(data_get($meeting, 'kode_presensi'))
            && !empty($expires)
            && now()->lt(\Illuminate\Support\Carbon::parse($expires, config('app.timezone')));
    }

    public static function checkoutOpen(array|object $meeting): bool
    {
        $expires = data_get($meeting, 'presensi_pulang_kedaluwarsa');
        return !empty(data_get($meeting, 'kode_presensi_pulang'))
            && !empty($expires)
            && now()->lt(\Illuminate\Support\Carbon::parse($expires, config('app.timezone')));
    }

    public static function canManuallyEnd(array|object $meeting): bool
    {
        if (data_get($meeting, 'ended_at')) {
            return false;
        }

        $starts = \Illuminate\Support\Carbon::parse(
            data_get($meeting, 'tanggal').' '.data_get($meeting, 'mulai'),
            config('app.timezone')
        );

        return $starts->isToday() && now()->greaterThanOrEqualTo($starts);
    }

    public static function code(int $length = 6): string
    {
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($index = 0; $index < $length; $index++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }

    public static function setting(string $key, string $default = ''): string
    {
        return (string) (DB::table('pengaturan')->where('kunci', $key)->value('nilai') ?? $default);
    }

    public static function date(string $date): string
    {
        return \Illuminate\Support\Carbon::parse($date)->locale('id')->translatedFormat('l, j F Y');
    }

    public static function minutesDraft(object $meeting, $attendees): string
    {
        $agenda = array_values(array_filter(array_map(
            fn (string $item) => trim(preg_replace('/^\d+\.\s*/', '', $item)),
            explode("\n", (string) $meeting->agenda)
        )));
        $lines = [
            'KEMENTERIAN KESEHATAN REPUBLIK INDONESIA',
            'POLITEKNIK KESEHATAN KEMENKES MANADO',
            '',
            'NOTULEN RAPAT',
            mb_strtoupper($meeting->judul),
            '',
            'Hari/Tanggal : '.self::date($meeting->tanggal),
            'Waktu        : '.substr($meeting->mulai, 0, 5).' - '.substr($meeting->selesai, 0, 5).' WITA',
            'Tempat       : '.($meeting->tempat ?: 'Belum ditentukan').' ('.$meeting->moda.')',
            'Pimpinan     : '.$meeting->pimpinan,
            'Notulis      : '.$meeting->notulis,
            '',
            'I. PESERTA RAPAT ('.$attendees->count().' orang)',
        ];
        foreach ($attendees as $index => $attendee) {
            $lines[] = ($index + 1).'. '.$attendee->nama.' (NIP '.$attendee->nip.')';
        }
        $lines[] = '';
        $lines[] = 'II. AGENDA RAPAT';
        foreach ($agenda as $index => $item) {
            $lines[] = ($index + 1).'. '.$item;
        }
        $lines[] = '';
        $lines[] = 'III. PEMBAHASAN';
        foreach ($agenda as $index => $item) {
            $lines[] = ($index + 1).'. '.$item;
            $lines[] = '   - [isi pokok pembahasan]';
        }
        $lines[] = '';
        $lines[] = 'IV. KESIMPULAN DAN TINDAK LANJUT';
        $lines[] = '[diisi notulis]';
        $lines[] = '';
        $lines[] = 'V. PENUTUP';

        return implode("\n", $lines);
    }
}
