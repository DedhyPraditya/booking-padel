# Padel Booking Platform

Monorepo untuk sistem reservasi lapangan padel berbasis **Laravel API + PostgreSQL** dan **Vue 3 + Vite + TypeScript + Tailwind CSS**.

## Struktur Repository

```text
booking-padel/
├── AGENTS.md                       # Aturan wajib AI coding agent
├── README.md                       # Dokumentasi utama repository
├── docs/
│   └── MASTER_SYSTEM_SPEC.md       # Spesifikasi utama produk & teknis
├── apps/
│   ├── api/                        # Backend Laravel API (PostgreSQL)
│   └── web/                        # Frontend Vue 3 + Vite + TypeScript
└── infrastructure/
    └── scripts/                    # Helper script lokal (start/stop PostgreSQL)
```

## Prasyarat Lokal (Laragon Windows)

- **PHP:** 8.3+ dengan ekstensi `pdo_pgsql` dan `pgsql` aktif
- **PostgreSQL:** 16.4+ (`D:\laragon\bin\postgresql\postgresql-16.4-1`, data di `D:\laragon\data\postgresql-16`)
- **Database:** `booking_padel` dan `booking_padel_test` (dengan ekstensi `btree_gist` aktif)
- **Node.js:** 20.14+ & npm 10+
- **Composer:** 2.9+

## Menjalankan PostgreSQL Lokal (Laragon)

Start PostgreSQL:
```powershell
& "D:\laragon\bin\postgresql\postgresql-16.4-1\bin\pg_ctl.exe" -D "D:\laragon\data\postgresql-16" -l "D:\laragon\data\postgresql-16\logfile.log" start
```

Stop PostgreSQL:
```powershell
& "D:\laragon\bin\postgresql\postgresql-16.4-1\bin\pg_ctl.exe" -D "D:\laragon\data\postgresql-16" stop
```

## Menjalankan Backend (`apps/api`)

```powershell
cd apps/api
php artisan migrate --seed
php artisan serve --port=8000
```

Menjalankan automated tests (terhadap PostgreSQL `booking_padel_test`):
```powershell
cd apps/api
php artisan test
```

## Menjalankan Frontend (`apps/web`)

```powershell
cd apps/web
npm install
npm run dev
```
