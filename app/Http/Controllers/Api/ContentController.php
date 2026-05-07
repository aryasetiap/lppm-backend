<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentDataset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ContentController extends Controller
{
    /**
     * Mapping key dataset yang diizinkan.
     */
    private function normalizeDatasetKey(string $filename): ?string
    {
        $allowed = [
            'profile',
            'statistics',
            'sub-bagian',
        ];

        return in_array($filename, $allowed, true) ? $filename : null;
    }

    /**
     * GET /admin/content/{filename}
     * Mengambil data JSON berdasarkan filename.
     */
    public function show(string $filename): JsonResponse
    {
        $datasetKey = $this->normalizeDatasetKey($filename);

        if (!$datasetKey) {
            return response()->json([
                'meta' => [
                    'code' => 404,
                    'status' => 'error',
                    'message' => 'File tidak ditemukan',
                ],
            ], 404);
        }

        try {
            $dataset = ContentDataset::query()->where('dataset_key', $datasetKey)->first();
            $data = $dataset?->data;

            // Migrasi otomatis dari JSON lama jika record DB belum ada
            if (!$data) {
                $data = $this->getLegacyJsonData($datasetKey) ?? $this->getDefaultData($datasetKey);
            }

            // Pastikan struktur metadata ada (merge dengan default jika tidak ada)
            if (!isset($data['metadata']) || !is_array($data['metadata'])) {
                $defaultData = $this->getDefaultData($datasetKey);
                $data = array_merge($defaultData, $data);
                $data['metadata'] = array_merge($defaultData['metadata'], $data['metadata'] ?? []);
            }

            // Pastikan last_updated ada
            if (empty($data['metadata']['last_updated'])) {
                $data['metadata']['last_updated'] = now()->format('Y-m-d');
            }

            if (!$dataset) {
                ContentDataset::query()->create([
                    'dataset_key' => $datasetKey,
                    'data' => $data,
                ]);
            }

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Data berhasil diambil',
                ],
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal membaca data: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * PUT /admin/content/{filename}
     * Mengupdate data JSON berdasarkan filename.
     */
    public function update(Request $request, string $filename): JsonResponse
    {
        $datasetKey = $this->normalizeDatasetKey($filename);

        if (!$datasetKey) {
            return response()->json([
                'meta' => [
                    'code' => 404,
                    'status' => 'error',
                    'message' => 'File tidak ditemukan',
                ],
            ], 404);
        }

        // Validasi request body (fleksibel - hanya validasi tipe data jika field ada)
        $validator = Validator::make($request->all(), [
            'metadata' => 'sometimes|array',
            'metadata.last_updated' => 'nullable|string',
            'metadata.data_source' => 'nullable|string',
            'metadata.description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'meta' => [
                    'code' => 400,
                    'status' => 'error',
                    'message' => 'Data tidak valid',
                    'errors' => $validator->errors(),
                ],
            ], 400);
        }

        try {
            // Ambil data dari request
            $data = $request->all();

            $dataset = ContentDataset::query()->where('dataset_key', $datasetKey)->first();
            $existingData = $dataset?->data ?? $this->getLegacyJsonData($datasetKey) ?? $this->getDefaultData($datasetKey);

            // Merge data lama dengan data baru (data baru prioritas).
            // Untuk array numerik (list), wajib replace total agar proses hapus item
            // dari frontend benar-benar tersimpan ke database.
            $mergedData = $this->mergeContentData($existingData, $data);

            // Pastikan metadata ada dan lengkap
            if (!isset($mergedData['metadata']) || !is_array($mergedData['metadata'])) {
                $mergedData['metadata'] = [];
            }

            // Update last_updated otomatis
            $mergedData['metadata']['last_updated'] = now()->format('Y-m-d');

            // Set default metadata jika belum ada
            if (empty($mergedData['metadata']['data_source'])) {
                $mergedData['metadata']['data_source'] = 'LPPM Unila Database';
            }
            if (empty($mergedData['metadata']['description'])) {
                $mergedData['metadata']['description'] = $this->getDefaultDescription($datasetKey);
            }

            ContentDataset::query()->updateOrCreate(
                ['dataset_key' => $datasetKey],
                ['data' => $mergedData]
            );

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Data berhasil diupdate',
                ],
                'data' => [
                    'dataset_key' => $datasetKey,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal menyimpan data: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /admin/upload-image
     * Upload gambar dan kembalikan path publiknya.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:4096',
            'folder' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'meta' => [
                    'code' => 400,
                    'status' => 'error',
                    'message' => 'File upload tidak valid',
                    'errors' => $validator->errors(),
                ],
            ], 400);
        }

        try {
            $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->input('folder', 'general'));
            $folder = $folder !== '' ? $folder : 'general';

            $uploadDir = public_path('images/uploads/' . $folder);
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $file = $request->file('image');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $filename = Str::uuid() . '.' . $ext;
            $file->move($uploadDir, $filename);

            $path = '/images/uploads/' . $folder . '/' . $filename;

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Upload gambar berhasil',
                ],
                'data' => [
                    'path' => $path,
                    'url' => rtrim((string) config('app.url'), '/') . $path,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal upload gambar: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Membaca data JSON lama untuk migrasi otomatis saat pertama kali dipanggil.
     */
    private function getLegacyJsonData(string $filename): ?array
    {
        $mapping = [
            'profile' => public_path('data/profile-lppm.json'),
            'statistics' => public_path('data/statistics.json'),
            'sub-bagian' => public_path('data/sub-bagian-lppm.json'),
        ];

        $filePath = $mapping[$filename] ?? null;
        if (!$filePath || !is_file($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Merge recursive dengan aturan:
     * - Jika key tidak ada di data baru -> pertahankan data lama (mendukung partial update)
     * - Jika keduanya array asosiatif -> merge per key
     * - Jika salah satu array numerik (list) -> replace penuh dengan data baru
     * - Selain array -> gunakan nilai data baru
     */
    private function mergeContentData(array $existing, array $incoming): array
    {
        $merged = $existing;

        foreach ($incoming as $key => $value) {
            if (array_key_exists($key, $existing)) {
                if (is_array($existing[$key]) && is_array($value)) {
                    $existingIsList = array_is_list($existing[$key]);
                    $incomingIsList = array_is_list($value);

                    if ($existingIsList || $incomingIsList) {
                        $merged[$key] = $value;
                    } else {
                        $merged[$key] = $this->mergeContentData($existing[$key], $value);
                    }
                } else {
                    $merged[$key] = $value;
                }
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Get default data structure untuk file yang belum ada.
     */
    private function getDefaultData(string $filename): array
    {
        $defaults = [
            'profile' => [
                'metadata' => [
                    'last_updated' => now()->format('Y-m-d'),
                    'data_source' => 'LPPM Unila Database',
                    'description' => 'Profil lengkap LPPM Universitas Lampung',
                ],
                'pimpinan' => [
                    'kepala_lppm' => [
                        'nama' => '',
                        'foto' => '',
                        'placeholder' => 'https://via.placeholder.com/400x400',
                        'jabatan' => 'Kepala LPPM',
                        'periode' => '',
                    ],
                    'sekretaris_lppm' => [
                        'nama' => '',
                        'foto' => '',
                        'placeholder' => 'https://via.placeholder.com/400x400',
                        'jabatan' => 'Sekretaris LPPM',
                        'periode' => '',
                    ],
                ],
                'visi_misi' => [
                    'visi' => '',
                    'misi' => [],
                ],
                'tugas_fungsi' => [
                    'tugas' => [],
                    'fungsi' => [],
                ],
                'struktur_organisasi' => [
                    'gambar_struktur' => '',
                    'gambar_placeholder' => 'https://via.placeholder.com/1200x800',
                    'deskripsi' => '',
                ],
            ],
            'statistics' => [
                'metadata' => [
                    'last_updated' => now()->format('Y-m-d'),
                    'data_source' => 'LPPM Unila Database',
                    'description' => 'Statistik penelitian, pengabdian, dan HKI/Paten LPPM Universitas Lampung periode 2020-2025',
                ],
                'yearly_data' => [],
                'total_summary' => [
                    'total_penelitian_blu' => 0,
                    'total_pengabdian_blu' => 0,
                    'total_paten' => 0,
                    'total_haki' => 0,
                    'growth_penelitian' => 0,
                    'growth_pengabdian' => 0,
                    'growth_paten' => 0,
                    'growth_haki' => 0,
                ],
                'quarterly_data' => [],
            ],
            'sub-bagian' => [
                'metadata' => [
                    'last_updated' => now()->format('Y-m-d'),
                    'data_source' => 'LPPM Unila Database',
                    'description' => 'Data lengkap sub bagian dan unit di LPPM Universitas Lampung',
                ],
                'sub_bagian' => [
                    'pui' => [],
                    'puslit' => [],
                    'administrasi' => [],
                ],
            ],
        ];

        return $defaults[$filename] ?? [];
    }

    /**
     * Get default description berdasarkan filename.
     */
    private function getDefaultDescription(string $filename): string
    {
        $descriptions = [
            'profile' => 'Profil lengkap LPPM Universitas Lampung',
            'statistics' => 'Statistik penelitian, pengabdian, dan HKI/Paten LPPM Universitas Lampung periode 2020-2025',
            'sub-bagian' => 'Data lengkap sub bagian dan unit di LPPM Universitas Lampung',
        ];

        return $descriptions[$filename] ?? 'Data LPPM Universitas Lampung';
    }
}

