<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditWordpressLegacyAssets extends Command
{
    /**
     * Command ini hanya membaca database dan filesystem legacy.
     * Manifest yang dihasilkan selalu ditulis pada private local disk Laravel.
     *
     * @var string
     */
    protected $signature = 'wp:audit-legacy-assets
        {--output= : Direktori relatif di storage/app/private untuk hasil audit}
        {--prefix= : Override prefix tabel WordPress untuk audit ini}
        {--uploads-root= : Override root filesystem wp-content/uploads}
        {--uploads-base-url= : Override base URL publik wp-content/uploads}
        {--document-root=* : Tambahkan root filesystem dokumen legacy; dapat diulang}
        {--skip-filesystem : Audit metadata database saja, tanpa memeriksa keberadaan file}';

    /**
     * @var string
     */
    protected $description = 'Buat manifest read-only attachment dan dokumen WordPress legacy';

    /**
     * @var list<string>
     */
    private const DOCUMENT_META_KEYS = [
        '__lppm_document_file',
        '__wpdm_files',
        '__wpdm_access',
    ];

    public function handle(): int
    {
        $connection = (string) config('services.wordpress.connection', 'wordpress');
        $prefix = trim((string) ($this->option('prefix') ?: config('services.wordpress.prefix', '')));

        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            $this->error('DB_WP_PREFIX harus berisi huruf, angka, atau underscore dan tidak boleh kosong.');

            return self::FAILURE;
        }

        $skipFilesystem = (bool) $this->option('skip-filesystem');
        $uploadsRoot = $this->normalizeRoot(
            $this->option('uploads-root') ?: config('services.wordpress.uploads_root', '')
        );
        $uploadsBaseUrl = rtrim((string) (
            $this->option('uploads-base-url') ?: config('services.wordpress.uploads_base_url', '')
        ), '/');
        $documentRoots = $this->documentRoots($uploadsRoot);

        if (!$skipFilesystem && $uploadsRoot === '') {
            $this->error('Root uploads belum tersedia. Set LEGACY_UPLOADS_ROOT atau gunakan --uploads-root.');

            return self::FAILURE;
        }

        if (!$skipFilesystem && $documentRoots === []) {
            $this->error('Root dokumen belum tersedia. Set LEGACY_DOCUMENT_ROOTS atau gunakan --document-root.');

            return self::FAILURE;
        }

        $postsTable = $prefix . 'posts';
        $postmetaTable = $prefix . 'postmeta';

        $posts = DB::connection($connection)
            ->table($postsTable)
            ->select([
                'ID',
                'post_type',
                'post_title',
                'post_status',
                'post_date',
                'post_modified',
                'post_mime_type',
            ])
            ->whereIn('post_type', ['attachment', 'wpdmpro'])
            ->orderBy('ID')
            ->get();

        $metadata = DB::connection($connection)
            ->table("{$postmetaTable} as pm")
            ->join("{$postsTable} as p", 'p.ID', '=', 'pm.post_id')
            ->select(['pm.post_id', 'pm.meta_key', 'pm.meta_value'])
            ->whereIn('p.post_type', ['attachment', 'wpdmpro'])
            ->whereIn('pm.meta_key', array_merge(['_wp_attached_file'], self::DOCUMENT_META_KEYS))
            ->orderBy('pm.meta_id')
            ->get();

        $metadataByPost = [];
        foreach ($metadata as $meta) {
            $metadataByPost[(int) $meta->post_id][$meta->meta_key][] = (string) $meta->meta_value;
        }

        $manifest = [];
        foreach ($posts as $post) {
            $postId = (int) $post->ID;
            $postMeta = $metadataByPost[$postId] ?? [];

            $manifest[] = $post->post_type === 'attachment'
                ? $this->auditAttachment($post, $postMeta, $uploadsRoot, $uploadsBaseUrl, $skipFilesystem)
                : $this->auditDocument($post, $postMeta, $uploadsRoot, $documentRoots, $skipFilesystem);
        }

        $outputDirectory = $this->outputDirectory();
        $summary = $this->makeSummary(
            $manifest,
            $connection,
            $prefix,
            $uploadsRoot,
            $documentRoots,
            $skipFilesystem
        );

        Storage::disk('local')->put(
            $outputDirectory . '/manifest.json',
            json_encode([
                'summary' => $summary,
                'items' => $manifest,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        Storage::disk('local')->put($outputDirectory . '/manifest.csv', $this->toCsv($manifest));
        Storage::disk('local')->put(
            $outputDirectory . '/summary.json',
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        $this->info('Audit selesai tanpa perubahan pada database WordPress atau aset legacy.');
        $this->table(
            ['Jenis', 'Jumlah'],
            collect($summary['classification_counts'])
                ->map(fn (int $count, string $classification) => [$classification, $count])
                ->values()
                ->all()
        );
        $this->line('Manifest privat: storage/app/private/' . $outputDirectory);

        return self::SUCCESS;
    }

    /**
     * @param array<string, list<string>> $metadata
     * @return array<string, mixed>
     */
    private function auditAttachment(
        object $post,
        array $metadata,
        string $uploadsRoot,
        string $uploadsBaseUrl,
        bool $skipFilesystem
    ): array
    {
        $reference = $this->firstNonEmpty($metadata['_wp_attached_file'] ?? []);

        return $this->baseItem($post) + $this->inspectReferences(
            sourceMetaKey: '_wp_attached_file',
            references: $reference === null ? [] : [$reference],
            roots: $uploadsRoot === '' ? [] : [$uploadsRoot],
            skipFilesystem: $skipFilesystem,
            staticBaseUrl: $uploadsBaseUrl,
            directStaticUrlAllowed: true
        );
    }

    /**
     * @param array<string, list<string>> $metadata
     * @return array<string, mixed>
     */
    private function auditDocument(
        object $post,
        array $metadata,
        string $uploadsRoot,
        array $documentRoots,
        bool $skipFilesystem
    ): array {
        $newReference = $this->firstNonEmpty($metadata['__lppm_document_file'] ?? []);
        $legacyReference = $this->firstNonEmpty($metadata['__wpdm_files'] ?? []);

        if ($newReference !== null) {
            $sourceMetaKey = '__lppm_document_file';
            $references = [$newReference];
        } elseif ($legacyReference !== null) {
            $sourceMetaKey = '__wpdm_files';
            $references = $this->unserializeFileReferences($legacyReference);
        } else {
            $sourceMetaKey = null;
            $references = [];
        }

        $roots = array_values(array_unique(array_filter([
            ...$documentRoots,
            $uploadsRoot,
        ])));

        return $this->baseItem($post) + $this->inspectReferences(
            sourceMetaKey: $sourceMetaKey,
            references: $references,
            roots: $roots,
            skipFilesystem: $skipFilesystem,
            staticBaseUrl: null,
            directStaticUrlAllowed: false
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseItem(object $post): array
    {
        return [
            'content_id' => (int) $post->ID,
            'content_type' => (string) $post->post_type,
            'title' => (string) $post->post_title,
            'post_status' => (string) $post->post_status,
            'post_date' => (string) $post->post_date,
            'post_modified' => (string) $post->post_modified,
            'mime_type' => (string) $post->post_mime_type,
        ];
    }

    /**
     * @param list<string> $references
     * @param list<string> $roots
     * @return array<string, mixed>
     */
    private function inspectReferences(
        ?string $sourceMetaKey,
        array $references,
        array $roots,
        bool $skipFilesystem,
        ?string $staticBaseUrl,
        bool $directStaticUrlAllowed
    ): array {
        $references = array_values(array_unique(array_filter(array_map('trim', $references))));

        if ($sourceMetaKey === null || $references === []) {
            return [
                'source_meta_key' => $sourceMetaKey,
                'file_references' => $references,
                'classification' => 'missing_file_reference',
                'filesystem_status' => 'not_checked',
                'resolved_paths' => [],
                'public_url_candidate' => null,
                'download_strategy' => $directStaticUrlAllowed ? 'static_when_resolved' : 'laravel_endpoint_required',
            ];
        }

        $normalizedReferences = [];
        foreach ($references as $reference) {
            $normalized = $this->normalizeRelativeReference($reference);
            if ($normalized['status'] !== 'valid') {
                return [
                    'source_meta_key' => $sourceMetaKey,
                    'file_references' => $references,
                    'classification' => $normalized['status'],
                    'filesystem_status' => 'not_checked',
                    'resolved_paths' => [],
                    'public_url_candidate' => null,
                    'download_strategy' => $directStaticUrlAllowed ? 'static_when_resolved' : 'laravel_endpoint_required',
                ];
            }
            $normalizedReferences[] = $normalized['path'];
        }

        if ($skipFilesystem) {
            return [
                'source_meta_key' => $sourceMetaKey,
                'file_references' => $references,
                'normalized_references' => $normalizedReferences,
                'classification' => 'metadata_valid_filesystem_not_checked',
                'filesystem_status' => 'skipped',
                'resolved_paths' => [],
                'public_url_candidate' => $this->staticUrlCandidate($staticBaseUrl, $normalizedReferences, $directStaticUrlAllowed),
                'download_strategy' => $directStaticUrlAllowed ? 'static_when_resolved' : 'laravel_endpoint_required',
            ];
        }

        $rootChecks = $this->rootChecks($roots);
        if (collect($rootChecks)->contains(fn (array $root) => $root['status'] !== 'available')) {
            return [
                'source_meta_key' => $sourceMetaKey,
                'file_references' => $references,
                'normalized_references' => $normalizedReferences,
                'classification' => 'root_unavailable',
                'filesystem_status' => 'root_unavailable',
                'resolved_paths' => [],
                'root_checks' => $rootChecks,
                'public_url_candidate' => null,
                'download_strategy' => $directStaticUrlAllowed ? 'static_when_resolved' : 'laravel_endpoint_required',
            ];
        }

        $resolvedPaths = [];
        foreach ($normalizedReferences as $normalizedReference) {
            foreach ($roots as $root) {
                $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $normalizedReference);

                if (is_file($candidate)) {
                    $realCandidate = realpath($candidate) ?: $candidate;
                    $resolvedPaths[] = $realCandidate;
                }
            }
        }
        $resolvedPaths = array_values(array_unique($resolvedPaths));

        $classification = match (count($resolvedPaths)) {
            0 => 'missing_file',
            1 => 'resolved',
            default => 'ambiguous_multiple_files',
        };

        return [
            'source_meta_key' => $sourceMetaKey,
            'file_references' => $references,
            'normalized_references' => $normalizedReferences,
            'classification' => $classification,
            'filesystem_status' => $classification === 'resolved' ? 'file_found' : $classification,
            'resolved_paths' => $resolvedPaths,
            'root_checks' => $rootChecks,
            'public_url_candidate' => $classification === 'resolved'
                ? $this->staticUrlCandidate($staticBaseUrl, $normalizedReferences, $directStaticUrlAllowed)
                : null,
            'download_strategy' => $directStaticUrlAllowed ? 'static_when_resolved' : 'laravel_endpoint_required',
        ];
    }

    /**
     * @return array{status: string, path?: string}
     */
    private function normalizeRelativeReference(string $reference): array
    {
        $reference = trim(str_replace('\\', '/', $reference));

        if ($reference === '') {
            return ['status' => 'missing_file_reference'];
        }

        if (preg_match('#^https?://#i', $reference)) {
            return ['status' => 'http_url_reference'];
        }

        if (str_starts_with($reference, '/') || preg_match('/^[A-Za-z]:\//', $reference)) {
            return ['status' => 'absolute_path_reference'];
        }

        while (str_starts_with($reference, './')) {
            $reference = substr($reference, 2);
        }
        $segments = explode('/', $reference);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return ['status' => 'unsafe_path_reference'];
        }

        return ['status' => 'valid', 'path' => $reference];
    }

    /**
     * @return list<string>
     */
    private function unserializeFileReferences(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parsed = @unserialize($value, ['allowed_classes' => false]);
        if ($parsed === false && $value !== 'b:0;') {
            return [$value];
        }

        $references = [];
        $collect = function (mixed $item) use (&$collect, &$references): void {
            if (is_string($item) && trim($item) !== '') {
                $references[] = $item;

                return;
            }

            if (is_array($item)) {
                foreach ($item as $nested) {
                    $collect($nested);
                }
            }
        };
        $collect($parsed);

        return array_values(array_unique($references));
    }

    /**
     * @param list<string> $roots
     * @return list<array{path: string, status: string, readable: bool}>
     */
    private function rootChecks(array $roots): array
    {
        return array_map(fn (string $root) => [
            'path' => $root,
            'status' => is_dir($root) ? 'available' : 'missing_directory',
            'readable' => is_readable($root),
        ], $roots);
    }

    /**
     * @param list<string> $references
     */
    private function staticUrlCandidate(?string $baseUrl, array $references, bool $allowed): ?string
    {
        if (!$allowed || $baseUrl === null || $baseUrl === '' || count($references) !== 1) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/' . str_replace('%2F', '/', rawurlencode($references[0]));
    }

    /**
     * @return list<string>
     */
    private function documentRoots(string $uploadsRoot): array
    {
        $roots = [
            ...config('services.wordpress.document_roots', []),
            ...$this->option('document-root'),
        ];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $root) => $this->normalizeRoot((string) $root),
            $roots
        ))));
    }

    private function normalizeRoot(string $root): string
    {
        return rtrim(trim($root), "\\/");
    }

    /**
     * @param list<string> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $manifest
     * @param list<string> $documentRoots
     * @return array<string, mixed>
     */
    private function makeSummary(
        array $manifest,
        string $connection,
        string $prefix,
        string $uploadsRoot,
        array $documentRoots,
        bool $skipFilesystem
    ): array {
        $classificationCounts = [];
        $typeCounts = [];
        foreach ($manifest as $item) {
            $classification = (string) $item['classification'];
            $type = (string) $item['content_type'];
            $classificationCounts[$classification] = ($classificationCounts[$classification] ?? 0) + 1;
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }
        ksort($classificationCounts);
        ksort($typeCounts);

        return [
            'generated_at' => now()->toIso8601String(),
            'mode' => 'read_only',
            'database_connection' => $connection,
            'wordpress_prefix' => $prefix,
            'filesystem_checked' => !$skipFilesystem,
            'uploads_root' => $uploadsRoot === '' ? null : $uploadsRoot,
            'document_roots' => $documentRoots,
            'type_counts' => $typeCounts,
            'classification_counts' => $classificationCounts,
            'total_items' => count($manifest),
        ];
    }

    /**
     * @param list<array<string, mixed>> $manifest
     */
    private function toCsv(array $manifest): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [
            'content_id',
            'content_type',
            'title',
            'post_status',
            'mime_type',
            'source_meta_key',
            'file_references',
            'classification',
            'filesystem_status',
            'resolved_paths',
            'download_strategy',
        ]);

        foreach ($manifest as $item) {
            fputcsv($stream, [
                $item['content_id'],
                $item['content_type'],
                $item['title'],
                $item['post_status'],
                $item['mime_type'],
                $item['source_meta_key'] ?? '',
                implode(' | ', $item['file_references'] ?? []),
                $item['classification'],
                $item['filesystem_status'],
                implode(' | ', $item['resolved_paths'] ?? []),
                $item['download_strategy'],
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $csv;
    }

    private function outputDirectory(): string
    {
        $requested = trim((string) $this->option('output'));
        $default = 'audits/wordpress-legacy/' . now()->format('Ymd-His');
        $directory = trim($requested !== '' ? $requested : $default, "\\/");

        if ($directory === '' || str_contains($directory, '..')) {
            throw new \InvalidArgumentException('Direktori output harus relatif terhadap storage/app/private dan tidak boleh memakai ..');
        }

        return str_replace('\\', '/', $directory);
    }
}
