<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ID memakai INT signed seperti database lama, sesuai kolom relasi pada migrasi berikutnya.
        if (!Schema::hasTable('pegawai')) {
            Schema::create('pegawai', function (Blueprint $table) {
                $table->integer('id', true);
                $table->char('nip', 18)->unique();
                $table->string('nama', 150);
                $table->string('jabatan', 150);
                $table->string('password_hash', 255)->nullable();
            });
        }

        if (!Schema::hasTable('ruang_rapat')) {
            Schema::create('ruang_rapat', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('nama', 120)->unique();
                $table->boolean('aktif')->default(true);
                $table->timestamp('dibuat_pada')->useCurrent();
            });
        }

        if (!Schema::hasTable('rapat')) {
            Schema::create('rapat', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('judul', 200);
                $table->date('tanggal');
                $table->time('mulai');
                $table->time('selesai');
                $table->string('tempat', 200)->nullable();
                $table->integer('room_id')->nullable();
                $table->string('link_meeting', 500)->nullable();
                $table->enum('moda', ['Luring', 'Daring', 'Hybrid'])->default('Luring');
                $table->string('pimpinan', 200)->nullable();
                $table->string('notulis', 200)->nullable();
                $table->string('pemateri', 200)->nullable();
                $table->text('agenda')->nullable();
                $table->string('link_rekaman', 500)->nullable();
                $table->longText('notulen')->nullable();
                $table->string('file_notulen', 100)->nullable();
                $table->string('kode_presensi', 20)->nullable();
                $table->dateTime('presensi_kedaluwarsa')->nullable();
                $table->boolean('presensi_diperpanjang')->default(false);
                $table->string('materi_rapat', 100)->nullable();
                $table->foreign('room_id')->references('id')->on('ruang_rapat')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('berkas_rapat')) {
            Schema::create('berkas_rapat', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('rapat_id');
                $table->enum('kategori', ['materi', 'rekaman']);
                $table->string('nama_file', 100)->nullable();
                $table->string('nama_asli', 255)->nullable();
                $table->string('tautan', 500)->nullable();
                $table->string('pengunggah', 150)->nullable();
                $table->timestamp('dibuat_pada')->useCurrent();
                $table->foreign('rapat_id')->references('id')->on('rapat')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('penugasan')) {
            Schema::create('penugasan', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('rapat_id');
                $table->integer('pegawai_id')->nullable();
                $table->string('nama_petugas', 150)->nullable();
                $table->text('tugas');
                $table->date('deadline')->nullable();
                $table->enum('status', ['Belum Selesai', 'Proses', 'Selesai'])->default('Belum Selesai');
                $table->foreign('rapat_id')->references('id')->on('rapat')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('kehadiran')) {
            Schema::create('kehadiran', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('rapat_id');
                $table->integer('pegawai_id');
                $table->enum('status', ['hadir', 'izin']);
                $table->string('jenis', 30)->nullable();
                $table->text('alasan')->nullable();
                $table->string('file', 100)->nullable();
                $table->string('perangkat_id', 255)->nullable();
                $table->enum('status_izin', ['menunggu', 'disetujui', 'ditolak'])->nullable();
                $table->timestamp('waktu')->useCurrent();
                $table->unique(['rapat_id', 'pegawai_id'], 'uq');
                $table->foreign('rapat_id')->references('id')->on('rapat')->cascadeOnDelete();
                $table->foreign('pegawai_id')->references('id')->on('pegawai')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('admin')) {
            Schema::create('admin', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('username', 50)->unique();
                $table->string('password_hash', 255);
            });
        }

        if (!Schema::hasTable('pengaturan')) {
            Schema::create('pengaturan', function (Blueprint $table) {
                $table->string('kunci', 50)->primary();
                $table->string('nilai', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kehadiran');
        Schema::dropIfExists('penugasan');
        Schema::dropIfExists('berkas_rapat');
        Schema::dropIfExists('rapat');
        Schema::dropIfExists('ruang_rapat');
        Schema::dropIfExists('pegawai');
        Schema::dropIfExists('admin');
        Schema::dropIfExists('pengaturan');
    }
};
