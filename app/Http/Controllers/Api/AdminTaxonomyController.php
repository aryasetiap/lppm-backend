<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Taxonomy lists shared by the CMS editor and taxonomy-management screen.
 */
class AdminTaxonomyController extends Controller
{
    /** @var array<string, string> */
    private const TAXONOMIES = [
        'categories' => 'category',
        'tags' => 'post_tag',
        'document-categories' => 'wpdmcategory',
    ];

    public function __construct(private readonly WordpressTableResolver $tables)
    {
    }

    /**
     * GET /api/admin/taxonomies/categories?search=&per_page=50
     * GET /api/admin/taxonomies/tags?search=&per_page=50
     * GET /api/admin/taxonomies/document-categories?search=&per_page=50
     */
    public function index(Request $request, string $taxonomy): JsonResponse
    {
        $wordpressTaxonomy = self::TAXONOMIES[$taxonomy] ?? null;
        if ($wordpressTaxonomy === null) {
            abort(404);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $termsTable = $this->tables->table('terms');
        $termTaxonomyTable = $this->tables->table('term_taxonomy');
        $query = $this->tables->connection()
            ->table("{$termTaxonomyTable} as taxonomy")
            ->join("{$termsTable} as terms", 'terms.term_id', '=', 'taxonomy.term_id')
            ->where('taxonomy.taxonomy', $wordpressTaxonomy)
            ->select(['terms.term_id', 'terms.name', 'terms.slug', 'taxonomy.description', 'taxonomy.parent', 'taxonomy.count']);

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->where(function ($inner) use ($search) {
                $inner->where('terms.name', 'like', '%' . $search . '%')
                    ->orWhere('terms.slug', 'like', '%' . $search . '%');
            });
        }

        $paginator = $query
            ->orderBy('terms.name')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'taxonomy' => $wordpressTaxonomy,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'data' => $paginator->getCollection()->map(fn ($term) => [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'parent_id' => (int) $term->parent > 0 ? (int) $term->parent : null,
                'count' => (int) $term->count,
            ]),
        ]);
    }
}
