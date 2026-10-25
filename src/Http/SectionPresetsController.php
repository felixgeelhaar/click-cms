<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Schema\JsonSectionPresetRepository;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Schema\SectionPresetNameConflict;
use Click\Cms\Infrastructure\Schema\SectionPresetNotFound;
use Click\Cms\Infrastructure\Schema\SectionPresetVersionConflict;
use RuntimeException;
use Throwable;

/** Private management API for a site's versioned section preset library. */
final class SectionPresetsController
{
    private readonly JsonSectionPresetRepository $presets;
    private readonly JsonSectionTypeRepository $types;
    private readonly SectionValidator $validator;
    private readonly string $starterPath;

    public function __construct(string $siteRoot, string $sectionTypesPath)
    {
        $this->presets = new JsonSectionPresetRepository($siteRoot);
        $this->types = new JsonSectionTypeRepository($sectionTypesPath);
        $this->validator = new SectionValidator();
        // Ship the built-in starter with application code so upgrades can
        // provide it even when site-owned config directories are preserved.
        $this->starterPath = dirname(__DIR__) . '/Resources/section-presets/business-site.json';
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function list(array $user): array
    {
        if (($failure = $this->authorize($user, Capability::UseSectionEditor)) !== null) return $failure;
        try {
            $records = array_map($this->withCompatibility(...), $this->presets->all());
            $starter = $this->checkedInStarter();
            if ($starter !== null) $records[] = $starter;
            usort($records, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            return ['status' => 200, 'data' => $records];
        } catch (Throwable $error) {
            return ['status' => 500, 'error' => 'Could not read the shared preset library.'];
        }
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function version(string $id, int $version, array $user): array
    {
        if (($failure = $this->authorize($user, Capability::UseSectionEditor)) !== null) return $failure;
        if ($version < 1) return ['status' => 400, 'error' => 'A valid preset version is required.'];
        try {
            if ($id === 'starter-business-site') {
                $starter = $this->checkedInStarter();
                return $version === 1 && $starter !== null
                    ? ['status' => 200, 'data' => $this->withCompatibility([
                        'id' => $starter['id'],
                        'name' => $starter['name'],
                        'version' => 1,
                        'sections' => $starter['sections'],
                    ])]
                    : ['status' => 404, 'error' => 'Preset version not found.'];
            }
            $snapshot = $this->presets->version($id, $version);
            return $snapshot === null
                ? ['status' => 404, 'error' => 'Preset version not found.']
                : ['status' => 200, 'data' => $this->withCompatibility($snapshot)];
        } catch (Throwable $error) {
            return ['status' => 500, 'error' => 'Could not read the shared preset library.'];
        }
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $user, array $input): array
    {
        if (($failure = $this->authorize($user, Capability::ManageSectionPresets)) !== null) return $failure;
        $validated = $this->validateInput($input);
        if (isset($validated['error'])) return ['status' => 422, 'error' => $validated['error'], 'details' => $validated['details'] ?? []];
        try {
            $starter = $this->checkedInStarter();
            if ($starter !== null && $this->foldName($starter['name']) === $this->foldName($validated['name'])) {
                return ['status' => 409, 'error' => 'A checked-in starter already uses this name.', 'data' => ['existingId' => $starter['id']]];
            }
            return ['status' => 201, 'data' => $this->presets->create($validated['name'], $validated['sections'], (string) $user['username'])];
        } catch (SectionPresetNameConflict $error) {
            return ['status' => 409, 'error' => $error->getMessage(), 'data' => ['existingId' => $error->existingId]];
        } catch (Throwable $error) {
            return ['status' => 500, 'error' => 'Could not save the shared preset.'];
        }
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $input @return array<string,mixed> */
    public function update(string $id, array $user, array $input): array
    {
        if (($failure = $this->authorize($user, Capability::ManageSectionPresets)) !== null) return $failure;
        $expectedVersion = $input['expectedVersion'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            return ['status' => 400, 'error' => 'A positive expectedVersion is required.'];
        }
        $validated = $this->validateInput($input);
        if (isset($validated['error'])) return ['status' => 422, 'error' => $validated['error'], 'details' => $validated['details'] ?? []];
        try {
            return ['status' => 200, 'data' => $this->presets->update($id, $expectedVersion, $validated['name'], $validated['sections'], (string) $user['username'])];
        } catch (SectionPresetVersionConflict $error) {
            return ['status' => 409, 'error' => $error->getMessage(), 'data' => ['currentVersion' => $error->currentVersion]];
        } catch (SectionPresetNameConflict $error) {
            return ['status' => 409, 'error' => $error->getMessage(), 'data' => ['existingId' => $error->existingId]];
        } catch (SectionPresetNotFound) {
            return ['status' => 404, 'error' => 'Preset not found.'];
        } catch (Throwable $error) {
            return ['status' => 500, 'error' => 'Could not update the shared preset.'];
        }
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function delete(string $id, array $user, mixed $expectedVersion): array
    {
        if (($failure = $this->authorize($user, Capability::ManageSectionPresets)) !== null) return $failure;
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            return ['status' => 400, 'error' => 'A positive expectedVersion is required.'];
        }
        try {
            if (!$this->presets->delete($id, $expectedVersion)) return ['status' => 404, 'error' => 'Preset not found.'];
            return ['status' => 200, 'data' => ['deleted' => true]];
        } catch (SectionPresetVersionConflict $error) {
            return ['status' => 409, 'error' => $error->getMessage(), 'data' => ['currentVersion' => $error->currentVersion]];
        } catch (Throwable $error) {
            return ['status' => 500, 'error' => 'Could not delete the shared preset.'];
        }
    }

    /** @param array<string,mixed> $user @return array<string,mixed>|null */
    private function authorize(array $user, Capability $capability): ?array
    {
        if (!is_string($user['username'] ?? null) || $user['username'] === '') {
            return ['status' => 401, 'error' => 'Sign in to access shared section presets.'];
        }
        if (!Role::fromName(is_string($user['role'] ?? null) ? $user['role'] : null)->can($capability)) {
            return ['status' => 403, 'error' => 'You do not have permission to manage shared section presets.'];
        }
        return null;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validateInput(array $input): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || $this->textLength($name) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            return ['error' => 'Preset names must contain 1 to 80 printable characters.'];
        }
        $sections = $input['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections) || $sections === [] || count($sections) > 100) {
            return ['error' => 'A preset must contain between 1 and 100 sections.'];
        }
        $normalized = [];
        $errors = [];
        foreach ($sections as $index => $section) {
            if (!is_array($section) || !is_string($section['type'] ?? null) || !is_array($section['values'] ?? null)) {
                $errors[(string) $index] = 'Each section needs a declared type and values object.';
                continue;
            }
            $type = $this->types->find($section['type']);
            if ($type === null) {
                $errors[(string) $index] = 'Section type is not declared for this site.';
                continue;
            }
            $result = $this->validator->validate($type, $section['values']);
            if (!$result->isValid()) {
                foreach ($result->errors as $field => $message) $errors["{$index}.{$field}"] = $message;
                continue;
            }
            $normalized[] = ['type' => $type->id, 'values' => $result->values];
        }
        return $errors === [] ? ['name' => $name, 'sections' => $normalized] : [
            'error' => 'One or more preset sections are invalid.',
            'details' => $errors,
        ];
    }

    /** @param array<string,mixed> $preset @return array<string,mixed> */
    private function withCompatibility(array $preset): array
    {
        $errors = [];
        foreach ($preset['sections'] as $index => $section) {
            $type = $this->types->find($section['type']);
            if ($type === null) {
                $errors[(string) $index] = 'Section type is no longer declared.';
                continue;
            }
            $result = $this->validator->validate($type, $section['values']);
            if (!$result->isValid()) {
                foreach ($result->errors as $field => $message) $errors["{$index}.{$field}"] = $message;
                continue;
            }
            $known = [];
            foreach ($type->fields as $field) $known[$field->name] = true;
            foreach (array_keys($section['values']) as $field) {
                if (!isset($known[$field])) $errors["{$index}.{$field}"] = 'This field is no longer declared.';
            }
        }
        $preset['compatible'] = $errors === [];
        $preset['compatibilityErrors'] = $errors;
        return $preset;
    }

    /** @return array<string,mixed>|null */
    private function checkedInStarter(): ?array
    {
        if (!is_file($this->starterPath)) return null;
        $raw = @file_get_contents($this->starterPath);
        $starter = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($starter) || ($starter['id'] ?? null) !== 'starter-business-site'
            || !is_string($starter['name'] ?? null) || !is_int($starter['version'] ?? null)
            || $starter['version'] !== 1 || !is_array($starter['sections'] ?? null)
            || !array_is_list($starter['sections']) || $starter['sections'] === []) {
            throw new RuntimeException('The checked-in business starter is invalid.');
        }
        $compatible = $this->withCompatibility([
            'id' => $starter['id'],
            'name' => $starter['name'],
            'currentVersion' => $starter['version'],
            'sections' => $starter['sections'],
            'versions' => [['version' => $starter['version'], 'updatedAt' => 'Source controlled', 'updatedBy' => 'Click CMS']],
        ]);
        return $compatible + ['editable' => false, 'origin' => 'source'];
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function foldName(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
