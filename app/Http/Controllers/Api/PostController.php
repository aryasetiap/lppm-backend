<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostController extends Controller
{
    /**
     * Menampilkan daftar berita (Pagination)
     */
    public function index()
    {
        // 1. Query Utama ke DB WordPress
        $posts = DB::connection('wordpress')
            ->table('2022_posts as p')
            ->select(
                'p.ID',
                'p.post_title',
                'p.post_date',
                'p.post_name as slug',
                'p.post_content', // Kita ambil untuk bikin excerpt, nanti dibuang agar ringan
                'img.guid as thumbnail_url', // URL Gambar
                'terms.name as category_name' // Nama Kategori
            )
            // JOIN GAMBAR (Featured Image)
            ->leftJoin('2022_postmeta as pm', function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')
                    ->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin('2022_posts as img', 'pm.meta_value', '=', 'img.ID')
            // JOIN KATEGORI
            ->leftJoin('2022_term_relationships as tr', 'p.ID', '=', 'tr.object_id')
            ->leftJoin('2022_term_taxonomy as tt', function ($join) {
                $join->on('tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
                    ->where('tt.taxonomy', 'category');
            })
            ->leftJoin('2022_terms as terms', 'tt.term_id', '=', 'terms.term_id')
            // FILTER HANYA BERITA PUBLISH
            ->where('p.post_status', 'publish')
            ->where('p.post_type', 'post')
            ->groupBy('p.ID') // Cegah duplikat jika 1 post punya banyak kategori
            ->orderBy('p.post_date', 'desc')
            ->paginate(10); // 10 Berita per halaman

        // 2. Cleaning Data (Looping hasil query)
        $cleanData = $posts->getCollection()->transform(function ($post) {
            return [
                'id' => $post->ID,
                'title' => $post->post_title,
                'slug' => $post->slug,
                'date' => date('d M Y', strtotime($post->post_date)), // Format: 20 Nov 2025
                'category' => $post->category_name ?? 'Umum',
                'thumbnail' => $this->fixImageUrl($post->thumbnail_url),
                'excerpt' => $this->makeExcerpt($post->post_content), // Buat cuplikan pendek
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $cleanData,
            'pagination' => [
                'total' => $posts->total(),
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
            ]
        ]);
    }

    /**
     * Menampilkan Detail Satu Berita
     */
    public function show($id)
    {
        $post = DB::connection('wordpress')
            ->table('2022_posts as p')
            ->select('p.*', 'img.guid as thumbnail_url', 'terms.name as category_name')
            ->leftJoin('2022_postmeta as pm', function ($join) {
                $join->on('p.ID', '=', 'pm.post_id')->where('pm.meta_key', '_thumbnail_id');
            })
            ->leftJoin('2022_posts as img', 'pm.meta_value', '=', 'img.ID')
            ->leftJoin('2022_term_relationships as tr', 'p.ID', '=', 'tr.object_id')
            ->leftJoin('2022_term_taxonomy as tt', function ($join) {
                $join->on('tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')->where('tt.taxonomy', 'category');
            })
            ->leftJoin('2022_terms as terms', 'tt.term_id', '=', 'terms.term_id')
            ->where('p.ID', $id)
            ->where('p.post_status', 'publish')
            ->first();

        if (!$post) {
            return response()->json(['message' => 'Berita tidak ditemukan'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $post->ID,
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'date' => date('l, d F Y', strtotime($post->post_date)),
                'category' => $post->category_name ?? 'Umum',
                'image' => $this->fixImageUrl($post->thumbnail_url),
                'content' => $this->cleanContent($post->post_content) // HTML BERSIH DI SINI
            ]
        ]);
    }

    // --- HELPER FUNCTIONS ---

    private function cleanContent($html)
    {
        // 1. Hapus wrapper Angular (ng-tns...)
        $clean = preg_replace('/<div class="ng-.*?">/', '', $html);
        $clean = str_replace('</div>', '', $clean); // Hapus sisa div tutup (agak agresif tapi aman untuk konten post)

        // 2. Hapus tag kosong
        $clean = preg_replace('/<p>&nbsp;<\/p>/', '', $clean);

        // 3. Perbaiki URL Gambar di dalam konten (jika masih relative path)
        // Misal src="/wp-content/..." jadi src="https://lppm.unila.ac.id/wp-content/..."
        $clean = str_replace('src="/wp-content', 'src="https://lppm.unila.ac.id/wp-content', $clean);

        return $clean;
    }

    private function makeExcerpt($html)
    {
        // Bersihkan tag HTML, ambil 150 karakter pertama
        $text = strip_tags($this->cleanContent($html));
        return Str::limit($text, 150, '...');
    }

    private function fixImageUrl($url)
    {
        if (!$url) return 'https://placehold.co/600x400?text=No+Image'; // Placeholder jika tidak ada gambar

        // Jika URL di database masih http, ubah ke https (opsional)
        return str_replace('http://', 'https://', $url);
    }
}
