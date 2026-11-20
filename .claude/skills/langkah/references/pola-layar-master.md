# Pola layar data master

Pola ini diambil dari Langkah 5 (tahun ajaran dan semester). Pakai saat
merencanakan layar data master baru. Lampiran ini hanya menunjuk file contoh,
jadi baca filenya langsung. Kalau lampiran ini dan CLAUDE.md berbeda, ikuti
CLAUDE.md.

## Model, migration, factory

- Model ada di `app/Domains/{Domain}/Models`. Nama tabel ditetapkan sendiri
  dengan `#[Table('...')]`, tidak memakai bentuk jamak bawaan Laravel.
  Contoh: `app/Domains/Academic/Models/TahunAjaran.php`.
- Migration dibuat lewat generator, lalu nama tabelnya diganti.
  Contoh: `database/migrations/*_create_tahun_ajaran_table.php`.
- Factory ada di `database/factories` dan terhubung lewat `#[UseModel]` dan
  `#[UseFactory]`. Data dari factory harus memenuhi aturan domain. Contoh:
  `database/factories/SemesterFactory.php` membuat tanggal di dalam rentang
  tahun ajaran induknya.

## Service

- Semua penulisan lewat Service, di dalam `DB::transaction`. Contoh:
  `app/Domains/Academic/Services/TahunAjaranService.php`. Docblock kelasnya
  merangkum aturan.
- Validasi memakai `Validator` di Service dan menghasilkan
  `ValidationException` dengan key nama field.
- Pelanggaran aturan yang tidak berasal dari form melempar exception domain.
  Contoh: `app/Domains/Academic/Services/PelanggaranAturanAkademik.php`.
- Baris yang dibaca untuk validasi dikunci dengan `lockForUpdate`. Kalau
  penyimpanan pertama bisa bertabrakan, tambahkan `attempts` pada transaksi.
- Setiap hitungan yang dipakai untuk menegakkan batas (kapasitas, stok,
  kuota) dibaca dengan kunci, bukan bacaan biasa.
  - Pakai `sharedLock()->count()` atau `lockForUpdate()`.
  - Kalau syaratnya ada di tabel lain, pakai `join`, bukan `whereHas`:
    kunci tidak menjangkau baris yang dibaca di subquery.
  - Alasannya: di REPEATABLE READ, bacaan biasa memakai snapshot transaksi,
    sehingga baris yang di-commit transaksi lain selama menunggu kunci tidak
    terlihat.
  - Contoh: `RombelSiswa::jumlahSiswaAktifTerkunci()` dan
    `RombelService::delete()`. Test-nya memeriksa SQL hitungan itu; lihat
    `tests/Feature/Domains/Academic/Services/HitunganKapasitasTerkunciTest.php`.
- Semua jalur yang mengunci beberapa tabel yang sama memakai satu urutan
  kunci. Jalur yang berangkat dari tengah urutan melanjutkan dari situ dan
  tidak pernah kembali ke langkah sebelumnya.
  - Urutan seluruh aplikasi: kunci identitas siswa → wali_murid → akun
    (`App\Domains\Foundation\Services\KunciIdentitas`), lalu S (baris siswa)
    → W (baris wali_murid) → baris users → R (baris siswa_wali).
  - Kunci identitas selalu diambil paling awal di transaksi, sebelum baris
    data mana pun, lewat satu panggilan `KunciIdentitas::kunci(...)` yang
    mengurutkannya sendiri.
  - Jalur yang berangkat dari wali memakai wali_murid (→ akun) → W → users
    → R; Kelola Pengguna memakai akun → users → R.
  - Alasannya: dua transaksi yang mengunci dua baris dengan urutan
    berlawanan bisa saling menunggu (*deadlock*). Dengan satu urutan,
    siklus seperti itu tidak bisa terbentuk.
  - Mengunci ulang baris yang sudah dipegang transaksi itu sendiri
    diperbolehkan, karena tidak menambah kunci baru.
  - Jangan pernah mengunci seluruh tabel `users`, `siswa`, atau
    `wali_murid`. Keunikan identitas dijaga kunci identitas, sehingga masuk,
    keluar, `remember_token`, dan perubahan status atau kata sandi tidak
    pernah menunggu import.
  - Tulis urutannya di docblock setiap Service yang memakainya. Contoh:
    `SiswaWaliService` dan `WaliMuridService`.
  - Test-nya memeriksa urutan pembacaan berkunci di SQL; lihat
    `tests/Feature/Domains/People/Services/UrutanKunciWaliTest.php` dan
    `UrutanKunciIdentitasTest.php`.
- **Batas kunci siswa.** Kunci identitas siswa hanya diambil oleh penulisan
  data identitas dan keanggotaan siswa: tabel `siswa`, `wali_murid`,
  `siswa_wali`, `penempatan_rombel`, dan akun siswa atau wali.
  - Modul yang menulis data *tentang* siswa di tabel lain (presensi,
    jurnal, e-poin, perpustakaan, permohonan) **dilarang** mengambil kunci
    identitas mana pun. Kalau mengambilnya, setiap simpan presensi akan
    menunggu setiap import dan setiap perubahan data siswa.
  - Kalau modul seperti itu perlu membaca keanggotaan siswa (siapa saja di
    sebuah rombel), cukup lewat `App\Domains\Academic\Services\RombelSiswa`,
    tanpa kunci identitas.
- *Deadlock* yang tetap bisa terjadi (misalnya *gap lock* saat dua siswa
  mendapat wali pertamanya bersamaan) diterima dan ditangani dua lapis:
  - transaksi Service memakai `attempts`, sehingga Laravel mencobanya ulang;
  - galat 1213 (*deadlock*) dan 1205 (*lock wait timeout*) yang masih
    tersisa sesudah percobaan ulang diubah di satu tempat,
    `App\Filament\Support\GalatKonkurensi`, menjadi notifikasi "Sedang ada
    perubahan lain pada data ini. Coba lagi.". Ini berlaku untuk semua aksi
    Filament, jadi layar tidak perlu menangkapnya sendiri.
  - Pengecualian: aksi massal yang melapor per baris (penempatan beberapa
    siswa) tetap menangkapnya per baris dan melanjutkan baris berikutnya.
- Field unik (kode, nama, username, email) dijaga dua lapis:
  - validasi `unique` dijalankan di dalam `DB::transaction(..., attempts)`
    setelah baris tabelnya dikunci dengan `lockForUpdate` (untuk identitas
    akun, siswa, dan wali: setelah kunci identitasnya diambil), jadi dua
    penyimpanan bersamaan berjalan bergiliran;
  - seluruh operasi dibungkus
    `App\Domains\Foundation\Services\KeunikanDatabase::jaga()`, yang
    mengubah pelanggaran indeks unik di database menjadi galat field,
    bukan galat 500.

  Contoh: `create()` dan `update()` di
  `app/Domains/Academic/Services/MataPelajaranService.php`. Test-nya meniru
  penyimpanan bersamaan dengan listener `creating` atau `updating` yang
  menyisipkan baris kembar setelah validasi lolos; lihat
  `test_a_concurrent_create_with_the_same_value_ends_as_a_field_error`.
- Pesan "sudah dipakai" menyebut pemilik nilainya, termasuk kalau pemiliknya
  sedang tidak aktif (bisa tersembunyi oleh filter status).
- Teks dari form dinormalkan sebelum divalidasi: spasi di tepi dibuang, dan
  deretan spasi di tengah, termasuk spasi Unicode seperti NBSP, dijadikan satu.
- Aturan "paling banyak satu yang aktif" dijaga dua lapis:
  - Service menonaktifkan yang lain lalu mengaktifkan yang dipilih, di bawah
    `lockForUpdate`;
  - database memakai kolom turunan yang bernilai 1 saat aktif dan NULL saat
    tidak, dengan indeks unik. Contoh:
    `database/migrations/2026_11_16_222736_add_penanda_aktif_to_tahun_ajaran_and_semester.php`.
    Migration itu memeriksa data lebih dulu, karena DDL MariaDB tidak ikut
    transaksi.

## Hak akses

- Policy ditulis tangan di `app/Domains/{Domain}/Policies` dan dipasang
  dengan `#[UsePolicy]`. Contoh:
  `app/Domains/Academic/Policies/TahunAjaranPolicy.php`.
- Resource dimasukkan ke `resources.exclude` di `config/filament-shield.php`,
  supaya `shield:generate` tidak menimpa Policy.
- Izin berformat Shield (`ViewAny:Model`) dibuat dan dibagikan ke role di
  `database/seeders/PeranDanIzinSeeder.php`. Contoh: konstanta
  `TAHUN_AJARAN_PERMISSIONS` dan `OPERATOR_PERMISSIONS`.

## Layar Filament

- Layar hanya memanggil Service. Tidak ada transaksi atau logika penulisan
  data di layar.
- Halaman Create dan Edit menimpa `handleRecordCreation` dan
  `handleRecordUpdate`, lalu meneruskan galat ke field form lewat
  `App\Filament\Support\GalatValidasi::prefix($e, 'data')`. Contoh:
  `app/Filament/Resources/TahunAjarans/Pages/CreateTahunAjaran.php`.
- Aksi dengan form di modal memakai state path
  `'mountedActions.'.($action->getNestingIndex() ?? 0).'.data'`. Contoh: aksi
  `ubahTanggal` di `RelationManagers/SemesterRelationManager.php`.
- Aksi tanpa form menangkap exception domain dan menampilkannya sebagai
  notifikasi. Contoh: aksi `hapus` di `Tables/TahunAjaransTable.php`.
- Aksi khusus memakai `->authorize('<ability>')`. Aksi yang tidak berlaku
  disembunyikan dengan `hidden()` atau `visible()`, tetapi Service tetap
  menolaknya.

## Log aktivitas

- Tambah, ubah, dan hapus dicatat lewat trait `LogsActivity` di model, dengan
  `logOnly`, `logOnlyDirty`, dan deskripsi berbahasa Indonesia. Kolom status
  tidak ikut dicatat oleh trait.
- Aksi bermakna seperti aktifkan dicatat eksplisit di Service, dalam
  transaksi yang sama. Contoh:
  `app/Domains/Academic/Services/AktivitasAkademik.php`.
- Tambahkan nama objek baru di `describeSubject` pada
  `app/Filament/Resources/ActivityLogs/Tables/ActivityLogsTable.php`.
- Model yang datanya bisa dihapus memakai trait
  `App\Domains\Academic\Models\LabelDiLogAktivitas`. Trait itu menyimpan label
  yang terbaca (misalnya "X-1 2026/2027") di `properties.label` pada setiap
  entri log, baik dari trait `LogsActivity` maupun dari `activity()`
  eksplisit. Log Aktivitas memakai label itu kalau subjeknya sudah tidak
  ada. Contoh: `TahunAjaran`, `Semester`, `Rombel`.

## Test

- Satu test per aturan Service, dengan data provider untuk variasi kasus,
  termasuk kasus tepat di batas. Contoh:
  `tests/Feature/Domains/Academic/Services/TahunAjaranServiceTest.php`.
- Hak akses diuji per role (super_admin, operator, tanpa role), baik di
  Policy maupun di layar. Contoh: `tests/Feature/Domains/Academic/Policies/`
  dan `tests/Feature/Filament/Resources/TahunAjarans/`.
- Untuk setiap aturan inti, lakukan uji mutasi: ubah atau buang aturannya
  sebentar, pastikan ada test yang gagal, lalu pulihkan. Laporkan hasilnya.

## Relasi ke periode akademik

- Tabel yang merujuk `tahun_ajaran` atau `semester` memakai
  `->constrained(...)->restrictOnDelete()`.
- Setiap data yang dirujuk tabel lain dengan `restrictOnDelete` wajib
  diperiksa pemakaiannya di Service sebelum dihapus. Penolakannya tampil
  sebagai pesan yang menyebut apa yang memakainya dan berapa banyak, bukan
  galat database. Contoh: `TahunAjaranService::delete()` menolak dengan
  "Tahun ajaran 2026/2027 sudah dipakai oleh 2 rombel. Hapus rombelnya
  dulu." Setiap kali tabel baru merujuk data yang sudah ada, tambahkan
  pemeriksaan itu di Service pemilik data tersebut, beserta test-nya.
- Pemeriksaan pemakaian dilakukan di dalam transaksi berkunci:
  - Service yang menghapus mengunci baris induk (`lockForUpdate`), lalu
    menghitung pemakaian dengan pembacaan berkunci (`sharedLock()->count()`);
  - Service yang membuat data anak mengunci baris induk yang dipilih
    (`sharedLock`) sebelum memvalidasinya.

  Dengan begitu, hapus dan tambah yang terjadi bersamaan berjalan bergiliran.
  Contoh: `TahunAjaranService::delete()` dan
  `RombelService::lockSchoolYear()`.
- Tahun ajaran dan semester aktif dibaca lewat
  `App\Domains\Academic\Services\PeriodeAkademik`.
