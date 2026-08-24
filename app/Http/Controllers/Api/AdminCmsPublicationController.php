<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressPostPublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Publication endpoints for draft news. */
final class AdminCmsPublicationController extends Controller
{
    public function __construct(private readonly WordpressPostPublication $publication)
    {
    }

    public function publish(Request $request, int $post): JsonResponse
    {
        $payload = $request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->publication->publishNow($post, $actor, $payload['expected_modified_at']);

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'message' => 'Berita telah diterbitkan.',
            ],
            'data' => $result,
        ]);
    }

    public function schedule(Request $request, int $post): JsonResponse
    {
        if (!config('services.wordpress.scheduling_enabled', false)) {
            return response()->json([
                'meta' => [
                    'code' => 403,
                    'status' => 'error',
                    'message' => 'Penjadwalan terbit sementara dinonaktifkan sampai konfigurasi zona waktu dan cron hosting selesai diuji.',
                ],
            ], 403);
        }

        $payload = $request->validate([
            'expected_modified_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'scheduled_at' => ['required', 'date_format:Y-m-d\\TH:i'],
        ]);

        /** @var array{id:int,username:string,capabilities?:list<string>} $actor */
        $actor = $request->attributes->get('admin_session');
        $result = $this->publication->schedule($post, $actor, $payload['expected_modified_at'], $payload['scheduled_at']);

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'message' => 'Jadwal terbit berita telah disimpan.',
            ],
            'data' => $result,
        ]);
    }
}
