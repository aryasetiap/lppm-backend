<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminPostController;
use App\Http\Controllers\Api\AdminMediaController;
use App\Http\Controllers\Api\AdminMediaUploadController;
use App\Http\Controllers\Api\AdminTaxonomyController;
use App\Http\Controllers\Api\AdminAuthorController;
use App\Http\Controllers\Api\AdminCmsPostController;
use App\Http\Controllers\Api\AdminCmsPublicationController;
use App\Http\Controllers\Api\AdminCmsTrashController;
use App\Http\Controllers\Api\AdminCmsRevisionController;
use App\Http\Controllers\Api\AdminCmsTaxonomyController;
use App\Http\Controllers\Api\AdminCmsDocumentController;
use App\Http\Controllers\Api\AdminCmsDocumentMutationController;
use App\Http\Controllers\Api\DocumentDownloadController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\PublicContentImageController;
use App\Http\Controllers\Api\BookWritingController;
use App\Http\Controllers\Api\PosApController;

// Endpoint Berita
Route::get('/posts/categories', [PostController::class, 'categories']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/slug/{slug}', [PostController::class, 'showBySlug']);
Route::get('/posts/{id}', [PostController::class, 'show']);

// Public Content Endpoint (for Homepage & Dashboard)
Route::get('/content/{filename}', [ContentController::class, 'show']);
Route::get('/content-images/{folder}/{filename}', [PublicContentImageController::class, 'show'])
    ->where('folder', '[A-Za-z0-9_-]+')
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('content-images.show');

// Endpoint Login Admin (WordPress Legacy)
Route::post('/admin/login', [AuthController::class, 'login']);

// Endpoint Content Management (Protected)
Route::middleware('auth.admin')->group(function () {
    Route::get('/admin/me', [AuthController::class, 'me']);
    Route::delete('/admin/logout', [AuthController::class, 'logout']);
    Route::get('/admin/authors', [AdminAuthorController::class, 'index']);
    Route::get('/admin/posts', [AdminPostController::class, 'index']);
    Route::get('/admin/posts/{post}', [AdminPostController::class, 'show'])->whereNumber('post');
    Route::post('/admin/cms/posts', [AdminCmsPostController::class, 'store']);
    Route::patch('/admin/cms/posts/{post}', [AdminCmsPostController::class, 'update'])->whereNumber('post');
    Route::post('/admin/cms/posts/{post}/publish', [AdminCmsPublicationController::class, 'publish'])->whereNumber('post');
    Route::post('/admin/cms/posts/{post}/schedule', [AdminCmsPublicationController::class, 'schedule'])->whereNumber('post');
    Route::post('/admin/cms/posts/{post}/trash', [AdminCmsTrashController::class, 'trash'])->whereNumber('post');
    Route::post('/admin/cms/posts/{post}/restore', [AdminCmsTrashController::class, 'restore'])->whereNumber('post');
    Route::get('/admin/cms/posts/{post}/revisions', [AdminCmsRevisionController::class, 'index'])->whereNumber('post');
    Route::post('/admin/cms/posts/{post}/revisions/{revision}/restore', [AdminCmsRevisionController::class, 'restore'])
        ->whereNumber('post')
        ->whereNumber('revision');
    Route::get('/admin/media', [AdminMediaController::class, 'index']);
    Route::post('/admin/media/images', [AdminMediaUploadController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/admin/media/{media}', [AdminMediaController::class, 'show'])->whereNumber('media');
    Route::get('/admin/cms/documents', [AdminCmsDocumentController::class, 'index']);
    Route::get('/admin/cms/documents/{document}', [AdminCmsDocumentController::class, 'show'])->whereNumber('document');
    Route::post('/admin/cms/documents', [AdminCmsDocumentMutationController::class, 'store']);
    Route::patch('/admin/cms/documents/{document}', [AdminCmsDocumentMutationController::class, 'update'])->whereNumber('document');
    Route::post('/admin/cms/documents/{document}/file', [AdminCmsDocumentMutationController::class, 'upload'])
        ->whereNumber('document')
        ->middleware('throttle:10,1');
    Route::post('/admin/cms/documents/{document}/publish', [AdminCmsDocumentMutationController::class, 'publish'])->whereNumber('document');
    Route::post('/admin/cms/documents/{document}/access', [AdminCmsDocumentMutationController::class, 'updateAccess'])->whereNumber('document');
    Route::post('/admin/cms/documents/{document}/trash', [AdminCmsDocumentMutationController::class, 'trash'])->whereNumber('document');
    Route::post('/admin/cms/documents/{document}/restore', [AdminCmsDocumentMutationController::class, 'restore'])->whereNumber('document');
    Route::get('/admin/taxonomies/{taxonomy}', [AdminTaxonomyController::class, 'index'])
        ->whereIn('taxonomy', ['categories', 'tags', 'document-categories']);
    Route::post('/admin/taxonomies/{taxonomy}', [AdminCmsTaxonomyController::class, 'store'])
        ->whereIn('taxonomy', ['categories', 'tags', 'document-categories']);
    Route::patch('/admin/taxonomies/{taxonomy}/{term}', [AdminCmsTaxonomyController::class, 'update'])
        ->whereIn('taxonomy', ['categories', 'tags', 'document-categories'])
        ->whereNumber('term');
    Route::get('/admin/content/{filename}', [ContentController::class, 'show']);
    Route::put('/admin/content/{filename}', [ContentController::class, 'update']);
    Route::post('/admin/upload-image', [ContentController::class, 'uploadImage']);
});

// POS-AP public endpoints
Route::get('/pos-ap/downloads', [PosApController::class, 'downloads']);
Route::get('/pos-ap/categories', [PosApController::class, 'categories']);

// Penulisan Buku public endpoint
Route::get('/penulisan-buku/downloads', [BookWritingController::class, 'downloads']);

// General Documents endpoint
Route::get('/documents', [\App\Http\Controllers\Api\DocumentController::class, 'index']);
Route::get('/documents/{document}/download', [DocumentDownloadController::class, 'show'])->whereNumber('document');
