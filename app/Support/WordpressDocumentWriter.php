<?php

namespace App\Support;

use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Draft-only writer for the legacy Download Manager package data format.
 *
 * The class owns only WordPress core post fields, wpdmcategory relationships,
 * `_edit_last`, and the two explicit file-reference meta keys. Unknown
 * Download Manager meta remains untouched, so the legacy records keep their
 * historical data even after the plugin runtime is retired.
 */
final class WordpressDocumentWriter
{
    /** @var array<string, list<string>> */
    private const ALLOWED_MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip-compressed'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressContentAuthorization $authorization,
        private readonly CmsAuditLogger $audit,
        private readonly WordpressDocumentResolver $resolver
    ) {
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{title:string,content?:string|null,excerpt?:string|null,slug?:string|null,category_ids?:list<int>} $input
     * @return array{id:int,title:string,slug:string,status:string,modified_at:string}
     */
    public function createDraft(array $actor, array $input): array
    {
        $this->authorization->ensureCanCreateDocument($actor);
        $this->assertDocumentCategories($input['category_ids'] ?? []);

        return $this->tables->connection()->transaction(function () use ($actor, $input): array {
            $postsTable = $this->tables->table('posts');
            $dates = $this->wordpressDates();
            $slug = $this->uniqueSlug((string) ($input['slug'] ?? $input['title']));
            $documentId = (int) $this->tables->connection()->table($postsTable)->insertGetId([
                'post_author' => $actor['id'],
                'post_date' => $dates['local'],
                'post_date_gmt' => $dates['gmt'],
                'post_content' => (string) ($input['content'] ?? ''),
                'post_title' => $input['title'],
                'post_excerpt' => (string) ($input['excerpt'] ?? ''),
                'post_status' => 'draft',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
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
                'post_type' => 'wpdmpro',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);
            $this->tables->connection()->table($postsTable)->where('ID', $documentId)->update([
                'guid' => rtrim((string) config('services.wordpress.site_url'), '/') . '/?p=' . $documentId,
            ]);
            $this->setEditLast($documentId, $actor['id']);
            $this->replaceCategories($documentId, $input['category_ids'] ?? []);

            $result = [
                'id' => $documentId,
                'title' => $input['title'],
                'slug' => $slug,
                'status' => 'draft',
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.document.draft_created', $actor, [
                'document_id' => $documentId,
                'status' => 'draft',
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{expected_modified_at:string,title?:string,content?:string|null,excerpt?:string|null,slug?:string|null,category_ids?:list<int>} $input
     * @return array{id:int,title:string,slug:string,status:string,modified_at:string}
     */
    public function updateDraft(int $documentId, array $actor, array $input): array
    {
        return $this->tables->connection()->transaction(function () use ($documentId, $actor, $input): array {
            $document = $this->lockedDraft($documentId);
            $this->authorization->ensureCanEditDocumentDraft($actor, $document);
            if ((string) $document->post_modified !== $input['expected_modified_at']) {
                throw new ConflictHttpException('Dokumen telah diubah oleh pengguna lain. Muat ulang sebelum menyimpan.');
            }

            $editable = ['title', 'content', 'excerpt', 'slug', 'category_ids'];
            if (!collect($editable)->contains(fn (string $key): bool => array_key_exists($key, $input))) {
                throw ValidationException::withMessages(['document' => 'Kirimkan setidaknya satu field yang dapat diubah.']);
            }
            if (array_key_exists('category_ids', $input)) {
                $this->assertDocumentCategories($input['category_ids'] ?? []);
            }

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
                $changes['post_name'] = $this->uniqueSlug((string) $input['slug'], $documentId);
            }

            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $documentId)->update($changes);
            if (array_key_exists('category_ids', $input)) {
                $this->replaceCategories($documentId, $input['category_ids'] ?? []);
            }
            $this->setEditLast($documentId, $actor['id']);

            $result = [
                'id' => $documentId,
                'title' => (string) ($changes['post_title'] ?? $document->post_title),
                'slug' => (string) ($changes['post_name'] ?? $document->post_name),
                'status' => 'draft',
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.document.draft_updated', $actor, [
                'document_id' => $documentId,
                'status' => 'draft',
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @return array{reference:string,file_name:string,mime:string,size:int,modified_at:string}
     */
    public function uploadDraftFile(int $documentId, array $actor, UploadedFile $file): array
    {
        // Lock and authorize before moving anything into the protected root.
        $document = $this->lockedDraft($documentId);
        $this->authorization->ensureCanUploadDocument($actor, $document);
        [$extension, $mime] = $this->validateFile($file);
        $root = $this->documentUploadRoot();
        $dates = $this->wordpressDates();
        $relativeDirectory = substr($dates['local'], 0, 4) . '/' . substr($dates['local'], 5, 2);
        $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Direktori dokumen tidak dapat dibuat.');
        }

        $base = $this->safeBaseName((string) $file->getClientOriginalName());
        $fileName = $this->uniqueFilename($directory, $base, $extension);
        $destination = $directory . DIRECTORY_SEPARATOR . $fileName;
        $reference = $relativeDirectory . '/' . $fileName;
        $sourceSize = (int) ($file->getSize() ?? 0);

        try {
            $file->move($directory, $fileName);
            $modifiedAt = $this->tables->connection()->transaction(function () use ($documentId, $actor, $reference): string {
                $locked = $this->lockedDraft($documentId);
                $this->authorization->ensureCanUploadDocument($actor, $locked);
                $dates = $this->wordpressDates();
                $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $documentId)->update([
                    'post_modified' => $dates['local'],
                    'post_modified_gmt' => $dates['gmt'],
                ]);
                $meta = $this->tables->connection()->table($this->tables->table('postmeta'));
                $meta->where('post_id', $documentId)
                    ->whereIn('meta_key', ['__lppm_document_file', '__wpdm_files'])
                    ->delete();
                $meta->insert([
                    ['post_id' => $documentId, 'meta_key' => '__lppm_document_file', 'meta_value' => $reference],
                    ['post_id' => $documentId, 'meta_key' => '__wpdm_files', 'meta_value' => serialize([$reference])],
                ]);
                $this->setEditLast($documentId, $actor['id']);

                return $dates['local'];
            });
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                @unlink($destination);
            }
            throw $exception;
        }

        $this->audit->contentMutation('cms.document.draft_file_uploaded', $actor, [
            'document_id' => $documentId,
            'mime' => $mime,
            'size' => $sourceSize,
        ]);

        return [
            'reference' => $reference,
            'file_name' => $fileName,
            'mime' => $mime,
            'size' => $sourceSize,
            'modified_at' => $modifiedAt,
        ];
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{expected_modified_at:string,access:'guest'|'administrator'} $input
     * @return array{id:int,status:string,access:'public'|'restricted',modified_at:string}
     */
    public function publishDraft(int $documentId, array $actor, array $input): array
    {
        $this->authorization->ensureCanPublishDocuments($actor);

        return $this->tables->connection()->transaction(function () use ($documentId, $actor, $input): array {
            $document = $this->lockedDocument($documentId);
            $this->authorization->ensureCanPublishDocument($actor, $document);
            if ((string) $document->post_status !== 'draft') {
                throw ValidationException::withMessages(['document' => 'Hanya draft dokumen yang dapat diterbitkan.']);
            }
            if ((string) $document->post_modified !== $input['expected_modified_at']) {
                throw new ConflictHttpException('Dokumen telah diubah oleh pengguna lain. Muat ulang sebelum menerbitkan.');
            }
            if (!$this->resolver->resolve($this->fileMetadata($documentId))['available']) {
                throw ValidationException::withMessages(['file' => 'Dokumen tidak dapat diterbitkan sebelum satu file valid tersedia.']);
            }

            $dates = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $documentId)->update([
                'post_status' => 'publish',
                'post_date' => $dates['local'],
                'post_date_gmt' => $dates['gmt'],
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            $this->replaceAccess($documentId, $input['access']);
            $this->adjustCategoryCounts($documentId, 1);
            $this->setEditLast($documentId, $actor['id']);
            $result = [
                'id' => $documentId,
                'status' => 'publish',
                'access' => $input['access'] === 'guest' ? 'public' : 'restricted',
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.document.published', $actor, [
                'document_id' => $documentId,
                'access' => $result['access'],
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{expected_modified_at:string,access:'guest'|'administrator'} $input
     * @return array{id:int,status:string,access:'public'|'restricted',modified_at:string}
     */
    public function updatePublishedAccess(int $documentId, array $actor, array $input): array
    {
        $this->authorization->ensureCanPublishDocuments($actor);

        return $this->tables->connection()->transaction(function () use ($documentId, $actor, $input): array {
            $document = $this->lockedDocument($documentId);
            $this->authorization->ensureCanPublishDocument($actor, $document);
            if ((string) $document->post_status !== 'publish') {
                throw ValidationException::withMessages(['document' => 'Akses hanya dapat diubah pada dokumen yang sudah terbit.']);
            }
            if ((string) $document->post_modified !== $input['expected_modified_at']) {
                throw new ConflictHttpException('Dokumen telah diubah oleh pengguna lain. Muat ulang sebelum mengubah akses.');
            }
            if ($input['access'] === 'guest' && !$this->resolver->resolve($this->fileMetadata($documentId))['available']) {
                throw ValidationException::withMessages(['file' => 'Dokumen publik memerlukan satu file valid.']);
            }

            $dates = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $documentId)->update([
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            $this->replaceAccess($documentId, $input['access']);
            $this->setEditLast($documentId, $actor['id']);
            $result = [
                'id' => $documentId,
                'status' => 'publish',
                'access' => $input['access'] === 'guest' ? 'public' : 'restricted',
                'modified_at' => $dates['local'],
            ];
            $this->audit->contentMutation('cms.document.access_updated', $actor, [
                'document_id' => $documentId,
                'access' => $result['access'],
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{expected_modified_at:string} $input
     * @return array{id:int,status:'trash',modified_at:string}
     */
    public function trash(int $documentId, array $actor, array $input): array
    {
        $this->authorization->ensureCanTrashDocuments($actor);

        return $this->tables->connection()->transaction(function () use ($documentId, $actor, $input): array {
            $document = $this->lockedDocument($documentId);
            $this->authorization->ensureCanTrashOrRestoreDocument($actor, $document);
            if ((string) $document->post_status === 'trash') {
                throw ValidationException::withMessages(['document' => 'Dokumen sudah berada di Sampah.']);
            }
            if ((string) $document->post_modified !== $input['expected_modified_at']) {
                throw new ConflictHttpException('Dokumen telah diubah oleh pengguna lain. Muat ulang sebelum memindahkan ke Sampah.');
            }

            $dates = $this->wordpressDates();
            $meta = $this->tables->connection()->table($this->tables->table('postmeta'));
            $meta->where('post_id', $documentId)->whereIn('meta_key', ['_wp_trash_meta_status', '_wp_trash_meta_time'])->delete();
            $meta->insert([
                ['post_id' => $documentId, 'meta_key' => '_wp_trash_meta_status', 'meta_value' => (string) $document->post_status],
                ['post_id' => $documentId, 'meta_key' => '_wp_trash_meta_time', 'meta_value' => (string) time()],
            ]);
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $documentId)->update([
                'post_status' => 'trash',
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            if ((string) $document->post_status === 'publish') {
                $this->adjustCategoryCounts($documentId, -1);
            }
            $this->setEditLast($documentId, $actor['id']);
            $this->audit->contentMutation('cms.document.trashed', $actor, ['document_id' => $documentId]);

            return ['id' => $documentId, 'status' => 'trash', 'modified_at' => $dates['local']];
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{expected_modified_at:string} $input
     * @return array{id:int,status:string,modified_at:string}
     */
    public function restore(int $documentId, array $actor, array $input): array
    {
        $this->authorization->ensureCanTrashDocuments($actor);

        return $this->tables->connection()->transaction(function () use ($documentId, $actor, $input): array {
            $document = $this->lockedDocument($documentId);
            if ((string) $document->post_status !== 'trash') {
                throw ValidationException::withMessages(['document' => 'Hanya dokumen di Sampah yang dapat dipulihkan.']);
            }
            if ((string) $document->post_modified !== $input['expected_modified_at']) {
                throw new ConflictHttpException('Dokumen telah diubah oleh pengguna lain. Muat ulang sebelum memulihkan.');
            }
            $restoreStatus = $this->trashStatus($documentId);
            $this->authorization->ensureCanTrashOrRestoreDocument($actor, $document, $restoreStatus);

            $dates = $this->wordpressDates();
            $this->tables->connection()->table($this->tables->table('posts'))->where('ID', $documentId)->update([
                'post_status' => $restoreStatus,
                'post_modified' => $dates['local'],
                'post_modified_gmt' => $dates['gmt'],
            ]);
            $this->tables->connection()->table($this->tables->table('postmeta'))
                ->where('post_id', $documentId)
                ->whereIn('meta_key', ['_wp_trash_meta_status', '_wp_trash_meta_time'])
                ->delete();
            if ($restoreStatus === 'publish') {
                $this->adjustCategoryCounts($documentId, 1);
            }
            $this->setEditLast($documentId, $actor['id']);
            $this->audit->contentMutation('cms.document.restored', $actor, [
                'document_id' => $documentId,
                'status' => $restoreStatus,
            ]);

            return ['id' => $documentId, 'status' => $restoreStatus, 'modified_at' => $dates['local']];
        });
    }

    private function lockedDraft(int $documentId): object
    {
        $document = $this->tables->connection()->table($this->tables->table('posts'))
            ->select(['ID', 'post_author', 'post_title', 'post_name', 'post_status', 'post_modified'])
            ->where('ID', $documentId)
            ->where('post_type', 'wpdmpro')
            ->lockForUpdate()
            ->first();
        if ($document === null) {
            throw new NotFoundHttpException('Package dokumen tidak ditemukan.');
        }

        return $document;
    }

    private function lockedDocument(int $documentId): object
    {
        $document = $this->tables->connection()->table($this->tables->table('posts'))
            ->select(['ID', 'post_author', 'post_title', 'post_name', 'post_status', 'post_modified'])
            ->where('ID', $documentId)
            ->where('post_type', 'wpdmpro')
            ->lockForUpdate()
            ->first();
        if ($document === null) {
            throw new NotFoundHttpException('Package dokumen tidak ditemukan.');
        }

        return $document;
    }

    /** @return array<string,list<string>> */
    private function fileMetadata(int $documentId): array
    {
        $metadata = [];
        $this->tables->connection()->table($this->tables->table('postmeta'))
            ->select(['meta_key', 'meta_value'])
            ->where('post_id', $documentId)
            ->whereIn('meta_key', ['__lppm_document_file', '__wpdm_files'])
            ->orderBy('meta_id')
            ->get()
            ->each(function ($row) use (&$metadata): void {
                $metadata[(string) $row->meta_key][] = (string) $row->meta_value;
            });

        return $metadata;
    }

    private function replaceAccess(int $documentId, string $access): void
    {
        $meta = $this->tables->connection()->table($this->tables->table('postmeta'));
        $meta->where('post_id', $documentId)->where('meta_key', '__wpdm_access')->delete();
        $meta->insert([
            'post_id' => $documentId,
            'meta_key' => '__wpdm_access',
            'meta_value' => serialize([$access]),
        ]);
    }

    private function trashStatus(int $documentId): string
    {
        $status = (string) $this->tables->connection()->table($this->tables->table('postmeta'))
            ->where('post_id', $documentId)
            ->where('meta_key', '_wp_trash_meta_status')
            ->orderByDesc('meta_id')
            ->value('meta_value');

        return in_array($status, ['draft', 'publish', 'future', 'pending', 'private'], true) ? $status : 'draft';
    }

    private function adjustCategoryCounts(int $documentId, int $direction): void
    {
        $relationshipsTable = $this->tables->table('term_relationships');
        $taxonomyTable = $this->tables->table('term_taxonomy');
        $taxonomyIds = $this->tables->connection()->table($relationshipsTable . ' as relationships')
            ->join($taxonomyTable . ' as taxonomy', 'taxonomy.term_taxonomy_id', '=', 'relationships.term_taxonomy_id')
            ->where('relationships.object_id', $documentId)
            ->where('taxonomy.taxonomy', 'wpdmcategory')
            ->pluck('taxonomy.term_taxonomy_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
        foreach ($taxonomyIds as $taxonomyId) {
            if ($direction > 0) {
                $this->tables->connection()->table($taxonomyTable)->where('term_taxonomy_id', $taxonomyId)->increment('count');
            } else {
                $this->tables->connection()->table($taxonomyTable)->where('term_taxonomy_id', $taxonomyId)
                    ->update(['count' => $this->tables->connection()->raw('CASE WHEN count > 0 THEN count - 1 ELSE 0 END')]);
            }
        }
    }

    /** @param list<int> $termIds */
    private function assertDocumentCategories(array $termIds): void
    {
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        if ($termIds === []) {
            return;
        }
        $found = $this->tables->connection()->table($this->tables->table('term_taxonomy'))
            ->where('taxonomy', 'wpdmcategory')
            ->whereIn('term_id', $termIds)
            ->pluck('term_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        sort($termIds);
        sort($found);
        if ($found !== $termIds) {
            throw ValidationException::withMessages(['category_ids' => 'Satu atau lebih kategori dokumen tidak valid.']);
        }
    }

    /** @param list<int> $termIds */
    private function replaceCategories(int $documentId, array $termIds): void
    {
        $taxonomyTable = $this->tables->table('term_taxonomy');
        $relationshipsTable = $this->tables->table('term_relationships');
        $existing = $this->tables->connection()->table($relationshipsTable . ' as relationships')
            ->join($taxonomyTable . ' as taxonomy', 'taxonomy.term_taxonomy_id', '=', 'relationships.term_taxonomy_id')
            ->where('relationships.object_id', $documentId)
            ->where('taxonomy.taxonomy', 'wpdmcategory')
            ->pluck('relationships.term_taxonomy_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($existing !== []) {
            $this->tables->connection()->table($relationshipsTable)
                ->where('object_id', $documentId)
                ->whereIn('term_taxonomy_id', $existing)
                ->delete();
        }

        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        if ($termIds === []) {
            return;
        }
        $taxonomyIds = $this->tables->connection()->table($taxonomyTable)
            ->where('taxonomy', 'wpdmcategory')
            ->whereIn('term_id', $termIds)
            ->pluck('term_taxonomy_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->tables->connection()->table($relationshipsTable)->insert(array_map(
            fn (int $taxonomyId): array => ['object_id' => $documentId, 'term_taxonomy_id' => $taxonomyId, 'term_order' => 0],
            $taxonomyIds
        ));
    }

    private function setEditLast(int $documentId, int $authorId): void
    {
        $meta = $this->tables->connection()->table($this->tables->table('postmeta'));
        $updated = $meta->where('post_id', $documentId)->where('meta_key', '_edit_last')->update(['meta_value' => (string) $authorId]);
        if ($updated === 0) {
            $meta->insert(['post_id' => $documentId, 'meta_key' => '_edit_last', 'meta_value' => (string) $authorId]);
        }
    }

    private function uniqueSlug(string $value, ?int $exceptDocumentId = null): string
    {
        $base = Str::limit(Str::slug($value), 180, '');
        $base = $base === '' ? 'dokumen-lppm' : $base;
        $candidate = $base;
        $number = 2;
        while (true) {
            $query = $this->tables->connection()->table($this->tables->table('posts'))
                ->where('post_type', 'wpdmpro')
                ->where('post_name', $candidate);
            if ($exceptDocumentId !== null) {
                $query->where('ID', '!=', $exceptDocumentId);
            }
            if (!$query->exists()) {
                return $candidate;
            }
            $suffix = '-' . $number++;
            $candidate = Str::limit($base, 200 - strlen($suffix), '') . $suffix;
        }
    }

    /** @return array{0:string,1:string} */
    private function validateFile(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Upload dokumen gagal diproses oleh server.']);
        }
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!array_key_exists($extension, self::ALLOWED_MIME_TYPES)) {
            throw ValidationException::withMessages(['file' => 'Format dokumen harus PDF, Word, Excel, PowerPoint, atau ZIP.']);
        }
        $mime = strtolower((string) $file->getMimeType());
        if (!in_array($mime, self::ALLOWED_MIME_TYPES[$extension], true)) {
            throw ValidationException::withMessages(['file' => 'Tipe isi file tidak sesuai dengan ekstensi dokumen.']);
        }
        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true) && !$this->isExpectedOfficeArchive($file, $extension)) {
            throw ValidationException::withMessages(['file' => 'Arsip Office tidak memiliki struktur dokumen yang valid.']);
        }

        return [$extension, $mime];
    }

    private function isExpectedOfficeArchive(UploadedFile $file, string $extension): bool
    {
        if (!class_exists(\ZipArchive::class) || $file->getRealPath() === false) {
            return false;
        }
        $entry = match ($extension) {
            'docx' => 'word/document.xml',
            'xlsx' => 'xl/workbook.xml',
            'pptx' => 'ppt/presentation.xml',
        };
        $archive = new \ZipArchive();
        if ($archive->open($file->getRealPath()) !== true) {
            return false;
        }
        try {
            return $archive->locateName($entry) !== false;
        } finally {
            $archive->close();
        }
    }

    private function documentUploadRoot(): string
    {
        $configured = trim((string) config('services.wordpress.document_upload_root', ''));
        if ($configured === '') {
            $configured = (string) (config('services.wordpress.document_roots', [])[0] ?? '');
        }
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
        if ($root === '' || !is_dir($root) || !is_writable($root)) {
            throw new RuntimeException('Root upload dokumen belum tersedia atau tidak dapat ditulis Laravel.');
        }
        $real = realpath($root);
        if ($real === false) {
            throw new RuntimeException('Root upload dokumen tidak dapat diresolusikan.');
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function safeBaseName(string $name): string
    {
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME));

        return Str::limit($base === '' ? 'dokumen-lppm' : $base, 120, '');
    }

    private function uniqueFilename(string $directory, string $baseName, string $extension): string
    {
        $candidate = $baseName . '.' . $extension;
        $number = 2;
        while (is_file($directory . DIRECTORY_SEPARATOR . $candidate)) {
            $suffix = '-' . $number++;
            $candidate = Str::limit($baseName, 180 - strlen($suffix), '') . $suffix . '.' . $extension;
        }

        return $candidate;
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
            $local = $utc->copy()->addMinutes((int) round((float) $this->option('gmt_offset', '0') * 60));
        }

        return ['local' => $local->format('Y-m-d H:i:s'), 'gmt' => $utc->format('Y-m-d H:i:s')];
    }

    private function option(string $name, string $fallback): string
    {
        $value = $this->tables->connection()->table($this->tables->table('options'))
            ->where('option_name', $name)
            ->value('option_value');

        return is_string($value) ? $value : $fallback;
    }
}
