<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

use Click\Cms\Domain\Schema\SectionTypeRepository;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

/**
 * Serves the section types a site declares.
 *
 * The admin UI needs this to populate its "add a section" picker, and any
 * front end needs it to know what shapes it may receive. Section types are
 * site-owned configuration, so the CMS ships none of its own — this plugin
 * only exposes whatever the site has declared in config/sections.
 *
 * Read-only: definitions are code-reviewed configuration, not editor content,
 * so there is deliberately no endpoint to create or change them at runtime.
 */
class Plugin_section_types extends \Click\Cms\Application\Plugin\BasePlugin
{
    private ?SectionTypeRepository $repository = null;

    public function getPluginId(): string
    {
        return 'section-types';
    }

    public function getPluginName(): string
    {
        return 'Section Types';
    }

    public function install(): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function deactivate(): bool
    {
        $this->repository = null;

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /**
     * @return array<string, callable>
     */
    public function hook_api_routes(array $params): array
    {
        return [
            'GET /api/section-types' => [$this, 'listTypes'],
            'GET /api/section-types/:id' => [$this, 'getType'],
        ];
    }

    /**
     * Handler arguments are bound by parameter name to the route's `:placeholders`,
     * so this takes none and getType() below takes $id.
     *
     * @return array<string, mixed>
     */
    public function listTypes(): array
    {
        $repo = $this->repository();

        $response = [
            'data' => array_map(
                static fn ($type): array => $type->toArray(),
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
    public function getType(string $id): array
    {
        $type = $this->repository()->find($id);

        if ($type === null) {
            return ['status' => 404, 'error' => 'Section type not found'];
        }

        return ['data' => $type->toArray()];
    }

    /**
     * Validate a section payload against its declared type.
     *
     * Exposed for other plugins rather than over HTTP: anything writing a
     * section should run its input through here so stored content can never
     * hold a shape the site's templates were not built for.
     *
     * @param array<string, mixed> $values
     * @return array{valid: bool, values: array<string, mixed>, errors: array<string, string>}
     */
    public function validateSection(string $typeId, array $values): array
    {
        $type = $this->repository()->find($typeId);

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

    private function repository(): SectionTypeRepository
    {
        return $this->repository ??= new JsonSectionTypeRepository($this->sectionsPath());
    }

    private function sectionsPath(): string
    {
        $configured = $this->getConfig('sections_path', 'config/sections');

        return $this->pluginManager->getBasePath() . '/' . ltrim((string) $configured, '/');
    }
}
