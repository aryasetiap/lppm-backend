<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressContentRevisionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lists and restores CMS-owned WordPress revision snapshots for drafts. */
final class AdminCmsRevisionController extends Controller
{
    public function __construct(private readonly WordpressContentRevisionManager $revisions)
    {
    }

    public function index(Request $request, int $post): JsonResponse
    {
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => $this->revisions->listForDraft($post, $actor),
        ]);
    }

    public function restore(Request $request, int $post, int $revision): JsonResponse
    {
        $payload = $request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->revisions->restoreDraftRevision($post, $revision, $actor, $payload['expected_modified_at']);

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'message' => 'Revisi berhasil dipulihkan. Versi sebelum pemulihan juga disimpan.',
            ],
            'data' => $result,
        ]);
    }
}
