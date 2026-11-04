<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MeetingData
{
    public static function status(array|object $meeting): string
    {
        $status = self::scheduleStatus($meeting);
        return $status === 'berlangsung' ? 'hari' : $status;
    }

    public static function scheduleStatus(array|object $meeting): string
    {
        if (data_get($meeting, 'cancelled_at')) {
            return 'dibatalkan';
        }
        if (data_get($meeting, 'ended_at')) {
            return 'selesai';
        }
        $meetingDate = (string) data_get($meeting, 'tanggal');
        if ($meetingDate < now()->toDateString()) {
            return data_get($meeting, 'started_at') ? 'selesai' : 'dibatalkan';
        }

        return data_get($meeting, 'started_at') ? 'berlangsung' : 'akan';
    }

    public static function slug(array|object $meeting): string
    {
        $date = (string) data_get($meeting, 'tanggal');
        $month = substr($date, 0, 7);
        $roomSlug = self::roomSlug($meeting);
        $number = 0;

        foreach (self::meetingsInMonth($month) as $candidate) {
            if (self::roomSlug($candidate) === $roomSlug) {
                $number++;
            }
            if ((int) $candidate->id === (int) data_get($meeting, 'id')) {
                break;
            }
        }

        return $month.'-'.$roomSlug.'-'.sprintf('%02d', max(1, $number));
    }

    public static function assignSlugs(iterable $meetings): void
    {
        $meetings = collect($meetings);
        $roomNames = DB::table('ruang_rapat')
            ->whereIn('id', $meetings->pluck('room_id')->filter()->unique())
            ->pluck('nama', 'id');
        $counts = [];

        foreach ($meetings->sortBy('id') as $meeting) {
            if (!data_get($meeting, 'room_name') && data_get($meeting, 'room_id')) {
                $meeting->room_name = $roomNames->get(data_get($meeting, 'room_id'));
            }
            $month = substr((string) data_get($meeting, 'tanggal'), 0, 7);
            $roomSlug = self::roomSlug($meeting);
            $key = $month.'-'.$roomSlug;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $meeting->meeting_slug = $key.'-'.sprintf('%02d', $counts[$key]);
        }
    }

    public static function findBySlug(string $slug): ?object
    {
        if (!preg_match('/^(\d{4}-\d{2})-([a-z0-9-]+)-(\d{2,})$/', $slug, $matches)) {
            return null;
        }

        [, $month, $roomSlug, $number] = $matches;
        [, $monthNumber] = explode('-', $month);
        if ((int) $monthNumber < 1 || (int) $monthNumber > 12) {
            return null;
        }

        $currentNumber = 0;
        foreach (self::meetingsInMonth($month) as $meeting) {
            if (self::roomSlug($meeting) !== $roomSlug) {
                continue;
            }

            $currentNumber++;
            if ($currentNumber === (int) $number) {
                return $meeting;
            }
        }

        return null;
    }

    private static function meetingsInMonth(string $month)
    {
        return DB::table('rapat as r')
            ->leftJoin('ruang_rapat as room', 'room.id', '=', 'r.room_id')
            ->whereBetween('r.tanggal', [$month.'-01', \Illuminate\Support\Carbon::parse($month.'-01')->endOfMonth()->toDateString()])
            ->orderBy('r.id')
            ->select('r.*', 'room.nama as room_name')
            ->get();
    }

    private static function roomSlug(array|object $meeting): string
    {
        $roomName = data_get($meeting, 'tempat');
        if (!$roomName && data_get($meeting, 'room_id')) {
            $roomName = DB::table('ruang_rapat')->where('id', data_get($meeting, 'room_id'))->value('nama');
        }

        return Str::slug((string) ($roomName ?: data_get($meeting, 'tempat') ?: 'umum')) ?: 'umum';
    }

    public static function attendanceOpen(array|object $meeting): bool
    {
        $expires = data_get($meeting, 'presensi_kedaluwarsa');
        return !empty(data_get($meeting, 'kode_presensi'))
            && !empty($expires)
            && now()->lt(\Illuminate\Support\Carbon::parse($expires, config('app.timezone')));
    }

    public static function minutesDeadline(array|object $meeting): \Illuminate\Support\Carbon
    {
        $endedAt = self::effectiveEndedAt($meeting);
        $meetingEnd = $endedAt
            ? $endedAt->copy()
            : \Illuminate\Support\Carbon::parse(
                data_get($meeting, 'tanggal').' '.data_get($meeting, 'selesai'),
                config('app.timezone')
            );

        return $meetingEnd->addDays((int) self::setting('batas_notulen_default_hari', '7'));
    }

    public static function minutesEditingOpen(array|object $meeting): bool
    {
        return now()->lessThanOrEqualTo(self::minutesDeadline($meeting));
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
        return (bool) data_get($meeting, 'started_at')
            && !data_get($meeting, 'ended_at')
            && !data_get($meeting, 'cancelled_at')
            && (string) data_get($meeting, 'tanggal') >= now()->toDateString();
    }

    public static function canManuallyStart(array|object $meeting): bool
    {
        return !data_get($meeting, 'started_at')
            && !data_get($meeting, 'ended_at')
            && !data_get($meeting, 'cancelled_at')
            && (string) data_get($meeting, 'tanggal') >= now()->toDateString();
    }

    public static function effectiveEndedAt(array|object $meeting): ?\Illuminate\Support\Carbon
    {
        $endedAt = data_get($meeting, 'ended_at');
        if ($endedAt) {
            return \Illuminate\Support\Carbon::parse($endedAt, config('app.timezone'));
        }

        if (data_get($meeting, 'started_at') && (string) data_get($meeting, 'tanggal') < now()->toDateString()) {
            return \Illuminate\Support\Carbon::parse(data_get($meeting, 'tanggal'), config('app.timezone'))
                ->addDay()
                ->startOfDay();
        }

        return null;
    }

    public static function overtimeMinutes(array|object $meeting): int
    {
        $scheduledEnd = \Illuminate\Support\Carbon::parse(
            data_get($meeting, 'tanggal').' '.data_get($meeting, 'selesai'),
            config('app.timezone')
        );
        $actualEnd = self::effectiveEndedAt($meeting) ?? now();

        return $actualEnd->greaterThan($scheduledEnd)
            ? (int) $scheduledEnd->diffInMinutes($actualEnd)
            : 0;
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
