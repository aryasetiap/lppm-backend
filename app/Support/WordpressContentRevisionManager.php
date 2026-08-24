<?php

namespace App\Support;

use DateTimeZone;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Creates and restores CMS-owned snapshots using standard WordPress revision
 * rows. The accompanying snapshot meta preserves fields WordPress revisions
 * do not normally carry (taxonomy and featured image) without touching
 * unrelated legacy revision metadata.
 */
final class WordpressContentRevisionManager
{
    private const SNAPSHOT_META_KEY = '_lppm_revision_snapshot';

    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressContentAuthorization $authorization,
        private readonly CmsAuditLogger $audit
    ) {
    }

    /**
     * Called inside the parent content transaction, immediately before an
     * editable content state is changed. The parent must contain the listed
     * standard post fields.
     *
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     */
    public function captureRevision(object $post, array $actor): int
    {
        $postId = (int) $post->ID;
        $dates = $this->wordpressDates();
        $revisionId = (int) $this->tables->connection()->table($this->tables->table('posts'))->insertGetId([
            'post_author' => $actor['id'],
            'post_date' => $dates['local'],
            'post_date_gmt' => $dates['gmt'],
            'post_content' => (string) $post->post_content,
            'post_title' => (string) $post->post_title,
            'post_excerpt' => (string) $post->post_excerpt,
            'post_status' => 'inherit',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $postId . '-revision-v1',
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $dates['local'],
            'post_modified_gmt' => $dates['gmt'],
            'post_content_filtered' => '',
            'post_parent' => $postId,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => 'revision',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);
        $this->setMeta($revisionId, self::SNAPSHOT_META_KEY, json_encode(
            $this->snapshotForContent($post),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $this->audit->contentMutation('cms.content.revision_created', $actor, [
            'content_id' => $postId,
            'content_type' => (string) $post->post_type,
            'revision_id' => $revisionId,
            'status' => (string) $post->post_status,
        ]);

        return $revisionId;
    }

    /**
     * Backward-compatible name for the draft revision flow. New callers that
     * may handle published news should use captureRevision().
     *
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     */
    public function captureDraftRevision(object $post, array $actor): int
    {
        return $this->captureRevision($post, $actor);
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return list<array{id:int,title:string,created_at:string,author:string,can_restore:bool}>
     */
    public function listForDraft(int $postId, array $actor): array
    {
        $post = $this->contentForAuthorization($postId);
        $this->authorization->ensureCanEditDraft($actor, $post);

        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $usersTable = $this->tables->table('users');
        return $this->tables->connection()->table("{$postsTable} as revision")
            ->leftJoin("{$usersTable} as author", 'author.ID', '=', 'revision.post_author')
            ->leftJoin("{$postmetaTable} as snapshot", function ($join) {
                $join->on('snapshot.post_id', '=', 'revision.ID')
                    ->where('snapshot.meta_key', self::SNAPSHOT_META_KEY);
            })
            ->where('revision.post_parent', $postId)
            ->where('revision.post_type', 'revision')
            ->orderByDesc('revision.ID')
            ->limit(50)
            ->get([
                'revision.ID',
                'revision.post_title',
                'revision.post_modified',
                'author.display_name as author_name',
                'snapshot.meta_value as snapshot',
            ])
            ->map(fn (object $revision): array => [
                'id' => (int) $revision->ID,
                'title' => (string) $revision->post_title,
                'created_at' => (string) $revision->post_modified,
                'author' => (string) ($revision->author_name ?: 'Tanpa nama'),
                'can_restore' => $this->decodeSnapshot($revision->snapshot, false) !== null,
            ])
            ->all();
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return array{id:int,type:string,status:string,modified_at:string,restored_revision_id:int}
     */
    public function restoreDraftRevision(int $postId, int $revisionId, array $actor, string $expectedModifiedAt): array
    {
        return $this->tables->connection()->transaction(function () use ($postId, $revisionId, $actor, $expectedModifiedAt) {
            $post = $this->lockedContent($postId);
            $this->authorization->ensureCanEditDraft($actor, $post);
            if ((string) $post->post_modified !== $expectedModifiedAt) {
                throw new ConflictHttpException('Konten telah diubah oleh pengguna lain. Muat ulang sebelum memulihkan revisi.');
            }

            $revision = $this->tables->connection()->table($this->tables->table('posts'))
                ->select(['ID', 'post_parent', 'post_type'])
                ->where('ID', $revisionId)
                ->where('post_parent', $postId)
                ->where('post_type', 'revision')
                ->lockForUpdate()
                ->first();
            if ($revision === null) {
                throw new NotFoundHttpException('Revisi tidak ditemukan untuk konten ini.');
            }

            $snapshot = $this->revisionSnapshot($revisionId);
            // Preserve the state being replaced so restore itself can be undone.
            $this->captureDraftRevision($post, $actor);

            $dates = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $postId)->update([
                'post_title' => $snapshot['title'],
                'post_content' => $snapshot['content'],
                'post_excerpt' => $snapshot['excerpt'],
                'post_name' => $snapshot['slug'],
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            $this->replaceTerms($postId, 'category', $snapshot['category_ids']);
            $this->replaceTerms($postId, 'post_tag', $snapshot['tag_ids']);
            $this->replaceFeaturedMedia($postId, $snapshot['featured_media_id']);
            $this->setMeta($postId, '_edit_last', (string) $actor['id']);

            $result = [
                'id' => $postId,
                'type' => (string) $post->post_type,
                'status' => 'draft',
                'modified_at' => $dates['local'],
                'restored_revision_id' => $revisionId,
            ];
            $this->audit->contentMutation('cms.content.revision_restored', $actor, [
                'content_id' => $postId,
                'content_type' => $result['type'],
                'revision_id' => $revisionId,
                'status' => 'draft',
            ]);

            return $result;
        });
    }

    /** @return array{version:int,title:string,content:string,excerpt:string,slug:string,category_ids:list<int>,tag_ids:list<int>,featured_media_id:?int} */
    private function snapshotForContent(object $post): array
    {
        $postId = (int) $post->ID;
        $terms = $this->termIdsForContent($postId);
        $featured = $this->tables->connection()->table($this->tables->table('postmeta'))
            ->where('post_id', $postId)
            ->where('meta_key', '_thumbnail_id')
            ->orderByDesc('meta_id')
            ->value('meta_value');

        return [
            'version' => 1,
            'title' => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'slug' => (string) $post->post_name,
            'category_ids' => $terms['category'],
            'tag_ids' => $terms['post_tag'],
            'featured_media_id' => is_numeric($featured) && (int) $featured > 0 ? (int) $featured : null,
        ];
    }

    /** @return array{category:list<int>,post_tag:list<int>} */
    private function termIdsForContent(int $postId): array
    {
        $terms = $this->tables->connection()->table($this->tables->table('term_relationships') . ' as relationships')
            ->join($this->tables->table('term_taxonomy') . ' as taxonomy', 'taxonomy.term_taxonomy_id', '=', 'relationships.term_taxonomy_id')
            ->where('relationships.object_id', $postId)
            ->whereIn('taxonomy.taxonomy', ['category', 'post_tag'])
            ->get(['taxonomy.taxonomy', 'taxonomy.term_id']);

        return [
            'category' => $terms->where('taxonomy', 'category')->pluck('term_id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
            'post_tag' => $terms->where('taxonomy', 'post_tag')->pluck('term_id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
        ];
    }

    /** @return array{title:string,content:string,excerpt:string,slug:string,category_ids:list<int>,tag_ids:list<int>,featured_media_id:?int} */
    private function revisionSnapshot(int $revisionId): array
    {
        $encoded = $this->tables->connection()->table($this->tables->table('postmeta'))
            ->where('post_id', $revisionId)
            ->where('meta_key', self::SNAPSHOT_META_KEY)
            ->orderByDesc('meta_id')
            ->value('meta_value');
        $snapshot = $this->decodeSnapshot($encoded, true);

        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'revision' => 'Revisi ini bukan snapshot yang dapat dipulihkan oleh CMS LPPM.',
            ]);
        }

        return $snapshot;
    }

    /** @return array{title:string,content:string,excerpt:string,slug:string,category_ids:list<int>,tag_ids:list<int>,featured_media_id:?int}|null */
    private function decodeSnapshot(mixed $encoded, bool $throwOnInvalid): ?array
    {
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        try {
            $snapshot = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($throwOnInvalid) {
                throw ValidationException::withMessages(['revision' => 'Format snapshot revisi tidak valid.']);
            }

            return null;
        }

        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1) {
            return null;
        }
        foreach (['title', 'content', 'excerpt', 'slug'] as $field) {
            if (!is_string($snapshot[$field] ?? null)) {
                return null;
            }
        }
        $categories = $this->validTermIds($snapshot['category_ids'] ?? null);
        $tags = $this->validTermIds($snapshot['tag_ids'] ?? null);
        $featured = $snapshot['featured_media_id'] ?? null;
        if ($categories === null || $tags === null || !($featured === null || (is_int($featured) && $featured > 0))) {
            return null;
        }

        return [
            'title' => $snapshot['title'],
            'content' => $snapshot['content'],
            'excerpt' => $snapshot['excerpt'],
            'slug' => $snapshot['slug'],
            'category_ids' => $categories,
            'tag_ids' => $tags,
            'featured_media_id' => $featured,
        ];
    }

    /** @return list<int>|null */
    private function validTermIds(mixed $ids): ?array
    {
        if (!is_array($ids)) {
            return null;
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                return null;
            }
        }

        return array_values(array_unique($ids));
    }

    private function replaceTerms(int $postId, string $taxonomy, array $termIds): void
    {
        $taxonomyTable = $this->tables->table('term_taxonomy');
        $relationshipsTable = $this->tables->table('term_relationships');
        $taxonomyRows = collect();
        if ($termIds !== []) {
            $taxonomyRows = $this->tables->connection()->table($taxonomyTable)
                ->where('taxonomy', $taxonomy)
                ->whereIn('term_id', $termIds)
                ->select(['term_taxonomy_id', 'term_id'])
                ->get();
            if ($taxonomyRows->count() !== count($termIds)) {
                throw ValidationException::withMessages(['revision' => 'Taxonomy pada snapshot revisi tidak lagi valid.']);
            }
        }

        $existingTaxonomyIds = $this->tables->connection()->table($taxonomyTable)
            ->where('taxonomy', $taxonomy)
            ->pluck('term_taxonomy_id')
            ->all();
        if ($existingTaxonomyIds !== []) {
            $this->tables->connection()->table($relationshipsTable)
                ->where('object_id', $postId)
                ->whereIn('term_taxonomy_id', $existingTaxonomyIds)
                ->delete();
        }
        foreach ($taxonomyRows as $row) {
            $this->tables->connection()->table($relationshipsTable)->insert([
                'object_id' => $postId,
                'term_taxonomy_id' => $row->term_taxonomy_id,
                'term_order' => 0,
            ]);
        }
    }

    private function replaceFeaturedMedia(int $postId, ?int $mediaId): void
    {
        $meta = $this->tables->connection()->table($this->tables->table('postmeta'));
        $meta->where('post_id', $postId)->where('meta_key', '_thumbnail_id')->delete();
        if ($mediaId !== null) {
            $meta->insert(['post_id' => $postId, 'meta_key' => '_thumbnail_id', 'meta_value' => (string) $mediaId]);
        }
    }

    private function contentForAuthorization(int $postId): object
    {
        $post = $this->tables->connection()->table($this->tables->table('posts'))
            ->select(['ID', 'post_author', 'post_type', 'post_status'])
            ->where('ID', $postId)
            ->whereIn('post_type', ['post', 'page'])
            ->first();
        if ($post === null) {
            throw new NotFoundHttpException('Konten tidak ditemukan.');
        }

        return $post;
    }

    private function lockedContent(int $postId): object
    {
        $post = $this->tables->connection()->table($this->tables->table('posts'))
            ->select(['ID', 'post_author', 'post_type', 'post_status', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_modified'])
            ->where('ID', $postId)
            ->whereIn('post_type', ['post', 'page'])
            ->lockForUpdate()
            ->first();
        if ($post === null) {
            throw new NotFoundHttpException('Konten tidak ditemukan.');
        }

        return $post;
    }

    private function setMeta(int $postId, string $key, string $value): void
    {
        $this->tables->connection()->table($this->tables->table('postmeta'))->updateOrInsert(
            ['post_id' => $postId, 'meta_key' => $key],
            ['meta_value' => $value]
        );
    }

    /** @return array{local:string,gmt:string} */
    private function wordpressDates(): array
    {
        $utc = now('UTC');
        $local = $utc->copy()->setTimezone($this->wordpressTimezone());

        return ['local' => $local->format('Y-m-d H:i:s'), 'gmt' => $utc->format('Y-m-d H:i:s')];
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
