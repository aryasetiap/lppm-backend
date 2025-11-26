<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosApController extends Controller
{
    /**
     * Helper untuk mendapatkan nama tabel WordPress sesuai prefix.
     */
    private function wpTable(string $table): string
    {
        $prefix = env('DB_WP_PREFIX', 'wp_');
        return $prefix . $table;
    }

    /**
     * GET /pos-ap/downloads?category=slug&limit=10
     * Mengambil daftar dokumen/download berdasarkan kategori wpdm.
     */
    public function downloads(Request $request): JsonResponse
    {
        $categorySlug = $request->query('category', 'download');
        $limit = (int) $request->query('limit', 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;

        $siteUrl = rtrim(config('services.wordpress.site_url', env('WP_BASE_URL', '')), '/');

        try {
            $connectionName = config('services.wordpress.connection', 'wordpress');
            $connection = DB::connection($connectionName);

            $postsTable = $this->wpTable('posts');
            $termsTable = $this->wpTable('terms');
            $termTaxTable = $this->wpTable('term_taxonomy');
            $termRelTable = $this->wpTable('term_relationships');

            $query = $connection->table("{$postsTable} as p")
                ->select([
                    'p.ID',
                    'p.post_title',
                    'p.post_excerpt',
                    'p.post_date',
                    'p.post_modified',
                    'p.post_name',
                    'p.guid',
                    't.slug as category_slug',
                    't.name as category_name',
                ])
                ->join("{$termRelTable} as tr", 'tr.object_id', '=', 'p.ID')
                ->join("{$termTaxTable} as tt", 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
                ->join("{$termsTable} as t", 't.term_id', '=', 'tt.term_id')
                ->where('p.post_status', 'publish')
                ->whereIn('p.post_type', ['wpdmpro', 'post', 'page'])
                ->where('tt.taxonomy', 'wpdmcategory')
                ->where('t.slug', $categorySlug)
                ->orderByDesc('p.post_date')
                ->limit($limit);

            $items = $query->get()->map(function ($item) use ($siteUrl) {
                $downloadUrl = $siteUrl
                    ? $siteUrl . '/?wpdmdl=' . $item->ID
                    : $item->guid;

                return [
                    'id' => (int) $item->ID,
                    'title' => $item->post_title,
                    'excerpt' => strip_tags((string) $item->post_excerpt),
                    'slug' => $item->post_name,
                    'category' => [
                        'slug' => $item->category_slug,
                        'name' => $item->category_name,
                    ],
                    'updated_at' => $item->post_modified ?: $item->post_date,
                    'download_url' => $downloadUrl,
                    'permalink' => $siteUrl
                        ? rtrim($siteUrl, '/') . '/' . $item->post_name
                        : $item->guid,
                ];
            });

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Data POS-AP berhasil diambil',
                    'count' => $items->count(),
                ],
                'data' => $items,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal mengambil data POS-AP: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /pos-ap/categories
     * Mengambil daftar kategori wpdm yang tersedia.
     */
    public function categories(): JsonResponse
    {
        try {
            $connectionName = config('services.wordpress.connection', 'wordpress');
            $connection = DB::connection($connectionName);

            $termsTable = $this->wpTable('terms');
            $termTaxTable = $this->wpTable('term_taxonomy');

            $categories = $connection->table("{$termTaxTable} as tt")
                ->select([
                    't.slug',
                    't.name',
                    'tt.count',
                ])
                ->join("{$termsTable} as t", 't.term_id', '=', 'tt.term_id')
                ->where('tt.taxonomy', 'wpdmcategory')
                ->orderByDesc('tt.count')
                ->get()
                ->map(function ($cat) {
                    return [
                        'slug' => $cat->slug,
                        'name' => $cat->name,
                        'count' => (int) $cat->count,
                    ];
                });

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Daftar kategori POS-AP berhasil diambil',
                    'count' => $categories->count(),
                ],
                'data' => $categories,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal mengambil kategori POS-AP: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }
}

