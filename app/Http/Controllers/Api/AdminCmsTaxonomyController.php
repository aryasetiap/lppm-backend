<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressTaxonomyWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mutation endpoints for managed legacy WordPress taxonomies. */
final class AdminCmsTaxonomyController extends Controller
{
    public function __construct(private readonly WordpressTaxonomyWriter $writer)
    {
    }

    public function store(Request $request, string $taxonomy): JsonResponse
    {
        $payload = $this->validatedPayload($request, $taxonomy);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->writer->create($taxonomy, $actor, $payload);

        return response()->json([
            'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Taxonomy berhasil dibuat.'],
            'data' => $result,
        ], 201);
    }

    public function update(Request $request, string $taxonomy, int $term): JsonResponse
    {
        $payload = $this->validatedPayload($request, $taxonomy);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->writer->update($taxonomy, $term, $actor, $payload);

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success', 'message' => 'Taxonomy berhasil diperbarui.'],
            'data' => $result,
        ]);
    }

    /** @return array{name:string,slug?:string|null,description?:string|null,parent_id?:int|null} */
    private function validatedPayload(Request $request, string $taxonomy): array
    {
        if (!in_array($taxonomy, ['categories', 'tags', 'document-categories'], true)) {
            abort(404);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'parent_id' => in_array($taxonomy, ['categories', 'document-categories'], true)
                ? ['nullable', 'integer', 'min:1']
                : ['prohibited'],
        ]);
    }
}
