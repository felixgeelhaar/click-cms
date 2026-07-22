<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Redirect\RedirectRules;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Managing redirect rules: send an old address to a new one.
 *
 * The rules live in a single content document (type `redirect`, slug `rules`),
 * so they inherit storage, versioning and backups like everything else and there
 * is no parallel store to keep in sync. Editing them is a management action —
 * ApiGuard's deny-by-default keeps these routes off the public surface — but the
 * kernel *reads* the same rules on the way to a 404 to serve the redirect.
 */
final class RedirectsController
{
    private const KEY_SLUG = 'rules';

    public function __construct(private readonly ContentService $content) {}

    /**
     * @return array<string, array{string, callable}>
     */
    public function routes(): array
    {
        return [
            'GET /api/redirects' => [$this, 'list'],
            'PUT /api/redirects' => [$this, 'replace'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return ['data' => $this->load()->toArray()];
    }

    /**
     * Replace the whole rule set. The editor sends the full list, which is
     * validated as a set — a hostile or malformed entry is dropped rather than
     * stored, so a bad paste cannot poison the rules the kernel later trusts.
     *
     * @return array<string, mixed>
     */
    public function replace(): array
    {
        $body = $this->jsonBody();
        $raw = is_array($body['redirects'] ?? null) ? $body['redirects'] : [];

        // Normalise through the domain, which drops anything unsafe, then store
        // only what survived — so what is on disk is always a set the kernel can
        // trust without re-checking.
        $clean = RedirectRules::fromArray($raw)->toArray();

        $this->content->save(Content::create($this->key(), ['redirects' => $clean]));

        return ['data' => $clean];
    }

    /**
     * The current rules, resolved for the kernel to match a request against.
     * Public method so the entry point can consult it before serving a 404.
     */
    public function rules(): RedirectRules
    {
        return $this->load();
    }

    private function load(): RedirectRules
    {
        $doc = $this->content->get($this->key());
        $raw = is_array($doc?->data['redirects'] ?? null) ? $doc->data['redirects'] : [];

        return RedirectRules::fromArray($raw);
    }

    private function key(): ContentKey
    {
        return ContentKey::fromString('redirect:' . self::KEY_SLUG);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return $_POST;
        }

        $data = json_decode($input, true);

        return is_array($data) ? $data : [];
    }
}
