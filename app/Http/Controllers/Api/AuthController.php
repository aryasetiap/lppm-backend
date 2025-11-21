<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressPasswordHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Endpoint login admin WordPress lama.
     *
     * Request body:
     * - username (user_login atau email)
     * - password
     */
    public function login(Request $request, WordpressPasswordHasher $hasher): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = DB::connection('wordpress')
            ->table('2022_users')
            ->where(function ($query) use ($credentials) {
                $query->where('user_login', $credentials['username'])
                    ->orWhere('user_email', $credentials['username']);
            })
            ->first();

        if (!$user || !$hasher->check($credentials['password'], $user->user_pass)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password salah.',
            ], 401);
        }

        if (!$this->isAdministrator($user->ID)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Akun ini bukan administrator.',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->ID,
                'username' => $user->user_login,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
            ],
            'meta' => [
                'token' => hash('sha256', Str::random(40) . $user->ID . microtime()),
                'login_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Cek apakah user WordPress memiliki peran administrator.
     */
    private function isAdministrator(int $userId): bool
    {
        $metaKeys = [
            '2022_capabilities',
            'wp_capabilities',
            'lppm_capabilities',
        ];

        $serialized = DB::connection('wordpress')
            ->table('2022_usermeta')
            ->where('user_id', $userId)
            ->whereIn('meta_key', $metaKeys)
            ->value('meta_value');

        if (!$serialized) {
            return false;
        }

        $roles = @unserialize($serialized);

        if (is_array($roles)) {
            return isset($roles['administrator']) && $roles['administrator'];
        }

        return str_contains($serialized, 'administrator');
    }
}

