<?php

namespace App\Http\Middleware;

use App\Support\WordpressAdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    public function __construct(private readonly WordpressAdminSession $sessions)
    {
    }

    /**
     * Handle an incoming request.
     * Cek Bearer token dari header Authorization.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $admin = $this->sessions->resolve($token);
        if ($admin === null) {
            return response()->json([
                'meta' => [
                    'code' => 401,
                    'status' => 'error',
                    'message' => 'Sesi admin tidak valid atau telah berakhir.',
                ],
            ], 401);
        }

        // The profile is resolved during login from WordPress role/capability
        // data and remains server-side in the short-lived Laravel session.
        $request->attributes->set('admin_session', $admin);

        return $next($request);
    }
}
