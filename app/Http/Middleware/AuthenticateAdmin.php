<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    /**
     * Handle an incoming request.
     * Cek Bearer token dari header Authorization.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'meta' => [
                    'code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ],
            ], 401);
        }

        // Validasi format token (sha256 = 64 karakter hex)
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return response()->json([
                'meta' => [
                    'code' => 401,
                    'status' => 'error',
                    'message' => 'Token tidak valid',
                ],
            ], 401);
        }

        // TODO: Nanti bisa ditambah validasi token dari database/cache
        // Untuk sekarang, kita cek format token-nya dulu

        return $next($request);
    }
}

