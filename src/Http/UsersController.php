<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Managing the people who can sign in.
 *
 * This was in the `rest-api` plugin, which was named for a public delivery API
 * but had become the thing holding account management together — so a site that
 * disabled "the delivery API" to render its own pages would lose the ability to
 * manage users. That is the fake optionality core.md exists to prevent: the
 * admin UI cannot work without this, so it is core.
 *
 * Every response strips the password hash; only the length rule and hashing
 * happen here, and the coarse "may this caller manage users at all" gate is the
 * kernel's {@see ApiGuard}, which refuses a non-administrator before any of this
 * runs.
 */
final class UsersController
{
    /**
     * @param (callable(string, array<string, mixed>): void)|null $fireHook
     *        Lets plugins react to account changes (role change, deletion, …),
     *        preserved from the plugin this moved out of. Optional: core does not
     *        depend on any listener existing.
     */
    public function __construct(
        private readonly ContentService $content,
        private readonly CoreConfig $config,
        private $fireHook = null,
    ) {}

    /**
     * @return array<string, array{string, callable}>
     */
    public function routes(): array
    {
        return [
            'GET /api/users' => [$this, 'list'],
            'GET /api/users/:username' => [$this, 'get'],
            'POST /api/users' => [$this, 'create'],
            'PUT /api/users/:username' => [$this, 'update'],
            'DELETE /api/users/:username' => [$this, 'delete'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return [
            'data' => array_map(
                fn ($u): array => $this->withoutPassword($u->toArray()),
                $this->content->all('user')
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $username): array
    {
        $user = $this->content->user($username);

        return $user === null
            ? ['status' => 404, 'error' => 'User not found']
            : ['data' => $this->withoutPassword($user->toArray())];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $data = $this->jsonBody();

        if (!isset($data['email'])) {
            return ['status' => 400, 'error' => 'Email required'];
        }
        if (empty($data['password'])) {
            return ['status' => 400, 'error' => 'Password required'];
        }

        $bad = $this->rejectWeakPassword((string) $data['password']);
        if ($bad !== null) {
            return ['status' => 400, 'error' => $bad];
        }

        $data['password'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        $username = $data['username'] ?? $this->slugify((string) $data['email']);

        if ($this->content->user($username) !== null) {
            return ['status' => 409, 'error' => 'User already exists'];
        }

        $content = Content::create(ContentKey::user($username), $data);
        $this->content->save($content);

        $this->hook('user_create', [
            'username' => $username,
            'email' => $data['email'] ?? '',
            'role' => $data['role'] ?? 'editor',
        ]);

        return ['status' => 201, 'data' => $this->withoutPassword($content->toArray())];
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $username): array
    {
        $data = $this->jsonBody();
        $user = $this->content->user($username);
        if ($user === null) {
            return ['status' => 404, 'error' => 'User not found'];
        }

        // A blank password field means "leave it alone", not "set an empty
        // password" — the edit form sends the field either way.
        if (isset($data['password']) && $data['password'] !== '') {
            $bad = $this->rejectWeakPassword((string) $data['password']);
            if ($bad !== null) {
                return ['status' => 400, 'error' => $bad];
            }
            $data['password'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        } else {
            unset($data['password']);
        }

        $old = $user->toArray();
        $updated = $user->update($data);
        $this->content->save($updated);

        if (isset($data['role']) && $data['role'] !== ($old['role'] ?? '')) {
            $this->hook('user_role_change', [
                'username' => $username,
                'old_role' => $old['role'] ?? '',
                'new_role' => $data['role'],
            ]);
        }

        // The account's active/suspended state — a separate thing from the page
        // publication status draft-and-publish removed, and one core still reads
        // at login. So this event still has a field to fire on.
        if (isset($data['status']) && $data['status'] !== ($old['status'] ?? '')) {
            $this->hook('user_status_change', [
                'username' => $username,
                'old_status' => $old['status'] ?? '',
                'new_status' => $data['status'],
            ]);
        }

        return ['data' => $this->withoutPassword($updated->toArray())];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $username): array
    {
        if ($this->content->user($username) === null) {
            return ['status' => 404, 'error' => 'User not found'];
        }

        $this->content->delete(ContentKey::user($username));
        $this->hook('user_delete', ['username' => $username]);

        return ['data' => ['deleted' => true, 'username' => $username]];
    }

    /* -------------------------------------------------------- helpers -- */

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function withoutPassword(array $user): array
    {
        // Removed, not nulled: a `"password": null` in a response reads like an
        // account without one, which is alarming and untrue.
        unset($user['data']['password'], $user['password']);

        return $user;
    }

    private function rejectWeakPassword(string $password): ?string
    {
        $minimum = $this->config->passwordMinLength();
        if (mb_strlen($password) < $minimum) {
            return "Password must be at least {$minimum} characters.";
        }
        if (mb_strlen($password) > 128) {
            return 'Password must be less than 128 characters.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hook(string $event, array $payload): void
    {
        if ($this->fireHook !== null) {
            ($this->fireHook)($event, $payload);
        }
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9-]/', '-', $text) ?? $text;
        $text = preg_replace('/-+/', '-', $text) ?? $text;

        return trim($text, '-');
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
