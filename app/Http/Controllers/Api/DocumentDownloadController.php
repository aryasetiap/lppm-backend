<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WordpressAdminSession;
use App\Support\WordpressContentAuthorization;
use App\Support\WordpressDocumentResolver;
use App\Support\WordpressTableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentDownloadController extends Controller
{
    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressDocumentResolver $documents,
        private readonly WordpressAdminSession $sessions,
        private readonly WordpressContentAuthorization $authorization
    ) {
    }

    /** GET /api/documents/{document}/download */
    public function show(Request $request, int $document): BinaryFileResponse|JsonResponse
    {
        $item = $this->tables->connection()
            ->table($this->tables->table('posts'))
            ->select(['ID', 'post_status'])
            ->where('ID', $document)
            ->where('post_type', 'wpdmpro')
            ->first();

        if ($item === null) {
            return $this->unavailable();
        }

        $metadata = $this->metadataFor((int) $item->ID);
        $file = $this->documents->resolve($metadata);
        if (!$file['available'] || $file['resolved_path'] === null) {
            return $this->unavailable();
        }

        $public = (string) $item->post_status === 'publish' && $this->documents->isPublic($metadata);
        if (!$public) {
            $admin = $this->sessions->resolve($request->bearerToken());
            if ($admin === null) {
                // Do not disclose restricted document existence to anonymous users.
                return $this->unavailable();
            }

            $this->authorization->ensureCanReadDocuments($admin);
        }

        return response()->download($file['resolved_path'], $file['file_name'] ?? 'dokumen');
    }

    /** @return array<string, list<string>> */
    private function metadataFor(int $documentId): array
    {
        $metadata = [];
        $this->tables->connection()
            ->table($this->tables->table('postmeta'))
            ->select(['meta_key', 'meta_value'])
            ->where('post_id', $documentId)
            ->whereIn('meta_key', ['__lppm_document_file', '__wpdm_files', '__wpdm_access'])
            ->orderBy('meta_id')
            ->get()
            ->each(function ($row) use (&$metadata): void {
                $metadata[(string) $row->meta_key][] = (string) $row->meta_value;
            });

        return $metadata;
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'meta' => [
                'code' => 404,
                'status' => 'error',
                'message' => 'Dokumen tidak tersedia untuk diunduh.',
            ],
        ], 404);
    }
}
