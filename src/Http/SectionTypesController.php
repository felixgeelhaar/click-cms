<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

/**
 * Section-type schema endpoints the admin editor cannot function without.
 *
 * An editor cannot choose a section design without these. Page management
 * lives on {@see PagesController}; media on {@see MediaController}. This is
 * what remains of the old CoreApiRoutes bag after those peels.
 */
final class SectionTypesController
{
    private ?JsonSectionTypeRepository $sectionTypes = null;

    /**
     * @param string  $basePath    The **site's** root. A site may declare its
     *        own section types under config/sections/.
     * @param ?string $installRoot The installation's root, for the shared schema
     *        fallback. Null means they are the same directory (single-site).
     */
    public function __construct(
        private readonly string $basePath,
        private readonly ?string $installRoot = null,
    ) {
    }

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
            'GET /api/section-types' => [$this, 'list'],
            'GET /api/section-types/:id' => [$this, 'get'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        $repo = $this->sectionTypes();

        $response = [
            'data' => array_map(
                static fn (SectionType $type): array => $type->toArray(),
                $repo->all()
            ),
        ];

        // Surface malformed definitions rather than pretending they are absent,
        // so an author notices a typo instead of wondering where a type went.
        $errors = $repo->errors();
        if ($errors !== []) {
            $response['warnings'] = $errors;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $type = $this->sectionTypes()->find($id);

        return $type === null
            ? ApiFault::of(404, 'Section type not found', 'not_found')
            : ['data' => $type->toArray()];
    }

    /**
     * A site's own `config/sections/` when it has one, and the installation's
     * otherwise — so an agency can share designs across sites while any one
     * of them departs without copying the rest.
     */
    private function sectionTypesPath(): string
    {
        $own = $this->basePath . '/config/sections';

        if (is_dir($own)) {
            return $own;
        }

        return ($this->installRoot ?? $this->basePath) . '/config/sections';
    }

    private function sectionTypes(): JsonSectionTypeRepository
    {
        return $this->sectionTypes ??= new JsonSectionTypeRepository(
            $this->sectionTypesPath()
        );
    }
}
