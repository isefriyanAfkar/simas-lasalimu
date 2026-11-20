---
name: langkah
description: Alur kerja untuk setiap tugas "Langkah" di proyek SIMAS (mis. "Tugas: Langkah 3 ..."): rencana dulu lalu berhenti menunggu persetujuan, cek dokumentasi paket lewat Boost, test lebih dulu untuk Service, verifikasi (test, Pint, PHPStan), satu commit, tinjauan kode, dan laporan berformat tetap. Gunakan skill ini setiap kali pengguna memberi tugas bernomor Langkah, menyetujui rencana Langkah, atau meminta fitur baru yang menyentuh Service, migration, atau layar Filament.
---

# Alur kerja Langkah SIMAS

Setiap tugas Langkah melewati enam tahap secara berurutan. Pengguna memeriksa
pekerjaan per tahap. Kalau satu tahap dilewati, misalnya kode ditulis sebelum
rencana disetujui atau selesai diklaim tanpa bukti, hasilnya sulit dipercaya
dan harus diulang.

**Aturan proyek ada di CLAUDE.md**, bagian "Aturan proyek SIMAS": stack,
lingkup, struktur folder, Service, transaksi, log aktivitas, Policy, penamaan,
pemeriksaan, dan perintah yang dilarang. Baca bagian itu setiap kali memulai
Langkah, karena isinya bisa berubah di antara tugas. Skill ini tidak menyalin
aturan tersebut. Kalau skill ini dan CLAUDE.md berbeda, ikuti CLAUDE.md.

**Skill pendamping:** `superpowers:test-driven-development`,
`superpowers:verification-before-completion`, dan
`superpowers:requesting-code-review`. Kalau skill superpowers tidak tersedia,
jalankan disiplin yang sama secara manual dan sebutkan hal itu di laporan:
- test ditulis lebih dulu dan dijalankan sampai gagal;
- bukti dikumpulkan sebelum menyatakan selesai;
- diff ditinjau ulang sendiri.

## Tugas kecil

Contoh tugas kecil:
- perbaikan temuan tinjauan;
- penyesuaian config;
- perapian;
- perubahan satu file tanpa aturan bisnis.

Tugas kecil tidak butuh rencana (tahap 1) maupun tinjauan kode (tahap 5). Yang
tetap wajib: verifikasi tahap 4, satu commit, dan laporan singkat berisi:
- commit beserta pesannya;
- hasil pemeriksaan;
- apa yang diubah;
- keputusan yang perlu diambil, kalau ada.

Kalau di tengah jalan tugas kecil ternyata menyentuh aturan bisnis, Service,
atau skema, berhenti dan perlakukan sebagai tugas Langkah biasa.

## 1. Rencana, lalu berhenti

Sebelum menulis rencana:
- Baca CLAUDE.md, serta `.ai/rules` kalau folder itu ada.
- Periksa kode yang akan disentuh. Jangan menebak struktur yang sudah ada.
- Cek dokumentasi setiap paket yang API-nya akan dipakai (lihat tahap 2).
- Untuk layar data master, baca dulu `references/pola-layar-master.md`.

Isi rencana:
- **Temuan dokumentasi:** versi, kompatibilitas, dan perbedaan dari versi lama
  yang memengaruhi rencana.
- **Rencana per nomor tugas:** file yang dibuat atau diubah, dan cara aturan
  CLAUDE.md dipenuhi.
- **Daftar test:** satu baris per perilaku yang akan dibuktikan.
- **Bagian yang tidak bisa test-dulu:** kalau untuk satu bagian test memang
  tidak mungkin dijalankan dan dilihat gagal sebelum kodenya ditulis (lihat
  tahap 3), sebutkan bagian itu dan alasannya di sini. Tulis "Tidak ada"
  kalau memang tidak ada. Pengecualian yang baru disebut di laporan sesudahnya
  tidak berlaku.
- **Keputusan yang perlu diambil pengguna:** paling banyak empat. Tiap
  keputusan diberi pilihan dan usulan (beri tanda "usul saya").

Lalu **berhenti**. Sebelum rencana disetujui, jangan menulis kode, memasang
paket, menjalankan migration, atau membuat commit. Riset yang hanya membaca
boleh dilakukan.

Kalau rencana disetujui dengan perubahan, perubahan itu menggantikan bagian
rencana yang bersangkutan. Jawaban atas pertanyaan keputusan menjadi bagian
dari tugas.

## 2. Cek dokumentasi sebelum memakai API

API paket berbeda antarversi, dan tebakan dari ingatan sering salah versi.
Urutannya:
1. **Paket sudah terpasang:** `search-docs` dari Boost dengan parameter
   `packages`. Cek versinya dengan `composer show <paket>`.
2. **Paket belum terpasang** (Boost belum punya dokumentasinya):
   - `composer show -a <paket>` untuk versi dan syarat (PHP, Laravel, paket lain);
   - README, UPGRADING, atau `docs/` di repo GitHub resmi untuk perubahan antarversi.
3. **Setelah terpasang, atau kalau dokumentasi diam:** baca kode sumber di
   `vendor/`. Kode sumber adalah bukti terakhir, termasuk saat dokumentasi
   dan kode tidak cocok. Laporkan ketidakcocokan seperti itu.

## 3. Kerjakan

- **Service yang punya aturan:** pakai skill `superpowers:test-driven-development`.
  Tulis test lebih dulu, jalankan sampai gagal karena alasan yang benar, lalu
  tulis kode secukupnya sampai lolos. Nilai yang diharapkan ditulis sebagai
  literal, bukan dihitung dengan kode yang sedang diuji.
- **Wajib, untuk setiap perilaku baru** (termasuk perbaikan temuan tinjauan
  dan tugas kecil): test-nya dijalankan dan **terlihat gagal karena alasan
  yang benar** sebelum kodenya ditulis. "Alasan yang benar" berarti pesan
  kegagalannya menunjuk perilaku yang belum ada, bukan galat ketik, fixture
  yang salah, atau kelas yang belum dibuat. Catat kegagalan itu untuk
  laporan. Kalau untuk satu bagian hal ini memang tidak mungkin, bagian itu
  harus sudah disebut di rencana sebelum mulai (tahap 1), bukan baru di
  laporan. Test yang hanya menambah cakupan untuk perilaku yang sudah ada
  boleh langsung lolos; sebutkan sebagai cakupan, bukan perilaku baru.
- **Uji mutasi** tetap dijalankan untuk aturan yang penting, tetapi bukan
  pengganti test yang terlihat gagal lebih dulu.
- **Composer:** jangan memotong keluarannya dengan pipa seperti `head` atau
  `tail`, karena bisa menghentikan instalasi di tengah jalan. Simpan
  keluarannya ke file kalau perlu disaring.
- **Membuat file:** pakai generator (`php artisan make:...`) dengan
  `--no-interaction`. Catat file mana yang dibuat generator atau paket dan
  mana yang ditulis tangan, karena keduanya masuk laporan.
- **Perintah interaktif** (yang meminta input, seperti `shield:setup`,
  `make:filament-user`, atau tinker interaktif): jangan dijalankan. Cari opsi
  yang tidak meminta input. Kalau tidak ada, tulis perintahnya untuk
  dijalankan pengguna.
- **`.env` dan perintah yang dilarang di CLAUDE.md** hanya boleh disentuh
  kalau pengguna mengizinkannya secara eksplisit di tugas itu. Izin di satu
  tugas tidak berlaku untuk tugas berikutnya. Jangan menampilkan nilai
  rahasia dari `.env`.
- **Penyimpangan:** kalau ternyata ada hal yang harus berbeda dari rencana,
  catat apa dan mengapa. Kalau perubahannya mengubah hasil yang terlihat oleh
  pengguna, berhenti dan tanyakan dulu.

## 4. Verifikasi, lalu satu commit

Pakai skill `superpowers:verification-before-completion`. Jalankan ulang,
setelah perubahan terakhir:

```
vendor/bin/pint --dirty --format agent
php artisan test --compact
vendor/bin/pint --test --format agent
vendor/bin/phpstan analyse --memory-limit=1G
```

- Baca keluaran setiap perintah. Kalau ada kegagalan, perbaiki penyebabnya,
  jangan menyembunyikannya (tanpa baseline atau `@phpstan-ignore`).
- Cocokkan hasil kerja dengan setiap nomor tugas.
- Buat **satu commit** untuk tugas itu dengan pesan persis seperti yang
  diminta. Tambahkan path secara eksplisit dengan `git add <path>` dan
  pastikan `.env` tidak ikut.
- Kalau tugas meminta lebih dari satu commit, setiap commit melewati
  verifikasinya sendiri.

## 5. Tinjauan kode

Setelah commit tugas utama, pakai skill `superpowers:requesting-code-review`
untuk rentang commit tersebut. Laporkan temuannya, tetapi jangan langsung
memperbaikinya. Pengguna yang memutuskan temuan mana yang diperbaiki, biasanya
dalam commit terpisah. Sebelum melapor, cek sendiri setiap temuan yang akan
disampaikan.

## 6. Laporan, lalu berhenti

Pakai format ini setiap kali, supaya pengguna bisa membandingkan antartugas:

```markdown
**<Langkah N> selesai, commit `<sha>` "<pesan>".** <satu kalimat status>

**Hasil pemeriksaan**
<keluaran php artisan test, pint --test, dan phpstan, apa adanya>

**`git log --oneline`**
<10 baris terakhir>

**File**
- Dibuat generator/paket: <daftar, dengan perintah yang membuatnya>
- Ditulis tangan: <daftar>

**Penyimpangan dari rencana**
<apa dan mengapa; tulis "Tidak ada" kalau memang tidak ada>

**Temuan tinjauan kode**
<per tingkat keparahan, dengan file:baris; tandai yang sudah dicek benar>

**Uji manual di browser**
<2–4 langkah konkret yang dicoba sendiri oleh pengguna, masing-masing dengan
hasil yang diharapkan; mis. "Masuk sebagai operator, buka /admin/log-aktivitas
→ halaman 403">

**File yang wajib Anda baca**
<1–2 file inti tugas ini, masing-masing dengan satu kalimat tentang apa yang
perlu dipahami di dalamnya>

**Keputusan yang perlu Anda ambil**
<daftar bernomor dengan usulan; tulis "Tidak ada" kalau memang tidak ada>
```

Setelah laporan, berhenti. Jangan memulai tugas berikutnya atau memperbaiki
temuan sampai pengguna memintanya.
