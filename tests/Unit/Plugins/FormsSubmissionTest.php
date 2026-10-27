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

    private function emailAdmin(string $role = 'admin'): void
    {
        @mkdir($this->base . '/data/sessions', 0700, true);
        $id = bin2hex(random_bytes(32));
        file_put_contents($this->base . '/data/sessions/' . $id . '.json', json_encode([
            'expiresAt' => time() + 3600, 'lastActivity' => time(),
            'user' => ['username' => 'tester', 'role' => $role],
        ]));
        $_COOKIE[\Click\Cms\Application\Authentication\SessionStore::COOKIE] = $id;
    }

    public function testEmailConfigurationIsAdminOnlyIncludingDirectCalls(): void
    {
        self::assertSame(403, $this->plugin->handleEmailSettings('GET')['status']);
        self::assertSame(403, $this->plugin->handleEmailTest()['status']);
        $this->emailAdmin('editor');
        self::assertSame(403, $this->plugin->handleEmailSettings('PUT')['status']);
        self::assertSame(403, $this->plugin->handleEmailTest()['status']);
        $this->emailAdmin();
        self::assertFalse($this->plugin->handleEmailSettings('GET')['data']['enabled']);
    }

    public function testEmailSettingsPersistOutsideReleaseFilesAndTakePrecedenceOverLegacy(): void
    {
        $this->emailAdmin();
        mkdir($this->base . '/config');
        file_put_contents($this->base . '/config/forms.json', json_encode(['notifications' => [
            'enabled' => true, 'to' => 'legacy@example.invalid', 'from' => 'cms@example.invalid', 'locale' => 'de',
        ]]));
        self::assertSame('legacy@example.invalid', $this->plugin->handleEmailSettings('GET')['data']['to']);
        $_POST = ['enabled' => false, 'to' => 'new@example.invalid', 'from' => 'cms@example.invalid', 'locale' => 'en'];
        self::assertSame($_POST, $this->plugin->handleEmailSettings('PUT')['data']);
        self::assertFalse($this->plugin->handleEmailSettings('GET')['data']['enabled']);
        self::assertFileExists($this->base . '/data/forms/email-settings.json');
        self::assertSame(0600, fileperms($this->base . '/data/forms/email-settings.json') & 0777);
        self::assertTrue($this->submit(['name' => 'A', 'email' => 'a@example.invalid', 'message' => 'Saved'])['success']);
        self::assertSame('disabled', $this->storedSubmissions()[0]['notification']);
    }

    public function testInvalidEmailSettingsAndStorageFailuresAreReportedWithoutFalseSuccess(): void
    {
        $this->emailAdmin();
        $_POST = ['enabled' => true, 'to' => "a@example.invalid\r\nBcc: victim@example.invalid", 'from' => 'cms@example.invalid', 'locale' => 'en'];
        self::assertSame(422, $this->plugin->handleEmailSettings('PUT')['status']);
        self::assertFileDoesNotExist($this->base . '/data/forms/email-settings.json');
        $_POST['to'] = 'valid@example.invalid';
        file_put_contents($this->base . '/data/forms', 'unwritable directory');
        self::assertSame(503, $this->plugin->handleEmailSettings('PUT')['status']);
    }

    public function testTestEmailRequiresSavedAddressesAndHasPersistentAbuseLimit(): void
    {
        $this->emailAdmin();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->plugin->handleEmailTest()['status']);
        }
        self::assertSame(429, $this->plugin->handleEmailTest()['status']);
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

    public function testOversizedFieldsAreNotStored(): void
    {
        foreach (['name' => 201, 'email' => 255, 'message' => 10001] as $field => $length) {
            $response = $this->submit($this->validFields([$field => str_repeat('x', $length)]));
            self::assertSame(413, $response['status']);
        }
        self::assertCount(0, $this->storedSubmissions());
    }

    public function testOversizedBodyIsRejectedBeforeStorage(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '32769';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE';
        try {
            $response = $this->submit($this->validFields());
            self::assertSame(413, $response['status']);
            self::assertStringContainsString('zu lang', $response['error']);
            self::assertCount(0, $this->storedSubmissions());
        } finally {
            unset($_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        }
    }

    public function testGermanValidationAndConfirmationFollowTheFormLocale(): void
    {
        $response = $this->submit($this->validFields(['locale' => 'de-CH', 'email' => 'bad']));
        self::assertSame('Bitte geben Sie eine gültige E-Mail-Adresse ein.', $response['error']);
        $response = $this->submit($this->validFields(['locale' => 'de']));
        self::assertSame('Vielen Dank. Ihre Nachricht ist eingegangen.', $response['message']);
    }

    public function testRateLimitSurvivesASecondPluginInstanceAndDoesNotStoreExcessMessages(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->submit($this->validFields())['success']);
        }
        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService($this->content);
        $this->plugin = new \Plugin_forms($manager);
        $response = $this->submit($this->validFields(['locale' => 'de']));
        self::assertSame(429, $response['status']);
        self::assertStringContainsString('Zu viele', $response['error']);
        self::assertCount(5, $this->storedSubmissions());
    }

    public function testUnwritableRateStorageFailsClosed(): void
    {
        file_put_contents($this->base . '/data/forms', 'blocked');
        $response = $this->submit($this->validFields(['locale' => 'de']));
        self::assertSame(503, $response['status']);
        self::assertCount(0, $this->storedSubmissions());
    }

    public function testNotificationFailureKeepsTheMessageAndShowsItsStatus(): void
    {
        mkdir($this->base . '/config');
        file_put_contents($this->base . '/config/forms.json', json_encode([
            'notifications' => ['enabled' => true, 'to' => "admin@example.com\r\nBcc: other@example.com", 'from' => 'cms@example.com'],
        ]));
        self::assertTrue($this->submit($this->validFields())['success']);
        self::assertCount(1, $this->storedSubmissions());
        self::assertSame('failed', $this->storedSubmissions()[0]['notification']);
    }

    public function testEmailAlertsAreOffUntilConfigured(): void
    {
        self::assertTrue($this->submit($this->validFields())['success']);
        self::assertSame('disabled', $this->storedSubmissions()[0]['notification']);
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
