<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

/**
 * Schema endpoints the CMS cannot function without.
 *
 * An editor cannot choose a section design without these. Page management lives
 * on {@see PagesController}; media on {@see MediaController}. Anything a site
 * can genuinely run without stays a plugin.
 *
 * Note the deliberate split from the `rest-api` plugin. That plugin is the
 * *public delivery* API — the one an external front end consumes — and is
 * optional, because a site that renders its own pages has no use for it. What
 * lives here is the *management* API for section types, which is not optional.
 */
final class CoreApiRoutes
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
     * Where this site's section types are declared.
     *
     * A site's own `config/sections/` when it has one, and the installation's
     * otherwise. That fallback is what lets an agency keep one shared set of
     * designs across eight client sites while any one of them departs from it,
     * without copying the other seven.
     */
    private function sectionTypesPath(): string
    {
        $own = $this->basePath . '/config/sections';

        if (is_dir($own)) {
            return $own;
        }

        return ($this->installRoot ?? $this->basePath) . '/config/sections';
    }

    /**
     * @return array<string, callable>
     */
    public function routes(): array
    {
        return [
            'GET /api/section-types' => [$this, 'listSectionTypes'],
            'GET /api/section-types/:id' => [$this, 'getSectionType'],
        ];
    }

    /* ------------------------------------------------------------ schema -- */

    /**
     * @return array<string, mixed>
     */
    public function listSectionTypes(): array
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
    public function getSectionType(string $id): array
    {
        $type = $this->sectionTypes()->find($id);

        return $type === null
            ? ['status' => 404, 'error' => 'Section type not found']
            : ['data' => $type->toArray()];
    }

    /**
     * Validate a section payload against its declared type.
     *
     * @param array<string, mixed> $values
     * @return array{valid: bool, values: array<string, mixed>, errors: array<string, string>}
     */
    public function validateSection(string $typeId, array $values): array
    {
        $type = $this->sectionTypes()->find($typeId);

        if ($type === null) {
            return [
                'valid' => false,
                'values' => [],
                'errors' => ['type' => "Unknown section type \"{$typeId}\"."],
            ];
        }

        $result = (new SectionValidator())->validate($type, $values);

        return [
            'valid' => $result->isValid(),
            'values' => $result->values,
            'errors' => $result->errors,
        ];
    }

    private function sectionTypes(): JsonSectionTypeRepository
    {
        return $this->sectionTypes ??= new JsonSectionTypeRepository(
            $this->sectionTypesPath()
        );
    }
}
