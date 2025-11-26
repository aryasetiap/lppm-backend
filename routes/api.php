<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\PosApController;

// Endpoint Berita
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{id}', [PostController::class, 'show']);

// Endpoint Login Admin (WordPress Legacy)
Route::post('/admin/login', [AuthController::class, 'login']);

// Endpoint Content Management (Protected)
Route::middleware('auth.admin')->group(function () {
    Route::get('/admin/content/{filename}', [ContentController::class, 'show']);
    Route::put('/admin/content/{filename}', [ContentController::class, 'update']);
});

// POS-AP public endpoints
Route::get('/pos-ap/downloads', [PosApController::class, 'downloads']);
Route::get('/pos-ap/categories', [PosApController::class, 'categories']);
