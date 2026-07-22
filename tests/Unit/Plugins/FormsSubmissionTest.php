<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The forms plugin, exercised as itself.
 *
 * A contact form is the one place a public, unauthenticated visitor writes into
 * the CMS, so every test here pins a property that keeps that write safe: a
 * submission is refused unless it is well-formed, a bot that trips the honeypot
 * is accepted-but-dropped so it cannot tell it was caught, and — the sharpest
 * one — whatever HTML a visitor types is stored as inert data, never as markup
 * that anything downstream could execute. That last case is the stored-XSS
 * failure a form invites by definition: the field exists to accept arbitrary
 * text from someone with no account, and the moment that text is trusted it is
 * an injection surface handed to whoever reads the submission later.
 */
final class FormsSubmissionTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private object $plugin;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-forms-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        );
        $this->content = new ContentService($storage);

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService($this->content);

        require_once dirname(__DIR__, 3) . '/plugins/forms/bootstrap.php';
        $this->plugin = new \Plugin_forms($manager);

        $_POST = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /**
     * Submit a form the way an anonymous visitor's browser would: values in
     * $_POST, which is what the plugin reads when there is no JSON body.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function submit(array $fields): array
    {
        $_POST = $fields;
        $response = $this->plugin->handleSubmit();
        $_POST = [];

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function storedSubmissions(): array
    {
        $listing = $this->plugin->handleSubmissions();

        return $listing['data'] ?? [];
    }

    private function validFields(array $overrides = []): array
    {
        return array_merge([
            'page' => 'contact',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'message' => 'I would like to know more.',
            'website' => '',
        ], $overrides);
    }

    /* -------------------------------------------------- a valid submission -- */

    public function testAValidSubmissionIsStored(): void
    {
        $response = $this->submit($this->validFields());

        $this->assertTrue($response['success'] ?? false, 'a valid submission is accepted');

        $stored = $this->storedSubmissions();
        $this->assertCount(1, $stored);
        $this->assertSame('contact', $stored[0]['page']);
        $this->assertSame('Ada Lovelace', $stored[0]['fields']['name']);
        $this->assertSame('ada@example.com', $stored[0]['fields']['email']);
        $this->assertSame('I would like to know more.', $stored[0]['fields']['message']);
        $this->assertArrayHasKey('submittedAt', $stored[0]);
    }

    public function testAStoredSubmissionIsAContentDocument(): void
    {
        // Stored through the content service so it inherits backups and storage,
        // rather than in a parallel file store the rest of the CMS cannot see.
        $this->submit($this->validFields());

        $documents = $this->content->all('form_submission');

        $this->assertCount(1, $documents);
        $this->assertSame('form_submission', $documents[0]->type());
    }

    /* ----------------------------------------------------------- refusals -- */

    public function testASubmissionMissingARequiredFieldIsRefused(): void
    {
        $response = $this->submit($this->validFields(['message' => '']));

        $this->assertSame(400, $response['status'] ?? 200);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame([], $this->storedSubmissions(), 'nothing is stored when a field is missing');
    }

    public function testASubmissionWithABadEmailIsRefused(): void
    {
        $response = $this->submit($this->validFields(['email' => 'not-an-email']));

        $this->assertSame(400, $response['status'] ?? 200);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame([], $this->storedSubmissions(), 'nothing is stored for a bad email');
    }

    /* ------------------------------------------------------------ honeypot -- */

    public function testAFilledHoneypotIsSilentlyDropped(): void
    {
        // A bot filled the hidden field. It must be accepted — an identical
        // success response, no error, no hint it was caught — and stored nowhere.
        $response = $this->submit($this->validFields(['website' => 'http://spam.example']));

        $this->assertTrue($response['success'] ?? false, 'the bot sees an ordinary success');
        $this->assertArrayNotHasKey('error', $response);
        $this->assertNotSame(400, $response['status'] ?? 200);
        $this->assertSame([], $this->storedSubmissions(), 'the honeypot submission is dropped');
    }

    /* ------------------------------------------------------ newest first -- */

    public function testSubmissionsAreListedNewestFirst(): void
    {
        $this->submit($this->validFields(['message' => 'first']));
        $this->submit($this->validFields(['message' => 'second']));
        $this->submit($this->validFields(['message' => 'third']));

        $messages = array_map(
            static fn (array $s): string => $s['fields']['message'],
            $this->storedSubmissions()
        );

        $this->assertSame(['third', 'second', 'first'], $messages);
    }

    /* ---------------------------------------------- untrusted input is data -- */

    public function testSubmittedHtmlIsStoredAsInertDataNotExecutableMarkup(): void
    {
        $payload = '<script>alert(document.cookie)</script>';

        $this->submit($this->validFields(['message' => $payload]));

        $stored = $this->storedSubmissions();

        // Stored verbatim, as the exact bytes the visitor typed — a string, not
        // parsed, not run, not written to any path. Whoever displays it escapes
        // it; storage's job is to keep it as data.
        $this->assertSame($payload, $stored[0]['fields']['message']);

        // And on disk it is a JSON string value, not something the store treated
        // as anything other than text.
        $onDisk = $this->content->all('form_submission')[0]->data;
        $this->assertIsString($onDisk['fields']['message']);
        $this->assertSame($payload, $onDisk['fields']['message']);
    }
}
