<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pegawai_invite_groups')) {
            return;
        }

        Schema::create('pegawai_invite_groups', function (Blueprint $table) {
            $table->integer('pegawai_id')->primary();
            $table->string('group_name', 150);
            $table->foreign('pegawai_id')->references('id')->on('pegawai')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawai_invite_groups');
    }
};
