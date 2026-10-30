# Deploy ke VPS DomaiNesia (Docker)

Target: `https://meeting.poltekkes-manado.ac.id` di VPS `103.181.182.168` (Ubuntu 22.04).

Aplikasi berjalan di Docker sendiri (project compose `rapat`), terpisah dari situs lain di VPS ini:

| Container | Isi |
| --- | --- |
| `rapat-app-1` | PHP 8.3-FPM + kode Laravel + aset Vite hasil build |
| `rapat-web-1` | nginx, terbuka hanya di `127.0.0.1:8100` |
| `rapat-db-1` | MySQL 8.4, database `rapat_poltekkes` (volume `rapat_dbdata`) |

Nginx host menangani HTTPS (Let's Encrypt) untuk `meeting.poltekkes-manado.ac.id` dan meneruskan ke `127.0.0.1:8100`.

| Path di VPS | Isi |
| --- | --- |
| `/var/www/rapat` | clone repo GitHub (build context image) |
| `/var/www/rapat/uploads` | berkas rapat, di-mount ke container sebagai `/var/www/uploads` |
| `/opt/rapat-deploy` | skrip di folder ini + `migrations/` (salinan migrasi `2026_10_19_000000` yang sudah diperbaiki) |
| `/opt/rapat-deploy/docker/.env` | konfigurasi produksi + password database (dibuat otomatis, mode 600) |
| `/etc/nginx/sites-available/meeting.poltekkes-manado.ac.id` | reverse proxy HTTPS host |
| `/root/backups` | cadangan database sebelum setiap update |

## Deploy dan update

```bash
bash /opt/rapat-deploy/deploy.sh
```

Skrip yang sama dipakai untuk deploy pertama dan setiap update dari GitHub: `git pull`, build image, cadangkan database (bila sudah berjalan), migrasi + seeder, `docker compose up -d`, lalu memastikan proxy Nginx host. Laporan cek terakhir: `https://meeting.poltekkes-manado.ac.id/up -> 200`.

Database dibuat kosong (hanya struktur + data ruang dan pengaturan dari seeder). Versi migrasi `2026_10_19_000000` di GitHub membuat ID `INT UNSIGNED`, sehingga foreign key di migrasi berikutnya (`INT` signed) gagal di database kosong. `deploy.sh` menjalankan versi yang sudah diperbaiki dari `migrations/` dengan nama yang sama. Perbaikan yang sama ada di `laravel-app/database/migrations/` di repo lokal dan sebaiknya di-commit ke GitHub.

## Super Admin pertama

Semua login memakai NIP, jadi minimal satu Super Admin harus dibuat sebelum pegawai lain bisa ditambahkan dari halaman **Pegawai**:

```bash
bash /opt/rapat-deploy/super-admin.sh 199001012015011001 "Nama Lengkap" "Jabatan"
```

Kata sandi awal = NIP, dan wajib diganti saat login pertama.

## Impor pegawai dari CSV

```bash
bash /opt/rapat-deploy/import-pegawai.sh /root/pegawai.csv
```

CSV ber-header `nip,nama,jabatan` (UTF-8). Pegawai baru mendapat kata sandi awal = NIP dan wajib menggantinya saat login pertama; pegawai yang sudah ada hanya diperbarui nama dan jabatannya. Impor awal (30 Oktober 2026) berasal dari sheet *LAPORAN DUK* di `LAPORAN_DUK_AGUSTUS_2026_DITAMBAH_WADIR_II.xlsx`: 195 pegawai, jabatan diambil dari *Jabatan Struktural / Penugasan* bila terisi, selain itu dari kolom *Jabatan*.

## Perintah berguna

```bash
cd /opt/rapat-deploy/docker
docker compose ps
docker compose logs -f app web
docker compose exec --user www-data app php artisan about
docker compose exec db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" rapat_poltekkes'
```

## Catatan

- Cloudflare harus tetap **DNS only** (awan abu-abu) untuk `meeting`, supaya perpanjangan sertifikat otomatis (`certbot renew`) berhasil.
- Batas unggah 100 MB diatur di `docker/php.ini`, `docker/nginx.conf`, dan proxy Nginx host (`client_max_body_size 110M`).
- Akses SSH deploy memakai key `rapat-deploy` di `/root/.ssh/authorized_keys`; hapus baris itu jika tidak diperlukan lagi.
