<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LegacyHtmlSanitizer;
use App\Support\WordpressDocumentWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Draft-only mutations for the mapped wpdmpro package contract. */
final class AdminCmsDocumentMutationController extends Controller
{
    public function __construct(
        private readonly WordpressDocumentWriter $writer,
        private readonly LegacyHtmlSanitizer $sanitizer
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->sanitize($this->normalizeTitle($request->validate([
            'title' => ['required', 'string', 'max:500'],
            'content' => ['nullable', 'string', 'max:2000000'],
            'excerpt' => ['nullable', 'string', 'max:100000'],
            'slug' => ['nullable', 'string', 'max:200'],
            'category_ids' => ['sometimes', 'array', 'max:30'],
            'category_ids.*' => ['integer', 'min:1'],
        ])));
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Draft dokumen berhasil dibuat.'],
            'data' => $this->writer->createDraft($actor, $payload),
        ], 201);
    }

    public function update(Request $request, int $document): JsonResponse
    {
        $payload = $this->sanitize($this->normalizeTitle($request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'title' => ['sometimes', 'required', 'string', 'max:500'],
            'content' => ['sometimes', 'nullable', 'string', 'max:2000000'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category_ids' => ['sometimes', 'array', 'max:30'],
            'category_ids.*' => ['integer', 'min:1'],
        ])));
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success', 'message' => 'Draft dokumen berhasil disimpan.'],
            'data' => $this->writer->updateDraft($document, $actor, $payload),
        ]);
    }

    public function upload(Request $request, int $document): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:51200']]);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 201, 'status' => 'success', 'message' => 'File draft dokumen berhasil diunggah.'],
            'data' => $this->writer->uploadDraftFile($document, $actor, $request->file('file')),
        ], 201);
    }

    public function publish(Request $request, int $document): JsonResponse
    {
        $payload = $request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'access' => ['required', 'in:guest,administrator'],
        ]);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success', 'message' => 'Dokumen berhasil diterbitkan.'],
            'data' => $this->writer->publishDraft($document, $actor, $payload),
        ]);
    }

    public function updateAccess(Request $request, int $document): JsonResponse
    {
        $payload = $request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'access' => ['required', 'in:guest,administrator'],
        ]);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success', 'message' => 'Akses dokumen berhasil diperbarui.'],
            'data' => $this->writer->updatePublishedAccess($document, $actor, $payload),
        ]);
    }

    public function trash(Request $request, int $document): JsonResponse
    {
        $payload = $request->validate(['expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s']]);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success', 'message' => 'Dokumen dipindahkan ke Sampah.'],
            'data' => $this->writer->trash($document, $actor, $payload),
        ]);
    }

    public function restore(Request $request, int $document): JsonResponse
    {
        $payload = $request->validate(['expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s']]);
        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success', 'message' => 'Dokumen berhasil dipulihkan.'],
            'data' => $this->writer->restore($document, $actor, $payload),
        ]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function sanitize(array $payload): array
    {
        if (array_key_exists('content', $payload)) {
            $payload['content'] = $this->sanitizer->sanitize((string) ($payload['content'] ?? ''));
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizeTitle(array $payload): array
    {
        if (!array_key_exists('title', $payload)) {
            return $payload;
        }
        $payload['title'] = trim((string) $payload['title']);
        if ($payload['title'] === '') {
            throw ValidationException::withMessages(['title' => 'Judul dokumen wajib diisi.']);
        }

        return $payload;
    }
}
