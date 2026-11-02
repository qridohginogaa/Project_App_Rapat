<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $meetingIdType = $this->columnType('rapat', 'id');
        $employeeIdType = $this->columnType('pegawai', 'id');

        $missingSignerColumns = [];
        foreach (['pimpinan_pegawai_id', 'notulis_pegawai_id'] as $column) {
            if (!Schema::hasColumn('rapat', $column)) {
                $missingSignerColumns[] = $column;
            }
        }

        if ($missingSignerColumns) {
            Schema::table('rapat', function (Blueprint $table) use ($employeeIdType) {
                foreach (['pimpinan_pegawai_id', 'notulis_pegawai_id'] as $column) {
                    if (!Schema::hasColumn('rapat', $column)) {
                        $this->addEmployeeIdColumn($table, $column, $employeeIdType);
                    }
                }
            });
        }

        if (!Schema::hasTable('meeting_signatures')) {
            Schema::create('meeting_signatures', function (Blueprint $table) use ($meetingIdType, $employeeIdType) {
                $table->increments('id');
                $this->addReferenceIdColumn($table, 'rapat_id', $meetingIdType);
                $this->addReferenceIdColumn($table, 'pegawai_id', $employeeIdType);
                $table->string('peran', 20);
                $table->string('token', 64)->unique();
                $table->string('status', 20)->default('menunggu');
                $table->dateTime('disetujui_pada')->nullable();
                $table->char('document_hash', 64)->nullable();
                $table->timestamps();
                $table->unique(['rapat_id', 'peran']);
                $table->foreign('rapat_id')->references('id')->on('rapat')->cascadeOnDelete();
                $table->foreign('pegawai_id')->references('id')->on('pegawai')->cascadeOnDelete();
            });
        }

        $employeesByName = DB::table('pegawai')->select('id', 'nama')->get()
            ->groupBy(fn ($employee) => mb_strtolower(trim($employee->nama)));
        $meetings = DB::table('rapat')->select('id', 'pimpinan', 'notulis')->get();

        foreach ($meetings as $meeting) {
            $assignments = [];
            foreach (['pimpinan', 'notulis'] as $role) {
                $normalizedName = mb_strtolower(trim((string) $meeting->$role));
                $matches = $employeesByName->get($normalizedName, collect());
                if ($normalizedName !== '' && $matches->count() === 1) {
                    $employeeId = (int) $matches->first()->id;
                    DB::table('rapat')->where('id', $meeting->id)->update([
                        $role.'_pegawai_id' => $employeeId,
                    ]);
                    $assignments[$role] = $employeeId;
                }
            }

            foreach ($assignments as $role => $employeeId) {
                $now = now()->toDateTimeString();
                DB::table('meeting_signatures')->insert([
                    'rapat_id' => $meeting->id,
                    'pegawai_id' => $employeeId,
                    'peran' => $role,
                    'token' => Str::random(64),
                    'status' => 'menunggu',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_signatures');

        if (Schema::hasColumn('rapat', 'pimpinan_pegawai_id')) {
            Schema::table('rapat', function (Blueprint $table) {
                $table->dropForeign(['pimpinan_pegawai_id']);
                $table->dropForeign(['notulis_pegawai_id']);
                $table->dropColumn(['pimpinan_pegawai_id', 'notulis_pegawai_id']);
            });
        }
    }

    private function columnType(string $table, string $column): ?string
    {
        if (DB::getDriverName() !== 'mysql') {
            return null;
        }

        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->value('COLUMN_TYPE');
    }

    private function addEmployeeIdColumn(Blueprint $table, string $name, ?string $employeeIdType): void
    {
        $this->addReferenceIdColumn($table, $name, $employeeIdType, true);
        $table->foreign($name)->references('id')->on('pegawai')->nullOnDelete();
    }

    private function addReferenceIdColumn(Blueprint $table, string $name, ?string $columnType, bool $nullable = false): void
    {
        $bigInteger = is_string($columnType) && str_starts_with($columnType, 'bigint');
        $unsigned = is_string($columnType) && str_contains($columnType, 'unsigned');

        if ($bigInteger) {
            $column = $unsigned ? $table->unsignedBigInteger($name) : $table->bigInteger($name);
        } else {
            $column = $unsigned ? $table->unsignedInteger($name) : $table->integer($name);
        }

        if ($nullable) {
            $column->nullable();
        }
    }
};
