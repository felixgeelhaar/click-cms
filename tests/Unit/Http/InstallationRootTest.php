<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * Where the installation lives on disk, when it is not where the entry script
 * would suggest.
 *
 * On shared hosting the whole account is served from one document root, so an
 * installation put inside it exposes `content/`, `data/` and `config/` to the
 * internet unless the app root is moved above it. `Application` has always been
 * able to take the path — but the only place to pass it was `public/index.php`,
 * which a release replaces, so an operator's edit survived exactly until their
 * first update. An environment variable is a setting the server holds, and the
 * updater cannot overwrite the server's configuration.
 */
final class InstallationRootTest extends TestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = getenv(Application::ROOT_ENV) ?: null;
        putenv(Application::ROOT_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            putenv(Application::ROOT_ENV . '=' . $this->previous);
        } else {
            putenv(Application::ROOT_ENV);
        }
    }

    public function testWithoutTheVariableTheRootIsTheInstallationItself(): void
    {
        $this->assertSame(dirname(__DIR__, 3), (new Application())->getBasePath());
    }

    public function testTheVariableMovesTheRootOffTheDocumentRoot(): void
    {
        $root = sys_get_temp_dir() . '/click-cms-root-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        try {
            putenv(Application::ROOT_ENV . '=' . $root);

            $this->assertSame($root, (new Application())->getBasePath());
        } finally {
            @rmdir($root);
        }
    }

    /**
     * A typo in a server config must not take the site down with a blank page of
     * missing-file errors. Falling back to the installation is the behaviour the
     * site had before anyone set the variable, which is the safe direction.
     */
    public function testADirectoryThatDoesNotExistIsIgnored(): void
    {
        putenv(Application::ROOT_ENV . '=/no/such/directory/anywhere');

        $this->assertSame(dirname(__DIR__, 3), (new Application())->getBasePath());
    }

    /**
     * The production shape. Apache's `SetEnv` reached PHP only as
     * `REDIRECT_CLICK_CMS_ROOT` on a cgi-fcgi SAPI, so reading `getenv()` alone
     * found nothing: the site fell back to the directory above `public/`, could
     * not find its own `vendor/`, and answered 500 while being correctly
     * configured the whole time.
     */
    public function testARootSetByApacheThroughARedirectIsFound(): void
    {
        $root = sys_get_temp_dir() . '/click-cms-redirect-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);
        $previous = $_SERVER;

        try {
            unset($_SERVER[Application::ROOT_ENV]);
            $_SERVER['REDIRECT_' . Application::ROOT_ENV] = $root;

            $this->assertSame($root, (new Application())->getBasePath());
        } finally {
            $_SERVER = $previous;
            @rmdir($root);
        }
    }

    /** An explicit path still wins — it is what every test and tool passes. */
    public function testAnExplicitPathBeatsTheVariable(): void
    {
        putenv(Application::ROOT_ENV . '=' . sys_get_temp_dir());

        $this->assertSame('/somewhere/explicit', (new Application('/somewhere/explicit'))->getBasePath());
    }
}
