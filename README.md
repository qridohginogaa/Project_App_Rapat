# Dashboard Rapat – Poltekkes Kemenkes Manado

Aplikasi ini menggunakan Laravel 12, PHP 8.2 atau lebih baru, dan MySQL. Sumber aplikasi PHP lama sudah dihapus. Folder `uploads/` di root proyek tetap dipakai Laravel untuk menyimpan berkas rapat lama dan unggahan baru.

## Menjalankan di komputer ini

1. Jalankan Apache dan MySQL melalui XAMPP.
2. Buka terminal di folder `laravel-app/`.
3. Jika dependensi belum tersedia, jalankan `composer install` dan `npm install`.
4. Pastikan `.env` memakai database MySQL `rapat_poltekkes` (nilai bawaan XAMPP: host `127.0.0.1`, port `3306`, user `root`, password kosong).
5. Jalankan `php artisan migrate --force`, `php artisan db:seed --force`, dan `npm run build`.
6. Jalankan `php artisan serve --host=127.0.0.1 --port=8000`, lalu buka `http://127.0.0.1:8000/`.

Migrasi tidak menghapus atau mengisi ulang data aplikasi yang sudah ada, kecuali menghapus akun login lama dengan username `admin`. Seeder hanya menambahkan nilai pengaturan/ruang yang belum tersedia. Semua pengguna, termasuk Super Admin, masuk menggunakan NIP pegawai dan kata sandi akun pegawai.

Waktu mulai dan selesai rapat ditulis dalam format 24 jam `HH:MM` (misalnya `13:30` atau `16:00`). Live Board menampilkan durasi rapat berjalan dalam jam, menit, dan detik (misalnya `30 menit 20 detik` atau `1 jam 20 menit 20 detik`).

Semua akun masuk melalui satu halaman Login tersendiri menggunakan NIP pegawai; tombol Login tersedia di bagian atas dashboard dan formulir tidak ditampilkan di dashboard. Akun Super Admin adalah akun pegawai yang diberi role Super Admin. Tingkatan akses adalah **Pegawai → Pengelola Rapat → Super Admin**. Super Admin dapat mengatur role dari halaman **Pegawai**. Pada tab **Hak Akses**, daftar Super Admin ditampilkan terpisah dan centang fitur Pengelola Rapat berlaku global untuk semua akun Pengelola Rapat. Super Admin dapat mengakhiri rapat berjalan secara manual dari dashboard; tindakan tersebut langsung menutup rapat dan membuka kode absen pulang selama 30 menit. Pegawai yang hadir tetapi belum mengisi kode pulang akan berstatus **Belum Absen Pulang** dan dapat mengisi keterangan/alasan dari detail rapat; keterangan tersebut ditampilkan pada laporan Pengelola Rapat/Super Admin. Akun pegawai dengan role Pengelola Rapat dapat berpindah antara mode Pegawai dan Pengelola Rapat menggunakan tombol **Beralih ke mode**.

Saat membuat atau mengubah rapat, pilih **Rapat untuk semua pegawai** atau kosongkan pilihan tersebut dan centang pegawai yang wajib mengikuti. Jika memilih pegawai tertentu, minimal satu nama harus dicentang; daftar undangan ditampilkan di detail rapat. Hanya Super Admin yang dapat membuat, memperbarui, memperpanjang, atau menutup kode absen masuk dan kode absen pulang. Pengelola Rapat dengan izin presensi dapat memantau daftar hadir dan pulang, tetapi tidak dapat mengatur kodenya. Kode pulang yang telah dibuat dan masih aktif dapat digunakan meskipun rapat belum diselesaikan; masa berlakunya dapat diatur Super Admin menjadi 15, 30, atau 60 menit. Jika rapat diselesaikan tanpa kode pulang aktif, sistem membuka kode otomatis selama 30 menit. Pegawai biasa tidak dapat membuat rapat atau menambahkan materi, rekaman, maupun notulen. Di halaman **Pegawai**, Super Admin dapat memilih role Pegawai, Pengelola Rapat, atau Super Admin.

Untuk akun yang dibuat dari daftar pegawai maupun akun baru yang ditambahkan Pengelola Rapat, kata sandi awal adalah NIP dan wajib diganti pada login pertama. Setelah itu, pegawai tidak dapat mengubah kata sandi sendiri; Pengelola Rapat dapat mengatur ulang kata sandi melalui **Pegawai**, dan pegawai wajib menggantinya lagi setelah login.

## Menjalankan melalui Apache XAMPP

Arahkan DocumentRoot virtual host Apache ke `laravel-app/public/`, bukan ke root proyek, folder `laravel-app/`, atau `uploads/`. Aktifkan `mod_rewrite` dan ekstensi PHP `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`, serta `session`. Untuk unggahan video, atur `upload_max_filesize` dan `post_max_size` di `php.ini` sekurangnya 100 MB.

## Live Board publik

Di atas kalender pada dashboard tersedia **Semua ruang** dan tombol Live Board terpisah untuk setiap ruang rapat aktif (Bunaken, Dihaga, dan Siladen). Papan semua ruang menampilkan seluruh rapat aktif, sedangkan papan suatu ruang hanya menampilkan rapat yang dijadwalkan di ruang itu dengan kartu yang melebar penuh. Papan menampilkan waktu mulai, lama rapat berjalan, dan jumlah pegawai hadir; lama berjalan diperbarui setiap detik dan data rapat/presensi setiap 5 detik. Halaman dan data Live Board dapat diakses publik tanpa login dan hanya menampilkan jumlah hadir, bukan identitas pegawai. Tombol **Preview Live Board** pada kartu rapat yang sedang berlangsung hanya ditampilkan kepada pengunjung yang belum login. Gunakan **Tampilkan layar penuh** untuk presentasi; mode ini mempertahankan papan rapat dengan header ringkas berisi logo, judul, jam, dan tombol navigasi kecil. Pengelola Rapat dapat meninjau presensi rapat yang sudah selesai melalui **Kehadiran & Izin** atau **Rapat**; catatan rapat selesai hanya dapat ditinjau, bukan dibuka ulang untuk presensi. Pada tab **Rapat**, empat rapat selesai terbaru ditampilkan terlebih dahulu; gunakan kolom pencarian untuk mencari berdasarkan judul, tanggal, atau tempat. Tombol **Tampilkan rapat selesai lainnya** membuka daftar lengkap atau menciutkannya kembali.

## Data dan berkas

- Data rapat, pegawai, kehadiran, dan pengaturan disimpan di database MySQL `rapat_poltekkes`.
- Berkas rapat tersimpan di folder `uploads/` di root proyek. Folder dan berkas di dalamnya jangan dihapus; Laravel mengaksesnya melalui route yang memeriksa autentikasi.
- Folder `laravel-app/database/` berisi migrasi dan seeder Laravel, bukan salinan database MySQL.
- Cadangkan database dan folder `uploads/` secara berkala. Jangan menghapus database MySQL ketika membersihkan berkas proyek.
