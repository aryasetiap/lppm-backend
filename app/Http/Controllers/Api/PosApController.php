<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressDocumentResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosApController extends Controller
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressDocumentResolver $documents
    )
    {
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

        try {
            $connection = $this->tables->connection();

            $postsTable = $this->tables->table('posts');
            $termsTable = $this->tables->table('terms');
            $termTaxTable = $this->tables->table('term_taxonomy');
            $termRelTable = $this->tables->table('term_relationships');

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
                ->where('p.post_type', 'wpdmpro')
                ->where('tt.taxonomy', 'wpdmcategory')
                ->where('t.slug', $categorySlug)
                ->orderByDesc('p.post_date')
                ->limit($limit);

            $documents = $query->get();
            $metadata = $this->metadataFor($documents->pluck('ID')->map(fn ($id) => (int) $id)->all());

            $items = $documents->map(function ($item) use ($metadata) {
                $file = $this->documents->resolve($metadata[(int) $item->ID] ?? []);
                $isPublic = $file['available'] && $this->documents->isPublic($metadata[(int) $item->ID] ?? []);

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
                    'download_url' => $isPublic ? $this->documents->downloadUrl((int) $item->ID) : null,
                    'availability' => $file['available'] ? 'available' : 'unavailable',
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

    /** @param list<int> $ids @return array<int, array<string, list<string>>> */
    private function metadataFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $metadata = [];
        $this->tables->connection()
            ->table($this->tables->table('postmeta'))
            ->select(['post_id', 'meta_key', 'meta_value'])
            ->whereIn('post_id', $ids)
            ->whereIn('meta_key', ['__lppm_document_file', '__wpdm_files', '__wpdm_access'])
            ->orderBy('meta_id')
            ->get()
            ->each(function ($row) use (&$metadata): void {
                $metadata[(int) $row->post_id][(string) $row->meta_key][] = (string) $row->meta_value;
            });

        return $metadata;
    }

    /**
     * GET /pos-ap/categories
     * Mengambil daftar kategori wpdm yang tersedia.
     */
    public function categories(): JsonResponse
    {
        try {
            $connection = $this->tables->connection();

            $termsTable = $this->tables->table('terms');
            $termTaxTable = $this->tables->table('term_taxonomy');

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
