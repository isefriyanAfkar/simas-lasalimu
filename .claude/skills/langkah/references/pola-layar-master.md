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
- Field unik (kode, nama, username, email) dijaga dua lapis:
  - validasi `unique` dijalankan di dalam `DB::transaction(..., attempts)`
    setelah baris tabelnya dikunci dengan `lockForUpdate`, jadi dua
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
- Tahun ajaran dan semester aktif dibaca lewat
  `App\Domains\Academic\Services\PeriodeAkademik`.
