<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Controlled create/update writer for supported legacy WordPress taxonomies. */
final class WordpressTaxonomyWriter
{
    /** @var array<string,string> */
    private const TAXONOMIES = [
        'categories' => 'category',
        'tags' => 'post_tag',
        'document-categories' => 'wpdmcategory',
    ];

    public function __construct(
        private readonly WordpressTableResolver $tables,
        private readonly WordpressContentAuthorization $authorization,
        private readonly CmsAuditLogger $audit
    ) {
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{name:string,slug?:string|null,description?:string|null,parent_id?:int|null} $input
     * @return array{id:int,name:string,slug:string,description:string,parent_id:?int,count:int}
     */
    public function create(string $routeTaxonomy, array $actor, array $input): array
    {
        $taxonomy = $this->wordpressTaxonomy($routeTaxonomy);
        $this->authorization->ensureCanManageTaxonomy($actor);

        return $this->tables->connection()->transaction(function () use ($taxonomy, $routeTaxonomy, $actor, $input) {
            $name = $this->normalizedName($input['name']);
            $slug = $this->uniqueSlug($taxonomy, (string) ($input['slug'] ?? $name));
            $parentId = $this->validatedParent($taxonomy, $input['parent_id'] ?? null);
            $description = trim((string) ($input['description'] ?? ''));
            $termsTable = $this->tables->table('terms');
            $taxonomyTable = $this->tables->table('term_taxonomy');

            $termId = (int) $this->tables->connection()->table($termsTable)->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'term_group' => 0,
            ]);
            $this->tables->connection()->table($taxonomyTable)->insert([
                'term_id' => $termId,
                'taxonomy' => $taxonomy,
                'description' => $description,
                'parent' => $parentId ?? 0,
                'count' => 0,
            ]);

            $result = [
                'id' => $termId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'parent_id' => $parentId,
                'count' => 0,
            ];
            $this->audit->contentMutation('cms.taxonomy.created', $actor, [
                'taxonomy' => $taxonomy,
                'term_id' => $termId,
                'route_taxonomy' => $routeTaxonomy,
            ]);

            return $result;
        });
    }

    /**
     * @param array{id:int,username:string,capabilities?:list<string>} $actor
     * @param array{name:string,slug?:string|null,description?:string|null,parent_id?:int|null} $input
     * @return array{id:int,name:string,slug:string,description:string,parent_id:?int,count:int}
     */
    public function update(string $routeTaxonomy, int $termId, array $actor, array $input): array
    {
        $taxonomy = $this->wordpressTaxonomy($routeTaxonomy);
        $this->authorization->ensureCanManageTaxonomy($actor);

        return $this->tables->connection()->transaction(function () use ($taxonomy, $routeTaxonomy, $termId, $actor, $input) {
            $taxonomyRow = $this->lockedTaxonomy($taxonomy, $termId);
            $this->assertTermIsNotShared($termId);
            $name = $this->normalizedName($input['name']);
            $slug = $this->uniqueSlug($taxonomy, (string) ($input['slug'] ?? $name), $termId);
            $parentId = $this->validatedParent($taxonomy, $input['parent_id'] ?? null, $termId);
            $description = trim((string) ($input['description'] ?? ''));

            $this->tables->connection()->table($this->tables->table('terms'))->where('term_id', $termId)->update([
                'name' => $name,
                'slug' => $slug,
            ]);
            $this->tables->connection()->table($this->tables->table('term_taxonomy'))
                ->where('term_taxonomy_id', $taxonomyRow->term_taxonomy_id)
                ->update([
                    'description' => $description,
                    'parent' => $parentId ?? 0,
                ]);

            $result = [
                'id' => $termId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'parent_id' => $parentId,
                'count' => (int) $taxonomyRow->count,
            ];
            $this->audit->contentMutation('cms.taxonomy.updated', $actor, [
                'taxonomy' => $taxonomy,
                'term_id' => $termId,
                'route_taxonomy' => $routeTaxonomy,
            ]);

            return $result;
        });
    }

    private function wordpressTaxonomy(string $routeTaxonomy): string
    {
        $taxonomy = self::TAXONOMIES[$routeTaxonomy] ?? null;
        if ($taxonomy === null) {
            throw new NotFoundHttpException('Taxonomy tidak ditemukan.');
        }

        return $taxonomy;
    }

    private function normalizedName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama taxonomy wajib diisi.']);
        }

        return $name;
    }

    private function uniqueSlug(string $taxonomy, string $value, ?int $exceptTermId = null): string
    {
        $base = Str::limit(Str::slug($value), 180, '');
        if ($base === '') {
            $base = 'term';
        }

        $termsTable = $this->tables->table('terms');
        $taxonomyTable = $this->tables->table('term_taxonomy');
        $candidate = $base;
        $number = 2;
        while (true) {
            $query = $this->tables->connection()->table("{$termsTable} as terms")
                ->join("{$taxonomyTable} as taxonomy", 'taxonomy.term_id', '=', 'terms.term_id')
                ->where('taxonomy.taxonomy', $taxonomy)
                ->where('terms.slug', $candidate);
            if ($exceptTermId !== null) {
                $query->where('terms.term_id', '!=', $exceptTermId);
            }
            if (!$query->exists()) {
                return $candidate;
            }

            $suffix = '-' . $number;
            $candidate = Str::limit($base, 200 - strlen($suffix), '') . $suffix;
            $number++;
        }
    }

    private function lockedTaxonomy(string $taxonomy, int $termId): object
    {
        $row = $this->tables->connection()->table($this->tables->table('term_taxonomy'))
            ->select(['term_taxonomy_id', 'term_id', 'taxonomy', 'parent', 'count'])
            ->where('term_id', $termId)
            ->where('taxonomy', $taxonomy)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw new NotFoundHttpException('Term taxonomy tidak ditemukan.');
        }

        return $row;
    }

    private function assertTermIsNotShared(int $termId): void
    {
        $count = $this->tables->connection()->table($this->tables->table('term_taxonomy'))
            ->where('term_id', $termId)
            ->count();
        if ($count > 1) {
            throw ValidationException::withMessages([
                'term' => 'Term legacy ini digunakan oleh lebih dari satu taxonomy dan tidak dapat diubah otomatis.',
            ]);
        }
    }

    private function validatedParent(string $taxonomy, ?int $parentId, ?int $termId = null): ?int
    {
        if ($parentId === null) {
            return null;
        }
        if (!in_array($taxonomy, ['category', 'wpdmcategory'], true)) {
            throw ValidationException::withMessages(['parent_id' => 'Taxonomy ini tidak mendukung parent.']);
        }
        if ($parentId < 1 || $parentId === $termId) {
            throw ValidationException::withMessages(['parent_id' => 'Parent kategori tidak valid.']);
        }

        $parent = $this->tables->connection()->table($this->tables->table('term_taxonomy'))
            ->select(['term_id', 'parent'])
            ->where('term_id', $parentId)
            ->where('taxonomy', $taxonomy)
            ->first();
        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'Parent kategori tidak ditemukan.']);
        }

        // Prevent a category from becoming a descendant of itself.
        $ancestor = $parent;
        $visited = [];
        while ((int) $ancestor->parent > 0) {
            $ancestorId = (int) $ancestor->parent;
            if ($ancestorId === $termId) {
                throw ValidationException::withMessages(['parent_id' => 'Parent kategori akan membentuk siklus.']);
            }
            if (isset($visited[$ancestorId])) {
                throw ValidationException::withMessages(['parent_id' => 'Hierarki kategori legacy tidak valid.']);
            }
            $visited[$ancestorId] = true;
            $ancestor = $this->tables->connection()->table($this->tables->table('term_taxonomy'))
                ->select(['term_id', 'parent'])
                ->where('term_id', $ancestorId)
                ->where('taxonomy', $taxonomy)
                ->first();
            if ($ancestor === null) {
                break;
            }
        }

        return $parentId;
    }
}
