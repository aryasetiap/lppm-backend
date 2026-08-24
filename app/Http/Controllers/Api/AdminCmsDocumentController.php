<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAssetResolver;
use App\Support\WordpressDocumentResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Read-only CMS view of legacy Download Manager packages. */
class AdminCmsDocumentController extends Controller
{
    /** @var list<string> */
    private const DOCUMENT_STATUSES = ['publish', 'future', 'draft', 'pending', 'private', 'trash'];

    private const BOOK_WRITING_FILE_PREFIX = 'Panduan-Penulisan-Buku-RMBC-Unila-2026';

    /** @var array<string, string> */
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

    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressDocumentResolver $documents,
        private readonly WordpressAssetResolver $assets
    ) {
    }

    /** GET /api/admin/cms/documents?source=all|wpdmpro|book_writing|attachments&status=publish&category_id=1&search=&per_page=20 */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:publish,future,draft,pending,private,trash'],
            'source' => ['nullable', 'in:all,wpdmpro,book_writing,attachments'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $usersTable = $this->tables->table('users');
        $query = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select([
                'p.ID', 'p.post_type', 'p.post_title', 'p.post_name', 'p.post_status',
                'p.post_date', 'p.post_modified', 'p.post_author', 'p.post_excerpt', 'p.post_mime_type',
                'u.display_name as author_name',
                'attachment_file.meta_value as attachment_path',
            ])
            ->leftJoin("{$usersTable} as u", 'u.ID', '=', 'p.post_author')
            ->leftJoin("{$postmetaTable} as attachment_file", function ($join) {
                $join->on('attachment_file.post_id', '=', 'p.ID')
                    ->where('attachment_file.meta_key', '_wp_attached_file');
            });

        $this->applySourceScope($query, (string) ($filters['source'] ?? 'all'));

        if (isset($filters['status'])) {
            if ($filters['status'] === 'publish') {
                $query->where(function (Builder $statuses) {
                    $statuses->where('p.post_status', 'publish')
                        ->orWhere(function (Builder $bookAttachments) {
                            $bookAttachments->where('p.post_type', 'attachment')
                                ->where('p.post_status', 'inherit');
                        });
                });
            } else {
                $query->where('p.post_status', $filters['status']);
            }
        } else {
            $query->where(function (Builder $statuses) {
                $statuses->whereIn('p.post_status', self::DOCUMENT_STATUSES)
                    ->orWhere(function (Builder $bookAttachments) {
                        $bookAttachments->where('p.post_type', 'attachment')
                            ->where('p.post_status', 'inherit');
                    });
            });
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->where(function ($inner) use ($search) {
                $inner->where('p.post_title', 'like', '%' . $search . '%')
                    ->orWhere('p.post_content', 'like', '%' . $search . '%')
                    ->orWhere('p.post_excerpt', 'like', '%' . $search . '%')
                    ->orWhere('attachment_file.meta_value', 'like', '%' . $search . '%');
            });
        }

        if (isset($filters['category_id'])) {
            $relationshipsTable = $this->tables->table('term_relationships');
            $taxonomyTable = $this->tables->table('term_taxonomy');
            $query->whereExists(function ($subquery) use ($relationshipsTable, $taxonomyTable, $filters) {
                $subquery->selectRaw('1')
                    ->from("{$relationshipsTable} as rel")
                    ->join("{$taxonomyTable} as tax", 'tax.term_taxonomy_id', '=', 'rel.term_taxonomy_id')
                    ->whereColumn('rel.object_id', 'p.ID')
                    ->where('tax.taxonomy', 'wpdmcategory')
                    ->where('tax.term_id', $filters['category_id']);
            });
        }

        $paginator = $query->orderByDesc('p.post_modified')->paginate((int) ($filters['per_page'] ?? 20));
        $documents = $paginator->getCollection();
        $metadata = $this->metadataFor($documents->pluck('ID')->map(fn ($id) => (int) $id)->all());
        $categories = $this->categoriesFor($documents->pluck('ID')->map(fn ($id) => (int) $id)->all());

        return response()->json([
            'meta' => [
                'code' => 200,
                'status' => 'success',
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            'data' => $documents->map(fn ($document) => $this->documentItem(
                $document,
                $metadata[(int) $document->ID] ?? [],
                $categories[(int) $document->ID] ?? []
            ))->values(),
        ]);
    }

    /** GET /api/admin/cms/documents/{document} */
    public function show(int $document): JsonResponse
    {
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $usersTable = $this->tables->table('users');
        $item = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select([
                'p.ID', 'p.post_type', 'p.post_title', 'p.post_name', 'p.post_status', 'p.post_date',
                'p.post_modified', 'p.post_author', 'p.post_excerpt', 'p.post_content', 'p.post_mime_type',
                'u.display_name as author_name',
                'attachment_file.meta_value as attachment_path',
            ])
            ->leftJoin("{$usersTable} as u", 'u.ID', '=', 'p.post_author')
            ->leftJoin("{$postmetaTable} as attachment_file", function ($join) {
                $join->on('attachment_file.post_id', '=', 'p.ID')
                    ->where('attachment_file.meta_key', '_wp_attached_file');
            })
            ->where('p.ID', $document)
            ;
        $this->applySourceScope($item, 'all');
        $item = $item->first();

        if ($item === null) {
            return response()->json([
                'meta' => ['code' => 404, 'status' => 'error', 'message' => 'Dokumen tidak ditemukan.'],
            ], 404);
        }

        $metadata = $this->metadataFor([(int) $item->ID]);
        $categories = $this->categoriesFor([(int) $item->ID]);

        return response()->json([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => [
                ...$this->documentItem($item, $metadata[(int) $item->ID] ?? [], $categories[(int) $item->ID] ?? []),
                'content' => (string) $item->post_content,
            ],
        ]);
    }

    /** @param list<int> $ids @return array<int, array<string, list<string>>> */
    private function metadataFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $metadata = [];
        $this->tables->connection()
            ->table($this->tables->table('postmeta'))
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

    /** @param list<int> $ids @return array<int, list<array{id:int,name:string,slug:string}>> */
    private function categoriesFor(array $ids): array
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
            ->select(['relationships.object_id', 'terms.term_id', 'terms.name', 'terms.slug'])
            ->orderBy('terms.name')
            ->get()
            ->each(function ($row) use (&$categories): void {
                $categories[(int) $row->object_id][] = [
                    'id' => (int) $row->term_id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                ];
            });

        return $categories;
    }

    /**
     * @param array<string, list<string>> $metadata
     * @param list<array{id:int,name:string,slug:string}> $categories
     * @return array<string, mixed>
     */
    private function documentItem(object $document, array $metadata, array $categories): array
    {
        $isAttachment = (string) $document->post_type === 'attachment';
        $isBookWriting = $isAttachment && $this->isBookWritingAttachment($document);
        $file = $isAttachment
            ? $this->attachmentFile((string) ($document->attachment_path ?? ''))
            : $this->documents->resolve($metadata);
        $isPublic = $isAttachment
            ? $file['available']
            : ($file['available'] && (string) $document->post_status === 'publish' && $this->documents->isPublic($metadata));
        if ($isBookWriting) {
            $categories = [[
                'id' => -1,
                'name' => 'Penulisan Buku',
                'slug' => 'penulisan-buku',
            ]];
        } elseif ($isAttachment) {
            $categories = [$this->supportAttachmentCategory(
                (string) $document->post_title,
                (string) $document->post_excerpt
            )];
        }

        return [
            'id' => (int) $document->ID,
            'source' => $isBookWriting ? 'book_writing' : ($isAttachment ? 'attachment' : 'wpdmpro'),
            'source_label' => $isBookWriting ? 'Penulisan Buku' : ($isAttachment ? 'Arsip & Dokumen Penunjang' : 'Download Manager'),
            'title' => (string) $document->post_title,
            'slug' => (string) $document->post_name,
            'status' => $isAttachment ? 'publish' : (string) $document->post_status,
            'date' => (string) $document->post_date,
            'modified_at' => (string) $document->post_modified,
            'excerpt' => Str::limit(trim(strip_tags((string) $document->post_excerpt)), 180),
            'author' => [
                'id' => (int) $document->post_author,
                'name' => (string) ($document->author_name ?: 'Tanpa nama'),
            ],
            'categories' => $categories,
            'file' => [
                'available' => $file['available'],
                'status' => $file['status'],
                'name' => $file['file_name'],
                'access' => $isPublic ? 'public' : 'restricted',
                // Direct browser navigation can only use a public URL. Admin
                // downloads of restricted files use download_api_path with an
                // Authorization header and therefore never leak a token in a URL.
                'download_url' => $isPublic
                    ? ($isAttachment
                        ? $this->assets->publicUrl((string) ($document->attachment_path ?? ''))
                        : $this->documents->downloadUrl((int) $document->ID))
                    : null,
                'download_api_path' => !$isAttachment && $file['available']
                    ? '/documents/' . (int) $document->ID . '/download'
                    : null,
            ],
        ];
    }

    private function applySourceScope(Builder $query, string $source): void
    {
        if ($source === 'wpdmpro') {
            $query->where('p.post_type', 'wpdmpro');

            return;
        }

        if ($source === 'book_writing') {
            $this->applyBookWritingScope($query);

            return;
        }

        if ($source === 'attachments') {
            $this->applySupportAttachmentScope($query);

            return;
        }

        $query->where(function (Builder $scope) {
            $scope->where('p.post_type', 'wpdmpro')
                ->orWhere(function (Builder $attachments) {
                    $this->applySupportAttachmentScope($attachments);
                });
        });
    }

    private function applyBookWritingScope(Builder $query): void
    {
        $this->applySupportAttachmentScope($query);
        $query
            ->where(function (Builder $matches) {
                $matches->where('attachment_file.meta_value', 'like', '%' . self::BOOK_WRITING_FILE_PREFIX . '%')
                    ->orWhere('p.post_name', 'like', strtolower(self::BOOK_WRITING_FILE_PREFIX) . '%')
                    ->orWhere('p.post_title', 'like', str_replace('-', ' ', self::BOOK_WRITING_FILE_PREFIX) . '%');
            });
    }

    private function applySupportAttachmentScope(Builder $query): void
    {
        $query->where('p.post_type', 'attachment')
            ->where('p.post_status', 'inherit')
            ->where(function (Builder $mime) {
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

    private function isBookWritingAttachment(object $document): bool
    {
        return str_contains((string) ($document->attachment_path ?? ''), self::BOOK_WRITING_FILE_PREFIX)
            || str_starts_with(strtolower((string) $document->post_name), strtolower(self::BOOK_WRITING_FILE_PREFIX))
            || str_starts_with((string) $document->post_title, str_replace('-', ' ', self::BOOK_WRITING_FILE_PREFIX));
    }

    /** @return array{id:int,name:string,slug:string} */
    private function supportAttachmentCategory(string $title, string $excerpt): array
    {
        $title = mb_strtolower(trim($title));
        $startsWith = static function (array $prefixes) use ($title): bool {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($title, $prefix)) {
                    return true;
                }
            }

            return false;
        };

        $slug = 'dokumen-pendukung-lainnya';
        if ($startsWith(['spmi -', 'dokumen spmi -'])) {
            $slug = 'dokumen-spmi-penelitian-dan-pengabdian';
        } elseif ($startsWith(['renstra -', 'peraturan rektor -', 'perrek -'])) {
            $slug = 'peraturan-rektor-dan-renstra';
        } elseif ($startsWith(['sk penerima hibah -'])) {
            $slug = 'sk-penerima-hibah';
        } elseif ($startsWith(['sk tim reviewer -'])) {
            $slug = 'sk-tim-reviewer';
        } elseif ($startsWith(['sk panitia -'])) {
            $slug = 'sk-panitia';
        } elseif ($startsWith(['panduan penelitian dan pkm -', 'panduan penelitian -', 'panduan pkm -'])) {
            $slug = 'panduan-penelitian-dan-pkm';
        } elseif ($startsWith(['data penelitian/pkm/buku/hki -', 'data penelitian -', 'hki -', 'buku -'])) {
            $slug = 'data-penelitian-pkm-buku-dan-hki';
        } elseif ($startsWith(['surat keputusan -', 'sk -'])) {
            $slug = 'surat-keputusan';
        }

        $position = array_search($slug, array_keys(self::SUPPORT_CATEGORIES), true);
        return [
            'id' => -100 - (is_int($position) ? $position : 0),
            'name' => self::SUPPORT_CATEGORIES[$slug],
            'slug' => $slug,
        ];
    }

    /** @return array{available:bool,status:string,source_meta_key:?string,file_name:?string,resolved_path:?string} */
    private function attachmentFile(string $path): array
    {
        $relativePath = $this->assets->normalizeRelativePath($path);
        if ($relativePath === null) {
            return [
                'available' => false,
                'status' => 'missing_file_reference',
                'source_meta_key' => '_wp_attached_file',
                'file_name' => null,
                'resolved_path' => null,
            ];
        }

        $exists = $this->assets->exists($relativePath);
        return [
            'available' => $exists === true,
            'status' => $exists === true ? 'resolved' : ($exists === null ? 'root_unavailable' : 'missing_file'),
            'source_meta_key' => '_wp_attached_file',
            'file_name' => basename($relativePath),
            'resolved_path' => null,
        ];
    }
}
