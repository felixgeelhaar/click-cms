<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Webhook\WebhookDispatcher;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Webhook\WebhookEndpoint;
use Click\Cms\Domain\Webhook\WebhookSignature;
use Click\Cms\Domain\Webhook\WebhookUrlPolicy;
use Click\Cms\Infrastructure\Webhook\FileDeliveryQueue;
use Click\Cms\Infrastructure\Webhook\FileEndpointRepository;
use Click\Cms\Infrastructure\Webhook\TransportFactory;

/**
 * Webhooks: telling other systems that something here changed.
 *
 * A plugin, not core, and it passes `docs/core.md`'s test cleanly — a site that
 * renders its own pages has nothing to notify, and deleting this directory
 * leaves a CMS that works exactly as before. It exists because the opposite kind
 * of site cannot work without it: a statically generated front end has no way to
 * know a page was published, so either it rebuilds on a timer (stale for as long
 * as the timer is) or something tells it (this).
 *
 * ## The shape, and why it is queue-then-sweep
 *
 * The hooks below run inside somebody's Save. They do not send anything; they
 * write a row to `data/webhooks/queue/` and return. `bin/click-webhooks.php`,
 * from cron, does the sending.
 *
 * Sending inline was rejected for three reasons, in increasing severity: it puts
 * a remote host's latency on the editor's Save button; a receiver that hangs
 * holds a PHP worker until the timeout, and shared hosting has few workers; and
 * it cannot retry, so a receiver that happened to be restarting simply never
 * hears about the change — which makes the whole mechanism unreliable exactly
 * when reliability is the point.
 *
 * It is the same answer scheduled publishing reached, and for the same
 * underlying constraint: no daemon, no queue service, nothing that does not run
 * on a machine with only PHP and cron.
 *
 * ## What it will not do
 *
 * - **It never fails a write.** Every hook here swallows its own errors. A
 *   plugin that can break Save is a plugin that can take a site down, and these
 *   hooks are announcements after the fact — the content is already stored, so
 *   there is nothing useful to do with a failure but log it.
 * - **It never sends content bodies it was not given.** The payload carries
 *   identity — type, slug, locale, who acted — and not the document. That is
 *   {@see \Click\Cms\Application\Plugin\ContentGate}'s decision, not this
 *   plugin's, and it is a security one: users are stored as content documents,
 *   so a payload carrying `data` would post password hashes to a third party on
 *   every password change. A receiver that needs the body reads the delivery
 *   API for the key it was handed.
 * - **It never points inside the network** without the site saying so — see
 *   {@see WebhookUrlPolicy}, which is what stops a phished administrator turning
 *   this into a way to read cloud instance metadata.
 */
class Plugin_webhooks extends \Click\Cms\Application\Plugin\BasePlugin
{
    public function getPluginId(): string
    {
        return 'webhooks';
    }

    public function getPluginName(): string
    {
        return 'Webhooks';
    }

    /* ------------------------------------------------------------ events -- */

    /**
     * @param array<string, mixed> $params
     */
    public function hook_content_saved(array $params): void
    {
        $this->queue('content.saved', $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hook_content_deleted(array $params): void
    {
        $this->queue('content.deleted', $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hook_content_unpublished(array $params): void
    {
        $this->queue('content.unpublished', $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hook_content_published(array $params): void
    {
        $this->queue('content.published', $params);
    }

    /**
     * Queue one event, and never let anything here reach the caller.
     *
     * @param array<string, mixed> $params
     */
    private function queue(string $event, array $params): void
    {
        try {
            // Users are content documents. An account being saved is not a
            // content change anybody's front end wants, and it is the one
            // document type where even the *identity* — which account, when —
            // is worth withholding from a third party by default.
            if (($params['type'] ?? null) === 'user') {
                return;
            }

            $this->dispatcher()->dispatch($event, $this->payload($params));
        } catch (\Throwable $e) {
            error_log("click-cms webhooks: {$event} could not be queued: {$e->getMessage()}");
        }
    }

    /**
     * What a receiver is told.
     *
     * Deliberately identity only. See the class docblock — the hook does not
     * hand over `data`, and this does not go looking for it.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function payload(array $params): array
    {
        return [
            'key' => $params['key'] ?? null,
            'type' => $params['type'] ?? null,
            'slug' => $params['slug'] ?? null,
            'locale' => $params['locale'] ?? null,
            'user' => $params['user']['username'] ?? null,
        ];
    }

    /* ------------------------------------------------------------ routes -- */

    /**
     * @param array<string, mixed> $params
     * @return array<string, callable>
     */
    public function hook_api_routes(array $params): array
    {
        // All management, all administrator-only. None of these are in the
        // kernel's public allowlist, so an anonymous caller is already refused
        // before a handler runs; the handlers add the capability check the
        // kernel cannot make on a plugin's behalf.
        return [
            'GET /api/webhooks' => [$this, 'listEndpoints'],
            'POST /api/webhooks' => [$this, 'createEndpoint'],
            'PUT /api/webhooks/:id' => [$this, 'updateEndpoint'],
            'DELETE /api/webhooks/:id' => [$this, 'deleteEndpoint'],
            'GET /api/webhooks/deliveries' => [$this, 'listDeliveries'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listEndpoints(): array
    {
        if (($refusal = $this->refuseUnlessAdministrator()) !== null) {
            return $refusal;
        }

        return ['data' => [
            'endpoints' => array_map(
                static fn (WebhookEndpoint $e): array => $e->toPublicArray(),
                $this->endpoints()->all()
            ),
            // So the admin can say the feature will not work rather than
            // accepting endpoints whose deliveries sit in the queue for ever.
            'canSend' => TransportFactory::isAvailable(),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function createEndpoint(): array
    {
        if (($refusal = $this->refuseUnlessAdministrator()) !== null) {
            return $refusal;
        }

        $body = $this->readBody();
        $url = trim((string) ($body['url'] ?? ''));

        $urlRefusal = $this->urlPolicy()->refusalFor($url);
        if ($urlRefusal !== null) {
            return ['status' => 422, 'error' => $urlRefusal];
        }

        $events = $this->readEvents($body['events'] ?? null);
        if ($events === []) {
            return ['status' => 422, 'error' => 'Choose at least one event to send.'];
        }

        $secret = WebhookSignature::generateSecret();

        $endpoint = new WebhookEndpoint(
            'ep_' . bin2hex(random_bytes(8)),
            $url,
            $secret,
            $events,
            (bool) ($body['active'] ?? true),
            trim((string) ($body['description'] ?? '')),
            gmdate('c'),
        );

        $this->endpoints()->save($endpoint);

        // The one and only time the secret is returned. Every later read uses
        // `toPublicArray()`, which withholds it — a secret that can be re-read
        // leaks through any read-only view of the admin, and the receiver needs
        // it exactly once, now, to configure its verifier.
        return ['data' => $endpoint->toPublicArray() + [
            'secret' => $secret,
            'secretNotice' => 'Copy this signing secret now. It is not shown again.',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateEndpoint(string $id): array
    {
        if (($refusal = $this->refuseUnlessAdministrator()) !== null) {
            return $refusal;
        }

        $existing = $this->endpoints()->find($id);
        if ($existing === null) {
            return ['status' => 404, 'error' => 'No such webhook.'];
        }

        $body = $this->readBody();

        $url = isset($body['url']) ? trim((string) $body['url']) : $existing->url;
        if ($url !== $existing->url) {
            $urlRefusal = $this->urlPolicy()->refusalFor($url);
            if ($urlRefusal !== null) {
                return ['status' => 422, 'error' => $urlRefusal];
            }
        }

        $events = isset($body['events']) ? $this->readEvents($body['events']) : $existing->events;
        if ($events === []) {
            return ['status' => 422, 'error' => 'Choose at least one event to send.'];
        }

        $updated = new WebhookEndpoint(
            $existing->id,
            $url,
            // The secret is never taken from the request: it is not shown in the
            // admin, so anything arriving here claiming to be one is either a
            // mistake or an attempt to set a known value.
            $existing->secret,
            $events,
            isset($body['active']) ? (bool) $body['active'] : $existing->active,
            isset($body['description']) ? trim((string) $body['description']) : $existing->description,
            $existing->createdAt,
        );

        $this->endpoints()->save($updated);

        return ['data' => $updated->toPublicArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteEndpoint(string $id): array
    {
        if (($refusal = $this->refuseUnlessAdministrator()) !== null) {
            return $refusal;
        }

        if (!$this->endpoints()->delete($id)) {
            return ['status' => 404, 'error' => 'No such webhook.'];
        }

        return ['data' => ['success' => true]];
    }

    /**
     * The recent delivery log — what was sent, what failed and why.
     *
     * The reason this exists rather than being left to the cron log: "did my
     * webhook fire?" is the only question anybody asks about this feature, and
     * answering it by asking an administrator to read a file over SSH means
     * nobody can answer it.
     *
     * @return array<string, mixed>
     */
    public function listDeliveries(): array
    {
        if (($refusal = $this->refuseUnlessAdministrator()) !== null) {
            return $refusal;
        }

        $status = $_GET['status'] ?? null;
        $status = is_string($status) && $status !== '' ? $status : null;

        return ['data' => array_map(
            static fn ($d): array => $d->toArray(),
            $this->queueStore()->recent(50, $status)
        )];
    }

    /* ------------------------------------------------------------ plumbing -- */

    private function dispatcher(): WebhookDispatcher
    {
        return new WebhookDispatcher($this->queueStore(), $this->endpoints());
    }

    private function endpoints(): FileEndpointRepository
    {
        return new FileEndpointRepository($this->dataDirectory());
    }

    private function queueStore(): FileDeliveryQueue
    {
        return new FileDeliveryQueue($this->dataDirectory() . '/queue');
    }

    private function dataDirectory(): string
    {
        return $this->pluginManager->getBasePath() . '/data/webhooks';
    }

    /**
     * The SSRF policy, as this site has configured it.
     *
     * Both dials default to the restrictive answer, so a site that has never
     * heard of either gets the safe behaviour without choosing it.
     */
    private function urlPolicy(): WebhookUrlPolicy
    {
        return new WebhookUrlPolicy(
            (bool) $this->getConfig('allowPrivateAddresses', false),
            (bool) $this->getConfig('allowPlainHttp', false),
        );
    }

    /**
     * Configuring a webhook means choosing a URL this server will fetch and
     * receiving a credential. That is administrator work — the same reasoning
     * that puts plugin installation behind the account with the most trust.
     *
     * @return array<string, mixed>|null
     */
    private function refuseUnlessAdministrator(): ?array
    {
        if (!class_exists(SessionStore::class)) {
            return null;
        }

        $sessions = new SessionStore($this->pluginManager->getBasePath() . '/data/sessions');
        $user = $sessions->user();

        // No session at all is the kernel's business, not this handler's: these
        // routes are not on the public allowlist, so an anonymous caller has
        // already been refused before reaching here. Answering 403 for a null
        // user would only mean a CLI caller could never manage endpoints.
        if ($user === null) {
            return null;
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ['status' => 403, 'error' => 'Only an administrator can manage webhooks.'];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function readEvents(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $events = [];
        foreach ($value as $event) {
            if (is_string($event) && trim($event) !== '') {
                $events[] = trim($event);
            }
        }

        return array_values(array_unique($events));
    }

    /**
     * @return array<string, mixed>
     */
    private function readBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
