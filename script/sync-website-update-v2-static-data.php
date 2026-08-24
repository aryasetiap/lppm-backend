<?php

declare(strict_types=1);

$backendRoot = dirname(__DIR__);
$workspaceRoot = dirname($backendRoot);
$update = require $backendRoot.'/database/data/website-update-v2.php';

$targets = [
    $backendRoot.'/public/data/sub-bagian-lppm.json',
    $workspaceRoot.'/lppm-frontend/public/data/sub-bagian-lppm.json',
];

$source = json_decode((string) file_get_contents($targets[0]), true, flags: JSON_THROW_ON_ERROR);
$source['metadata'] = $update['metadata'];
$source['sub_bagian'] ??= [];
unset($source['sub_bagian']['pui'], $source['sub_bagian']['puslit']);
$source['sub_bagian']['pusat-lppm'] = $update['centers'];

foreach ($targets as $target) {
    file_put_contents(
        $target,
        json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
    );
}

$profileTargets = [
    $backendRoot.'/public/data/profile-lppm.json',
    $workspaceRoot.'/lppm-frontend/public/data/profile-lppm.json',
];

$profile = json_decode((string) file_get_contents($profileTargets[0]), true, flags: JSON_THROW_ON_ERROR);
$profile['metadata']['last_updated'] = $update['metadata']['last_updated'];
$profile['struktur_organisasi']['gambar_struktur'] = '/images/struktur/struktur-organisasi-2026.png';

foreach ($profileTargets as $target) {
    file_put_contents(
        $target,
        json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
    );
}

fwrite(STDOUT, "Static data UPDATE WEBSITE versi 2 berhasil disinkronkan.\n");
