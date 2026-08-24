<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicContentImageController extends Controller
{
    /**
     * Melayani gambar konten yang disimpan di public backend.
     *
     * Pada hosting, document root frontend dan folder public Laravel terpisah.
     * Karena itu gambar tidak boleh lagi diasumsikan tersedia melalui /images.
     */
    public function show(string $folder, string $filename): BinaryFileResponse
    {
        if (
            !preg_match('/^[A-Za-z0-9_-]+$/', $folder)
            || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)
            || !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)
        ) {
            throw new NotFoundHttpException();
        }

        $root = realpath(public_path('images/uploads'));
        $file = realpath(public_path("images/uploads/{$folder}/{$filename}"));

        if (
            $root === false
            || $file === false
            || !is_file($file)
            || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)
        ) {
            throw new NotFoundHttpException();
        }

        return response()->file($file, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
