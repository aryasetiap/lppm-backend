<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Stores short-lived admin sessions outside the WordPress database.
 *
 * The bearer token itself is never persisted as a cache key; only its SHA-256
 * digest is used. Clearing Laravel's cache invalidates active sessions safely.
 */
final class WordpressAdminSession
{
    private const CACHE_PREFIX = 'lppm:wordpress-admin-session:';

    /**
     * @param array{id:int,username:string,display_name:string,email:string,roles:list<string>,capabilities?:list<string>} $user
     * @return array{token:string,expires_at:string}
     */
    public function issue(array $user): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        Cache::put($this->cacheKey($token), $user, $expiresAt);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{id:int,username:string,display_name:string,email:string,roles:list<string>,capabilities?:list<string>}|null
     */
    public function resolve(?string $token): ?array
    {
        if (!$this->isWellFormedToken($token)) {
            return null;
        }

        $session = Cache::get($this->cacheKey($token));

        if (!is_array($session) || !isset($session['id'], $session['username'], $session['roles'])) {
            return null;
        }

        return $session;
    }

    public function forget(?string $token): void
    {
        if ($this->isWellFormedToken($token)) {
            Cache::forget($this->cacheKey($token));
        }
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX . hash('sha256', $token);
    }

    private function isWellFormedToken(?string $token): bool
    {
        return is_string($token) && strlen($token) === 64 && ctype_xdigit($token);
    }

    private function ttlMinutes(): int
    {
        return max(5, min(1440, (int) config('services.wordpress.admin_token_ttl_minutes', 120)));
    }
}
