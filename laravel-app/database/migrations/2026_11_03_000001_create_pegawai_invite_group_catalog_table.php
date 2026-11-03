<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_GROUP = 'Lainnya / belum dikelompokkan';

    public function up(): void
    {
        Schema::create('pegawai_invite_group_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('group_name', 150)->unique();
        });

        DB::table('pegawai_invite_group_catalog')->insert(['group_name' => self::DEFAULT_GROUP]);

        DB::table('pegawai_invite_groups')
            ->select('group_name')
            ->distinct()
            ->orderBy('group_name')
            ->pluck('group_name')
            ->each(function (string $groupName) {
                if (mb_strtolower(trim($groupName)) !== 'pensiun') {
                    DB::table('pegawai_invite_group_catalog')->insertOrIgnore([
                        'group_name' => trim($groupName),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawai_invite_group_catalog');
    }
};
