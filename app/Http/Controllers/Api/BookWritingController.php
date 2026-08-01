<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookWritingController extends Controller
{
    /**
     * Nama berkas yang menjadi awalan panduan Penulisan Buku RMBC Unila.
     */
    private const FILE_PREFIX = 'Panduan-Penulisan-Buku-RMBC-Unila-2026';

    /**
     * GET /penulisan-buku/downloads?page=1&limit=10&search=
     */
    public function downloads(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);
        $limit = $limit > 0 ? min($limit, 100) : 10;
        $search = trim((string) $request->query('search', ''));

        try {
            $connection = DB::connection(config('services.wordpress.connection', 'wordpress'));
            $prefix = env('DB_WP_PREFIX', 'wp_');
            $postsTable = $prefix . 'posts';

            $query = $connection->table($postsTable)
                ->select([
                    'ID',
                    'post_title',
                    'post_excerpt',
                    'post_date',
                    'post_modified',
                    'post_name',
                    'post_mime_type',
                    'guid',
                ])
                ->where('post_type', 'attachment')
                ->where('post_status', 'inherit')
                ->where('post_mime_type', 'application/pdf')
                ->where(function ($query) {
                    // GUID menyimpan nama file asli; dua kondisi lain mendukung data WordPress
                    // yang judul atau slug-nya telah dinormalisasi saat file diunggah.
                    $query->where('guid', 'like', '%' . self::FILE_PREFIX . '%')
                        ->orWhere('post_name', 'like', strtolower(self::FILE_PREFIX) . '%')
                        ->orWhere('post_title', 'like', str_replace('-', ' ', self::FILE_PREFIX) . '%');
                });

            if ($search !== '') {
                $query->where('post_title', 'like', '%' . $search . '%');
            }

            $paginator = $query
                ->orderByDesc('post_date')
                ->paginate($limit);

            $items = $paginator->getCollection()->map(fn ($item) => [
                'id' => (int) $item->ID,
                'title' => $item->post_title,
                'excerpt' => strip_tags((string) $item->post_excerpt),
                'slug' => $item->post_name,
                'updated_at' => $item->post_modified ?: $item->post_date,
                'date' => $item->post_date,
                'url' => str_replace('http://', 'https://', $item->guid),
                'download_url' => str_replace('http://', 'https://', $item->guid),
                'type' => 'pdf',
                'mime' => $item->post_mime_type,
            ]);

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Daftar panduan penulisan buku berhasil diambil',
                    'count' => $items->count(),
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'next_page_url' => $paginator->nextPageUrl(),
                        'prev_page_url' => $paginator->previousPageUrl(),
                    ],
                ],
                'data' => $items,
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal mengambil panduan penulisan buku: ' . $exception->getMessage(),
                ],
            ], 500);
        }
    }
}
