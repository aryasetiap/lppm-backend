<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/cek-koneksi', function () {
    try {
        // Mencoba mengambil 1 judul berita dari tabel WP
        $data = DB::connection('wordpress')->table('2022_posts')
            ->where('post_type', 'post')
            ->first();

        return "KONEKSI SUKSES! Judul Berita: " . $data->post_title;
    } catch (\Exception $e) {
        return "GAGAL :( Error: " . $e->getMessage();
    }
});
