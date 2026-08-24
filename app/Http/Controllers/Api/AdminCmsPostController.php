<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LegacyHtmlSanitizer;
use App\Support\WordpressContentWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CMS mutation endpoint for drafts and already-published news. Publication,
 * schedule, trash, and media lifecycle actions remain separate endpoints.
 */
class AdminCmsPostController extends Controller
{
    public function __construct(
        private readonly WordpressContentWriter $writer,
        private readonly LegacyHtmlSanitizer $sanitizer
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->normalizeTitle($request->validate([
            'type' => ['required', 'in:post,page'],
            'title' => ['required', 'string', 'max:500'],
            'content' => ['nullable', 'string', 'max:2000000'],
            'excerpt' => ['nullable', 'string', 'max:100000'],
            'slug' => ['nullable', 'string', 'max:200'],
            'category_ids' => ['sometimes', 'array', 'max:30'],
            'category_ids.*' => ['integer', 'min:1'],
            'tag_ids' => ['sometimes', 'array', 'max:30'],
            'tag_ids.*' => ['integer', 'min:1'],
            'featured_media_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]));
        $payload = $this->sanitizeSubmittedContent($payload);

        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->writer->createDraft($actor, $payload);

        return response()->json([
            'meta' => [
                'code' => 201,
                'status' => 'success',
                'message' => 'Draft berhasil dibuat.',
            ],
            'data' => $result,
        ], 201);
    }

    public function update(Request $request, int $post): JsonResponse
    {
        $payload = $this->normalizeTitle($request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'title' => ['sometimes', 'required', 'string', 'max:500'],
            'content' => ['sometimes', 'nullable', 'string', 'max:2000000'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category_ids' => ['sometimes', 'array', 'max:30'],
            'category_ids.*' => ['integer', 'min:1'],
            'tag_ids' => ['sometimes', 'array', 'max:30'],
            'tag_ids.*' => ['integer', 'min:1'],
            'featured_media_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]));
        $payload = $this->sanitizeSubmittedContent($payload);

        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->writer->updateEditablePost($post, $actor, $payload);

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'message' => $result['status'] === 'publish'
                    ? 'Perubahan berita terbit berhasil disimpan.'
                    : 'Draft berhasil disimpan.',
            ],
            'data' => $result,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sanitizeSubmittedContent(array $payload): array
    {
        if (array_key_exists('content', $payload)) {
            $payload['content'] = $this->sanitizer->sanitize((string) ($payload['content'] ?? ''));
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeTitle(array $payload): array
    {
        if (!array_key_exists('title', $payload)) {
            return $payload;
        }

        $payload['title'] = trim((string) $payload['title']);
        if ($payload['title'] === '') {
            throw ValidationException::withMessages([
                'title' => 'Judul wajib diisi.',
            ]);
        }

        return $payload;
    }
}
