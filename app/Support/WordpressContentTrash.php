<?php

namespace App\Support;

use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * WordPress-compatible reversible trash/restore lifecycle for posts and pages.
 * Files, attachment rows, taxonomies, and unrelated legacy meta are retained.
 */
final class WordpressContentTrash
{
    /** @var list<string> */
    private const RESTORABLE_STATUSES = ['draft', 'publish', 'future', 'pending', 'private'];

    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressContentAuthorization $authorization,
        private readonly CmsAuditLogger $audit
    ) {
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return array{id:int,type:string,status:string,modified_at:string}
     */
    public function trash(int $postId, array $actor, string $expectedModifiedAt): array
    {
        return $this->tables->connection()->transaction(function () use ($postId, $actor, $expectedModifiedAt) {
            $post = $this->lockedContent($postId);
            $this->assertExpectedVersion($post, $expectedModifiedAt);
            $this->authorization->ensureCanTrashOrRestore($actor, $post);

            if ((string) $post->post_status === 'trash') {
                throw ValidationException::withMessages(['status' => 'Konten ini sudah berada di Sampah.']);
            }
            if (!in_array((string) $post->post_status, self::RESTORABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Status konten ini belum dapat dipindahkan ke Sampah.']);
            }

            $dates = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $postId)->update([
                'post_status' => 'trash',
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            $this->setMeta($postId, '_wp_trash_meta_status', (string) $post->post_status);
            $this->setMeta($postId, '_wp_trash_meta_time', (string) time());
            if ((string) $post->post_status === 'publish') {
                $this->adjustPublishedTermCounts($postId, -1);
            }

            $result = [
                'id' => $postId,
                'type' => (string) $post->post_type,
                'status' => 'trash',
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.content.trashed', $actor, [
                'content_id' => $postId,
                'content_type' => $result['type'],
                'status' => 'trash',
                'previous_status' => (string) $post->post_status,
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return array{id:int,type:string,status:string,modified_at:string}
     */
    public function restore(int $postId, array $actor, string $expectedModifiedAt): array
    {
        return $this->tables->connection()->transaction(function () use ($postId, $actor, $expectedModifiedAt) {
            $post = $this->lockedContent($postId);
            $this->assertExpectedVersion($post, $expectedModifiedAt);

            if ((string) $post->post_status !== 'trash') {
                throw ValidationException::withMessages(['status' => 'Hanya konten di Sampah yang dapat dipulihkan.']);
            }

            $restoredStatus = $this->restorableStatus($postId);
            $this->authorization->ensureCanTrashOrRestore($actor, $post, $restoredStatus);
            $dates = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $postId)->update([
                'post_status' => $restoredStatus,
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            $this->deleteMeta($postId, '_wp_trash_meta_status');
            $this->deleteMeta($postId, '_wp_trash_meta_time');
            if ($restoredStatus === 'publish') {
                $this->adjustPublishedTermCounts($postId, 1);
            }

            $result = [
                'id' => $postId,
                'type' => (string) $post->post_type,
                'status' => $restoredStatus,
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.content.restored', $actor, [
                'content_id' => $postId,
                'content_type' => $result['type'],
                'status' => $restoredStatus,
            ]);

            return $result;
        });
    }

    private function lockedContent(int $postId): object
    {
        $post = $this->tables->connection()->table($this->tables->table('posts'))
            ->select(['ID', 'post_author', 'post_type', 'post_status', 'post_modified'])
            ->where('ID', $postId)
            ->whereIn('post_type', ['post', 'page'])
            ->lockForUpdate()
            ->first();

        if ($post === null) {
            throw new NotFoundHttpException('Konten tidak ditemukan.');
        }

        return $post;
    }

    private function assertExpectedVersion(object $post, string $expectedModifiedAt): void
    {
        if ((string) $post->post_modified !== $expectedModifiedAt) {
            throw new ConflictHttpException('Konten telah diubah oleh pengguna lain. Muat ulang sebelum mengubah Sampah.');
        }
    }

    private function restorableStatus(int $postId): string
    {
        $status = $this->tables->connection()->table($this->tables->table('postmeta'))
            ->where('post_id', $postId)
            ->where('meta_key', '_wp_trash_meta_status')
            ->orderByDesc('meta_id')
            ->value('meta_value');

        return is_string($status) && in_array($status, self::RESTORABLE_STATUSES, true)
            ? $status
            : 'draft';
    }

    private function setMeta(int $postId, string $key, string $value): void
    {
        $this->tables->connection()->table($this->tables->table('postmeta'))->updateOrInsert(
            ['post_id' => $postId, 'meta_key' => $key],
            ['meta_value' => $value]
        );
    }

    private function deleteMeta(int $postId, string $key): void
    {
        $this->tables->connection()->table($this->tables->table('postmeta'))
            ->where('post_id', $postId)
            ->where('meta_key', $key)
            ->delete();
    }

    private function adjustPublishedTermCounts(int $postId, int $direction): void
    {
        $taxonomyTable = $this->tables->table('term_taxonomy');
        $relationshipsTable = $this->tables->table('term_relationships');
        $taxonomyIds = $this->tables->connection()->table("{$relationshipsTable} as relationships")
            ->join("{$taxonomyTable} as taxonomy", 'taxonomy.term_taxonomy_id', '=', 'relationships.term_taxonomy_id')
            ->where('relationships.object_id', $postId)
            ->whereIn('taxonomy.taxonomy', ['category', 'post_tag'])
            ->pluck('taxonomy.term_taxonomy_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($taxonomyIds === []) {
            return;
        }

        $query = $this->tables->connection()->table($taxonomyTable)->whereIn('term_taxonomy_id', $taxonomyIds);
        if ($direction > 0) {
            $query->increment('count');

            return;
        }

        $query->where('count', '>', 0)->decrement('count');
    }

    /** @return array{local:string,gmt:string} */
    private function wordpressDates(): array
    {
        $utc = now('UTC');
        $local = $utc->copy()->setTimezone($this->wordpressTimezone());

        return [
            'local' => $local->format('Y-m-d H:i:s'),
            'gmt' => $utc->format('Y-m-d H:i:s'),
        ];
    }

    private function wordpressTimezone(): DateTimeZone
    {
        $configured = trim($this->option('timezone_string', ''));
        if ($configured !== '') {
            try {
                return new DateTimeZone($configured);
            } catch (Throwable) {
                // Fall through to WordPress' legacy gmt_offset setting.
            }
        }

        $offsetMinutes = (int) round((float) $this->option('gmt_offset', '0') * 60);
        $sign = $offsetMinutes < 0 ? '-' : '+';
        $absolute = abs($offsetMinutes);

        return new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60));
    }

    private function option(string $name, string $fallback): string
    {
        $value = $this->tables->connection()->table($this->tables->table('options'))
            ->where('option_name', $name)
            ->value('option_value');

        return is_string($value) ? $value : $fallback;
    }
}
