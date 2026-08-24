<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAssetResolver;
use App\Support\WordpressDocumentResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressAssetResolver $assets,
        private readonly WordpressDocumentResolver $documents
    ) {
    }

    /**
     * Daftar kategori dokumen penunjang.
     */
    private const SUPPORT_CATEGORIES = [
        'peraturan-rektor-dan-renstra' => 'Peraturan Rektor dan Dokumen Renstra',
        'surat-keputusan' => 'Surat Keputusan',
        'sk-penerima-hibah' => 'SK Penerima Hibah',
        'sk-tim-reviewer' => 'SK Tim Reviewer',
        'sk-panitia' => 'SK Panitia',
        'panduan-penelitian-dan-pkm' => 'Panduan Penelitian dan PKM',
        'data-penelitian-pkm-buku-dan-hki' => 'Data Penelitian, PKM, Buku dan HKI',
        'dokumen-pendukung-lainnya' => 'Dokumen Pendukung Lainnya',
        'dokumen-spmi-penelitian-dan-pengabdian' => 'Dokumen SPMI Penelitian dan Pengabdian',
    ];

    /**
     * GET /documents
     * Mengambil daftar dokumen (attachment) dari WordPress.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        $search = $request->query('search');
        $categoryFilter = (string) $request->query('category', '');

        try {
            // "Arsip Lainnya" is the public general archive. It must include
            // both normal WordPress attachments and modern/legacy Download
            // Manager packages. The support-category view below intentionally
            // remains attachment-only because its categories are historical
            // title-based groupings, not wpdmcategory terms.
            if ($categoryFilter === '') {
                return $this->publicArchive($limit, trim((string) $search));
            }

            $connection = $this->tables->connection();
            $postsTable = $this->tables->table('posts');
            $postmetaTable = $this->tables->table('postmeta');

            $query = $connection->table("{$postsTable} as p")
                ->select([
                    'p.ID',
                    'p.post_title',
                    'p.post_date',
                    'p.post_mime_type',
                    'p.post_excerpt',
                    'p.post_name',
                    'file_meta.meta_value as attachment_path',
                ])
                ->leftJoin("{$postmetaTable} as file_meta", function ($join) {
                    $join->on('p.ID', '=', 'file_meta.post_id')
                        ->where('file_meta.meta_key', '_wp_attached_file');
                })
                ->where('p.post_type', 'attachment')
                ->where('p.post_status', 'inherit'); // Attachment biasanya statusnya inherit

            // Filter hanya file dokumen (PDF, Word, Excel, PPT, ZIP)
            $query->where(function($q) {
                $q->where('post_mime_type', 'like', 'application/pdf')
                  ->orWhere('post_mime_type', 'like', 'application/msword')
                  ->orWhere('post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') // docx
                  ->orWhere('post_mime_type', 'like', 'application/vnd.ms-excel')
                  ->orWhere('post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') // xlsx
                  ->orWhere('post_mime_type', 'like', 'application/vnd.ms-powerpoint')
                  ->orWhere('post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.presentationml.presentation') // pptx
                  ->orWhere('post_mime_type', 'like', 'application/zip')
                  ->orWhere('post_mime_type', 'like', 'application/x-rar-compressed');
            });

            if ($search) {
                $query->where('p.post_title', 'like', '%' . $search . '%');
            }

            // Terapkan filter kategori di level SQL (sebelum pagination)
            // agar hasil tidak hilang karena terpotong halaman.
            if (
                $categoryFilter !== '' &&
                isset(self::SUPPORT_CATEGORIES[$categoryFilter])
            ) {
                $this->applyCategorySqlFilter($query, $categoryFilter);
            }

            $query->orderByDesc('p.post_date');

            // Gunakan paginate() dari Laravel
            $paginator = $query->paginate($limit);

            $items = $paginator->getCollection()->map(function ($item) {
                $url = $this->assets->publicUrl($item->attachment_path);

                // Tentukan tipe file simpel
                $type = 'file';
                if (strpos($item->post_mime_type, 'pdf') !== false) $type = 'pdf';
                elseif (strpos($item->post_mime_type, 'word') !== false) $type = 'word';
                elseif (strpos($item->post_mime_type, 'excel') !== false || strpos($item->post_mime_type, 'spreadsheet') !== false) $type = 'excel';
                elseif (strpos($item->post_mime_type, 'powerpoint') !== false || strpos($item->post_mime_type, 'presentation') !== false) $type = 'ppt';
                elseif (strpos($item->post_mime_type, 'zip') !== false || strpos($item->post_mime_type, 'rar') !== false) $type = 'archive';

                $category = $this->categorizeDocument($item->post_title, $item->post_excerpt);

                return [
                    'id' => $item->ID,
                    'title' => $item->post_title,
                    'date' => $item->post_date,
                    'url' => $url,
                    'type' => $type,
                    'mime' => $item->post_mime_type,
                    'excerpt' => $item->post_excerpt,
                    'category' => $category,
                ];
            });

            if ($categoryFilter !== '' && isset(self::SUPPORT_CATEGORIES[$categoryFilter])) {
                $items = $items->filter(
                    fn ($item) => $this->matchesRequestedCategory($item, $categoryFilter)
                )->values();
            }

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Daftar dokumen berhasil diambil',
                    'count' => $items->count(),
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'next_page_url' => $paginator->nextPageUrl(),
                        'prev_page_url' => $paginator->previousPageUrl(),
                    ]
                ],
                'data' => $items,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal mengambil dokumen: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    private function publicArchive(int $limit, string $search): JsonResponse
    {
        $connection = $this->tables->connection();
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');

        $attachments = $connection->table("{$postsTable} as p")
            ->select([
                'p.ID', 'p.post_title', 'p.post_date', 'p.post_mime_type', 'p.post_excerpt', 'p.post_name',
                'file_meta.meta_value as attachment_path',
                $connection->raw("'attachment' as source"),
            ])
            ->leftJoin("{$postmetaTable} as file_meta", function ($join) {
                $join->on('p.ID', '=', 'file_meta.post_id')
                    ->where('file_meta.meta_key', '_wp_attached_file');
            })
            ->where('p.post_type', 'attachment')
            ->where('p.post_status', 'inherit');
        $this->applyDocumentMimeScope($attachments);
        if ($search !== '') {
            $attachments->where(function (Builder $query) use ($search) {
                $query->where('p.post_title', 'like', '%' . $search . '%')
                    ->orWhere('p.post_excerpt', 'like', '%' . $search . '%')
                    ->orWhere('file_meta.meta_value', 'like', '%' . $search . '%');
            });
        }

        $packages = $connection->table("{$postsTable} as p")
            ->select([
                'p.ID', 'p.post_title', 'p.post_date', 'p.post_mime_type', 'p.post_excerpt', 'p.post_name',
                $connection->raw('NULL as attachment_path'),
                $connection->raw("'wpdmpro' as source"),
            ])
            ->where('p.post_type', 'wpdmpro')
            ->where('p.post_status', 'publish')
            ->whereExists(function (Builder $access) use ($postmetaTable) {
                $access->selectRaw('1')
                    ->from("{$postmetaTable} as access_meta")
                    ->whereColumn('access_meta.post_id', 'p.ID')
                    ->where('access_meta.meta_key', '__wpdm_access')
                    ->where('access_meta.meta_value', 'like', '%guest%');
            });
        if ($search !== '') {
            $packages->where(function (Builder $query) use ($search) {
                $query->where('p.post_title', 'like', '%' . $search . '%')
                    ->orWhere('p.post_excerpt', 'like', '%' . $search . '%')
                    ->orWhere('p.post_content', 'like', '%' . $search . '%');
            });
        }

        $paginator = $connection->query()
            ->fromSub($attachments->unionAll($packages), 'archive')
            ->select('archive.*')
            ->orderByDesc('archive.post_date')
            ->paginate($limit);
        $rows = $paginator->getCollection();
        $packageIds = $rows->where('source', 'wpdmpro')->pluck('ID')->map(fn ($id): int => (int) $id)->all();
        $metadata = $this->packageMetadataFor($packageIds);
        $categories = $this->packageCategoriesFor($packageIds);

        $items = $rows->map(function ($item) use ($metadata, $categories) {
            if ((string) $item->source === 'wpdmpro') {
                $file = $this->documents->resolve($metadata[(int) $item->ID] ?? []);
                if (!$file['available'] || !$this->documents->isPublic($metadata[(int) $item->ID] ?? [])) {
                    // SQL filters public intent; the resolver is the final
                    // authority for the actual safe filesystem reference.
                    return null;
                }
                $category = $categories[(int) $item->ID][0] ?? [
                    'slug' => 'arsip-lainnya',
                    'name' => 'Arsip Lainnya',
                ];

                return [
                    'id' => (int) $item->ID,
                    'title' => (string) $item->post_title,
                    'date' => (string) $item->post_date,
                    'url' => $this->documents->downloadUrl((int) $item->ID),
                    'download_url' => $this->documents->downloadUrl((int) $item->ID),
                    'type' => $this->documentType((string) $file['file_name']),
                    'mime' => (string) $item->post_mime_type,
                    'excerpt' => (string) $item->post_excerpt,
                    'category' => $category,
                    'source' => 'wpdmpro',
                    'source_label' => 'Arsip LPPM',
                    'availability' => 'available',
                ];
            }

            $url = $this->assets->publicUrl($item->attachment_path);
            $category = $this->categorizeDocument($item->post_title, $item->post_excerpt);

            return [
                'id' => (int) $item->ID,
                'title' => (string) $item->post_title,
                'date' => (string) $item->post_date,
                'url' => $url,
                'download_url' => $url,
                'type' => $this->documentType((string) $item->post_mime_type),
                'mime' => (string) $item->post_mime_type,
                'excerpt' => (string) $item->post_excerpt,
                'category' => $category,
                'source' => 'attachment',
                'source_label' => 'Dokumen pendukung',
                'availability' => $url === null ? 'unavailable' : 'available',
            ];
        })->filter()->values();

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'message' => 'Arsip publik berhasil diambil',
                'count' => $items->count(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'prev_page_url' => $paginator->previousPageUrl(),
                ],
            ],
            'data' => $items,
        ]);
    }

    private function applyDocumentMimeScope(Builder $query): void
    {
        $query->where(function (Builder $mime) {
            $mime->where('p.post_mime_type', 'like', 'application/pdf')
                ->orWhere('p.post_mime_type', 'like', 'application/msword')
                ->orWhere('p.post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
                ->orWhere('p.post_mime_type', 'like', 'application/vnd.ms-excel')
                ->orWhere('p.post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->orWhere('p.post_mime_type', 'like', 'application/vnd.ms-powerpoint')
                ->orWhere('p.post_mime_type', 'like', 'application/vnd.openxmlformats-officedocument.presentationml.presentation')
                ->orWhere('p.post_mime_type', 'like', 'application/zip')
                ->orWhere('p.post_mime_type', 'like', 'application/x-rar-compressed');
        });
    }

    /** @param list<int> $ids @return array<int,array<string,list<string>>> */
    private function packageMetadataFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $metadata = [];
        $this->tables->connection()->table($this->tables->table('postmeta'))
            ->select(['post_id', 'meta_key', 'meta_value'])
            ->whereIn('post_id', $ids)
            ->whereIn('meta_key', ['__lppm_document_file', '__wpdm_files', '__wpdm_access'])
            ->orderBy('meta_id')
            ->get()
            ->each(function ($row) use (&$metadata): void {
                $metadata[(int) $row->post_id][(string) $row->meta_key][] = (string) $row->meta_value;
            });

        return $metadata;
    }

    /** @param list<int> $ids @return array<int,list<array{slug:string,name:string}>> */
    private function packageCategoriesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $categories = [];
        $this->tables->connection()
            ->table($this->tables->table('term_relationships') . ' as relationships')
            ->join($this->tables->table('term_taxonomy') . ' as taxonomy', 'taxonomy.term_taxonomy_id', '=', 'relationships.term_taxonomy_id')
            ->join($this->tables->table('terms') . ' as terms', 'terms.term_id', '=', 'taxonomy.term_id')
            ->whereIn('relationships.object_id', $ids)
            ->where('taxonomy.taxonomy', 'wpdmcategory')
            ->select(['relationships.object_id', 'terms.slug', 'terms.name'])
            ->orderBy('terms.name')
            ->get()
            ->each(function ($row) use (&$categories): void {
                $categories[(int) $row->object_id][] = [
                    'slug' => (string) $row->slug,
                    'name' => (string) $row->name,
                ];
            });

        return $categories;
    }

    private function documentType(string $value): string
    {
        $value = strtolower($value);
        if (str_contains($value, 'pdf') || str_ends_with($value, '.pdf')) return 'pdf';
        if (str_contains($value, 'word') || str_contains($value, 'document') || preg_match('/\.docx?$/', $value)) return 'word';
        if (str_contains($value, 'excel') || str_contains($value, 'spreadsheet') || preg_match('/\.xlsx?$/', $value)) return 'excel';
        if (str_contains($value, 'powerpoint') || str_contains($value, 'presentation') || preg_match('/\.pptx?$/', $value)) return 'ppt';
        if (str_contains($value, 'zip') || str_contains($value, 'rar') || preg_match('/\.(zip|rar)$/', $value)) return 'archive';

        return 'file';
    }

    /**
     * Menentukan kategori dokumen berdasarkan judul/eksersip.
     */
    private function categorizeDocument(?string $title, ?string $excerpt): array
    {
        $titleNorm = mb_strtolower(trim((string) $title));

        $startsWithAny = static function (string $value, array $prefixes): bool {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    return true;
                }
            }

            return false;
        };

        $slug = 'dokumen-pendukung-lainnya';

        // Klasifikasi utama berbasis prefix judul agar tidak salah masuk kategori.
        if (
            $startsWithAny($titleNorm, ['spmi -', 'dokumen spmi -'])
        ) {
            $slug = 'dokumen-spmi-penelitian-dan-pengabdian';
        } elseif (
            $startsWithAny($titleNorm, ['renstra -', 'peraturan rektor -', 'perrek -'])
        ) {
            $slug = 'peraturan-rektor-dan-renstra';
        } elseif (
            $startsWithAny($titleNorm, ['sk penerima hibah -'])
        ) {
            $slug = 'sk-penerima-hibah';
        } elseif (
            $startsWithAny($titleNorm, ['sk tim reviewer -'])
        ) {
            $slug = 'sk-tim-reviewer';
        } elseif (
            $startsWithAny($titleNorm, ['sk panitia -'])
        ) {
            $slug = 'sk-panitia';
        } elseif (
            $startsWithAny($titleNorm, ['panduan penelitian dan pkm -', 'panduan penelitian -', 'panduan pkm -'])
        ) {
            $slug = 'panduan-penelitian-dan-pkm';
        } elseif (
            $startsWithAny($titleNorm, ['data penelitian/pkm/buku/hki -', 'data penelitian -', 'hki -', 'buku -'])
        ) {
            $slug = 'data-penelitian-pkm-buku-dan-hki';
        } elseif (
            $startsWithAny($titleNorm, ['dokumen pendukung lainnya -', 'dokumen pendukung lain -'])
        ) {
            $slug = 'dokumen-pendukung-lainnya';
        } elseif (
            $startsWithAny($titleNorm, ['surat keputusan -', 'sk -'])
        ) {
            $slug = 'surat-keputusan';
        }

        return [
            'slug' => $slug,
            'name' => self::SUPPORT_CATEGORIES[$slug],
        ];
    }

    /**
     * Filter kategori langsung di query DB untuk kategori yang eksplisit.
     */
    private function applyCategorySqlFilter(Builder $query, string $categorySlug): void
    {
        $prefixesByCategory = [
            'peraturan-rektor-dan-renstra' => ['renstra -', 'peraturan rektor -', 'perrek -'],
            'surat-keputusan' => ['surat keputusan -', 'sk -', 'sk penerima hibah -', 'sk tim reviewer -', 'sk panitia -'],
            'sk-penerima-hibah' => ['sk penerima hibah -'],
            'sk-tim-reviewer' => ['sk tim reviewer -'],
            'sk-panitia' => ['sk panitia -'],
            'panduan-penelitian-dan-pkm' => ['panduan penelitian dan pkm -', 'panduan penelitian -', 'panduan pkm -'],
            'data-penelitian-pkm-buku-dan-hki' => ['data penelitian/pkm/buku/hki -', 'data penelitian -', 'hki -', 'buku -'],
            'dokumen-spmi-penelitian-dan-pengabdian' => ['spmi -', 'dokumen spmi -'],
            'dokumen-pendukung-lainnya' => ['dokumen pendukung lainnya -', 'dokumen pendukung lain -'],
        ];

        if ($categorySlug === 'dokumen-pendukung-lainnya') {
            $excludePrefixes = collect($prefixesByCategory)
                ->except(['dokumen-pendukung-lainnya'])
                ->flatten()
                ->unique()
                ->values()
                ->all();

            $ownPrefixes = $prefixesByCategory['dokumen-pendukung-lainnya'];

            $query->where(function (Builder $inner) use ($excludePrefixes) {
                foreach ($excludePrefixes as $prefix) {
                    $inner->where('post_title', 'not like', $prefix . '%');
                }
            })->where(function (Builder $inner) use ($ownPrefixes) {
                foreach ($ownPrefixes as $prefix) {
                    $inner->orWhere('post_title', 'like', $prefix . '%');
                }
            });

            return;
        }

        $prefixes = $prefixesByCategory[$categorySlug] ?? [];
        if ($prefixes === []) {
            return;
        }

        $query->where(function (Builder $inner) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $inner->orWhere('post_title', 'like', $prefix . '%');
            }
        });
    }

    /**
     * Cocokkan item dengan kategori yang diminta (khusus surat keputusan include semua turunan SK).
     */
    private function matchesRequestedCategory(array $item, string $categoryFilter): bool
    {
        $itemSlug = $item['category']['slug'] ?? '';

        if ($categoryFilter === 'surat-keputusan') {
            return in_array($itemSlug, [
                'surat-keputusan',
                'sk-penerima-hibah',
                'sk-tim-reviewer',
                'sk-panitia',
            ], true);
        }

        return $itemSlug === $categoryFilter;
    }
}
