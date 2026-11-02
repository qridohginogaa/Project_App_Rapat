<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        $meetingIdType = $driver === 'mysql'
            ? DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'rapat')
                ->where('COLUMN_NAME', 'id')
                ->value('COLUMN_TYPE')
            : null;
        $unsigned = is_string($meetingIdType) && str_contains($meetingIdType, 'unsigned');
        $bigInteger = is_string($meetingIdType) && str_starts_with($meetingIdType, 'bigint');

        if (!Schema::hasTable('kehadiran_tamu')) {
            Schema::create('kehadiran_tamu', function (Blueprint $table) use ($unsigned, $bigInteger) {
                $table->increments('id');
                if ($bigInteger) {
                    $unsigned ? $table->unsignedBigInteger('rapat_id') : $table->bigInteger('rapat_id');
                } else {
                    $unsigned ? $table->unsignedInteger('rapat_id') : $table->integer('rapat_id');
                }
                $table->enum('jenis', ['tamu', 'mahasiswa']);
                $table->string('nama', 150);
                $table->string('asal', 200);
                $table->string('nim', 30)->nullable();
                $table->char('identitas_hash', 64);
                $table->timestamp('waktu')->useCurrent();
                $table->unique(['rapat_id', 'identitas_hash'], 'uq_guest_attendance_identity');
                $table->foreign('rapat_id')->references('id')->on('rapat')->cascadeOnDelete();
            });
        }

        if ($driver === 'mysql' && Schema::hasTable('kehadiran_tamu')) {
            $expectedType = ($bigInteger ? 'bigint' : 'int').($unsigned ? ' unsigned' : '');
            $actualType = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'kehadiran_tamu')
                ->where('COLUMN_NAME', 'rapat_id')
                ->value('COLUMN_TYPE');

            $normalizedActualType = is_string($actualType)
                ? (string) preg_replace('/\(\d+\)/', '', $actualType)
                : null;
            if ($normalizedActualType !== $expectedType) {
                $sqlType = $bigInteger ? 'BIGINT' : 'INT';
                $sqlUnsigned = $unsigned ? ' UNSIGNED' : '';
                DB::statement("ALTER TABLE kehadiran_tamu MODIFY rapat_id {$sqlType}{$sqlUnsigned} NOT NULL");
            }

            $uniqueIndexExists = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'kehadiran_tamu')
                ->where('INDEX_NAME', 'uq_guest_attendance_identity')
                ->exists();
            if (!$uniqueIndexExists) {
                DB::statement('ALTER TABLE kehadiran_tamu ADD UNIQUE uq_guest_attendance_identity (rapat_id, identitas_hash)');
            }

            $foreignKeyExists = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'kehadiran_tamu')
                ->where('COLUMN_NAME', 'rapat_id')
                ->where('REFERENCED_TABLE_NAME', 'rapat')
                ->exists();
            if (!$foreignKeyExists) {
                DB::statement('ALTER TABLE kehadiran_tamu ADD CONSTRAINT kehadiran_tamu_rapat_id_foreign FOREIGN KEY (rapat_id) REFERENCES rapat (id) ON DELETE CASCADE');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kehadiran_tamu');
    }
};
