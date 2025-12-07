<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostController extends Controller
{
    /**
     * MENAMPILKAN DAFTAR BERITA (LIST)
     * Fitur: Pagination (9 item), Search, Filter Category
     * Endpoint: GET /api/posts
     * Parameter Opsional: ?page=2, ?keyword=publ, ?category=pengumuman
     */
    public function index(Request $request)
    {
        // 1. Inisialisasi Query Builder ke DB WordPress
        // Kita gunakan '2022_posts' sebagai tabel utama (alias 'p')
        $query = DB::connection('wordpress')
            ->table('2022_posts as p')
            ->select(
                'p.ID',
                'p.post_title',
                'p.post_date',
                'p.post_name as slug',
                'p.post_content', // Diambil untuk generate excerpt
                'img.guid as thumbnail_url', // URL Gambar Featured
                'terms.name as category_name', // Nama Kategori (Visual)
                'terms.slug as category_slug'  // Slug Kategori (Untuk Filter)
            )
            // --- JOIN KE TABEL GAMBAR (FEATURED IMAGE) ---
            // Logic: Posts -> Postmeta (_thumbnail_id) -> Posts (Attachment)
            ->leftJoin('2022_postmeta as pm', function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')
                    ->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin('2022_posts as img', 'pm.meta_value', '=', 'img.ID')

            // --- JOIN KE TABEL KATEGORI ---
            // Logic: Posts -> Term Relationships -> Term Taxonomy -> Terms
            ->leftJoin('2022_term_relationships as tr', 'p.ID', '=', 'tr.object_id')
            ->leftJoin('2022_term_taxonomy as tt', function ($join) {
                $join->on('tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
                    ->where('tt.taxonomy', 'category');
            })
            ->leftJoin('2022_terms as terms', 'tt.term_id', '=', 'terms.term_id')

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
                'thumbnail' => $this->fixImageUrl($post->thumbnail_url),
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
        // 1. Fetch Main Post
        $post = DB::connection('wordpress')
            ->table('2022_posts as p')
            ->select(
                'p.*',
                'img.guid as thumbnail_url',
                'u.display_name as author_name' // Ambil nama author
            )
            // Join Gambar
            ->leftJoin('2022_postmeta as pm', function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin('2022_posts as img', 'pm.meta_value', '=', 'img.ID')
            // Join Author
            ->leftJoin('2022_users as u', 'p.post_author', '=', 'u.ID')
            ->where('p.post_name', $slug)
            ->where('p.post_status', 'publish')
            ->first();

        if (!$post) {
            return response()->json(['message' => 'Berita tidak ditemukan'], 404);
        }

        // 2. Fetch Categories & Tags belonging to this post
        $terms = DB::connection('wordpress')
            ->table('2022_term_relationships as tr')
            ->join('2022_term_taxonomy as tt', 'tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
            ->join('2022_terms as t', 'tt.term_id', '=', 't.term_id')
            ->where('tr.object_id', $post->ID)
            ->select('t.name', 't.slug', 'tt.taxonomy')
            ->get();

        $categories = $terms->where('taxonomy', 'category')->values();
        $tags = $terms->where('taxonomy', 'post_tag')->values();
        
        // Ambil kategori utama untuk related posts
        $mainCategorySlug = $categories->first()->slug ?? 'umum';

        // 3. Increment Views (Optional - Simpan di meta jika perlu, atau custom table)
        // DB::connection('wordpress')->table('2022_postmeta')->updateOrInsert(...)

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
                'image' => $this->fixImageUrl($post->thumbnail_url),
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
        $posts = DB::connection('wordpress')
            ->table('2022_posts as p')
            ->select('p.ID', 'p.post_title', 'p.post_name as slug', 'p.post_date', 'img.guid as thumbnail_url')
            // Join Gambar
            ->leftJoin('2022_postmeta as pm', function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin('2022_posts as img', 'pm.meta_value', '=', 'img.ID')
            // Join Category
            ->join('2022_term_relationships as tr', 'p.ID', '=', 'tr.object_id')
            ->join('2022_term_taxonomy as tt', 'tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
            ->join('2022_terms as t', 'tt.term_id', '=', 't.term_id')
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
                'image' => $this->fixImageUrl($p->thumbnail_url)
            ];
        });
    }

    /**
     * Helper: Get Recent Posts (Sidebar)
     */
    private function getRecentPosts($currentId)
    {
        $posts = DB::connection('wordpress')
            ->table('2022_posts as p')
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

        // 4. Perbaiki URL Gambar Relative (src="/wp-content...") menjadi Absolute
        $clean = str_replace('src="/wp-content', 'src="https://lppm.unila.ac.id/wp-content', $clean);

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
    private function fixImageUrl($url)
    {
        // Jika tidak ada gambar, pakai placeholder
        if (!$url) return 'https://placehold.co/600x400?text=No+Image';

        // Jika URL relative (mulai dengan /), tambahkan domain
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $url = 'https://lppm.unila.ac.id' . $url;
        }

        // Pastikan URL menggunakan HTTPS agar tidak diblokir browser modern
        $url = str_replace('http://', 'https://', $url);

        // Pastikan URL lengkap (ada http:// atau https://)
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = 'https://lppm.unila.ac.id' . $url;
        }

        return $url;
    }
    /**
     * MENAMPILKAN DAFTAR KATEGORI
     * Endpoint: GET /api/posts/categories
     */
    public function categories()
    {
        $categories = DB::connection('wordpress')
            ->table('2022_terms as t')
            ->join('2022_term_taxonomy as tt', 't.term_id', '=', 'tt.term_id')
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
