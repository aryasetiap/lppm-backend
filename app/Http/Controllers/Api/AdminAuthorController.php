<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only list of authors who own post/page content.
 *
 * This is not a user-management endpoint. It intentionally exposes neither
 * email addresses, password hashes, roles, nor arbitrary user metadata.
 */
class AdminAuthorController extends Controller
{
    public function __construct(private readonly WordpressTableResolver $tables)
    {
    }

    /**
     * GET /api/admin/authors?type=post&search=&per_page=100
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:post,page'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $postsTable = $this->tables->table('posts');
        $usersTable = $this->tables->table('users');

        $query = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->join("{$usersTable} as u", 'u.ID', '=', 'p.post_author')
            ->whereIn('p.post_type', isset($filters['type']) ? [$filters['type']] : ['post', 'page'])
            ->whereIn('p.post_status', ['publish', 'future', 'draft', 'pending', 'private', 'trash'])
            ->selectRaw('u.ID, u.display_name, COUNT(p.ID) as content_count')
            ->groupBy('u.ID', 'u.display_name');

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $query->where('u.display_name', 'like', '%' . trim($filters['search']) . '%');
        }

        $paginator = $query
            ->orderBy('u.display_name')
            ->paginate((int) ($filters['per_page'] ?? 100));

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
            'data' => $paginator->getCollection()->map(static fn (object $author) => [
                'id' => (int) $author->ID,
                'name' => (string) ($author->display_name ?: 'Tanpa nama'),
                'content_count' => (int) $author->content_count,
            ])->values(),
        ]);
    }
}
