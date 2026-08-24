<?php

namespace App\Support;

/**
 * Resolves legacy WordPress Download Manager file metadata without loading the
 * WordPress plugin. Filesystem paths are deliberately kept internal: callers
 * may expose only the availability state, file name, and Laravel URL.
 */
final class WordpressDocumentResolver
{
    /** @var list<string> */
    private const FILE_META_KEYS = ['__lppm_document_file', '__wpdm_files'];

    /**
     * @param array<string, list<string>> $metadata
     * @return array{available:bool,status:string,source_meta_key:?string,file_name:?string,resolved_path:?string}
     */
    public function resolve(array $metadata): array
    {
        [$sourceMetaKey, $references] = $this->fileReferences($metadata);

        if ($sourceMetaKey === null || $references === []) {
            return $this->unavailable('missing_file_reference', $sourceMetaKey);
        }

        $normalizedReferences = [];
        foreach ($references as $reference) {
            $normalized = $this->normalizeRelativeReference($reference);
            if ($normalized['status'] !== 'valid') {
                return $this->unavailable($normalized['status'], $sourceMetaKey);
            }

            $normalizedReferences[] = $normalized['path'];
        }

        $normalizedReferences = array_values(array_unique($normalizedReferences));
        if (count($normalizedReferences) !== 1) {
            // Multi-file packages need an explicit operator decision before a
            // browser can download one arbitrary member of the package.
            return $this->unavailable('multiple_file_references', $sourceMetaKey);
        }

        $reference = $normalizedReferences[0];
        $availableRoots = $this->availableRoots();
        if ($availableRoots === []) {
            return $this->unavailable('root_unavailable', $sourceMetaKey, $reference);
        }

        $matches = [];
        foreach ($availableRoots as $root) {
            $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $reference);
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $realCandidate = realpath($candidate);
            if ($realCandidate === false || !$this->isInsideRoot($realCandidate, $root)) {
                continue;
            }

            $matches[] = $realCandidate;
        }

        $matches = array_values(array_unique($matches));
        if ($matches === []) {
            return $this->unavailable('missing_file', $sourceMetaKey, $reference);
        }

        if (count($matches) > 1) {
            return $this->unavailable('ambiguous_multiple_files', $sourceMetaKey, $reference);
        }

        return [
            'available' => true,
            'status' => 'resolved',
            'source_meta_key' => $sourceMetaKey,
            'file_name' => basename($reference),
            // Never pass this value to a JSON response or frontend component.
            'resolved_path' => $matches[0],
        ];
    }

    /**
     * A Download Manager package is public only when its legacy access meta
     * explicitly includes the conventional `guest` role. Missing or malformed
     * access metadata stays protected by default.
     *
     * @param array<string, list<string>> $metadata
     */
    public function isPublic(array $metadata): bool
    {
        foreach ($this->accessEntries($metadata) as $entry) {
            if (strtolower($entry) === 'guest') {
                return true;
            }
        }

        return false;
    }

    public function downloadUrl(int $documentId): string
    {
        return url('/api/documents/' . $documentId . '/download');
    }

    /**
     * @param array<string, list<string>> $metadata
     * @return array{0:?string,1:list<string>}
     */
    private function fileReferences(array $metadata): array
    {
        $modern = $this->firstNonEmpty($metadata['__lppm_document_file'] ?? []);
        if ($modern !== null) {
            return ['__lppm_document_file', [$modern]];
        }

        $legacy = $this->firstNonEmpty($metadata['__wpdm_files'] ?? []);
        if ($legacy === null) {
            return [null, []];
        }

        return ['__wpdm_files', $this->serializedStringValues($legacy)];
    }

    /**
     * @param array<string, list<string>> $metadata
     * @return list<string>
     */
    private function accessEntries(array $metadata): array
    {
        $values = [];
        foreach ($metadata['__wpdm_access'] ?? [] as $value) {
            $values = [...$values, ...$this->serializedStringValues($value)];
        }

        return array_values(array_unique(array_filter(array_map('trim', $values))));
    }

    /**
     * Supports scalar legacy values and PHP serialized arrays while preventing
     * object deserialization. Unknown structures yield no usable reference.
     *
     * @return list<string>
     */
    private function serializedStringValues(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parsed = @unserialize($value, ['allowed_classes' => false]);
        if ($parsed === false && $value !== 'b:0;') {
            return [$value];
        }

        $values = [];
        $collect = function (mixed $item) use (&$collect, &$values): void {
            if (is_string($item) && trim($item) !== '') {
                $values[] = $item;

                return;
            }

            if (is_array($item)) {
                foreach ($item as $nested) {
                    $collect($nested);
                }
            }
        };
        $collect($parsed);

        return array_values(array_unique($values));
    }

    /** @return array{status:string,path?:string} */
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

    /** @return list<string> */
    private function availableRoots(): array
    {
        $configuredRoots = [
            ...config('services.wordpress.document_roots', []),
            (string) config('services.wordpress.uploads_root', ''),
        ];

        $roots = [];
        foreach ($configuredRoots as $configuredRoot) {
            $configuredRoot = trim((string) $configuredRoot);
            if ($configuredRoot === '' || !is_dir($configuredRoot) || !is_readable($configuredRoot)) {
                continue;
            }

            $realRoot = realpath($configuredRoot);
            if ($realRoot !== false) {
                $roots[] = rtrim($realRoot, "\\/");
            }
        }

        return array_values(array_unique($roots));
    }

    private function isInsideRoot(string $path, string $root): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        return str_starts_with($normalizedPath, $normalizedRoot . '/');
    }

    /** @param list<string> $values */
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
     * @return array{available:false,status:string,source_meta_key:?string,file_name:?string,resolved_path:null}
     */
    private function unavailable(string $status, ?string $sourceMetaKey, ?string $reference = null): array
    {
        return [
            'available' => false,
            'status' => $status,
            'source_meta_key' => $sourceMetaKey,
            'file_name' => $reference === null ? null : basename($reference),
            'resolved_path' => null,
        ];
    }
}
