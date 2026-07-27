# Webhooks

Telling another system that something here changed — a static front end that
needs to rebuild, a search index, a chat channel that should say "the pricing
page just went live".

Webhooks are a **plugin**. A site that renders its own pages has nothing to
notify, and deleting `plugins/webhooks` leaves a CMS that works exactly as
before. A statically generated front end is the opposite case: without this it
either rebuilds on a timer — and is stale for as long as the timer is — or never
finds out at all.

## Setting one up

**Manage → Webhooks**, administrator only. Configuring a webhook means choosing
a URL this server will fetch and receiving a credential, which is the same class
of decision as installing a plugin.

1. Enter the URL the receiver listens on. It must be `https`.
2. Choose which events to send.
3. **Copy the signing secret.** It is shown once and never again — see below.

### The events

| Event | When |
|---|---|
| `content.published` | a page or entry goes live |
| `content.unpublished` | a page or entry leaves the public site |
| `content.saved` | any edit, published or not |
| `content.deleted` | a page or entry is removed entirely |

A front end that rebuilds on publication wants the first two. One that shows
drafts in a preview environment wants `content.saved` as well.

Account changes are never sent. Users are stored as content documents like
everything else, and for that one type even the identity of the change is worth
withholding from a third party.

## What a delivery looks like

A POST with a JSON body:

```json
{
  "id": "9f2a1c4e8b7d6a5c3e1f0b92",
  "event": "content.published",
  "sentAt": "2026-07-27T09:00:00+00:00",
  "occurredAt": "2026-07-27T08:59:58+00:00",
  "attempt": 1,
  "data": {
    "key": "page:en:pricing",
    "type": "page",
    "slug": "pricing",
    "locale": "en",
    "user": "jo"
  }
}
```

and these headers:

```
X-Click-Event: content.published
X-Click-Delivery: 9f2a1c4e8b7d6a5c3e1f0b92
X-Click-Signature: t=1785312000,v1=<hex sha256>
```

**The body carries identity, not content.** There is no `data.title`, no
sections, no document. That is deliberate and it is a security decision before
it is a design one: users are content documents, so a payload that carried the
document body would post password hashes to a third party on every password
change — and would keep doing so for whatever secret a future content type
happens to hold. A receiver that needs the body reads the delivery API for the
key it was just handed.

## Verifying the signature

Do this. Without it, anyone who learns the URL can drive your rebuild.

The scheme is the one Stripe and GitHub use, deliberately, so that whatever
framework you are receiving with probably already has a verifier:

1. Read `t` and `v1` from `X-Click-Signature`.
2. Refuse the delivery if `t` is more than five minutes from your own clock.
   This is the replay defence, and skipping it makes the timestamp decorative.
3. Compute `HMAC-SHA256(secret, "<t>." + rawBody)`.
4. Compare with `v1` in **constant time**.

Verify against the **raw request body**, before parsing. A framework that hands
you a re-encoded JSON object will produce a different byte string and every
delivery will fail authentication for a reason nothing in either log explains.

```php
$expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
if (!hash_equals($expected, $v1)) { http_response_code(403); exit; }
```

`Domain\Webhook\WebhookSignature::verify()` in this repository is a working
reference implementation, and it is tested.

### The secret is shown once

When the webhook is created, and never again. This is how every provider of
these behaves, for the reason that a secret which can be re-read leaks through
any read-only view of the admin — a screen share, a support session, a
screenshot in a ticket. If you lose it, delete the webhook and make another.

## Delivery, retries and giving up

**Nothing is sent during the editor's save.** The event is written to a queue on
disk and a cron entry sends it:

    0,5,10,15,20,25,30,35,40,45,50,55 * * * *  cd /var/www/html && php bin/click-webhooks.php >> data/webhooks/cron.log 2>&1

Sending inline was rejected three times over: it would put your receiver's
latency on the editor's Save button; a receiver that hangs would hold a PHP
worker until the timeout, and shared hosting has few of those; and it could not
retry, so a receiver that happened to be restarting would simply never hear
about the change.

**Without that cron entry nothing is ever delivered.** The Webhooks screen shows
deliveries sitting in `pending` and the queue grows. This is the one setup step
that cannot be done from the admin.

Failures are retried with a growing gap — one minute, two, four, eight, sixteen,
capped at an hour — for six attempts over roughly two hours, and then given up
on. What counts as a failure:

- no response at all: refused, timed out, DNS or TLS failure
- any status outside `2xx`

**A redirect is a failure.** Redirects are not followed, because the URL was
checked against the address policy below and a redirect target has not been.

### At least once, not exactly once

A delivery that succeeds but whose bookkeeping fails is sent again. Use
`X-Click-Delivery` as an idempotency key: if you have already processed that id,
discard the repeat.

Ordering is best effort. A delivery that fails and backs off is overtaken by
later ones, and guaranteeing order would mean stalling the whole queue behind
one broken endpoint. `occurredAt` is there for receivers that care.

## Where a webhook may point

An endpoint is a URL this server fetches, unattended, from inside your network.
That is a server-side request forgery primitive by construction, so:

- **`https` only**, unless the site enables `allowPlainHttp`.
- **No private, loopback or link-local addresses**, unless the site enables
  `allowPrivateAddresses`. This is what stops `169.254.169.254` — the cloud
  metadata address, which answers unauthenticated on several providers and hands
  back credentials.
- **No credentials in the URL.** A webhook authenticates with its signature.

A site whose front end genuinely is a sibling container on a private network can
turn the second dial on in the plugin's configuration. Both default to the
restrictive answer so a site that has never heard of either is safe without
choosing to be.

**One thing this does not solve:** DNS rebinding. The hostname is resolved when
the endpoint is saved and again when the request is made, and a name that
answers publicly at the first and privately at the second walks past the check.
Closing it properly needs control of the socket that neither available transport
gives up. The residual risk is an administrator configuring a hostile hostname,
which is a much smaller set than "any URL at all".

## Requirements

An outbound HTTP path: either the `curl` extension or `allow_url_fopen`. Most
installations have one; some hardened ones have neither, and the Webhooks screen
says so rather than accepting endpoints whose deliveries will never move.

## From the command line

```bash
php bin/click-webhooks.php            # send what is due
php bin/click-webhooks.php --list     # the recent delivery log
php bin/click-webhooks.php --prune    # discard finished rows older than a week
php bin/click-webhooks.php --limit=25 # take fewer in one run
```

Exit code 1 means something needs a person — a delivery discarded because its
endpoint was deleted or switched off while events were still queued for it. A
retryable failure exits 0, because a receiver restarting is ordinary and mailing
every one of those trains people to filter the mail that also carries the real
problems.
