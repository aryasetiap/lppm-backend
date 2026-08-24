<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;

/** Server-side WordPress capability policy for content mutations. */
final class WordpressContentAuthorization
{
    /**
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    public function ensureCanCreate(array $actor, string $type): void
    {
        $this->ensureCapability($actor, $this->editCapability($type));
    }

    /**
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    public function ensureCanEditDraft(array $actor, object $post): void
    {
        $type = (string) $post->post_type;
        $this->ensureCapability($actor, $this->editCapability($type));

        if ((int) $post->post_author !== $actor['id']) {
            $this->ensureCapability($actor, $this->editOthersCapability($type));
        }

        if ((string) $post->post_status !== 'draft') {
            throw new AuthorizationException('Checkpoint ini hanya mengizinkan penyuntingan draft.');
        }
    }

    /**
     * Allows an already-public news item to be edited without changing its
     * publication status. Pages are deliberately excluded until their public
     * URL/cutover policy is implemented.
     *
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    public function ensureCanEditPublishedPost(array $actor, object $post): void
    {
        if ((string) $post->post_type !== 'post' || (string) $post->post_status !== 'publish') {
            throw new AuthorizationException('Hanya berita yang sudah terbit yang dapat disunting pada checkpoint ini.');
        }

        $this->ensureCapability($actor, 'edit_posts');
        if ((int) $post->post_author !== $actor['id']) {
            $this->ensureCapability($actor, 'edit_others_posts');
        }
        $this->ensureCapability($actor, 'edit_published_posts');
    }

    /**
     * Shared revision policy for draft posts/pages and published news.
     *
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    public function ensureCanEditRevisionableContent(array $actor, object $post): void
    {
        if ((string) $post->post_status === 'draft') {
            $this->ensureCanEditDraft($actor, $post);

            return;
        }

        $this->ensureCanEditPublishedPost($actor, $post);
    }

    /**
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    public function ensureCanUploadMedia(array $actor): void
    {
        $this->ensureCapability($actor, 'upload_files');
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanManageTaxonomy(array $actor): void
    {
        // WordPress uses manage_categories as the classic capability for the
        // standard post category/tag administration screen.
        $this->ensureCapability($actor, 'manage_categories');
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanReadDocuments(array $actor): void
    {
        // The public guest rule is handled by WordpressDocumentResolver. Any
        // non-public package requires a valid WordPress reader capability.
        $this->ensureCapability($actor, 'read');
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanCreateDocument(array $actor): void
    {
        // `wpdmpro` does not have a stable custom capability mapping once the
        // plugin is removed. Until an Operator Dokumen policy is approved, the
        // standard administrator capabilities are deliberately required.
        $this->ensureCapability($actor, 'edit_posts');
        $this->ensureCanManageTaxonomy($actor);
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanEditDocumentDraft(array $actor, object $document): void
    {
        $this->ensureCanCreateDocument($actor);
        if ((int) $document->post_author !== $actor['id']) {
            $this->ensureCapability($actor, 'edit_others_posts');
        }
        if ((string) $document->post_status !== 'draft') {
            throw new AuthorizationException('Checkpoint ini hanya mengizinkan penyuntingan draft dokumen.');
        }
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanUploadDocument(array $actor, object $document): void
    {
        $this->ensureCanEditDocumentDraft($actor, $document);
        $this->ensureCapability($actor, 'upload_files');
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanPublishDocuments(array $actor): void
    {
        $this->ensureCanCreateDocument($actor);
        $this->ensureCapability($actor, 'publish_posts');
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanPublishDocument(array $actor, object $document): void
    {
        $this->ensureCanPublishDocuments($actor);
        if ((int) $document->post_author !== $actor['id']) {
            $this->ensureCapability($actor, 'edit_others_posts');
        }
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanTrashDocuments(array $actor): void
    {
        $this->ensureCanCreateDocument($actor);
        $this->ensureCapability($actor, 'delete_posts');
    }

    /** @param array{id:int,capabilities?:list<string>} $actor */
    public function ensureCanTrashOrRestoreDocument(array $actor, object $document, ?string $restoredStatus = null): void
    {
        $this->ensureCanTrashDocuments($actor);
        if ((int) $document->post_author !== $actor['id']) {
            $this->ensureCapability($actor, 'delete_others_posts');
        }
        if (($restoredStatus ?? (string) $document->post_status) === 'publish') {
            $this->ensureCapability($actor, 'delete_published_posts');
        }
    }

    /**
     * Publishing is intentionally narrower than creating a draft: only a
     * WordPress user with the standard post publishing capability may make a
     * news item public. Pages are held back until their public URL mapping is
     * implemented in a later checkpoint.
     *
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    public function ensureCanPublishPost(array $actor, object $post): void
    {
        if ((string) $post->post_type !== 'post') {
            throw new AuthorizationException('Checkpoint publikasi ini hanya tersedia untuk berita.');
        }

        $this->ensureCapability($actor, 'edit_posts');
        if ((int) $post->post_author !== $actor['id']) {
            $this->ensureCapability($actor, 'edit_others_posts');
        }
        $this->ensureCapability($actor, 'publish_posts');
    }

    /**
     * WordPress uses delete capabilities for both moving content to trash and
     * restoring it. Permanent deletion is intentionally not part of this
     * policy/checkpoint.
     *
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    public function ensureCanTrashOrRestore(array $actor, object $post, ?string $restoredStatus = null): void
    {
        $type = (string) $post->post_type;
        $this->ensureCapability($actor, $this->deleteCapability($type));

        if ((int) $post->post_author !== $actor['id']) {
            $this->ensureCapability($actor, $this->deleteOthersCapability($type));
        }

        if (($restoredStatus ?? (string) $post->post_status) === 'publish') {
            $this->ensureCapability($actor, $this->deletePublishedCapability($type));
        }
    }

    /**
     * @param array{id:int,capabilities?:list<string>} $actor
     */
    private function ensureCapability(array $actor, string $capability): void
    {
        if (in_array($capability, $actor['capabilities'] ?? [], true)) {
            return;
        }

        throw new AuthorizationException('Akun ini tidak memiliki capability WordPress yang diperlukan.');
    }

    private function editCapability(string $type): string
    {
        return $type === 'page' ? 'edit_pages' : 'edit_posts';
    }

    private function editOthersCapability(string $type): string
    {
        return $type === 'page' ? 'edit_others_pages' : 'edit_others_posts';
    }

    private function deleteCapability(string $type): string
    {
        return $type === 'page' ? 'delete_pages' : 'delete_posts';
    }

    private function deleteOthersCapability(string $type): string
    {
        return $type === 'page' ? 'delete_others_pages' : 'delete_others_posts';
    }

    private function deletePublishedCapability(string $type): string
    {
        return $type === 'page' ? 'delete_published_pages' : 'delete_published_posts';
    }
}
