<?php

declare(strict_types=1);

/**
 * Runner sekali pakai untuk cPanel tanpa Terminal.
 *
 * PENTING:
 * - Upload file ini SAJA ke public_html dengan nama yang sulit ditebak.
 * - Buat token di:
 *   /home/USERNAME/lppm-backend/storage/app/private/deploy-update-v2.token
 * - Isi token minimal 32 karakter acak.
 * - Hapus file publik dan token segera setelah berhasil.
 *
 * Runner ini hanya:
 * 1. menjalankan migration content_datasets; dan
 * 2. menjalankan Database\Seeders\WebsiteUpdateV2Seeder.
 *
 * Runner ini tidak menjalankan DatabaseSeeder, LocalWordpressAdminSeeder,
 * optimize:clear, cache:clear, rollback, maupun perintah WordPress.
 */

use Illuminate\Contracts\Console\Kernel;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function respond(int $status, string $title, string $message, string $details = ''): never
{
    http_response_code($status);

    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDetails = htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="id"><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.$safeTitle.'</title>';
    echo '<style>body{font:16px/1.55 system-ui,sans-serif;max-width:760px;margin:48px auto;padding:0 20px;color:#172033}';
    echo 'main{border:1px solid #d8deea;border-radius:14px;padding:24px}h1{font-size:1.4rem;margin-top:0}';
    echo 'input{box-sizing:border-box;width:100%;padding:10px;margin:8px 0 16px}button{padding:10px 16px;cursor:pointer}';
    echo 'pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f4f6fa;padding:14px;border-radius:8px}</style>';
    echo '<main><h1>'.$safeTitle.'</h1><p>'.$safeMessage.'</p>';

    if ($safeDetails !== '') {
        echo '<pre>'.$safeDetails.'</pre>';
    }

    echo '</main></html>';
    exit;
}

$backendRoot = dirname(__DIR__).DIRECTORY_SEPARATOR.'lppm-backend';

if (!is_file($backendRoot.'/artisan') || !is_file($backendRoot.'/vendor/autoload.php')) {
    respond(
        500,
        'Backend tidak ditemukan',
        'Runner mengharapkan backend di /home/USERNAME/lppm-backend dan file ini berada di /home/USERNAME/public_html.'
    );
}

$tokenPath = $backendRoot.'/storage/app/private/deploy-update-v2.token';
$expectedToken = is_file($tokenPath) ? trim((string) file_get_contents($tokenPath)) : '';

if (strlen($expectedToken) < 32) {
    respond(
        503,
        'Token deployment belum siap',
        'Buat storage/app/private/deploy-update-v2.token pada folder backend dengan isi minimal 32 karakter acak.'
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(200);
    echo '<!doctype html><html lang="id"><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Deploy Website Update V2</title>';
    echo '<style>body{font:16px/1.55 system-ui,sans-serif;max-width:760px;margin:48px auto;padding:0 20px;color:#172033}';
    echo 'main{border:1px solid #d8deea;border-radius:14px;padding:24px}h1{font-size:1.4rem;margin-top:0}';
    echo 'input{box-sizing:border-box;width:100%;padding:10px;margin:8px 0 16px}button{padding:10px 16px;cursor:pointer}</style>';
    echo '<main><h1>Deploy Website Update V2</h1>';
    echo '<p>Perintah ini hanya membuat tabel <code>content_datasets</code> bila diperlukan dan menerapkan <code>WebsiteUpdateV2Seeder</code>.</p>';
    echo '<form method="post"><label for="token">Token deployment</label>';
    echo '<input id="token" name="token" type="password" minlength="32" required autocomplete="off">';
    echo '<label><input name="confirmation" type="checkbox" value="RUN_UPDATE_V2" required style="width:auto"> Saya sudah membuat backup database.</label><br><br>';
    echo '<button type="submit">Jalankan Update V2</button></form></main></html>';
    exit;
}

$submittedToken = isset($_POST['token']) ? (string) $_POST['token'] : '';
$confirmation = isset($_POST['confirmation']) ? (string) $_POST['confirmation'] : '';

if (!hash_equals($expectedToken, $submittedToken) || $confirmation !== 'RUN_UPDATE_V2') {
    respond(403, 'Akses ditolak', 'Token atau konfirmasi tidak valid.');
}

require $backendRoot.'/vendor/autoload.php';
$app = require $backendRoot.'/bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (!$app->environment('production')) {
    respond(409, 'Environment ditolak', 'APP_ENV harus production sebelum runner dapat dijalankan.');
}

$migrationPath = 'database/migrations/2026_05_07_191900_create_content_datasets_table.php';
$migrationExit = $kernel->call('migrate', [
    '--path' => $migrationPath,
    '--force' => true,
]);
$migrationOutput = trim($kernel->output());

if ($migrationExit !== 0) {
    respond(500, 'Migration gagal', 'Seeder belum dijalankan.', $migrationOutput);
}

$seederExit = $kernel->call('db:seed', [
    '--class' => 'Database\\Seeders\\WebsiteUpdateV2Seeder',
    '--force' => true,
]);
$seederOutput = trim($kernel->output());

if ($seederExit !== 0) {
    respond(500, 'Seeder gagal', 'Migration berhasil, tetapi WebsiteUpdateV2Seeder gagal.', $seederOutput);
}

respond(
    200,
    'Website Update V2 berhasil',
    'Hapus runner dari public_html dan hapus file token sekarang. Login dan user WordPress tidak diubah.',
    trim($migrationOutput."\n".$seederOutput)
);
