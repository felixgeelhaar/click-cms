<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * The published builder document schema against the renderer that consumes it.
 *
 * Nothing validates a builder document against `visual-builder.schema.json` at
 * runtime, so the schema is documentation — and documentation with no test is
 * documentation that is already wrong. It fell a full six node types behind the
 * renderer before anyone looked. These two assertions are what stop that
 * happening again: a type the renderer draws must be published, and a type the
 * schema promises must actually draw.
 */
final class VisualBuilderSchemaTest extends TestCase
{
    /** @return list<string> */
    private function schemaTypes(): array
    {
        $path = dirname(__DIR__, 3) . '/schemas/visual-builder.schema.json';
        $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $enum = $schema['properties']['nodes']['additionalProperties']['properties']['type']['enum'] ?? null;
        $this->assertIsArray($enum, 'the schema must still declare the node type enum this test reads');

        return array_values($enum);
    }

    /** @return list<string> */
    private function rendererTypes(): array
    {
        require_once dirname(__DIR__, 3) . '/plugins/visual-builder/bootstrap.php';

        return \Plugin_visual_builder::NODE_TYPES;
    }

    public function testThePublishedSchemaListsEveryTypeTheRendererDraws(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff($this->rendererTypes(), $this->schemaTypes())),
            'a node type the renderer draws is missing from the published schema'
        );
    }

    public function testTheSchemaPromisesNoTypeTheRendererCannotDraw(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff($this->schemaTypes(), $this->rendererTypes())),
            'the schema promises a node type the renderer would skip'
        );
    }

    /**
     * The declared list is not a second, drifting copy of the dispatch: every
     * type in it renders *something*, and a type not in it renders nothing.
     */
    public function testEveryDeclaredTypeActuallyRendersAndAnUndeclaredOneDoesNot(): void
    {
        foreach ($this->rendererTypes() as $type) {
            $this->assertNotSame(
                '',
                $this->renderBare($type),
                "\"{$type}\" is declared as a node type but renders nothing"
            );
        }

        $this->assertSame('', $this->renderBare('definitely-not-a-node-type'));
    }

    /**
     * Render one node of the given type, with enough props that types requiring
     * content have some. What is asserted is only that markup came back.
     */
    private function renderBare(string $type): string
    {
        require_once dirname(__DIR__, 3) . '/plugins/visual-builder/bootstrap.php';
        $plugin = new \Plugin_visual_builder(new PluginManager(dirname(__DIR__, 3) . '/plugins'));

        $page = \Click\Cms\Domain\Content\Content::create(
            \Click\Cms\Domain\ValueObjects\ContentKey::page('landing'),
            ['title' => 'Landing', 'builder' => [
                'root' => 'n',
                'breakpoints' => [],
                'nodes' => ['n' => [
                    'type' => $type,
                    'children' => [],
                    'props' => [
                        'text' => 'Some words',
                        'label' => 'Some words',
                        'href' => 'https://example.com',
                        'src' => 'https://example.com/clip.mp4',
                        'alt' => 'A picture',
                        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        'items' => ['One', 'Two'],
                        'count' => 2,
                        'data' => [['label' => 'A', 'value' => 1]],
                    ],
                ]],
            ]]
        );

        $html = (string) $plugin->hook_web_render(['page' => $page, 'preview' => false]);

        // The shell wraps whatever the renderer produced; the node's own markup
        // is what carries the node id, so that is what is looked for.
        return str_contains($html, 'data-node-id="n"') ? $html : '';
    }
}
