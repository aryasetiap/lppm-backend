<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAssetResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Read-only admin view of WordPress posts and pages.
 *
 * This controller deliberately exposes no create, update, delete, media, or
 * WordPress write operation. Those are separate phases after restore testing.
 */
class AdminPostController extends Controller
{
    /** @var list<string> */
    private const CONTENT_TYPES = ['post', 'page'];

    /** @var list<string> */
    private const POST_STATUSES = ['publish', 'future', 'draft', 'pending', 'private', 'trash'];

    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressAssetResolver $assets
    ) {
    }

    /**
     * GET /api/admin/posts?type=post&status=draft&author_id=1&category_id=2&date_from=2026-01-01&date_to=2026-01-31&search=&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:post,page'],
            'status' => ['nullable', 'in:publish,future,draft,pending,private,trash'],
            'search' => ['nullable', 'string', 'max:200'],
            'author_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $contentType = $filters['type'] ?? 'post';
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $usersTable = $this->tables->table('users');

        $query = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select([
                'p.ID',
                'p.post_type',
                'p.post_title',
                'p.post_name',
                'p.post_status',
                'p.post_date',
                'p.post_modified',
                'p.post_author',
                'p.post_excerpt',
                'u.display_name as author_name',
                'thumbnail.meta_value as featured_media_id',
                'img_file.meta_value as thumbnail_path',
            ])
            ->leftJoin("{$usersTable} as u", 'u.ID', '=', 'p.post_author')
            ->leftJoin("{$postmetaTable} as thumbnail", function ($join) {
                $join->on('thumbnail.post_id', '=', 'p.ID')
                    ->where('thumbnail.meta_key', '_thumbnail_id');
            })
            ->leftJoin("{$postsTable} as img", 'img.ID', '=', 'thumbnail.meta_value')
            ->leftJoin("{$postmetaTable} as img_file", function ($join) {
                $join->on('img_file.post_id', '=', 'img.ID')
                    ->where('img_file.meta_key', '_wp_attached_file');
            })
            ->where('p.post_type', $contentType);

        if (isset($filters['status'])) {
            $query->where('p.post_status', $filters['status']);
        } else {
            $query->whereIn('p.post_status', self::POST_STATUSES);
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->where(function ($inner) use ($search) {
                $inner->where('p.post_title', 'like', '%' . $search . '%')
                    ->orWhere('p.post_content', 'like', '%' . $search . '%');
            });
        }

        if (isset($filters['author_id'])) {
            $query->where('p.post_author', $filters['author_id']);
        }

        if (isset($filters['category_id'])) {
            $relationshipsTable = $this->tables->table('term_relationships');
            $taxonomyTable = $this->tables->table('term_taxonomy');

            $query->whereExists(function ($subquery) use ($relationshipsTable, $taxonomyTable, $filters) {
                $subquery->selectRaw('1')
                    ->from("{$relationshipsTable} as rel")
                    ->join("{$taxonomyTable} as tax", 'tax.term_taxonomy_id', '=', 'rel.term_taxonomy_id')
                    ->whereColumn('rel.object_id', 'p.ID')
                    ->where('tax.taxonomy', 'category')
                    ->where('tax.term_id', $filters['category_id']);
            });
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('p.post_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('p.post_date', '<=', $filters['date_to']);
        }

        $paginator = $query
            ->orderByDesc('p.post_modified')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $items = $paginator->getCollection()->map(fn ($post) => $this->listItem($post));

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'data' => $items,
        ]);
    }

    /**
     * GET /api/admin/posts/{post}
     */
    public function show(int $post): JsonResponse
    {
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $usersTable = $this->tables->table('users');

        $item = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select([
                'p.ID',
                'p.post_type',
                'p.post_title',
                'p.post_name',
                'p.post_status',
                'p.post_date',
                'p.post_date_gmt',
                'p.post_modified',
                'p.post_modified_gmt',
                'p.post_author',
                'p.post_content',
                'p.post_excerpt',
                'u.display_name as author_name',
                'thumbnail.meta_value as featured_media_id',
                'img_file.meta_value as thumbnail_path',
            ])
            ->leftJoin("{$usersTable} as u", 'u.ID', '=', 'p.post_author')
            ->leftJoin("{$postmetaTable} as thumbnail", function ($join) {
                $join->on('thumbnail.post_id', '=', 'p.ID')
                    ->where('thumbnail.meta_key', '_thumbnail_id');
            })
            ->leftJoin("{$postsTable} as img", 'img.ID', '=', 'thumbnail.meta_value')
            ->leftJoin("{$postmetaTable} as img_file", function ($join) {
                $join->on('img_file.post_id', '=', 'img.ID')
                    ->where('img_file.meta_key', '_wp_attached_file');
            })
            ->where('p.ID', $post)
            ->whereIn('p.post_type', self::CONTENT_TYPES)
            ->first();

        if ($item === null) {
            return response()->json([
                'meta' => [
                    'code' => 404,
                    'status' => 'error',
                    'message' => 'Konten tidak ditemukan.',
                ],
            ], 404);
        }

        $terms = $this->termsForPost((int) $item->ID);

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
            ],
            'data' => [
                ...$this->listItem($item),
                'content' => $item->post_content,
                'date_gmt' => $item->post_date_gmt,
                'modified_gmt' => $item->post_modified_gmt,
                'scheduling_enabled' => (bool) config('services.wordpress.scheduling_enabled', false),
                'categories' => $terms['categories'],
                'tags' => $terms['tags'],
            ],
        ]);
    }

    /**
     * @return array{id:int,type:string,title:string,slug:string,status:string,date:string,modified_at:string,excerpt:string,author:array{id:int,name:string},thumbnail:?string,featured_media_id:?int}
     */
    private function listItem(object $post): array
    {
        return [
            'id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'status' => (string) $post->post_status,
            'date' => (string) $post->post_date,
            'modified_at' => (string) $post->post_modified,
            'excerpt' => Str::limit(trim(strip_tags((string) $post->post_excerpt)), 180),
            'author' => [
                'id' => (int) $post->post_author,
                'name' => (string) ($post->author_name ?: 'Tanpa nama'),
            ],
            'thumbnail' => $this->assets->publicUrl($post->thumbnail_path),
            'featured_media_id' => isset($post->featured_media_id) && (int) $post->featured_media_id > 0
                ? (int) $post->featured_media_id
                : null,
        ];
    }

    /**
     * @return array{categories:list<array{id:int,name:string,slug:string}>,tags:list<array{id:int,name:string,slug:string}>}
     */
    private function termsForPost(int $postId): array
    {
        $terms = $this->tables->connection()
            ->table($this->tables->table('term_relationships') . ' as relationships')
            ->join($this->tables->table('term_taxonomy') . ' as taxonomy', 'taxonomy.term_taxonomy_id', '=', 'relationships.term_taxonomy_id')
            ->join($this->tables->table('terms') . ' as terms', 'terms.term_id', '=', 'taxonomy.term_id')
            ->where('relationships.object_id', $postId)
            ->whereIn('taxonomy.taxonomy', ['category', 'post_tag'])
            ->select(['terms.term_id', 'terms.name', 'terms.slug', 'taxonomy.taxonomy'])
            ->get();

        return [
            'categories' => $terms->where('taxonomy', 'category')->map(fn ($term) => [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ])->values()->all(),
            'tags' => $terms->where('taxonomy', 'post_tag')->map(fn ($term) => [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ])->values()->all(),
        ];
    }
}
