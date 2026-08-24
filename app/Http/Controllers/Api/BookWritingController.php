<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAssetResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookWritingController extends Controller
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressAssetResolver $assets
    ) {
    }

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
            $connection = $this->tables->connection();
            $postsTable = $this->tables->table('posts');
            $postmetaTable = $this->tables->table('postmeta');

            $query = $connection->table("{$postsTable} as p")
                ->select([
                    'p.ID',
                    'p.post_title',
                    'p.post_excerpt',
                    'p.post_date',
                    'p.post_modified',
                    'p.post_name',
                    'p.post_mime_type',
                    'file_meta.meta_value as attachment_path',
                ])
                ->leftJoin("{$postmetaTable} as file_meta", function ($join) {
                    $join->on('p.ID', '=', 'file_meta.post_id')
                        ->where('file_meta.meta_key', '_wp_attached_file');
                })
                ->where('p.post_type', 'attachment')
                ->where('p.post_status', 'inherit')
                ->where('p.post_mime_type', 'application/pdf')
                ->where(function ($query) {
                    $query->where('file_meta.meta_value', 'like', '%' . self::FILE_PREFIX . '%')
                        ->orWhere('p.post_name', 'like', strtolower(self::FILE_PREFIX) . '%')
                        ->orWhere('p.post_title', 'like', str_replace('-', ' ', self::FILE_PREFIX) . '%');
                });

            if ($search !== '') {
                $query->where('p.post_title', 'like', '%' . $search . '%');
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
                'url' => $this->assets->publicUrl($item->attachment_path),
                'download_url' => $this->assets->publicUrl($item->attachment_path),
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
