<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class ContentController extends Controller
{
    /**
     * Mapping filename ke path file.
     */
    private function getFilePath(string $filename): ?string
    {
        $mapping = [
            'profile' => public_path('data/profile-lppm.json'),
            'statistics' => public_path('data/statistics.json'),
            'sub-bagian' => public_path('data/sub-bagian-lppm.json'),
        ];

        return $mapping[$filename] ?? null;
    }

    /**
     * GET /admin/content/{filename}
     * Mengambil data JSON berdasarkan filename.
     */
    public function show(string $filename): JsonResponse
    {
        $filePath = $this->getFilePath($filename);

        if (!$filePath) {
            return response()->json([
                'meta' => [
                    'code' => 404,
                    'status' => 'error',
                    'message' => 'File tidak ditemukan',
                ],
            ], 404);
        }

        // Jika file belum ada, return struktur default kosong
        if (!File::exists($filePath)) {
            $defaultData = $this->getDefaultData($filename);
            
            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Data berhasil diambil',
                ],
                'data' => $defaultData,
            ]);
        }

        try {
            $content = File::get($filePath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'meta' => [
                        'code' => 500,
                        'status' => 'error',
                        'message' => 'File JSON tidak valid',
                    ],
                ], 500);
            }

            // Pastikan struktur metadata ada (merge dengan default jika tidak ada)
            if (!isset($data['metadata']) || !is_array($data['metadata'])) {
                $defaultData = $this->getDefaultData($filename);
                $data = array_merge($defaultData, $data);
                $data['metadata'] = array_merge($defaultData['metadata'], $data['metadata'] ?? []);
            }

            // Pastikan last_updated ada
            if (empty($data['metadata']['last_updated'])) {
                $data['metadata']['last_updated'] = now()->format('Y-m-d');
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
                    'message' => 'Gagal membaca file: ' . $e->getMessage(),
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
        $filePath = $this->getFilePath($filename);

        if (!$filePath) {
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
            // Backup file lama (opsional, tapi recommended)
            if (File::exists($filePath)) {
                $backupPath = $filePath . '.backup.' . date('Y-m-d_His');
                File::copy($filePath, $backupPath);
            }

            // Pastikan folder data ada
            $dataDir = public_path('data');
            if (!File::isDirectory($dataDir)) {
                File::makeDirectory($dataDir, 0755, true);
            }

            // Ambil data dari request
            $data = $request->all();
            
            // Jika file sudah ada, merge dengan data lama (partial update)
            $existingData = [];
            if (File::exists($filePath)) {
                $existingContent = File::get($filePath);
                $existingData = json_decode($existingContent, true) ?? [];
            }
            
            // Merge data lama dengan data baru (data baru prioritas)
            $mergedData = array_merge_recursive($existingData, $data);
            
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
                $mergedData['metadata']['description'] = $this->getDefaultDescription($filename);
            }
            
            // Gunakan merged data untuk disimpan
            $data = $mergedData;

            // Simpan ke file
            $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($jsonContent === false) {
                return response()->json([
                    'meta' => [
                        'code' => 500,
                        'status' => 'error',
                        'message' => 'Gagal mengkonversi data ke JSON',
                    ],
                ], 500);
            }

            File::put($filePath, $jsonContent);

            return response()->json([
                'meta' => [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Data berhasil diupdate',
                ],
                'data' => [
                    'filename' => basename($filePath),
                    'updated_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => 'Gagal menyimpan file: ' . $e->getMessage(),
                ],
            ], 500);
        }
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

