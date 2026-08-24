<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressImageUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Uploads a new image as a WordPress-compatible attachment. */
class AdminMediaUploadController extends Controller
{
    public function __construct(private readonly WordpressImageUploader $uploader)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
            'title' => ['nullable', 'string', 'max:500'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->uploader->upload(
            $actor,
            $request->file('image'),
            $payload['title'] ?? null,
            $payload['alt_text'] ?? null
        );

        return response()->json([
            'meta' => [
                'code' => 201,
                'status' => 'success',
                'message' => 'Gambar berhasil diunggah ke pustaka media.',
            ],
            'data' => $result,
        ], 201);
    }
}
