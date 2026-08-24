<?php

namespace App\Support;

use DateTimeZone;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Transactional writer for the standard WordPress post/page schema.
 *
 * This class writes drafts and already-published news. Editing a published
 * news item preserves its publication status; state transitions remain in
 * their dedicated lifecycle services.
 */
final class WordpressContentWriter
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressContentAuthorization $authorization,
        private readonly WordpressAssetResolver $assets,
        private readonly CmsAuditLogger $audit,
        private readonly WordpressContentRevisionManager $revisions
    ) {
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{type:'post'|'page',title:string,content?:string|null,excerpt?:string|null,slug?:string|null,category_ids?:list<int>,tag_ids?:list<int>,featured_media_id?:int|null} $input
     * @return array{id:int,type:string,title:string,slug:string,status:string,modified_at:string}
     */
    public function createDraft(array $actor, array $input): array
    {
        $type = $input['type'];
        $this->authorization->ensureCanCreate($actor, $type);
        $this->assertTermsAllowedForType($type, $input);
        if (array_key_exists('featured_media_id', $input)) {
            $this->assertFeaturedMediaAllowed($input['featured_media_id']);
        }

        return $this->tables->connection()->transaction(function () use ($actor, $input, $type) {
            $postsTable = $this->tables->table('posts');
            $dates = $this->wordpressDates();
            $slug = $this->uniqueSlug($type, (string) ($input['slug'] ?? $input['title']));

            $postId = $this->tables->connection()->table($postsTable)->insertGetId([
                'post_author' => $actor['id'],
                'post_date' => $dates['local'],
                'post_date_gmt' => $dates['gmt'],
                'post_content' => (string) ($input['content'] ?? ''),
                'post_title' => $input['title'],
                'post_excerpt' => (string) ($input['excerpt'] ?? ''),
                'post_status' => 'draft',
                'comment_status' => $this->optionStatus('default_comment_status', 'closed'),
                'ping_status' => $this->optionStatus('default_ping_status', 'closed'),
                'post_password' => '',
                'post_name' => $slug,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => $type,
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);

            // WordPress convention for post GUIDs. It is never used by this
            // application to resolve asset locations.
            $this->tables->connection()->table($postsTable)->where('ID', $postId)->update([
                'guid' => rtrim((string) config('services.wordpress.site_url'), '/') . '/?p=' . $postId,
            ]);
            $this->setEditLast($postId, $actor['id']);
            $this->replaceTerms($postId, 'category', $input['category_ids'] ?? []);
            $this->replaceTerms($postId, 'post_tag', $input['tag_ids'] ?? []);
            if (array_key_exists('featured_media_id', $input)) {
                $this->replaceFeaturedMedia($postId, $input['featured_media_id']);
            }

            $result = [
                'id' => (int) $postId,
                'type' => $type,
                'title' => $input['title'],
                'slug' => $slug,
                'status' => 'draft',
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.content.draft_created', $actor, [
                'content_id' => $result['id'],
                'content_type' => $type,
                'status' => 'draft',
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{expected_modified_at:string,title?:string,content?:string|null,excerpt?:string|null,slug?:string|null,category_ids?:list<int>,tag_ids?:list<int>,featured_media_id?:int|null} $input
     * @return array{id:int,type:string,title:string,slug:string,status:string,modified_at:string}
     */
    public function updateEditablePost(int $postId, array $actor, array $input): array
    {
        return $this->tables->connection()->transaction(function () use ($postId, $actor, $input) {
            $postsTable = $this->tables->table('posts');
            $post = $this->tables->connection()->table($postsTable)
                ->select(['ID', 'post_author', 'post_type', 'post_status', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_modified'])
                ->where('ID', $postId)
                ->whereIn('post_type', ['post', 'page'])
                ->lockForUpdate()
                ->first();

            if ($post === null) {
                throw new NotFoundHttpException('Konten tidak ditemukan.');
            }

            $isPublished = (string) $post->post_status === 'publish';
            if ($isPublished) {
                $this->authorization->ensureCanEditPublishedPost($actor, $post);
            } else {
                $this->authorization->ensureCanEditDraft($actor, $post);
            }
            if ((string) $post->post_modified !== $input['expected_modified_at']) {
                throw new ConflictHttpException('Konten telah diubah oleh pengguna lain. Muat ulang sebelum menyimpan.');
            }

            $this->assertTermsAllowedForType((string) $post->post_type, $input);
            if (array_key_exists('featured_media_id', $input)) {
                $this->assertFeaturedMediaAllowed($input['featured_media_id']);
            }
            $hasChanges = collect(['title', 'content', 'excerpt', 'slug', 'category_ids', 'tag_ids', 'featured_media_id'])
                ->contains(static fn (string $field): bool => array_key_exists($field, $input));
            if (!$hasChanges) {
                throw ValidationException::withMessages([
                    'content' => 'Kirimkan setidaknya satu field yang dapat diubah.',
                ]);
            }

            // Keep the last valid state before replacing any field. This runs
            // in the same transaction as the update for draft and published
            // news alike.
            $this->revisions->captureRevision($post, $actor);

            $dates = $this->wordpressDates();
            $changes = [
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ];
            if (array_key_exists('title', $input)) {
                $changes['post_title'] = $input['title'];
            }
            if (array_key_exists('content', $input)) {
                $changes['post_content'] = (string) ($input['content'] ?? '');
            }
            if (array_key_exists('excerpt', $input)) {
                $changes['post_excerpt'] = (string) ($input['excerpt'] ?? '');
            }
            if (array_key_exists('slug', $input)) {
                $changes['post_name'] = $this->uniqueSlug((string) $post->post_type, (string) $input['slug'], $postId);
            }

            $this->tables->connection()->table($postsTable)->where('ID', $postId)->update($changes);
            if (array_key_exists('category_ids', $input)) {
                $this->replaceTerms($postId, 'category', $input['category_ids'] ?? [], $isPublished);
            }
            if (array_key_exists('tag_ids', $input)) {
                $this->replaceTerms($postId, 'post_tag', $input['tag_ids'] ?? [], $isPublished);
            }
            if (array_key_exists('featured_media_id', $input)) {
                $this->replaceFeaturedMedia($postId, $input['featured_media_id']);
            }
            $this->setEditLast($postId, $actor['id']);

            $result = [
                'id' => $postId,
                'type' => (string) $post->post_type,
                'title' => (string) ($changes['post_title'] ?? $post->post_title),
                'slug' => (string) ($changes['post_name'] ?? $post->post_name),
                'status' => (string) $post->post_status,
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation($isPublished ? 'cms.content.published_updated' : 'cms.content.draft_updated', $actor, [
                'content_id' => $postId,
                'content_type' => $result['type'],
                'status' => $result['status'],
            ]);

            return $result;
        });
    }

    /**
     * @param array<string,mixed> $input
     */
    private function assertTermsAllowedForType(string $type, array $input): void
    {
        if ($type === 'page' && (!empty($input['category_ids']) || !empty($input['tag_ids']))) {
            throw ValidationException::withMessages([
                'category_ids' => 'Halaman tidak memakai kategori atau tag pada skema WordPress standar.',
            ]);
        }
    }

    /**
     * @param list<int> $termIds
     */
    private function replaceTerms(int $postId, string $taxonomy, array $termIds, bool $adjustPublishedCounts = false): void
    {
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
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
                throw ValidationException::withMessages([
                    $taxonomy === 'category' ? 'category_ids' : 'tag_ids' => 'Satu atau lebih taxonomy tidak valid.',
                ]);
            }
        }

        $existingTaxonomyIds = $this->tables->connection()->table($taxonomyTable)
            ->where('taxonomy', $taxonomy)
            ->pluck('term_taxonomy_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($existingTaxonomyIds !== []) {
            $previousRelationshipIds = $this->tables->connection()->table($relationshipsTable)
                ->where('object_id', $postId)
                ->whereIn('term_taxonomy_id', $existingTaxonomyIds)
                ->pluck('term_taxonomy_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($adjustPublishedCounts) {
                $this->adjustTermCounts($previousRelationshipIds, -1);
            }
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

        if ($adjustPublishedCounts) {
            $this->adjustTermCounts(
                $taxonomyRows->pluck('term_taxonomy_id')->map(static fn (mixed $id): int => (int) $id)->all(),
                1
            );
        }

        // WordPress does not count draft post relationships as published terms.
        // Counts are deliberately left unchanged until the publish checkpoint.
    }

    private function setEditLast(int $postId, int $userId): void
    {
        $this->tables->connection()->table($this->tables->table('postmeta'))->updateOrInsert(
            ['post_id' => $postId, 'meta_key' => '_edit_last'],
            ['meta_value' => (string) $userId]
        );
    }

    /** @param list<int> $taxonomyIds */
    private function adjustTermCounts(array $taxonomyIds, int $direction): void
    {
        $taxonomyIds = array_values(array_unique($taxonomyIds));
        if ($taxonomyIds === []) {
            return;
        }

        $query = $this->tables->connection()->table($this->tables->table('term_taxonomy'))
            ->whereIn('term_taxonomy_id', $taxonomyIds);
        if ($direction > 0) {
            $query->increment('count');

            return;
        }

        $query->where('count', '>', 0)->decrement('count');
    }

    private function replaceFeaturedMedia(int $postId, ?int $mediaId): void
    {
        $meta = $this->tables->connection()->table($this->tables->table('postmeta'));
        $meta->where('post_id', $postId)->where('meta_key', '_thumbnail_id')->delete();

        if ($mediaId !== null) {
            $meta->insert([
                'post_id' => $postId,
                'meta_key' => '_thumbnail_id',
                'meta_value' => (string) $mediaId,
            ]);
        }
    }

    private function assertFeaturedMediaAllowed(?int $mediaId): void
    {
        if ($mediaId === null) {
            return;
        }

        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $media = $this->tables->connection()->table("{$postsTable} as p")
            ->leftJoin("{$postmetaTable} as file_meta", function ($join) {
                $join->on('file_meta.post_id', '=', 'p.ID')
                    ->where('file_meta.meta_key', '_wp_attached_file');
            })
            ->where('p.ID', $mediaId)
            ->where('p.post_type', 'attachment')
            ->where('p.post_status', 'inherit')
            ->select(['p.post_mime_type', 'file_meta.meta_value as attachment_path'])
            ->first();

        $path = $media === null ? null : $this->assets->normalizeRelativePath($media->attachment_path);
        if (
            $media === null ||
            !str_starts_with((string) $media->post_mime_type, 'image/') ||
            $path === null ||
            $this->assets->exists($path) !== true
        ) {
            throw ValidationException::withMessages([
                'featured_media_id' => 'Gambar unggulan harus berupa attachment gambar WordPress yang file-nya tersedia.',
            ]);
        }
    }

    private function uniqueSlug(string $type, string $value, ?int $exceptId = null): string
    {
        $base = Str::limit(Str::slug($value), 180, '');
        if ($base === '') {
            $base = 'konten';
        }

        $postsTable = $this->tables->table('posts');
        $candidate = $base;
        $number = 2;
        while (true) {
            $query = $this->tables->connection()->table($postsTable)
                ->where('post_type', $type)
                ->where('post_name', $candidate);
            if ($exceptId !== null) {
                $query->where('ID', '!=', $exceptId);
            }

            if (!$query->exists()) {
                return $candidate;
            }

            $suffix = '-' . $number;
            $candidate = Str::limit($base, 200 - strlen($suffix), '') . $suffix;
            $number++;
        }
    }

    /** @return array{local:string,gmt:string} */
    private function wordpressDates(): array
    {
        $utc = now('UTC');
        $timezone = trim((string) $this->option('timezone_string', ''));

        try {
            $local = $timezone === '' ? null : now(new DateTimeZone($timezone));
        } catch (Throwable) {
            $local = null;
        }

        if ($local === null) {
            $offsetMinutes = (int) round((float) $this->option('gmt_offset', '0') * 60);
            $local = $utc->copy()->addMinutes($offsetMinutes);
        }

        return [
            'local' => $local->format('Y-m-d H:i:s'),
            'gmt' => $utc->format('Y-m-d H:i:s'),
        ];
    }

    private function optionStatus(string $name, string $fallback): string
    {
        $value = (string) $this->option($name, $fallback);

        return in_array($value, ['open', 'closed'], true) ? $value : $fallback;
    }

    private function option(string $name, string $fallback): string
    {
        $value = $this->tables->connection()->table($this->tables->table('options'))
            ->where('option_name', $name)
            ->value('option_value');

        return is_string($value) ? $value : $fallback;
    }
}
