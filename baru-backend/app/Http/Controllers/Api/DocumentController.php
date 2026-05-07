<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
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
     * Helper untuk mendapatkan nama tabel WordPress sesuai prefix.
     */
    private function wpTable(string $table): string
    {
        // Fallback ke 'wp_' jika env tidak ada, tapi idealnya cek config
        // Kita coba deteksi apakah PostController pakai '2022_'
        // Untuk aman, kita pakai logic yang sama dengan PosApController dulu
        $prefix = env('DB_WP_PREFIX', 'wp_');
        // Jika di PostController hardcode '2022_', mungkin kita perlu sesuaikan
        // Tapi mari kita coba pakai env dulu atau default standard.
        // Cek PostController: ->table('2022_posts as p')
        // Sepertinya prefixnya '2022_'.
        // Mari kita buat dynamic tapi default ke '2022_' jika env tak set, atau ikuti env.
        
        return $prefix . $table;
    }

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

        // URL Base WordPress untuk link file
        $siteUrl = rtrim(config('services.wordpress.site_url', env('WP_BASE_URL', 'https://lppm.unila.ac.id')), '/');

        try {
            $connectionName = config('services.wordpress.connection', 'wordpress');
            $connection = DB::connection($connectionName);

            // Kita gunakan hardcode '2022_' jika env tidak ada, karena PostController pakai itu.
            // Atau lebih aman kita cek apakah tabel '2022_posts' ada?
            // Asumsi: ikuti pattern PostController yang sudah jalan.
            // Tapi PosApController pakai $this->wpTable.
            // Mari kita coba pakai '2022_' sebagai default prefix di sini jika env kosong.
            $prefix = env('DB_WP_PREFIX', '2022_'); 
            
            $postsTable = $prefix . 'posts';

            $query = $connection->table($postsTable)
                ->select([
                    'ID',
                    'post_title',
                    'post_date',
                    'post_mime_type',
                    'guid',
                    'post_excerpt',
                    'post_name'
                ])
                ->where('post_type', 'attachment')
                ->where('post_status', 'inherit'); // Attachment biasanya statusnya inherit

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
                $query->where('post_title', 'like', '%' . $search . '%');
            }

            // Terapkan filter kategori di level SQL (sebelum pagination)
            // agar hasil tidak hilang karena terpotong halaman.
            if (
                $categoryFilter !== '' &&
                isset(self::SUPPORT_CATEGORIES[$categoryFilter])
            ) {
                $this->applyCategorySqlFilter($query, $categoryFilter);
            }

            $query->orderByDesc('post_date');

            // Gunakan paginate() dari Laravel
            $paginator = $query->paginate($limit);

            $items = $paginator->getCollection()->map(function ($item) use ($siteUrl) {
                // Fix URL
                $url = $item->guid;
                if (strpos($url, 'http') !== 0) {
                     $url = $siteUrl . $url;
                }
                // Force HTTPS
                $url = str_replace('http://', 'https://', $url);

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
