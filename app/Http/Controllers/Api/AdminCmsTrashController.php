<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressContentTrash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reversible content trash and restore endpoints. */
final class AdminCmsTrashController extends Controller
{
    public function __construct(private readonly WordpressContentTrash $trash)
    {
    }

    public function trash(Request $request, int $post): JsonResponse
    {
        $payload = $request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->trash->trash($post, $actor, $payload['expected_modified_at']);

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'message' => 'Konten dipindahkan ke Sampah. File dan data legacy lain tidak dihapus.',
            ],
            'data' => $result,
        ]);
    }

    public function restore(Request $request, int $post): JsonResponse
    {
        $payload = $request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->trash->restore($post, $actor, $payload['expected_modified_at']);

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'message' => 'Konten dipulihkan ke status sebelumnya.',
            ],
            'data' => $result,
        ]);
    }
}
