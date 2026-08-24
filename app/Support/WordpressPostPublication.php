<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Handles the WordPress-standard lifecycle transition for news only.
 *
 * A post remains a draft until a user explicitly publishes or schedules it.
 * This class does not implement page publication, trash, or permanent delete.
 */
final class WordpressPostPublication
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressContentAuthorization $authorization,
        private readonly CmsAuditLogger $audit
    ) {
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return array{id:int,status:string,published_at:string,modified_at:string}
     */
    public function publishNow(int $postId, array $actor, string $expectedModifiedAt): array
    {
        return $this->tables->connection()->transaction(function () use ($postId, $actor, $expectedModifiedAt) {
            $post = $this->lockedPost($postId);
            $this->assertExpectedVersion($post, $expectedModifiedAt);
            $this->authorization->ensureCanPublishPost($actor, $post);
            $this->assertDraft($post);

            $dates = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $postId)->update([
                'post_status' => 'publish',
                'post_date' => $dates['local'],
                'post_date_gmt' => $dates['gmt'],
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            $this->adjustPublishedTermCounts($postId, 1);

            $result = [
                'id' => $postId,
                'status' => 'publish',
                'published_at' => $dates['local'],
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.content.published', $actor, [
                'content_id' => $postId,
                'content_type' => 'post',
                'status' => 'publish',
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return array{id:int,status:string,published_at:string,modified_at:string}
     */
    public function schedule(int $postId, array $actor, string $expectedModifiedAt, string $scheduledAt): array
    {
        return $this->tables->connection()->transaction(function () use ($postId, $actor, $expectedModifiedAt, $scheduledAt) {
            $post = $this->lockedPost($postId);
            $this->assertExpectedVersion($post, $expectedModifiedAt);
            $this->authorization->ensureCanPublishPost($actor, $post);
            $this->assertDraft($post);

            $scheduled = $this->scheduledDates($scheduledAt);
            $now = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $postId)->update([
                'post_status' => 'future',
                'post_date' => $scheduled['local'],
                'post_date_gmt' => $scheduled['gmt'],
                'post_modified' => $now['local'],
                'post_modified_gmt' => $now['gmt'],
            ]);

            $result = [
                'id' => $postId,
                'status' => 'future',
                'published_at' => $scheduled['local'],
                'modified_at' => $now['local'],
            ];
            $this->audit->contentMutation('cms.content.scheduled', $actor, [
                'content_id' => $postId,
                'content_type' => 'post',
                'status' => 'future',
                'scheduled_at' => $scheduled['local'],
            ]);

            return $result;
        });
    }

    /** Publishes due, already-scheduled news. Used only by Laravel's scheduler. */
    public function publishDue(): int
    {
        $postsTable = $this->tables->table('posts');
        $nowGmt = now('UTC')->format('Y-m-d H:i:s');
        $postIds = $this->tables->connection()->table($postsTable)
            ->where('post_type', 'post')
            ->where('post_status', 'future')
            ->where('post_date_gmt', '<=', $nowGmt)
            ->orderBy('ID')
            ->pluck('ID')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $published = 0;
        foreach ($postIds as $postId) {
            $wasPublished = $this->tables->connection()->transaction(function () use ($postId): bool {
                $post = $this->lockedPost($postId);
                if (
                    (string) $post->post_status !== 'future'
                    || (string) $post->post_date_gmt > now('UTC')->format('Y-m-d H:i:s')
                ) {
                    return false;
                }

                $dates = $this->wordpressDates();
                $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $postId)->update([
                    'post_status' => 'publish',
                    'post_modified' => $dates['local'],
                    'post_modified_gmt' => $dates['gmt'],
                ]);
                $this->adjustPublishedTermCounts($postId, 1);
                $this->audit->systemContentMutation('cms.content.scheduled_published', [
                    'content_id' => $postId,
                    'content_type' => 'post',
                    'status' => 'publish',
                ]);

                return true;
            });

            if ($wasPublished) {
                $published++;
            }
        }

        return $published;
    }

    private function lockedPost(int $postId): object
    {
        $post = $this->tables->connection()->table($this->tables->table('posts'))
            ->select(['ID', 'post_author', 'post_type', 'post_status', 'post_modified', 'post_date_gmt'])
            ->where('ID', $postId)
            ->where('post_type', 'post')
            ->lockForUpdate()
            ->first();

        if ($post === null) {
            throw new NotFoundHttpException('Berita tidak ditemukan.');
        }

        return $post;
    }

    private function assertExpectedVersion(object $post, string $expectedModifiedAt): void
    {
        if ((string) $post->post_modified !== $expectedModifiedAt) {
            throw new ConflictHttpException('Berita telah diubah oleh pengguna lain. Muat ulang sebelum menerbitkan.');
        }
    }

    private function assertDraft(object $post): void
    {
        if ((string) $post->post_status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Hanya berita berstatus draft yang dapat diterbitkan atau dijadwalkan pada checkpoint ini.',
            ]);
        }
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

    /** @return array{local:string,gmt:string} */
    private function scheduledDates(string $scheduledAt): array
    {
        $timezone = $this->wordpressTimezone();
        $scheduled = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $scheduledAt, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($scheduled === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Waktu jadwal tidak valid.',
            ]);
        }

        if ($scheduled <= new DateTimeImmutable('now', $timezone)) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'Waktu jadwal harus berada di masa depan.',
            ]);
        }

        return [
            'local' => $scheduled->format('Y-m-d H:i:s'),
            'gmt' => $scheduled->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
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
