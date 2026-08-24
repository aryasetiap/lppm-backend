<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAssetResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PostController extends Controller
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressAssetResolver $assets
    ) {
    }

    /**
     * MENAMPILKAN DAFTAR BERITA (LIST)
     * Fitur: Pagination (9 item), Search, Filter Category
     * Endpoint: GET /api/posts
     * Parameter Opsional: ?page=2, ?keyword=publ, ?category=pengumuman
     */
    public function index(Request $request)
    {
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $termRelationshipsTable = $this->tables->table('term_relationships');
        $termTaxonomyTable = $this->tables->table('term_taxonomy');
        $termsTable = $this->tables->table('terms');

        // Prefix tabel dan URL attachment selalu berasal dari resolver.
        $query = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select(
                'p.ID',
                'p.post_title',
                'p.post_date',
                'p.post_name as slug',
                'p.post_content', // Diambil untuk generate excerpt
                'img_file.meta_value as thumbnail_path',
                'terms.name as category_name', // Nama Kategori (Visual)
                'terms.slug as category_slug'  // Slug Kategori (Untuk Filter)
            )
            // --- JOIN KE TABEL GAMBAR (FEATURED IMAGE) ---
            // Logic: Posts -> Postmeta (_thumbnail_id) -> Posts (Attachment)
            ->leftJoin("{$postmetaTable} as pm", function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')
                    ->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin("{$postsTable} as img", 'pm.meta_value', '=', 'img.ID')
            ->leftJoin("{$postmetaTable} as img_file", function ($join) {
                $join->on('img.ID', '=', 'img_file.post_id')
                    ->where('img_file.meta_key', '_wp_attached_file');
            })

            // --- JOIN KE TABEL KATEGORI ---
            // Logic: Posts -> Term Relationships -> Term Taxonomy -> Terms
            ->leftJoin("{$termRelationshipsTable} as tr", 'p.ID', '=', 'tr.object_id')
            ->leftJoin("{$termTaxonomyTable} as tt", function ($join) {
                $join->on('tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
                    ->where('tt.taxonomy', 'category');
            })
            ->leftJoin("{$termsTable} as terms", 'tt.term_id', '=', 'terms.term_id')

            // --- FILTER WAJIB (Hanya Berita Terbit) ---
            ->where('p.post_status', 'publish')
            ->where('p.post_type', 'post');

        // 2. LOGIKA PENCARIAN (SEARCH)
        // Jika ada parameter ?keyword=... di URL
        if ($keyword = $request->query('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('p.post_title', 'like', '%' . $keyword . '%')
                    ->orWhere('p.post_content', 'like', '%' . $keyword . '%');
            });
        }

        // 3. LOGIKA FILTER KATEGORI
        // Jika ada parameter ?category=... (slug) di URL
        if ($category = $request->query('category')) {
            $query->where('terms.slug', $category);
        }

        // 4. EKSEKUSI QUERY (Pagination 9 per halaman)
        // GroupBy ID penting agar jika 1 post punya 2 kategori, tidak muncul ganda
        $posts = $query->groupBy('p.ID')
            ->orderBy('p.post_date', 'desc') // Terbitkan terbaru dulu
            ->paginate(9); // SESUAI REQUEST: 9 ITEM

        // 5. CLEANING DATA (Transformasi Hasil)
        // Loop setiap item untuk membersihkan data sebelum dikirim ke JSON
        $cleanData = $posts->getCollection()->transform(function ($post) {
            return [
                'id' => $post->ID,
                'title' => $post->post_title,
                'slug' => $post->slug,
                // Format Tanggal: 20 Nov 2025
                'date' => date('d M Y', strtotime($post->post_date)),
                'category' => $post->category_name ?? 'Umum',
                'category_slug' => $post->category_slug ?? 'umum',
                // Fix URL Gambar (Placeholder jika kosong)
                'thumbnail' => $this->fixImageUrl($post->thumbnail_path),
                // Buat cuplikan teks pendek dari konten
                'excerpt' => $this->makeExcerpt($post->post_content),
            ];
        });

        // 6. RETURN JSON RESPONSE
        return response()->json([
            'status' => 'success',
            'data' => $cleanData,
            'pagination' => [
                'total' => $posts->total(),
                'per_page' => $posts->perPage(),
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                // URL Next/Prev berguna untuk Frontend
                'next_page_url' => $posts->nextPageUrl(),
                'prev_page_url' => $posts->previousPageUrl(),
            ]
        ]);
    }

    /**
     * MENAMPILKAN DETAIL SATU BERITA BY SLUG
     * Endpoint: GET /api/posts/slug/{slug}
     */
    public function showBySlug($slug)
    {
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $usersTable = $this->tables->table('users');

        // 1. Fetch Main Post
        $post = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select(
                'p.*',
                'img_file.meta_value as thumbnail_path',
                'u.display_name as author_name' // Ambil nama author
            )
            // Join Gambar
            ->leftJoin("{$postmetaTable} as pm", function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin("{$postsTable} as img", 'pm.meta_value', '=', 'img.ID')
            ->leftJoin("{$postmetaTable} as img_file", function ($join) {
                $join->on('img.ID', '=', 'img_file.post_id')->where('img_file.meta_key', '_wp_attached_file');
            })
            // Join Author
            ->leftJoin("{$usersTable} as u", 'p.post_author', '=', 'u.ID')
            ->where('p.post_name', $slug)
            ->where('p.post_status', 'publish')
            ->first();

        if (!$post) {
            return response()->json(['message' => 'Berita tidak ditemukan'], 404);
        }

        // 2. Fetch Categories & Tags belonging to this post
        $terms = $this->tables->connection()
            ->table($this->tables->table('term_relationships') . ' as tr')
            ->join($this->tables->table('term_taxonomy') . ' as tt', 'tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
            ->join($this->tables->table('terms') . ' as t', 'tt.term_id', '=', 't.term_id')
            ->where('tr.object_id', $post->ID)
            ->select('t.name', 't.slug', 'tt.taxonomy')
            ->get();

        $categories = $terms->where('taxonomy', 'category')->values();
        $tags = $terms->where('taxonomy', 'post_tag')->values();
        
        // Ambil kategori utama untuk related posts
        $mainCategorySlug = $categories->first()->slug ?? 'umum';

        // Penghitung views tidak ditulis pada Fase 1 agar akses WordPress tetap read-only.

        // 4. Return Data
        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $post->ID,
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'date' => date('l, d F Y', strtotime($post->post_date)),
                'author' => $post->author_name ?? 'Admin LPPM',
                'categories' => $categories->map(fn($c) => ['name' => $c->name, 'slug' => $c->slug]),
                'tags' => $tags->map(fn($t) => ['name' => $t->name, 'slug' => $t->slug]),
                'image' => $this->fixImageUrl($post->thumbnail_path),
                'content' => $this->cleanContent($post->post_content),
                // Data Tambahan untuk Widget
                'related_posts' => $this->getRelatedPosts($post->ID, $mainCategorySlug),
                'recent_posts' => $this->getRecentPosts($post->ID),
            ]
        ]);
    }

    /**
     * Helper: Get Related Posts by Category
     */
    private function getRelatedPosts($currentId, $categorySlug)
    {
        $postsTable = $this->tables->table('posts');
        $postmetaTable = $this->tables->table('postmeta');
        $termRelationshipsTable = $this->tables->table('term_relationships');
        $termTaxonomyTable = $this->tables->table('term_taxonomy');
        $termsTable = $this->tables->table('terms');

        $posts = $this->tables->connection()
            ->table("{$postsTable} as p")
            ->select('p.ID', 'p.post_title', 'p.post_name as slug', 'p.post_date', 'img_file.meta_value as thumbnail_path')
            // Join Gambar
            ->leftJoin("{$postmetaTable} as pm", function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin("{$postsTable} as img", 'pm.meta_value', '=', 'img.ID')
            ->leftJoin("{$postmetaTable} as img_file", function ($join) {
                $join->on('img.ID', '=', 'img_file.post_id')->where('img_file.meta_key', '_wp_attached_file');
            })
            // Join Category
            ->join("{$termRelationshipsTable} as tr", 'p.ID', '=', 'tr.object_id')
            ->join("{$termTaxonomyTable} as tt", 'tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
            ->join("{$termsTable} as t", 'tt.term_id', '=', 't.term_id')
            ->where('t.slug', $categorySlug)
            ->where('p.post_status', 'publish')
            ->where('p.ID', '!=', $currentId) // Validasi exclude current post
            ->where('p.post_type', 'post')
            ->orderBy('p.post_date', 'desc')
            ->limit(3)
            ->distinct()
            ->get();

        return $posts->transform(function ($p) {
            return [
                'title' => $p->post_title,
                'slug' => $p->slug,
                'date' => date('d M Y', strtotime($p->post_date)),
                'image' => $this->fixImageUrl($p->thumbnail_path)
            ];
        });
    }

    /**
     * Helper: Get Recent Posts (Sidebar)
     */
    private function getRecentPosts($currentId)
    {
        $posts = $this->tables->connection()
            ->table($this->tables->table('posts') . ' as p')
            ->select('p.ID', 'p.post_title', 'p.post_name as slug', 'p.post_date')
            ->where('p.post_status', 'publish')
            ->where('p.post_type', 'post')
            ->where('p.ID', '!=', $currentId)
            ->orderBy('p.post_date', 'desc')
            ->limit(5)
            ->get();

        return $posts->transform(function ($p) {
            return [
                'title' => $p->post_title,
                'slug' => $p->slug,
                'date' => date('d M Y', strtotime($p->post_date)),
            ];
        });
    }

    /**
     * MENAMPILKAN DETAIL SATU BERITA (LEGACY ID)
     * Endpoint: GET /api/posts/{id}
     */
    public function show($id)
    {
        return $this->showBySlug($id); // Fallback logic or keep purely ID based if preferred, but for now we focus on slug
    }

    // ==========================================
    // HELPER FUNCTIONS (FUNGSI BANTUAN PRIVAT)
    // ==========================================

    /**
     * Membersihkan HTML dari Tag Angular & Shortcodes
     */
    private function cleanContent($html)
    {
        // 1. Hapus wrapper Angular (ng-tns...) dengan Regex
        $clean = preg_replace('/<div class="ng-.*?">/', '', $html);

        // 2. Hapus tag penutup div sisa (agak agresif, tapi aman untuk konten post standar)
        $clean = str_replace('</div>', '', $clean);

        // 3. Hapus tag paragraf kosong (&nbsp;)
        $clean = preg_replace('/<p>&nbsp;<\/p>/', '', $clean);

        // Perbaiki URL relatif legacy tanpa menanam domain ke kode.
        $siteUrl = rtrim((string) config('services.wordpress.site_url', ''), '/');
        if ($siteUrl !== '') {
            $clean = str_replace('src="/wp-content', 'src="' . $siteUrl . '/wp-content', $clean);
        }

        return $clean;
    }

    /**
     * Membuat cuplikan teks pendek (Excerpt)
     */
    private function makeExcerpt($html)
    {
        // Bersihkan semua tag HTML, ambil teksnya saja
        $text = strip_tags($this->cleanContent($html));
        // Potong jadi 150 karakter + '...'
        return Str::limit($text, 150, '...');
    }

    /**
     * Memperbaiki URL Gambar (HTTPS & Placeholder)
     */
    private function fixImageUrl(?string $relativePath): string
    {
        return $this->assets->publicUrl($relativePath)
            ?? 'https://placehold.co/600x400?text=No+Image';
    }
    /**
     * MENAMPILKAN DAFTAR KATEGORI
     * Endpoint: GET /api/posts/categories
     */
    public function categories()
    {
        $categories = $this->tables->connection()
            ->table($this->tables->table('terms') . ' as t')
            ->join($this->tables->table('term_taxonomy') . ' as tt', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'category')
            ->where('tt.count', '>', 0) // Hanya kategori yang ada isinya
            ->select('t.term_id', 't.name', 't.slug')
            ->orderBy('t.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories
        ]);
    }
}
