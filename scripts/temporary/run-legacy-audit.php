<?php

declare(strict_types=1);

/*
 * ONE-TIME, BROWSER-ONLY FALLBACK FOR THE LEGACY ASSET AUDIT
 *
 * Do not deploy this file as part of the application. Upload it manually to
 * /home/teslppm/public_html using a random filename, run it once over HTTPS,
 * and verify that it has deleted itself after a successful audit.
 */

use Illuminate\Contracts\Console\Kernel;

ini_set('display_errors', '0');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');

/**
 * Read only the single-value environment variables required by this temporary
 * file. Laravel may have a cached configuration, so the one-time browser tool
 * reads these values directly from the backend .env file.
 */
function auditEnvValue(string $key): ?string
{
    $environmentFile = dirname(__DIR__) . '/lppm-backend/.env';
    if (!is_readable($environmentFile)) {
        return null;
    }

    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/';
    foreach (file($environmentFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!preg_match($pattern, $line, $matches)) {
            continue;
        }

        $value = $matches[1];
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return trim($value);
    }

    return null;
}

function auditPage(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title><style>body{font-family:system-ui,sans-serif;max-width:42rem;margin:4rem auto;padding:0 1rem;line-height:1.5}input,button{font:inherit;padding:.6rem;width:100%;box-sizing:border-box}button{margin-top:1rem;cursor:pointer}pre{white-space:pre-wrap;background:#f4f4f5;padding:1rem;overflow:auto}.notice{padding:1rem;background:#fff7ed;border:1px solid #fdba74}</style></head><body>'
        . $body
        . '</body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    auditPage(
        'Audit aset legacy',
        '<h1>Audit aset legacy</h1><p class="notice">Halaman sementara ini hanya menjalankan audit baca-saja sekali. Masukkan token audit yang disimpan pada <code>LEGACY_AUDIT_TOKEN</code>, lalu file akan menghapus dirinya bila audit selesai.</p><form method="post" autocomplete="off"><label for="audit_token">Token audit</label><input id="audit_token" name="audit_token" type="password" required autofocus><button type="submit">Jalankan audit baca-saja</button></form>'
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    auditPage('Metode tidak diizinkan', '<h1>Metode tidak diizinkan</h1>', 405);
}

$expectedToken = auditEnvValue('LEGACY_AUDIT_TOKEN');
$providedToken = is_string($_POST['audit_token'] ?? null) ? $_POST['audit_token'] : '';
if ($expectedToken === null || strlen($expectedToken) < 32 || !hash_equals($expectedToken, $providedToken)) {
    auditPage('Akses ditolak', '<h1>Akses ditolak</h1><p>Token audit tidak valid.</p>', 403);
}

$prefix = auditEnvValue('DB_WP_PREFIX');
$uploadsRoot = auditEnvValue('LEGACY_UPLOADS_ROOT');
$uploadsBaseUrl = auditEnvValue('LEGACY_UPLOADS_BASE_URL');
$documentRoots = auditEnvValue('LEGACY_DOCUMENT_ROOTS');
if ($prefix === null || $uploadsRoot === null || $uploadsBaseUrl === null || $documentRoots === null) {
    auditPage('Konfigurasi belum lengkap', '<h1>Konfigurasi belum lengkap</h1><p>Hapus file sementara ini, lengkapi konfigurasi audit di backend, lalu unggah kembali bila diperlukan.</p>', 503);
}

try {
    $backendPath = dirname(__DIR__) . '/lppm-backend';
    require $backendPath . '/vendor/autoload.php';
    $app = require $backendPath . '/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    // The command directory is registered in bootstrap/app.php. This file
    // never accepts a command name, path, prefix, or executable input from
    // the request.
    $exitCode = $kernel->call('wp:audit-legacy-assets', [
        '--prefix' => $prefix,
        '--uploads-root' => $uploadsRoot,
        '--uploads-base-url' => $uploadsBaseUrl,
        '--document-root' => array_values(array_filter(array_map('trim', explode(',', $documentRoots)))),
    ]);
    $output = $kernel->output();

    if ($exitCode !== 0) {
        $commandOutput = substr(trim($output), 0, 4000);
        $message = 'Legacy audit command gagal dengan exit code ' . $exitCode . '.';
        try {
            \Illuminate\Support\Facades\Log::error($message, [
                'exit_code' => $exitCode,
                'output' => $commandOutput,
            ]);
        } catch (Throwable) {
            error_log($message . ' Output: ' . $commandOutput);
        }

        auditPage('Audit gagal', '<h1>Audit gagal</h1><p>Periksa log Laravel melalui kanal internal. File sementara ini belum dihapus agar administrator dapat memperbaiki konfigurasi dan mencoba ulang.</p>', 500);
    }

    $deleted = @unlink(__FILE__);
    auditPage(
        'Audit selesai',
        '<h1>Audit selesai</h1><p>Audit tidak mengubah database WordPress maupun aset legacy. Ambil manifest privat melalui File Manager di <code>/home/teslppm/lppm-backend/storage/app/private/audits/wordpress-legacy/</code>.</p><pre>'
        . htmlspecialchars($output, ENT_QUOTES, 'UTF-8')
        . '</pre><p>'
        . ($deleted ? 'File sementara telah menghapus dirinya.' : 'Hapus file sementara ini sekarang melalui File Manager.')
        . '</p>'
    );
} catch (Throwable $exception) {
    $message = 'Legacy audit tool gagal: ' . $exception->getMessage();
    if (isset($app)) {
        try {
            \Illuminate\Support\Facades\Log::error($message, ['exception' => $exception]);
        } catch (Throwable) {
            error_log($message);
        }
    } else {
        error_log($message);
    }

    auditPage('Audit gagal', '<h1>Audit gagal</h1><p>Periksa log Laravel melalui kanal internal. Hapus file sementara ini bila tidak akan dipakai ulang.</p>', 500);
}
