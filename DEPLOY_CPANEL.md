# Tutorial Deploy LPPM ke cPanel

## Persiapan

### 1. Build Frontend React
Sebelum upload, build dulu frontend React jadi static files:

```bash
cd lppm-frontend
npm run build
```

Setelah build selesai, folder `dist/` atau `build/` akan muncul (tergantung konfigurasi Vite).

### 2. Siapkan File Backend Laravel
Pastikan file `.env` production sudah disiapkan dengan konfigurasi yang benar.

---

## ⚠️ PENTING: Handle WordPress yang Sudah Ada

Jika di `public_html` sudah ada WordPress (wp-admin, wp-content, wp-includes, dll), ada **3 opsi**:

### Opsi 1: Pindahkan WordPress ke Subfolder (REKOMENDASI) ✅

**Cara ini paling aman dan memungkinkan aplikasi baru di root domain.**

1. **Buat folder baru di `public_html`** untuk WordPress:
   ```
   public_html/wordpress/
   ```

2. **Pindahkan semua file WordPress** ke folder tersebut:
   - wp-admin/
   - wp-content/
   - wp-includes/
   - wp-config.php
   - index.php (WordPress)
   - Dan file WordPress lainnya

3. **Edit `wp-config.php`** di folder `wordpress/`:
   ```php
   // Tambahkan di bagian atas file
   define('WP_SITEURL', 'https://lppm.unila.ac.id/wordpress');
   define('WP_HOME', 'https://lppm.unila.ac.id/wordpress');
   ```

4. **Buat file `public_html/.htaccess`** untuk redirect WordPress:
   ```apache
   # Redirect WordPress ke subfolder (opsional, jika mau)
   # RewriteRule ^wp-admin(.*)$ /wordpress/wp-admin$1 [L,R=301]
   # RewriteRule ^wp-content(.*)$ /wordpress/wp-content$1 [L,R=301]
   ```

5. **Sekarang `public_html/` kosong** → siap untuk deploy aplikasi baru!

**Hasil:**
- WordPress: `https://lppm.unila.ac.id/wordpress`
- Aplikasi Baru: `https://lppm.unila.ac.id` (root)

### Opsi 2: Deploy Aplikasi Baru di Subfolder `app/` ✅

Jika WordPress harus tetap di root, deploy aplikasi baru di subfolder `app/`:

**Langkah-langkah:**

1. **Buat folder** `public_html/app/`

2. **Update konfigurasi React untuk base path `/app/`** (di lokal, sebelum build):
   
   **a. Edit `vite.config.ts`:**
   ```typescript
   import { defineConfig } from "vite";
   import react from "@vitejs/plugin-react";

   export default defineConfig({
     plugins: [react()],
     base: '/app/', // Tambahkan ini
   });
   ```

   **b. Edit `src/App.tsx`** - tambahkan basename ke Router:
   ```typescript
   import { BrowserRouter as Router } from "react-router-dom";

   function App() {
     return (
       <Router basename="/app"> {/* Tambahkan basename */}
         {/* ... routes ... */}
       </Router>
     );
   }
   ```

   **c. Edit `index.html`** (jika perlu):
   ```html
   <base href="/app/" />
   ```

3. **Build frontend** dengan base path baru:
   ```bash
   cd lppm-frontend
   npm run build
   ```

4. **Upload semua file dari `dist/`** ke `public_html/app/`:
   - index.html
   - assets/
   - Semua file lain dari dist/

5. **Setup `.htaccess` di root** (`public_html/.htaccess`):
   ```apache
   # Redirect root ke aplikasi baru (opsional)
   RewriteEngine On
   RewriteCond %{REQUEST_URI} ^/$
   RewriteRule ^(.*)$ /app/ [L,R=301]

   # Atau biarkan WordPress di root, aplikasi di /app/
   ```

6. **Setup `.htaccess` di subfolder** (`public_html/app/.htaccess`):
   ```apache
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteBase /app/

       # Handle Authorization Header
       RewriteCond %{HTTP:Authorization} .
       RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

       # Send API Requests To Laravel (di root)
       RewriteCond %{REQUEST_URI} ^/app/api
       RewriteRule ^api/(.*)$ /api/$1 [L]

       # Send Requests To Frontend (React Router)
       RewriteCond %{REQUEST_FILENAME} !-f
       RewriteCond %{REQUEST_FILENAME} !-d
       RewriteCond %{REQUEST_URI} !^/app/api
       RewriteRule ^ index.html [L]
   </IfModule>
   ```

**Hasil:**
- WordPress: `https://lppm.unila.ac.id/wp-admin` (tetap bisa diakses di root)
- Aplikasi Baru: `https://lppm.unila.ac.id/app/` (semua route React)
- API: `https://lppm.unila.ac.id/api/...` (Laravel API tetap di root)

**⚠️ Catatan:** 
- Semua URL aplikasi akan jadi `lppm.unila.ac.id/app/...`
- Pastikan update API URL di frontend jadi `/api/...` (relative) atau `https://lppm.unila.ac.id/api/...` (absolute)

### Opsi 3: Ganti WordPress dengan Aplikasi Baru

Jika WordPress tidak lagi digunakan:

1. **Backup semua file WordPress** dulu (untuk jaga-jaga)
2. **Hapus semua file WordPress** dari `public_html/`
3. **Deploy aplikasi baru** langsung di `public_html/`

**Hasil:**
- Aplikasi Baru: `https://lppm.unila.ac.id` (root)
- WordPress: Tidak bisa diakses lagi

---

## Struktur Folder di cPanel (Setelah Handle WordPress)

Jika pilih **Opsi 1** (pindahkan WordPress), struktur-nya jadi:

```
/home/username/
├── public_html/          # Root domain (untuk aplikasi baru)
│   ├── index.php         # Entry point Laravel
│   ├── .htaccess
│   ├── assets/           # File static dari React build
│   └── wordpress/        # WordPress dipindah ke sini
│       ├── wp-admin/
│       ├── wp-content/
│       └── wp-includes/
├── lppm-backend/         # Folder Laravel lengkap (di luar public_html)
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── routes/
│   ├── storage/
│   ├── vendor/
│   └── .env
└── lppm-frontend-build/  # File hasil build React (opsional)
```

---

## Langkah-langkah Deploy

### Step 0: Handle WordPress (Jika Ada)

**Jika ada WordPress di `public_html/`**, ikuti **Opsi 1** di atas dulu:
1. Buat folder `public_html/wordpress/`
2. Pindahkan semua file WordPress ke folder tersebut
3. Edit `wp-config.php` untuk update URL
4. Pastikan `public_html/` sudah kosong (kecuali folder `wordpress/`)

**Setelah itu, lanjut ke Step 1.**

---

### Step 1: Upload Backend Laravel

1. **Buat folder di luar public_html** (via File Manager atau SSH):
   ```
   /home/username/lppm-backend
   ```

2. **Upload semua file Laravel** ke folder tersebut (kecuali folder `public/`):
   - app/
   - bootstrap/
   - config/
   - database/
   - routes/
   - storage/
   - vendor/ (atau upload composer.json lalu jalankan `composer install` di server)
   - artisan
   - composer.json
   - .env (buat file baru di server, jangan upload .env lokal)

3. **Buat folder storage dengan permission yang benar**:
   ```bash
   chmod -R 775 storage
   chmod -R 775 bootstrap/cache
   ```

### Step 2: Setup File Public Laravel

1. **Copy isi folder `public/` Laravel** ke `public_html/` (⚠️ **KECUALI `.htaccess`**):
   - ✅ index.php
   - ❌ `.htaccess` (JANGAN di-copy, akan di-edit manual di Step 4)
   - ✅ robots.txt
   - ✅ favicon.ico
   - ✅ folder `data/` (jika ada)

   **⚠️ PENTING:** Jangan copy `.htaccess` dari Laravel karena akan menimpa `.htaccess` WordPress yang sudah ada konfigurasi PHP dari cPanel. File `.htaccess` akan di-edit manual di Step 4.

2. **Edit file `public_html/index.php`**:
   ```php
   <?php

   use Illuminate\Contracts\Http\Kernel;
   use Illuminate\Http\Request;

   define('LARAVEL_START', microtime(true));

   // Ubah path ini sesuai lokasi folder backend kamu
   require __DIR__.'/../lppm-backend/vendor/autoload.php';

   $app = require_once __DIR__.'/../lppm-backend/bootstrap/app.php';

   $kernel = $app->make(Kernel::class);

   $response = $kernel->handle(
       $request = Request::capture()
   )->send();

   $kernel->terminate($request, $response);
   ```

### Step 3: Upload Frontend React Build

**Pilih salah satu:**

#### A. Deploy di Root (`public_html/`) - Default

1. **Build frontend** (di lokal):
   ```bash
   cd lppm-frontend
   npm run build
   ```
   
   Setelah build selesai, akan muncul folder **`dist/`** (karena pakai Vite).

2. **⚠️ PENTING: Hanya upload isi folder `dist/` saja!**
   
   **Yang harus di-upload ke `public_html/`:**
   - ✅ `dist/index.html` → upload ke `public_html/index.html`
   - ✅ `dist/assets/` → upload folder `assets/` ke `public_html/assets/`
   - ✅ Semua file/folder lain di dalam `dist/` (jika ada)
   
   **Yang TIDAK perlu di-upload:**
   - ❌ `node_modules/` (sangat besar, tidak perlu)
   - ❌ `src/` (source code, tidak perlu di production)
   - ❌ `package.json`, `vite.config.ts`, dll (file konfigurasi, tidak perlu)
   - ❌ Folder `dist/` itu sendiri (hanya isinya yang di-upload)

3. **Cara upload:**
   - Buka folder `lppm-frontend/dist/` di komputer lokal
   - **Pilih semua file dan folder** di dalam `dist/` (bukan folder `dist/`-nya)
   - Upload ke `public_html/` via cPanel File Manager atau FTP
   
   **Struktur setelah upload:**
   ```
   public_html/
   ├── index.html          (dari dist/index.html)
   ├── assets/             (dari dist/assets/)
   │   ├── index-xxx.js
   │   ├── index-xxx.css
   │   └── ...
   └── ... (file lain dari dist/)
   ```

4. **Edit `public_html/index.html`** (jika perlu):
   - Pastikan base path sesuai (misal: `<base href="/">`)
   - Pastikan API URL mengarah ke backend Laravel (cek file `.env` atau config frontend)

#### B. Deploy di Subfolder `app/` (Jika WordPress Tetap di Root) ⭐

**Langkah-langkah:**

1. **Update konfigurasi React untuk base path `/app/`** (di lokal, sebelum build):
   
   **a. Edit `lppm-frontend/vite.config.ts`:**
   ```typescript
   import { defineConfig } from "vite";
   import react from "@vitejs/plugin-react";

   export default defineConfig({
     plugins: [react()],
     base: '/app/', // ⭐ Tambahkan ini
   });
   ```

   **b. Edit `lppm-frontend/src/App.tsx`** - tambahkan basename ke Router:
   ```typescript
   import { BrowserRouter as Router } from "react-router-dom";

   function App() {
     return (
       <Router basename="/app"> {/* ⭐ Tambahkan basename */}
         <div className="min-h-screen flex flex-col">
           <Header />
           <main className="flex-grow">
             <Routes>
               {/* ... routes ... */}
             </Routes>
           </main>
           <Footer />
         </div>
       </Router>
     );
   }
   ```

   **c. (Opsional) Edit `lppm-frontend/index.html`:**
   ```html
   <head>
     <base href="/app/" /> <!-- ⭐ Tambahkan ini -->
     <!-- ... -->
   </head>
   ```

2. **Build frontend** dengan base path baru:
   ```bash
   cd lppm-frontend
   npm run build
   ```

3. **Buat folder `public_html/app/`** di server

4. **Upload semua file dari `dist/`** ke `public_html/app/`:
   - `dist/index.html` → `public_html/app/index.html`
   - `dist/assets/` → `public_html/app/assets/`
   - Semua file lain dari `dist/`

5. **Buat file `public_html/app/.htaccess`:**
   ```apache
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteBase /app/

       # Handle Authorization Header
       RewriteCond %{HTTP:Authorization} .
       RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

       # Send API Requests To Laravel (di root, bukan di /app/api)
       RewriteCond %{REQUEST_URI} ^/app/api
       RewriteRule ^api/(.*)$ /api/$1 [L,R=301]

       # Send Requests To Frontend (React Router)
       RewriteCond %{REQUEST_FILENAME} !-f
       RewriteCond %{REQUEST_FILENAME} !-d
       RewriteCond %{REQUEST_URI} !^/app/api
       RewriteRule ^ index.html [L]
   </IfModule>
   ```

6. **Update API URL di frontend** (pastikan pakai absolute path atau relative ke root):
   - Di file yang pakai API, pastikan URL-nya: `/api/...` (relative) atau `https://lppm.unila.ac.id/api/...` (absolute)
   - Jangan pakai `/app/api/...` karena API tetap di root

**Struktur setelah upload:**
```
public_html/
├── wp-admin/          (WordPress tetap di root)
├── wp-content/        (WordPress)
├── index.php          (Laravel entry point untuk API)
├── .htaccess          (untuk root - handle API & WordPress)
└── app/               (Aplikasi React baru)
    ├── index.html
    ├── assets/
    └── .htaccess      (untuk routing React di subfolder)
```

**Hasil:**
- WordPress: `https://lppm.unila.ac.id/wp-admin` (tetap di root)
- Aplikasi Baru: `https://lppm.unila.ac.id/app/` (semua route React)
- API: `https://lppm.unila.ac.id/api/...` (Laravel API di root)

### Step 4: Setup .htaccess

**⚠️ PENTING:** Jika di `public_html/` sudah ada file `.htaccess` dari WordPress, **ganti bagian rewrite rules-nya** tapi **pertahankan konfigurasi PHP dari cPanel**.

**Cara edit `.htaccess`:**

1. **Backup dulu** file `.htaccess` yang ada (untuk jaga-jaga)

2. **Edit file `public_html/.htaccess`** dengan konten berikut:

```apache
# ============================================
# LARAVEL + REACT ROUTING
# ============================================
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Allow WordPress di subfolder (jika ada)
    RewriteCond %{REQUEST_URI} ^/wordpress
    RewriteRule ^ - [L]

    # Allow WordPress di root (jika WordPress tetap di root) - HARUS SEBELUM REDIRECT
    RewriteCond %{REQUEST_URI} ^/(wp-admin|wp-content|wp-includes|wp-login|wp-cron)
    RewriteRule ^ - [L]

    # Allow aplikasi di subfolder app/ (jika deploy di subfolder)
    RewriteCond %{REQUEST_URI} ^/app
    RewriteRule ^ - [L]

    # Redirect root ke /app/ (jika deploy di subfolder app/)
    # Hanya redirect jika bukan WordPress URL
    RewriteCond %{REQUEST_URI} ^/$
    RewriteCond %{REQUEST_URI} !^/(wp-admin|wp-content|wp-includes|wp-login)
    RewriteRule ^(.*)$ /app/ [L,R=301]

    # Send API Requests To Laravel
    RewriteCond %{REQUEST_URI} ^/api
    RewriteRule ^ index.php [L]

    # Send Requests To Frontend (React Router) - hanya jika deploy di root
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} !^/api
    RewriteCond %{REQUEST_URI} !^/wordpress
    RewriteCond %{REQUEST_URI} !^/app
    RewriteCond %{REQUEST_URI} !^/(wp-admin|wp-content|wp-includes)
    RewriteRule ^ index.html [L]
</IfModule>

# ============================================
# SECURITY RULES (dari WordPress, dipertahankan)
# ============================================
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Block access to ACME challenge path
    RewriteRule ^\.well-known/ - [F,L]
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/.*google.*$ [NC]
    RewriteRule .* - [F,L]
</IfModule>

# ============================================
# PHP CONFIGURATION (dari cPanel - JANGAN DIUBAH!)
# ============================================
# BEGIN cPanel-generated php ini directives, do not edit
# Manual editing of this file may result in unexpected behavior.
# To make changes to this file, use the cPanel MultiPHP INI Editor

<IfModule php8_module>
   php_flag display_errors Off
   php_value max_execution_time 30
   php_value max_input_time 60
   php_value max_input_vars 1000
   php_value memory_limit 9128M
   php_value post_max_size 8M
   php_value session.gc_maxlifetime 1440
   php_value session.save_path "/var/cpanel/php/sessions/ea-php82"
   php_value upload_max_filesize 20M
   php_flag zlib.output_compression Off
</IfModule>

<IfModule lsapi_module>
   php_flag display_errors Off
   php_value max_execution_time 30
   php_value max_input_time 60
   php_value max_input_vars 1000
   php_value memory_limit 9128M
   php_value post_max_size 8M
   php_value session.gc_maxlifetime 1440
   php_value session.save_path "/var/cpanel/php/sessions/ea-php82"
   php_value upload_max_filesize 20M
   php_flag zlib.output_compression Off
</IfModule>
# END cPanel-generated php ini directives, do not edit

# ============================================
# PHP HANDLER (dari cPanel - JANGAN DIUBAH!)
# ============================================
# php -- BEGIN cPanel-generated handler, do not edit
# Set the "ea-php82" package as the default "PHP" programming language.
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml
</IfModule>
# php -- END cPanel-generated handler, do not edit
```

**Penjelasan:**
- **Bagian Laravel + React Routing**: Ganti semua rewrite rules WordPress dengan rules untuk Laravel + React
- **Bagian Security Rules**: Pertahankan (block .well-known dan google files)
- **Bagian PHP Config**: **JANGAN DIUBAH** - ini dari cPanel, penting untuk PHP berjalan dengan benar
- **Bagian PHP Handler**: **JANGAN DIUBAH** - ini dari cPanel, menentukan versi PHP yang digunakan

**Catatan:** 
- Jika WordPress dipindah ke `wordpress/`, WordPress akan punya `.htaccess` sendiri di `public_html/wordpress/.htaccess` (jangan diubah, biarkan WordPress handle sendiri)
- Jika cPanel pakai PHP-FPM atau FastCGI dan API tidak bisa diakses, alternatifnya buat file `public_html/api/index.php`:

```php
<?php
// public_html/api/index.php
require __DIR__.'/../../lppm-backend/bootstrap/app.php';
```

### Step 5: Konfigurasi .env Production

Edit file `/home/username/lppm-backend/.env`:

```env
APP_NAME="LPPM Universitas Lampung"
APP_ENV=production
APP_KEY=base64:... (generate dengan: php artisan key:generate)
APP_DEBUG=false
APP_URL=https://lppm.unila.ac.id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nama_database
DB_USERNAME=username_db
DB_PASSWORD=password_db

# WordPress Connection (jika pakai)
WP_DB_CONNECTION=mysql
DB_WP_PREFIX=2022_
WP_BASE_URL=https://lppm.unila.ac.id

# API URL untuk frontend
VITE_LARAVEL_API_URL=https://lppm.unila.ac.id/api
```

### Step 6: Install Dependencies & Optimize

**Pilih salah satu metode:**

#### Metode A: Via SSH/Terminal cPanel (Jika Tersedia)

```bash
cd /home/teslppm/lppm-backend

# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# Generate app key (jika belum)
php artisan key:generate

# Cache config & routes
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permission
chmod -R 775 storage bootstrap/cache
```

#### Metode B: Tanpa SSH/Terminal (Alternatif) ⭐

**1. Install Composer Dependencies:**

**Opsi 1: Upload folder `vendor/` dari lokal**
- Di lokal, jalankan: `composer install --no-dev --optimize-autoloader`
- Upload folder `vendor/` ke `/home/teslppm/lppm-backend/vendor/`

**Opsi 2: Pakai Composer di lokal lalu upload**
- Di lokal, jalankan: `composer install --no-dev --optimize-autoloader`
- Zip folder `vendor/` dan upload ke server, lalu extract

**2. Generate APP_KEY:**

Buat file PHP untuk generate key. Buat file baru di `public_html/generate-key.php`:

```php
<?php
// generate-key.php - HAPUS FILE INI SETELAH DIGUNAKAN!

$key = 'base64:' . base64_encode(random_bytes(32));

echo "APP_KEY yang di-generate:\n";
echo $key . "\n\n";

echo "Copy key di atas dan paste ke file /home/teslppm/lppm-backend/.env\n";
echo "Setelah itu, HAPUS file ini untuk keamanan!";
```

- Buka di browser: `https://lppm.unila.ac.id/generate-key.php`
- Copy `APP_KEY` yang muncul
- Paste ke file `/home/teslppm/lppm-backend/.env` (baris `APP_KEY=`)
- **HAPUS file `generate-key.php` setelah digunakan!**

**3. Set Permission (via File Manager):**
- Masuk ke folder `/home/teslppm/lppm-backend/storage/`
- Klik kanan → "Change Permissions" atau "Permissions"
- Set permission menjadi `775` (atau centang: Owner: Read, Write, Execute | Group: Read, Write, Execute | Public: Read, Execute)
- Lakukan hal yang sama untuk `/home/teslppm/lppm-backend/bootstrap/cache/`

**4. Cache Config (Opsional - bisa skip dulu):**
Buat file `public_html/cache-config.php`:

```php
<?php
// cache-config.php - HAPUS FILE INI SETELAH DIGUNAKAN!

require __DIR__.'/../lppm-backend/vendor/autoload.php';
$app = require_once __DIR__.'/../lppm-backend/bootstrap/app.php';

$app->make('Illuminate\Contracts\Console\Kernel')->call('config:cache');
$app->make('Illuminate\Contracts\Console\Kernel')->call('route:cache');

echo "Config dan route sudah di-cache! HAPUS file ini sekarang!";
```

- Buka di browser: `https://lppm.unila.ac.id/cache-config.php`
- **HAPUS file `cache-config.php` setelah digunakan!**

**⚠️ PENTING:** Hapus semua file helper PHP (`generate-key.php`, `cache-config.php`) setelah digunakan untuk keamanan!

### Step 7: Setup Database

1. **Import database** via phpMyAdmin atau SSH
2. **Pastikan kredensial di `.env` sesuai**

### Step 8: Test

1. Buka `https://lppm.unila.ac.id` → harus muncul halaman React
2. Buka `https://lppm.unila.ac.id/api/pos-ap/categories` → harus return JSON
3. Test fitur admin login, dll.

---

## Alternatif: Deploy dengan Subdomain

Jika mau pisahkan frontend & backend:

### Frontend di `public_html/`
- Semua file build React
- `.htaccess` untuk React Router

### Backend di `api.lppm.unila.ac.id` (subdomain)
- Setup subdomain di cPanel
- Document root: `/home/username/lppm-backend/public`
- Atau pakai struktur yang sama seperti di atas

Lalu update `VITE_LARAVEL_API_URL` di frontend jadi `https://api.lppm.unila.ac.id`.

---

## Troubleshooting

### Error 500 Internal Server Error
- Cek permission folder `storage/` dan `bootstrap/cache/` (harus 775)
- Cek log di `lppm-backend/storage/logs/laravel.log`
- Pastikan PHP version >= 8.1

### API tidak bisa diakses
- Pastikan `.htaccess` sudah benar
- Cek apakah mod_rewrite aktif
- Coba akses langsung: `https://lppm.unila.ac.id/api/pos-ap/categories`

### Frontend tidak muncul
- Pastikan semua file dari `dist/` sudah ter-upload
- Cek console browser untuk error
- Pastikan base path di `index.html` sesuai

### Database connection error
- Pastikan kredensial di `.env` benar
- Cek apakah database user punya akses ke database tersebut
- Test koneksi via phpMyAdmin

---

## Catatan Penting

1. **Jangan upload folder `node_modules/`** ke server (terlalu besar)
2. **Jangan upload `.env` lokal** ke production (buat baru di server)
3. **Selalu backup database** sebelum deploy
4. **Test di staging dulu** kalau memungkinkan
5. **Gunakan SSL/HTTPS** untuk production

---

## Quick Checklist

- [ ] **Handle WordPress** (jika ada): Pindahkan ke `public_html/wordpress/`
- [ ] **Build frontend React** (`npm run build` di lokal)
- [ ] **Upload backend Laravel** ke `/home/username/lppm-backend` (kecuali folder `public/`)
- [ ] **Copy file `public/` Laravel** ke `public_html/` (index.php, .htaccess, dll)
- [ ] **Upload isi folder `dist/`** ke `public_html/` (hanya isinya, bukan folder `dist/`-nya)
- [ ] **Setup `.htaccess`** di `public_html/`
- [ ] **Edit `public_html/index.php`** (path ke backend)
- [ ] **Buat `.env` production** di server (jangan upload .env lokal)
- [ ] **Install Composer dependencies** (`composer install --no-dev`)
- [ ] **Set permission folder `storage/`** (chmod 775)
- [ ] **Cache config & routes Laravel** (`php artisan config:cache`)
- [ ] **Import database**
- [ ] **Test semua fitur** (frontend, API, admin login, dll)

---

Selamat deploy! 🚀

