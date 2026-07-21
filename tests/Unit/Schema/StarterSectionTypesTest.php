<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Schema;

use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Guards the section types shipped in config/sections.
 *
 * They are examples a site is free to delete, but while they ship they are the
 * first thing anyone sees, so a broken one must fail the build rather than a
 * new user's install.
 */
final class StarterSectionTypesTest extends TestCase
{
    private JsonSectionTypeRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections');
    }

    public function testEveryStarterTypeParses(): void
    {
        $this->assertSame([], $this->repo->errors());
        $this->assertNotEmpty($this->repo->all());
    }

    public function testStarterTypesAreUsableWithTheValidator(): void
    {
        $validator = new SectionValidator();

        foreach ($this->repo->all() as $type) {
            // Empty input against a type with required fields must produce
            // errors, not a crash — this exercises every declared field.
            $result = $validator->validate($type, []);

            $this->assertIsArray($result->errors, "Validating {$type->id} did not return errors array");
        }
    }

    /**
     * The starter set must stay generic. A section type named after a specific
     * company or industry is a sign this drifted into being one site's schema.
     */
    public function testStarterTypesAreGenericallyNamed(): void
    {
        $tooSpecific = ['turbo', 'science', 'engineering', 'gmbh', 'client'];

        foreach ($this->repo->all() as $type) {
            $haystack = strtolower($type->id . ' ' . $type->label . ' ' . ($type->description ?? ''));

            foreach ($tooSpecific as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $haystack,
                    "Starter section type \"{$type->id}\" looks specific to one site."
                );
            }
        }
    }

    public function testStarterTypesDeclareLabelsForEveryField(): void
    {
        foreach ($this->repo->all() as $type) {
            foreach ($type->fields as $field) {
                $this->assertNotSame(
                    '',
                    trim($field->label),
                    "Field {$type->id}.{$field->name} has no label"
                );
            }
        }
    }
}
