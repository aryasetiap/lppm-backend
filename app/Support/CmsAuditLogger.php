<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/** Writes CMS mutation events without creating a new database table. */
final class CmsAuditLogger
{
    /**
     * @param array{id:int,username:string} $actor
     * @param array<string,int|string> $context
     */
    public function contentMutation(string $event, array $actor, array $context): void
    {
        Log::channel('cms_audit')->info($event, [
            'actor_id' => $actor['id'],
            'actor_username' => $actor['username'],
            ...$context,
        ]);
    }

    /** @param array<string,int|string> $context */
    public function systemContentMutation(string $event, array $context): void
    {
        Log::channel('cms_audit')->info($event, [
            'actor_id' => null,
            'actor_username' => 'laravel-scheduler',
            ...$context,
        ]);
    }
}
