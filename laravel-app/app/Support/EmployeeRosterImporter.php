<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EmployeeRosterImporter
{
    public function import(array $records): array
    {
        Validator::make($records, [
            '*.nip' => ['required', 'regex:/^\d{18}$/'],
            '*.nama' => ['required', 'string', 'max:150'],
            '*.jabatan' => ['nullable', 'string', 'max:150'],
            '*.group_name' => ['nullable', 'string', 'max:150'],
        ])->validate();

        $seenNips = [];
        foreach ($records as $record) {
            if (isset($seenNips[$record['nip']])) {
                throw ValidationException::withMessages([
                    'roster' => 'NIP duplikat ditemukan pada data sumber.',
                ]);
            }
            $seenNips[$record['nip']] = true;
        }

        return DB::transaction(function () use ($records) {
            $employeesAdded = 0;
            $employeesExisting = 0;
            $groupsAdded = 0;
            $employeesSkipped = 0;

            foreach ($records as $record) {
                if (mb_strtolower(trim($record['group_name'] ?? '')) === 'pensiun') {
                    $employeesSkipped++;

                    continue;
                }

                $employee = DB::table('pegawai')->where('nip', $record['nip'])->first();

                if ($employee) {
                    $employeesExisting++;
                    $employeeId = (int) $employee->id;
                } else {
                    $employeeId = DB::table('pegawai')->insertGetId([
                        'nip' => $record['nip'],
                        'nama' => trim($record['nama']),
                        'jabatan' => trim($record['jabatan'] ?? '') ?: 'Pegawai',
                        'password_hash' => Hash::make($record['nip']),
                        'password_change_required' => true,
                    ]);
                    $employeesAdded++;
                }

                $groupName = trim($record['group_name'] ?? '') ?: 'Lainnya / belum dikelompokkan';
                DB::table('pegawai_invite_group_catalog')->insertOrIgnore([
                    'group_name' => $groupName,
                ]);
                $groupsAdded += DB::table('pegawai_invite_groups')->insertOrIgnore([
                    'pegawai_id' => $employeeId,
                    'group_name' => $groupName,
                ]);
            }

            return [
                'employees_added' => $employeesAdded,
                'employees_existing' => $employeesExisting,
                'groups_added' => $groupsAdded,
                'employees_skipped' => $employeesSkipped,
            ];
        });
    }
}
