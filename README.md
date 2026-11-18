# Etoko — Multi-Branch E-Commerce System

Platform e-commerce untuk **satu perusahaan dengan banyak cabang**. Satu website, satu database pusat,
satu katalog produk, tetapi **stok dikelola terpisah per cabang**. Pesanan dari website diproses oleh
cabang yang dipilih, dan pusat tetap bisa memantau seluruh cabang dari satu dashboard.

> Status: **Phase 2 sedang berjalan** (Phase 0 dan 1 sudah lengkap). Antarmuka dan dokumentasi
> menggunakan Bahasa Indonesia.

## Daftar isi

- [Fitur](#fitur)
- [Prinsip bisnis](#prinsip-bisnis)
- [Tech stack](#tech-stack)
- [Peran pengguna](#peran-pengguna)
- [Persyaratan](#persyaratan)
- [Instalasi](#instalasi)
- [Menjalankan aplikasi](#menjalankan-aplikasi)
- [Data demo](#data-demo)
- [Pengujian dan kualitas kode](#pengujian-dan-kualitas-kode)
- [Konfigurasi penting](#konfigurasi-penting)
- [Roadmap](#roadmap)
- [Kontribusi](#kontribusi)
- [Lisensi](#lisensi)

## Fitur

### Storefront (pelanggan)

- Beranda, katalog produk, kategori, varian produk, pencarian dan filter.
- Detail produk dengan ulasan pelanggan dan produk unggulan.
- Keranjang belanja dan daftar wishlist.
- Checkout dengan pilihan cabang pemroses, perhitungan ongkos kirim, dan voucher diskon.
- Pembayaran online lewat Midtrans (virtual account bank, QRIS, GoPay) dan COD.
- Riwayat pesanan, tracking pengiriman, serta retur dan refund.
- Click & Collect: pelanggan mengambil pesanan di cabang dengan kode pengambilan.
- Profil dan buku alamat pelanggan.

### Panel staf (pusat, cabang, dan gudang)

- **Master data:** produk, varian, SKU dan barcode, kategori, harga khusus per cabang, cabang, gudang, pengguna, dan pelanggan.
- **Inventaris:** stok per cabang, barang masuk dan keluar, transfer stok antar-cabang dengan persetujuan, stok opname (selisih otomatis tercatat di buku stok), dan peringatan stok menipis.
- **Pesanan:** daftar pesanan, penentuan cabang pemroses, perubahan status dengan aturan transisi, pengemasan (daftar kemas), dan invoice PDF otomatis.
- **Pembayaran, pengiriman, dan retur:** verifikasi pembayaran, integrasi ekspedisi lewat Biteship, nomor resi, serta alur retur dan refund.
- **Marketing:** voucher, promosi, flash sale, banner, produk unggulan, dan moderasi ulasan.
- **Laporan dan dashboard:** dashboard pusat dan cabang, laporan penjualan, transaksi, stok, produk terlaris, pelanggan, dan performa cabang, dengan ekspor Excel dan PDF.
- **Notifikasi:** email dan notifikasi database untuk perubahan status pesanan.
- **Akses dan keamanan:** login, registrasi, reset password, verifikasi email, dan 2FA (TOTP) lewat Laravel Fortify, serta passkey.

## Prinsip bisnis

Aturan berikut tidak boleh dilanggar oleh fitur apa pun:

| Aturan | Penjelasan |
| --- | --- |
| Terpusat | Produk dan kategori dikelola admin pusat. Cabang tidak membuat produk sendiri. |
| Satu harga di website | Harga di website sama untuk semua cabang. Yang berbeda per cabang adalah **stok**. |
| Stok independen | Setiap cabang punya stok sendiri per varian produk. |
| Pembatasan data cabang | Admin cabang hanya melihat dan mengubah data cabangnya (lewat global scope, policy, dan middleware). |
| Pusat memantau semua | Admin pusat dan super admin dapat melihat seluruh cabang. |
| Cabang wajib tercatat | Setiap pesanan wajib memiliki cabang pemroses. |
| Anti-overselling | Stok dicadangkan saat checkout dan dikunci dengan `lockForUpdate` di dalam transaksi database. |

## Tech stack

| Lapisan | Teknologi |
| --- | --- |
| Backend | PHP 8.4 (minimal 8.3), Laravel 13 |
| Auth | Laravel Fortify (2FA, passkey) |
| Hubungan backend–frontend | Inertia.js v3 (Laravel adapter) |
| Frontend | React 19, TypeScript, Vite |
| Routing ke frontend | Laravel Wayfinder (fungsi TypeScript yang di-generate dari rute Laravel) |
| UI | Tailwind CSS 4, shadcn/ui, Radix UI, Recharts |
| Hak akses | `spatie/laravel-permission` + Policy + branch scope |
| Pembayaran | Midtrans (`midtrans/midtrans-php`) |
| Pengiriman | Biteship |
| Dokumen | `barryvdh/laravel-dompdf` (PDF), `openspout/openspout` (Excel) |
| Database | MySQL 8 (SQLite in-memory untuk test) |
| Cache dan queue | Redis (phpredis) |
| Test dan analisis | Pest 5, Larastan (PHPStan), Laravel Pint |

## Peran pengguna

| Peran | Area | Cakupan data |
| --- | --- | --- |
| Super Admin | Seluruh sistem | Semua cabang |
| Admin Pusat | Panel pusat | Semua cabang, kecuali manajemen role dan pengaturan sistem kritikal |
| Admin Cabang | Panel cabang | Hanya cabangnya |
| Staff Gudang | Panel cabang (inventaris) | Hanya cabangnya |
| Kasir | POS (Phase 3) | Hanya cabangnya |
| Customer | Storefront | Data miliknya sendiri |

Permission memakai konvensi `domain.aksi`, misalnya `products.update` atau `stock_transfers.approve`.

## Persyaratan

- PHP 8.4 (minimal 8.3) dengan ekstensi `redis` (phpredis), `pdo_mysql`, dan `gd`
- Composer 2
- Node.js 22 dan npm
- MySQL 8
- Redis (dipakai untuk cache dan queue secara default)

Jika belum memakai Redis, ubah `QUEUE_CONNECTION` dan `CACHE_STORE` di `.env` menjadi `database`.

## Instalasi

```bash
git clone https://github.com/aliffaizar/etoko.git
cd etoko

# Instal dependensi, membuat .env, generate APP_KEY, migrasi, dan build frontend
composer setup
```

Perintah `composer setup` menjalankan langkah-langkah berikut:

1. `composer install`
2. Menyalin `.env.example` menjadi `.env` jika belum ada
3. `php artisan key:generate`
4. `php artisan migrate --force`
5. `npm install`
6. `npm run build`

Sebelum menjalankan `composer setup` untuk pertama kali, buat database MySQL dan isi `DB_*` di `.env`.

Jika mengikuti langkah manual, jalankan urutan perintah di atas satu per satu.

## Menjalankan aplikasi

```bash
composer run dev
```

Perintah ini menjalankan server Laravel, queue worker, dan Vite sekaligus.

Alamat default:

- Storefront: `http://localhost:8000`
- Panel staf: `http://admin.localhost:8000` (lihat [Konfigurasi penting](#konfigurasi-penting))

Gunakan `localhost`, bukan `127.0.0.1`, agar sesi toko dan panel tidak saling bercampur.

## Data demo

Seeder dapat mengisi katalog, stok, pelanggan, pesanan, retur, ulasan, dan voucher contoh:

```bash
php artisan db:seed
```

Seeder referensi (peran, izin, metode pembayaran, dan kurir) selalu dijalankan di semua lingkungan.
Data demo dilewati di lingkungan `production`.

Akun demo dan kata sandinya didefinisikan di `database/seeders/DemoDataSeeder.php`.
Jangan pakai akun demo di lingkungan produksi.

## Pengujian dan kualitas kode

```bash
composer test        # clear config, cek format Pint, cek tipe PHPStan, lalu test Laravel
composer ci:check    # cek frontend (vp check), cek tipe TypeScript, lalu test Laravel

vendor/bin/pest --filter=NamaTest   # menjalankan satu test
vendor/bin/pint                     # merapikan format PHP
npm run check:fix                   # merapikan lint dan format frontend
```

Test memakai SQLite in-memory. Ada juga test konkurensi di `tests/Concurrency` untuk memverifikasi anti-overselling.

CI berjalan di GitHub Actions (`.github/workflows/tests.yml`) untuk setiap push ke `main` dan setiap pull request.

## Konfigurasi penting

Variabel di `.env` yang paling sering diubah:

| Variabel | Fungsi |
| --- | --- |
| `APP_URL` | URL storefront, misalnya `http://localhost:8000` |
| `ADMIN_DOMAIN` | Host terpisah untuk panel staf (`/admin`, `/branch`). Kosongkan untuk memakai satu host. |
| `APP_TIMEZONE` / `APP_LOCALE` | Default `Asia/Jakarta` dan `id` |
| `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_PRODUCTION` | Kredensial dan mode Midtrans (default sandbox) |
| `BITESHIP_API_KEY` | Kunci API Biteship untuk ongkir dan tracking |
| `SHOP_RESERVATION_MINUTES` | Lama stok ditahan sebelum pesanan dibayar (default 1440 menit) |
| `SHOP_FLAT_SHIPPING_COST`, `SHOP_TAX_PERCENT` | Ongkir tetap dan persentase pajak |

Dengan `ADMIN_DOMAIN` diisi, panel staf hanya bisa diakses dari host tersebut, sedangkan storefront hanya dari `APP_URL`. Di lingkungan produksi, gunakan subdomain seperti `admin.domain-anda.id`.

Jangan commit file `.env`. Kunci API dan kredensial hanya disimpan di server atau di mesin lokal.

## Roadmap

| Phase | Isi | Status |
| --- | --- | --- |
| 0 | Fondasi, kerangka panel, RBAC | Selesai |
| 1 | Katalog, inventaris, cabang, keranjang, checkout, pesanan | Selesai |
| 2 | Pembayaran, pengiriman, transfer stok, retur, voucher, invoice, dashboard dan laporan, marketing | Berjalan |
| 3 | POS (kasir offline), sesi kasir, dan fitur lanjutan | Belum dimulai |

Keputusan teknis dicatat sebagai ADR (Architecture Decision Record) dan dapat dilihat di riwayat proyek.

## Kontribusi

Kontribusi sangat diterima. Sebelum membuka pull request:

1. Buat branch dari `main`.
2. Tambahkan atau perbarui test untuk perubahan perilaku.
3. Jalankan `composer test` dan pastikan semuanya lulus.
4. Jelaskan di deskripsi PR apa yang berubah dan mengapa.

Untuk laporan bug atau usulan fitur, buka issue di GitHub.

## Lisensi

Proyek ini dirilis di bawah [MIT License](LICENSE).
