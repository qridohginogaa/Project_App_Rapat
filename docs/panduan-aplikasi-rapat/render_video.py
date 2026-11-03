from __future__ import annotations

import argparse
import math
import subprocess
import tempfile
from pathlib import Path

import cv2
import imageio_ffmpeg
import numpy as np
from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parent
ASSETS = ROOT / "screenshots"
WIDTH, HEIGHT, FPS = 1920, 1080, 30
NAVY = "#14263D"
TEAL = "#087F75"
MUTED = "#64788E"
WHITE = "#FFFFFF"
LINE = "#D8E9E6"
PALE = "#F2FAF8"
LIME = "#C5E85B"
FONT_PATH = Path(r"C:\Windows\Fonts\segoeui.ttf")
BOLD_PATH = Path(r"C:\Windows\Fonts\segoeuib.ttf")
FONTS = {size: ImageFont.truetype(str(FONT_PATH), size) for size in range(14, 131)}
BOLDS = {size: ImageFont.truetype(str(BOLD_PATH), size) for size in range(14, 131)}


def rounded(draw, rect, fill=WHITE, radius=28, outline=None, width=1):
    draw.rounded_rectangle(rect, radius=radius, fill=fill, outline=outline, width=width)


def box(image, rect, radius=28, fill=WHITE, outline=LINE):
    x1, y1, x2, y2 = rect
    draw = ImageDraw.Draw(image, "RGBA")
    draw.rounded_rectangle(
        (x1 + 4, y1 + 9, x2 + 4, y2 + 9),
        radius=radius,
        fill=(25, 55, 70, 20),
    )
    draw.rounded_rectangle(rect, radius=radius, fill=fill, outline=outline, width=2)


def wrapped_lines(draw, text, font, max_width):
    lines = []
    for paragraph in text.split("\n"):
        line = ""
        for word in paragraph.split():
            candidate = word if not line else f"{line} {word}"
            if draw.textbbox((0, 0), candidate, font=font)[2] <= max_width:
                line = candidate
            else:
                if line:
                    lines.append(line)
                line = word
        if line:
            lines.append(line)
    return lines


def paragraph(draw, text, x, y, max_width, font, color=MUTED, leading=1.38, max_lines=None):
    lines = wrapped_lines(draw, text, font, max_width)
    if max_lines is not None and len(lines) > max_lines:
        lines = lines[:max_lines]
        while lines[-1] and draw.textbbox((0, 0), lines[-1] + "…", font=font)[2] > max_width:
            lines[-1] = lines[-1][:-1]
        lines[-1] = lines[-1].rstrip() + "…"

    line_height = int(font.size * leading)
    for line in lines:
        draw.text((x, y), line, font=font, fill=color)
        y += line_height
    return y


def background():
    top = np.array([249, 252, 251], dtype=np.float32)
    bottom = np.array([231, 244, 241], dtype=np.float32)
    vertical = np.linspace(0, 1, HEIGHT, dtype=np.float32)[:, None, None]
    pixels = np.broadcast_to(top * (1 - vertical) + bottom * vertical, (HEIGHT, WIDTH, 3))
    image = Image.fromarray(pixels.astype(np.uint8), "RGB")
    draw = ImageDraw.Draw(image, "RGBA")
    draw.ellipse((1370, -470, 2220, 380), fill=(34, 181, 160, 20))
    draw.ellipse((-380, 760, 450, 1590), fill=(188, 224, 91, 20))
    for x, y, radius in [(1700, 720, 240), (1600, 790, 150), (360, 210, 82)]:
        draw.ellipse(
            (x - radius, y - radius, x + radius, y + radius),
            outline=(8, 127, 117, 15),
            width=2,
        )
    return image


logo_source = Image.open(ASSETS / "01-dashboard.png").convert("RGB")
logo = logo_source.crop((42, 16, 240, 88)).resize((220, 80), Image.Resampling.LANCZOS)
logo_mask = Image.new("L", logo.size, 0)
ImageDraw.Draw(logo_mask).rounded_rectangle((0, 0, *logo.size), radius=18, fill=255)


def header(image, slide, label="PANDUAN PENGGUNA"):
    draw = ImageDraw.Draw(image, "RGBA")
    draw.rounded_rectangle(
        (62, 38, 298, 128),
        radius=22,
        fill=(255, 255, 255, 235),
        outline=(220, 235, 232, 255),
        width=2,
    )
    image.paste(logo, (70, 43), logo_mask)
    draw.text((330, 52), label, font=FONTS[20], fill=TEAL)
    draw.text((330, 82), "Poltekkes Kemenkes Manado", font=FONTS[24], fill=NAVY)
    draw.rounded_rectangle(
        (1726, 55, 1844, 110),
        radius=27,
        fill=(255, 255, 255, 235),
        outline=(216, 233, 230, 255),
        width=2,
    )
    draw.text((1750, 70), f"{slide:02d} / 12", font=FONTS[20], fill=NAVY)
    draw.line((72, 148, 1848, 148), fill=(8, 127, 117, 55), width=2)


def footer(image, text="APLIKASI RAPAT • PANDUAN SINGKAT"):
    draw = ImageDraw.Draw(image)
    draw.text((82, 1014), text, font=FONTS[18], fill=MUTED)
    draw.text((1640, 1014), "WAKTU • WITA", font=FONTS[18], fill=TEAL)


def headline(image, title, subtitle):
    draw = ImageDraw.Draw(image)
    draw.text((82, 178), title, font=BOLDS[52], fill=NAVY)
    paragraph(draw, subtitle, 86, 245, 1730, FONTS[26], MUTED, 1.3, 2)


def badge(draw, x, y, text, color=TEAL):
    font = FONTS[20]
    width = draw.textbbox((0, 0), text, font=font)[2] + 36
    draw.rounded_rectangle(
        (x, y, x + width, y + 42),
        radius=21,
        fill="#E8F5F2",
        outline=color,
        width=1,
    )
    draw.text((x + 18, y + 8), text, font=font, fill=color)


def ImageColor(value):
    value = value.lstrip("#")
    return tuple(int(value[index : index + 2], 16) for index in (0, 2, 4))


def add_screen(image, filename, rect, crop=None, conceal_guest_data=False):
    x1, y1, x2, y2 = rect
    box(image, rect, 26, WHITE, LINE)
    inner_width, inner_height = x2 - x1 - 30, y2 - y1 - 30
    source = Image.open(ASSETS / filename).convert("RGB")
    if crop:
        source = source.crop(crop)
    if conceal_guest_data:
        mask_draw = ImageDraw.Draw(source)
        for top, bottom in [(555, 615), (665, 730), (770, 840), (875, 950), (980, 1050), (1140, 1220)]:
            mask_draw.rounded_rectangle(
                (397, top, 1128, bottom),
                radius=13,
                fill=WHITE,
                outline="#CFE3DF",
                width=3,
            )
        mask_draw.text((430, 1160), "Masukkan kode dari panitia", font=FONTS[28], fill=MUTED)

    source.thumbnail((inner_width, inner_height), Image.Resampling.LANCZOS)
    x = x1 + 15 + (inner_width - source.width) // 2
    y = y1 + 15 + (inner_height - source.height) // 2
    mask = Image.new("L", source.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, source.width - 1, source.height - 1), radius=12, fill=255)
    image.paste(source, (x, y), mask)

    draw = ImageDraw.Draw(image, "RGBA")
    draw.rounded_rectangle(
        (x1 + 24, y2 - 58, x1 + 390, y2 - 23),
        radius=17,
        fill=(19, 38, 60, 195),
    )
    draw.text(
        (x1 + 42, y2 - 52),
        "TAMPILAN CONTOH • DATA SIMULASI",
        font=BOLDS[17],
        fill=WHITE,
    )


def steps_card(image, eyebrow, lines, note=None):
    x1, y1, x2, y2 = 1230, 285, 1840, 952
    box(image, (x1, y1, x2, y2), 30, WHITE, LINE)
    draw = ImageDraw.Draw(image)
    draw.text((x1 + 40, y1 + 36), eyebrow.upper(), font=BOLDS[22], fill=TEAL)
    y = y1 + 95
    for number, line in enumerate(lines, 1):
        draw.ellipse((x1 + 38, y + 3, x1 + 80, y + 45), fill=TEAL)
        draw.text((x1 + 52, y + 10), str(number), font=FONTS[22], fill=WHITE)
        end_y = paragraph(draw, line, x1 + 99, y, x2 - x1 - 140, FONTS[25], NAVY, 1.33, 4)
        y = max(y + 82, end_y + 22)
        if y > y2 - 140:
            break
    if note:
        draw.rounded_rectangle(
            (x1 + 32, y2 - 145, x2 - 32, y2 - 27),
            radius=20,
            fill="#F1F8E3",
            outline="#E3EDC8",
            width=1,
        )
        draw.text((x1 + 53, y2 - 125), "CATATAN", font=BOLDS[18], fill=TEAL)
        paragraph(draw, note, x1 + 53, y2 - 95, x2 - x1 - 105, FONTS[19], MUTED, 1.2, 3)


def title_slide(image, slide, title, subtitle, eyebrow="ALUR PENGGUNAAN"):
    header(image, slide, eyebrow)
    headline(image, title, subtitle)


def icon_card(image, rect, icon, title, body, accent=TEAL):
    x1, y1, x2, y2 = rect
    box(image, rect, 28, WHITE, LINE)
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle(
        (x1 + 28, y1 + 26, x1 + 88, y1 + 86),
        radius=18,
        fill=(*ImageColor(accent), 22),
        outline=(*ImageColor(accent), 80),
        width=2,
    )
    draw.text((x1 + 45, y1 + 33), icon, font=BOLDS[32], fill=accent)
    end_y = paragraph(draw, title, x1 + 28, y1 + 110, x2 - x1 - 56, BOLDS[30], NAVY, 1.2, 2)
    paragraph(draw, body, x1 + 28, end_y + 20, x2 - x1 - 56, FONTS[23], MUTED, 1.35, 5)


def make_scenes():
    scenes = []

    image = background()
    header(image, 1, "VIDEO PANDUAN • 1080p")
    draw = ImageDraw.Draw(image)
    draw.text((105, 235), "SISTEM INFORMASI RAPAT", font=BOLDS[23], fill=TEAL)
    draw.text((100, 302), "Rapat lebih", font=BOLDS[94], fill=NAVY)
    draw.text((100, 408), "tertata.", font=BOLDS[112], fill=TEAL)
    paragraph(draw, "Panduan penggunaan aplikasi rapat\nPoltekkes Kemenkes Manado", 108, 555, 840, FONTS[35], MUTED, 1.45)
    badge(draw, 106, 730, "PRESENSI • UNDANGAN • NOTULEN", TEAL)
    box(image, (1030, 245, 1812, 895), 34, WHITE, LINE)
    for index, color in enumerate(["#EE786C", "#F0C052", "#40B890"]):
        draw.ellipse((1081 + index * 31, 278, 1099 + index * 31, 296), fill=color)
    draw.text((1080, 330), "DASHBOARD RAPAT", font=BOLDS[26], fill=TEAL)
    draw.text((1080, 374), "Presensi Tamu & Mahasiswa", font=FONTS[18], fill=TEAL)
    draw.text((1700, 374), "Login", font=BOLDS[18], fill=NAVY)
    rounded(draw, (1070, 420, 1460, 605), "#E8F7F4", 24)
    draw.text((1100, 449), "POLTEKKES KEMENKES MANADO", font=BOLDS[15], fill=TEAL)
    draw.text((1100, 483), "Jadwal rapat", font=BOLDS[34], fill=NAVY)
    paragraph(draw, "Pantau rapat yang akan datang dan sedang berlangsung.", 1100, 535, 330, FONTS[18], MUTED, 1.25, 2)
    rounded(draw, (1480, 420, 1770, 605), "#F4FAF9", 24)
    draw.text((1510, 445), "Waktu Manado (WITA)", font=FONTS[17], fill=MUTED)
    draw.text((1510, 480), "11.13.20", font=BOLDS[36], fill=TEAL)
    draw.text((1510, 545), "Selasa, 3 November 2026", font=FONTS[16], fill=MUTED)
    draw.text((1080, 642), "Rapat akan datang", font=BOLDS[22], fill=NAVY)
    badge(draw, 1320, 638, "0", TEAL)
    rounded(draw, (1070, 686, 1770, 738), "#F5FAF9", 18, LINE, 2)
    draw.text((1100, 700), "Tidak ada rapat yang akan datang.", font=FONTS[18], fill=MUTED)
    draw.text((1080, 766), "Rapat sedang berlangsung", font=BOLDS[22], fill=NAVY)
    badge(draw, 1398, 762, "0", "#18A7A0")
    rounded(draw, (1070, 810, 1770, 862), "#F5FAF9", 18, LINE, 2)
    draw.text((1100, 824), "Tidak ada rapat yang sedang berlangsung.", font=FONTS[18], fill=MUTED)
    footer(image, "ANIMASI PANDUAN • ILUSTRASI DATA CONTOH")
    scenes.append((image, 8))

    image = background()
    title_slide(image, 2, "Mulai dari dashboard", "Lihat jadwal, jam WITA, dan akses cepat menuju presensi pada halaman awal.")
    add_screen(image, "01-dashboard-video.png", (78, 290, 1190, 950), crop=(0, 0, 1140, 700))
    steps_card(
        image,
        "Apa yang ditampilkan",
        [
            "Rapat akan datang dan rapat yang sedang berlangsung.",
            "Pilih jadwal untuk membuka informasi rapat setelah login.",
            "Gunakan tombol Presensi Tamu & Mahasiswa atau Login di header.",
            "Jam dan tanggal Manado (WITA) tampil di samping judul dashboard.",
        ],
        "Scroll ke bawah untuk melihat Live Board dan kalender rapat. Menu mengikuti role dan izin akun.",
    )
    footer(image)
    scenes.append((image, 11))

    image = background()
    title_slide(image, 3, "Tiga peran, alur yang jelas", "Gunakan akun pribadi. Fitur tiap pengguna mengikuti role dan hak akses yang diberikan.")
    icon_card(image, (78, 330, 652, 855), "01", "Pegawai", "Melihat rapat, mencatat presensi sendiri, mengajukan izin, dan menyetujui notulen bila ditugaskan.", TEAL)
    icon_card(image, (673, 330, 1247, 855), "02", "Pengelola Rapat", "Mengatur jadwal serta konten rapat sesuai izin dari Super Admin.", "#139A91")
    icon_card(image, (1268, 330, 1842, 855), "03", "Super Admin", "Mengelola akun, hak akses, grup undangan, ruang, serta kode presensi.", "#758E22")
    footer(image)
    scenes.append((image, 11))

    image = background()
    title_slide(image, 4, "Masuk dengan akun Anda", "Pegawai, Pengelola Rapat, dan Super Admin memakai halaman login yang sama.")
    add_screen(image, "02-login.png", (78, 306, 1190, 943))
    steps_card(
        image,
        "Login pertama kali",
        [
            "Buka Login, masukkan NIP dan kata sandi akun.",
            "Akun baru memakai NIP sebagai kata sandi awal.",
            "Saat pertama masuk, buat kata sandi baru sesuai petunjuk aplikasi.",
            "Lupa kata sandi? Hubungi Super Admin untuk reset.",
        ],
        "Jangan pernah menampilkan NIP atau kata sandi di video, chat umum, atau layar proyektor.",
    )
    footer(image)
    scenes.append((image, 12))

    image = background()
    title_slide(image, 5, "Pegawai: isi presensi rapat", "Lakukan absen masuk saat rapat berlangsung, lalu absen pulang saat kode dibuka.")
    add_screen(image, "07-detail-rapat.png", (78, 300, 1190, 948), crop=(70, 930, 1440, 1740))
    steps_card(
        image,
        "Langkah presensi",
        [
            "Buka rapat yang benar dari dashboard.",
            "Masukkan kode hadir yang dibagikan panitia ketika rapat berlangsung.",
            "Pastikan status Hadir tercatat.",
            "Untuk pulang, masukkan kode absen pulang yang berbeda.",
            "Jika pulang lebih awal, isi alasan pada menu yang tersedia.",
        ],
        "Kode presensi aktif terbatas waktu. Minta kode baru dari panitia jika sudah kedaluwarsa.",
    )
    footer(image)
    scenes.append((image, 12))

    image = background()
    title_slide(image, 6, "Tamu & mahasiswa: presensi mandiri", "Satu formulir publik dipakai untuk absen masuk maupun pulang.")
    add_screen(image, "10-presensi-mahasiswa.png", (78, 295, 1190, 953), conceal_guest_data=True)
    steps_card(
        image,
        "Isi formulir",
        [
            "Pilih rapat yang dihadiri dan jenis peserta.",
            "Isi nama serta instansi/program studi.",
            "Mahasiswa juga mengisi NIM.",
            "Masukkan kode sesuai tahap hadir atau pulang dari panitia, lalu kirim.",
        ],
        "Bila rapat belum membuka presensi, hubungi panitia. Kode tidak dicantumkan dalam panduan ini.",
    )
    footer(image)
    scenes.append((image, 12))

    image = background()
    title_slide(image, 7, "Pengelola: siapkan rapat", "Buat jadwal dengan informasi, agenda, ruangan, dan peserta yang tepat.")
    add_screen(image, "06-menu-rapat.png", (78, 294, 1190, 951), crop=(0, 140, 1510, 1471))
    steps_card(
        image,
        "Membuat jadwal",
        [
            "Buka menu Rapat, lalu pilih Buat rapat baru.",
            "Isi judul, tanggal, waktu, moda, tempat, agenda, pimpinan, dan notulis.",
            "Pilih untuk semua pegawai atau undang peserta tertentu.",
            "Simpan setelah memeriksa jadwal dan daftar peserta.",
        ],
        "Tombol pengelolaan bergantung pada izin Buat Rapat.",
    )
    footer(image)
    scenes.append((image, 13))

    image = background()
    title_slide(image, 8, "Pilih undangan per jurusan/unit", "Grup mempercepat pencarian peserta; rapat tetap menyimpan pegawai yang dipilih.")
    box(image, (78, 294, 1190, 950), 30, WHITE, LINE)
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle((78, 294, 1190, 385), radius=28, fill=PALE, outline=LINE, width=2)
    draw.text((118, 318), "PILIH PESERTA RAPAT", font=BOLDS[30], fill=NAVY)
    draw.text((118, 356), "Cari pegawai atau pilih anggota berdasarkan grup", font=FONTS[21], fill=MUTED)
    rounded(draw, (116, 410, 1148, 468), WHITE, 20, LINE, 2)
    draw.ellipse((145, 426, 164, 445), outline="#8495A7", width=3)
    draw.line((161, 443, 171, 453), fill="#8495A7", width=3)
    draw.text((185, 426), "Cari nama, NIP, atau jabatan", font=FONTS[22], fill="#8495A7")
    for index, (name, count, selected) in enumerate(
        [("KEPERAWATAN", "Daftar anggota jurusan", "PILIHAN"), ("FARMASI", "Pilih anggota grup", "PILIHAN"), ("DIREKTORAT / UNIT", "Pegawai dikelompokkan", "PILIHAN")]
    ):
        y = 495 + index * 126
        rounded(draw, (115, y, 1150, y + 106), "#FBFEFD", 22, LINE, 2)
        draw.rounded_rectangle((142, y + 28, 184, y + 70), radius=12, fill=TEAL)
        draw.line((153, y + 48, 162, y + 57, 175, y + 39), fill=WHITE, width=4, joint="curve")
        draw.text((208, y + 20), name, font=BOLDS[24], fill=NAVY)
        draw.text((208, y + 57), count, font=FONTS[20], fill=MUTED)
        badge(draw, 875, y + 31, selected, TEAL)
    steps_card(
        image,
        "Saat membuat rapat",
        [
            "Buka grup untuk melihat daftar pegawainya.",
            "Pilih satu per satu atau gunakan Pilih semua pegawai dalam grup.",
            "Pencarian membantu menemukan pegawai. Periksa jumlah terpilih sebelum menyimpan.",
        ],
        "Pilihan grup hanya alat bantu memilih peserta; yang disimpan adalah akun yang dicentang.",
    )
    footer(image)
    scenes.append((image, 14))

    image = background()
    title_slide(image, 9, "Super Admin: atur hak & grup", "Beri kewenangan kelola grup, lalu tambah jurusan/unit baru saat dibutuhkan.")
    box(image, (78, 294, 1190, 950), 30, WHITE, LINE)
    draw = ImageDraw.Draw(image)
    draw.text((122, 330), "HAK AKSES PENGELOLA RAPAT", font=BOLDS[26], fill=NAVY)
    badge(draw, 805, 323, "CONTOH PENGATURAN", TEAL)
    permissions = [
        "Buat Rapat",
        "Kelola Notulen",
        "Materi Presentasi",
        "Tautan Rekaman",
        "Presensi & Kehadiran",
        "Kelola grup jurusan/unit undangan",
    ]
    for index, label in enumerate(permissions):
        column, row = index % 2, index // 2
        x, y = 122 + column * 516, 405 + row * 104
        rounded(draw, (x, y, x + 478, y + 76), "#FBFEFD", 17, LINE, 2)
        draw.rounded_rectangle((x + 18, y + 18, x + 56, y + 56), radius=10, fill=TEAL if index in (0, 5) else "#E5F0ED")
        if index in (0, 5):
            draw.line((x + 27, y + 38, x + 36, y + 47, x + 49, y + 29), fill=WHITE, width=4, joint="curve")
        draw.text((x + 73, y + 22), label, font=FONTS[21], fill=NAVY)
    rounded(draw, (122, 758, 1126, 893), "#F0F8E1", 20, "#E2ECC6", 2)
    draw.text((153, 782), "GRUP UNDANGAN", font=BOLDS[22], fill=TEAL)
    for index, label in enumerate(["+ Tambah jurusan/unit", "Farmasi", "Keperawatan"]):
        x = 153 + index * 305
        rounded(draw, (x, 830, x + 280, 878), WHITE, 15, LINE, 1)
        draw.text((x + 16, 841), label, font=FONTS[20], fill=NAVY)
    steps_card(
        image,
        "Atur grup undangan",
        [
            "Di Hak Akses, aktifkan Kelola grup jurusan/unit undangan lalu simpan.",
            "Buka Grup Undangan dan tambahkan nama unit/jurusan baru.",
            "Akun pegawai baru bisa langsung diberi grup saat ditambahkan.",
        ],
        "Hapus grup tidak menghapus akun pegawai; anggota dipindahkan ke Lainnya / belum dikelompokkan.",
    )
    footer(image)
    scenes.append((image, 14))

    image = background()
    title_slide(image, 10, "Super Admin: buka kode presensi", "Kode hadir dan pulang dikelola terpisah serta hanya berlaku selama periode aktifnya.")
    add_screen(image, "07-detail-rapat.png", (78, 300, 1190, 948), crop=(70, 930, 1440, 1740))
    steps_card(
        image,
        "Sebelum peserta absen",
        [
            "Buka rapat lalu pilih pengaturan presensi.",
            "Buka kode hadir dan tentukan masa aktifnya.",
            "Bagikan kode hanya kepada peserta rapat.",
            "Buka kode pulang saat peserta melakukan absen pulang.",
        ],
        "Pengelola Rapat dapat memantau kehadiran bila diberi izin, tetapi tidak mengelola kode.",
    )
    footer(image)
    scenes.append((image, 12))

    image = background()
    title_slide(image, 11, "Akhiri dengan laporan & notulen", "Periksa presensi, simpan keputusan, dan selesaikan persetujuan dokumen.")
    add_screen(image, "08-daftar-kehadiran.png", (78, 300, 1190, 948))
    steps_card(
        image,
        "Sesudah rapat",
        [
            "Menu Kehadiran & Izin menampilkan catatan pegawai dan tamu/mahasiswa.",
            "Ekspor daftar hadir bila role memiliki izin.",
            "Lengkapi notulen serta tindak lanjut.",
            "Pimpinan dan notulis yang ditunjuk menyetujui notulen versi terbaru.",
        ],
        "Perubahan notulen setelah persetujuan memerlukan persetujuan kembali.",
    )
    footer(image)
    scenes.append((image, 13))

    image = background()
    title_slide(image, 12, "Pantau rapat. Jaga akun.", "Gunakan aplikasi sesuai kewenangan; data dan tampilan pada video ini merupakan simulasi.")
    add_screen(image, "04-live-board.png", (78, 300, 1190, 948))
    steps_card(
        image,
        "Ingat selalu",
        [
            "Pegawai gunakan akun pribadi dan pastikan presensi tercatat.",
            "Pengelola hanya memakai menu yang diizinkan.",
            "Super Admin menata akun, hak akses, grup, dan kode.",
            "Jangan membagikan kata sandi atau kode presensi di grup umum.",
        ],
        "Musik: AgusAlvarez & Luke Bergs — The Feeling · freetouse.com",
    )
    footer(image, "TERIMA KASIH • POLTEKKES KEMENKES MANADO")
    scenes.append((image, 11))
    return scenes


def render_video(scenes, silent_path):
    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
    total_frames = sum(duration for _, duration in scenes) * FPS
    command = [
        ffmpeg,
        "-hide_banner",
        "-loglevel",
        "error",
        "-y",
        "-f",
        "rawvideo",
        "-pix_fmt",
        "bgr24",
        "-s:v",
        f"{WIDTH}x{HEIGHT}",
        "-r",
        str(FPS),
        "-i",
        "pipe:0",
        "-an",
        "-c:v",
        "libx264",
        "-preset",
        "medium",
        "-crf",
        "18",
        "-profile:v",
        "high",
        "-level:v",
        "4.1",
        "-pix_fmt",
        "yuv420p",
        "-g",
        str(FPS * 2),
        "-movflags",
        "+faststart",
        str(silent_path),
    ]
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stderr=subprocess.PIPE)
    previous_frame = None
    completed = 0
    crossfade_frames = 21

    try:
        for scene_index, (image, duration) in enumerate(scenes):
            base = cv2.cvtColor(np.asarray(image), cv2.COLOR_RGB2BGR)
            scene_frames = duration * FPS
            final_scene_frame = None

            for frame_index in range(scene_frames):
                progress = frame_index / max(scene_frames - 1, 1)
                easing = progress * progress * (3 - 2 * progress)
                scale = 1.0 + 0.012 * easing
                matrix = cv2.getRotationMatrix2D((WIDTH / 2, HEIGHT / 2), 0, scale)
                matrix[0, 2] += math.sin(progress * math.pi) * 5
                matrix[1, 2] += math.sin(progress * math.pi) * -3
                frame = cv2.warpAffine(
                    base,
                    matrix,
                    (WIDTH, HEIGHT),
                    flags=cv2.INTER_CUBIC,
                    borderMode=cv2.BORDER_REPLICATE,
                )

                overall = completed + frame_index
                bar_x, bar_y, bar_width = 80, HEIGHT - 18, WIDTH - 160
                cv2.rectangle(frame, (bar_x, bar_y), (bar_x + bar_width, HEIGHT - 10), (219, 233, 230), -1)
                cv2.rectangle(
                    frame,
                    (bar_x, bar_y),
                    (bar_x + max(1, int(bar_width * overall / total_frames)), HEIGHT - 10),
                    (117, 127, 8),
                    -1,
                )
                cv2.circle(
                    frame,
                    (1680, 82),
                    5 + int((1 + math.sin(progress * math.tau)) * 1.5),
                    (8, 127, 117),
                    -1,
                    cv2.LINE_AA,
                )

                if previous_frame is not None and frame_index < crossfade_frames:
                    alpha = (frame_index + 1) / crossfade_frames
                    alpha = alpha * alpha * (3 - 2 * alpha)
                    frame = cv2.addWeighted(previous_frame, 1 - alpha, frame, alpha, 0)

                process.stdin.write(frame.tobytes())
                if frame_index == scene_frames - 1:
                    final_scene_frame = frame

            previous_frame = final_scene_frame
            completed += scene_frames
            print(f"Rendered scene {scene_index + 1}/{len(scenes)} ({duration}s)", flush=True)

        process.stdin.close()
        stderr = process.stderr.read()
        exit_code = process.wait()
        if exit_code:
            raise RuntimeError(stderr.decode("utf-8", errors="replace"))
    except Exception:
        if process.poll() is None:
            process.kill()
            process.wait()
        raise


def add_soundtrack(video_path, soundtrack, output_path, duration):
    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
    fade_start = max(0, duration - 5)
    command = [
        ffmpeg,
        "-hide_banner",
        "-loglevel",
        "error",
        "-y",
        "-i",
        str(video_path),
        "-i",
        str(soundtrack),
        "-map",
        "0:v:0",
        "-map",
        "1:a:0",
        "-c:v",
        "copy",
        "-af",
        f"volume=0.45,afade=t=out:st={fade_start}:d=5",
        "-c:a",
        "aac",
        "-b:a",
        "160k",
        "-t",
        str(duration),
        "-movflags",
        "+faststart",
        "-metadata",
        "title=Panduan Video Aplikasi Rapat - Poltekkes Kemenkes Manado",
        "-metadata",
        "artist=Poltekkes Kemenkes Manado",
        str(output_path),
    ]
    result = subprocess.run(command, capture_output=True, text=True, check=False)
    if result.returncode:
        raise RuntimeError(result.stderr)


def main():
    parser = argparse.ArgumentParser(description="Render a Full HD animated meeting-app tutorial.")
    parser.add_argument("--music", required=True, type=Path, help="User-provided soundtrack in MP3 format.")
    parser.add_argument("--output", type=Path, default=ROOT / "Video-Panduan-Animasi-Aplikasi-Rapat-HD.mp4")
    args = parser.parse_args()

    if not args.music.is_file():
        raise FileNotFoundError(f"Soundtrack not found: {args.music}")
    if not (ASSETS / "01-dashboard.png").is_file() or not (ASSETS / "01-dashboard-video.png").is_file():
        raise FileNotFoundError(f"Guide screenshots not found: {ASSETS}")

    scenes = make_scenes()
    duration = sum(seconds for _, seconds in scenes)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="rapat-video-") as workdir:
        silent_path = Path(workdir) / "tutorial-silent.mp4"
        render_video(scenes, silent_path)
        add_soundtrack(silent_path, args.music, args.output, duration)

    print(f"Video saved: {args.output}")
    print(f"Duration: {duration} s | Resolution: {WIDTH}x{HEIGHT} | FPS: {FPS}")


if __name__ == "__main__":
    main()
