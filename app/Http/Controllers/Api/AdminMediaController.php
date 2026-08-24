<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAssetResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Read-only media library backed by WordPress attachment records.
 */
class AdminMediaController extends Controller
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressAssetResolver $assets
    ) {
    }

    /**
     * GET /api/admin/media?kind=image|document&search=&status=inherit&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['nullable', 'in:image,document'],
            'status' => ['nullable', 'in:inherit,trash'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->baseQuery($filters)
            ->orderByDesc('p.post_date')
            ->orderByDesc('p.ID')
            ->paginate((int) ($filters['per_page'] ?? 20));

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
            'data' => $paginator->getCollection()->map(fn ($media) => $this->mediaItem($media)),
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    }

    /**
     * GET /api/admin/media/{media}
     */
    public function show(int $media): JsonResponse
    {
        $item = $this->baseQuery([])
            ->where('p.ID', $media)
            ->first();

        if ($item === null) {
            return response()->json([
                'meta' => [
                    'code' => 404,
                    'status' => 'error',
                    'message' => 'Media tidak ditemukan.',
                ],
            ], 404);
        }

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
            ],
            'data' => $this->mediaItem($item),
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    }

    /**
     * @param array{kind?:string,status?:string,search?:string,per_page?:int} $filters
     */
    private function baseQuery(array $filters)
    {
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');

        $query = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select([
                'p.ID',
                'p.post_title',
                'p.post_name',
                'p.post_status',
                'p.post_date',
                'p.post_modified',
                'p.post_parent',
                'p.post_mime_type',
                'p.post_excerpt',
                'file_meta.meta_value as attachment_path',
                'alt_meta.meta_value as alt_text',
            ])
            ->leftJoin("{$postmetaTable} as file_meta", function ($join) {
                $join->on('file_meta.post_id', '=', 'p.ID')
                    ->where('file_meta.meta_key', '_wp_attached_file');
            })
            ->leftJoin("{$postmetaTable} as alt_meta", function ($join) {
                $join->on('alt_meta.post_id', '=', 'p.ID')
                    ->where('alt_meta.meta_key', '_wp_attachment_image_alt');
            })
            ->where('p.post_type', 'attachment');

        if (isset($filters['status'])) {
            $query->where('p.post_status', $filters['status']);
        } else {
            $query->whereIn('p.post_status', ['inherit', 'trash']);
        }

        if (($filters['kind'] ?? null) === 'image') {
            $query->where('p.post_mime_type', 'like', 'image/%');
        }

        if (($filters['kind'] ?? null) === 'document') {
            $query->where('p.post_mime_type', 'not like', 'image/%');
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->where(function ($inner) use ($search) {
                $inner->where('p.post_title', 'like', '%' . $search . '%')
                    ->orWhere('file_meta.meta_value', 'like', '%' . $search . '%');
            });
        }

        return $query;
    }

    /**
     * @return array{id:int,title:string,slug:string,status:string,mime:string,kind:string,date:string,modified_at:string,parent_id:int,excerpt:string,alt_text:string,file:array{reference:?string,url:?string,exists:?bool}}
     */
    private function mediaItem(object $media): array
    {
        $mime = (string) $media->post_mime_type;
        $path = is_string($media->attachment_path) ? $media->attachment_path : null;

        return [
            'id' => (int) $media->ID,
            'title' => (string) $media->post_title,
            'slug' => (string) $media->post_name,
            'status' => (string) $media->post_status,
            'mime' => $mime,
            'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document',
            'date' => (string) $media->post_date,
            'modified_at' => (string) $media->post_modified,
            'parent_id' => (int) $media->post_parent,
            'excerpt' => Str::limit(trim(strip_tags((string) $media->post_excerpt)), 180),
            'alt_text' => (string) ($media->alt_text ?? ''),
            'file' => [
                'reference' => $this->assets->normalizeRelativePath($path),
                'url' => $this->assets->publicUrl($path),
                'exists' => $this->assets->exists($path),
            ],
        ];
    }
}
