<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    /**
     * Helper untuk mendapatkan nama tabel WordPress sesuai prefix.
     */
    private function wpTable(string $table): string
    {
        // Fallback ke 'wp_' jika env tidak ada, tapi idealnya cek config
        // Kita coba deteksi apakah PostController pakai '2022_'
        // Untuk aman, kita pakai logic yang sama dengan PosApController dulu
        $prefix = env('DB_WP_PREFIX', 'wp_');
        // Jika di PostController hardcode '2022_', mungkin kita perlu sesuaikan
        // Tapi mari kita coba pakai env dulu atau default standard.
        // Cek PostController: ->table('2022_posts as p')
        // Sepertinya prefixnya '2022_'.
        // Mari kita buat dynamic tapi default ke '2022_' jika env tak set, atau ikuti env.
        
        return $prefix . $table;
    }

    /**
     * GET /documents
     * Mengambil daftar dokumen (attachment) dari WordPress.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        $search = $request->query('search');

        // URL Base WordPress untuk link file
        $siteUrl = rtrim(config('services.wordpress.site_url', env('WP_BASE_URL', 'https://lppm.unila.ac.id')), '/');

        try {
            $connectionName = config('services.wordpress.connection', 'wordpress');
            $connection = DB::connection($connectionName);

            // Kita gunakan hardcode '2022_' jika env tidak ada, karena PostController pakai itu.
            // Atau lebih aman kita cek apakah tabel '2022_posts' ada?
            // Asumsi: ikuti pattern PostController yang sudah jalan.
            // Tapi PosApController pakai $this->wpTable.
            // Mari kita coba pakai '2022_' sebagai default prefix di sini jika env kosong.
            $prefix = env('DB_WP_PREFIX', '2022_'); 
            
            $postsTable = $prefix . 'posts';

            $query = $connection->table($postsTable)
                ->select([
                    'ID',
                    'post_title',
                    'post_date',
                    'post_mime_type',
                    'guid',
                    'post_excerpt',
                    'post_name'
                ])
                ->where('post_type', 'attachment')
                ->where('post_status', 'inherit'); // Attachment biasanya statusnya inherit

            // Filter hanya file dokumen (PDF, Word, Excel, PPT, ZIP)
            $query->where(function($q) {
                $q->where('post_mime_type', 'like', 'application/pdf')
                  ->orWhere('post_mime_type', 'like', 'application/msword')
                  ->orWhere('post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') // docx
                  ->orWhere('post_mime_type', 'like', 'application/vnd.ms-excel')
                  ->orWhere('post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') // xlsx
                  ->orWhere('post_mime_type', 'like', 'application/vnd.ms-powerpoint')
                  ->orWhere('post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.presentationml.presentation') // pptx
                  ->orWhere('post_mime_type', 'like', 'application/zip')
                  ->orWhere('post_mime_type', 'like', 'application/x-rar-compressed');
            });

            if ($search) {
                $query->where('post_title', 'like', '%' . $search . '%');
            }

            $query->orderByDesc('post_date');

            // Gunakan paginate() dari Laravel
            $paginator = $query->paginate($limit);

            $items = $paginator->getCollection()->map(function ($item) use ($siteUrl) {
                // Fix URL
                $url = $item->guid;
                if (strpos($url, 'http') !== 0) {
                     $url = $siteUrl . $url;
                }
                // Force HTTPS
                $url = str_replace('http://', 'https://', $url);

                // Tentukan tipe file simpel
                $type = 'file';
                if (strpos($item->post_mime_type, 'pdf') !== false) $type = 'pdf';
                elseif (strpos($item->post_mime_type, 'word') !== false) $type = 'word';
                elseif (strpos($item->post_mime_type, 'excel') !== false || strpos($item->post_mime_type, 'spreadsheet') !== false) $type = 'excel';
                elseif (strpos($item->post_mime_type, 'powerpoint') !== false || strpos($item->post_mime_type, 'presentation') !== false) $type = 'ppt';
                elseif (strpos($item->post_mime_type, 'zip') !== false || strpos($item->post_mime_type, 'rar') !== false) $type = 'archive';

                return [
                    'id' => $item->ID,
                    'title' => $item->post_title,
                    'date' => $item->post_date,
                    'url' => $url,
                    'type' => $type,
                    'mime' => $item->post_mime_type,
                    'excerpt' => $item->post_excerpt
                ];
            });

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Daftar dokumen berhasil diambil',
                    'count' => $items->count(),
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'next_page_url' => $paginator->nextPageUrl(),
                        'prev_page_url' => $paginator->previousPageUrl(),
                    ]
                ],
                'data' => $items,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal mengambil dokumen: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }
}
