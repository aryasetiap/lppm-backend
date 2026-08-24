<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAdminSession;
use App\Support\WordpressCapabilityResolver;
use App\Support\WordpressPasswordHasher;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    /**
     * Endpoint login admin WordPress lama.
     *
     * Request body:
     * - username (user_login atau email)
     * - password
     */
    public function login(
        Request $request,
        WordpressPasswordHasher $hasher,
        WordpressTableResolver $tables,
        WordpressCapabilityResolver $capabilities,
        WordpressAdminSession $sessions
    ): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $rateLimitKey = 'admin-login:' . sha1(strtolower($credentials['username']) . '|' . (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terlalu banyak percobaan login. Coba kembali beberapa menit lagi.',
            ], 429)->header('Retry-After', RateLimiter::availableIn($rateLimitKey));
        }

        $user = $tables->connection()
            ->table($tables->table('users'))
            ->where(function ($query) use ($credentials) {
                $query->where('user_login', $credentials['username'])
                    ->orWhere('user_email', $credentials['username']);
            })
            ->first();

        if (!$user || !$hasher->check($credentials['password'], $user->user_pass)) {
            RateLimiter::hit($rateLimitKey, 300);

            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password salah.',
            ], 401);
        }

        $profile = $capabilities->profileForUser((int) $user->ID);
        $roles = $profile['roles'];
        if (!$this->canReadAdmin($roles)) {
            RateLimiter::hit($rateLimitKey, 300);

            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak. Akun ini tidak memiliki peran admin yang diizinkan.',
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);

        $session = $sessions->issue([
            'id' => (int) $user->ID,
            'username' => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
            'email' => (string) $user->user_email,
            'roles' => $roles,
            'capabilities' => $profile['capabilities'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->ID,
                'username' => $user->user_login,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
            ],
            'meta' => [
                'token' => $session['token'],
                'login_at' => now()->toIso8601String(),
                'expires_at' => $session['expires_at'],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var array{id:int,username:string,display_name:string,email:string,roles:list<string>,capabilities?:list<string>} $admin */
        $admin = $request->attributes->get('admin_session');

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $admin['id'],
                'username' => $admin['username'],
                'display_name' => $admin['display_name'],
                'email' => $admin['email'],
                'roles' => $admin['roles'],
            ],
        ]);
    }

    public function logout(Request $request, WordpressAdminSession $sessions): JsonResponse
    {
        $sessions->forget($request->bearerToken());

        return response()->json([
            'status' => 'success',
            'message' => 'Sesi admin telah diakhiri.',
        ]);
    }

    /**
     * @param list<string> $roles
     */
    private function canReadAdmin(array $roles): bool
    {
        $allowedRoles = config('services.wordpress.admin_read_roles', ['administrator']);

        return count(array_intersect($roles, $allowedRoles)) > 0;
    }
}
