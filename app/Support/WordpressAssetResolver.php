<?php

namespace App\Support;

/**
 * Resolves normal WordPress attachment paths without relying on `guid`.
 *
 * This class intentionally supports only relative `_wp_attached_file` values.
 * Download Manager documents have a different access policy and will receive a
 * dedicated resolver in Fase 4.
 */
final class WordpressAssetResolver
{
    public function publicUrl(?string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        $baseUrl = rtrim((string) config(
            'services.wordpress.uploads_base_url',
            rtrim((string) config('services.wordpress.site_url', ''), '/') . '/wp-content/uploads'
        ), '/');

        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
    }

    public function filesystemPath(?string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $uploadsRoot = rtrim(str_replace('\\', '/', (string) config('services.wordpress.uploads_root', '')), '/');

        if ($relativePath === null || $uploadsRoot === '') {
            return null;
        }

        return $uploadsRoot . '/' . $relativePath;
    }

    /**
     * Returns null when this environment cannot access the configured root.
     */
    public function exists(?string $relativePath): ?bool
    {
        $path = $this->filesystemPath($relativePath);
        $uploadsRoot = rtrim(str_replace('\\', '/', (string) config('services.wordpress.uploads_root', '')), '/');

        if ($path === null || $uploadsRoot === '' || !is_dir($uploadsRoot)) {
            return null;
        }

        return is_file($path);
    }

    public function normalizeRelativePath(?string $relativePath): ?string
    {
        if (!is_string($relativePath)) {
            return null;
        }

        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if (
            $relativePath === '' ||
            str_contains($relativePath, "\0") ||
            str_starts_with($relativePath, '/') ||
            str_contains($relativePath, '://') ||
            preg_match('/^[A-Za-z]:\//', $relativePath)
        ) {
            return null;
        }

        $segments = explode('/', $relativePath);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        return implode('/', $segments);
    }
}
