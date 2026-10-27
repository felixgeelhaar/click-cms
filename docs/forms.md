# Contact forms

Enable **Forms** under Plugins, then add a Contact form section to a published page. Visitors can send a name, email address and message. Editors read saved messages under **Submissions**. Visitor text is displayed as text, including the confirmation message.

The form's page language selects English or German validation, sending, connection-error and confirmation messages. Authored field labels and confirmations remain as written by the editor. A separate SPA should send `locale: "en"` or `locale: "de"` and display the returned `error` on failure.

## Limits and spam protection

The server accepts at most 32 KiB per request, 200 bytes for the name, 254 bytes for the email and 10,000 bytes for the message. HTML field limits assist visitors; server limits also apply to API clients. Oversized requests receive HTTP 413.

A persistent, site-scoped limiter allows five attempts per client address and 100 attempts across the site in each ten-minute window. Excess attempts receive HTTP 429. Failed validation and honeypot attempts count too. The limiter does not trust forwarded-IP headers. Behind a reverse proxy, configure the web server's trusted-proxy handling so `REMOTE_ADDR` identifies the visitor; otherwise visitors share the proxy's limit.

The limiter stores keyed address hashes rather than raw IP addresses, discards counters at the next window and caps its state size. If its storage cannot be locked or written, the form temporarily refuses submissions with HTTP 503. It never accepts unbounded writes. The hidden honeypot silently discards ordinary bot submissions.

## Optional email alerts

Submissions stay in Click even if an email alert fails. Email alerts are off by default.

An administrator can set them up without editing files:

1. Enable **Forms** under **Plugins**.
2. Open **Plugins → Forms → Settings**.
3. Enter the recipient under **Send alerts to**.
4. Enter a **Sender email address** your website is allowed to send from. Your hosting provider can confirm this address.
5. Choose English or German for the emails and click **Save email settings**.
6. Click **Send test email**, then check the recipient’s inbox and spam folder. You can test while automatic alerts are still off.
7. Select **Enable email alerts** and save when the test arrives.

If a test fails, the screen explains what to do. Settings and submissions remain saved. If the server accepts a test but it does not arrive, ask your hosting provider to check delivery. Click uses the host’s PHP mail service; it does not supply an email service itself. Test emails are limited to five attempts per site in each ten-minute window.

Only administrators can change addresses or send tests. Alerts contain a reference and a request to open the inbox; they do not copy the visitor’s name, email address or message into email. Visitor input cannot set mail headers.

Settings are stored privately under the site's writable `data/forms/` directory and survive CMS upgrades. Existing `config/forms.json` settings remain readable until an administrator saves preferences in the CMS. CMS preferences then take precedence, including when alerts are turned off. No file editing is needed.

The inbox distinguishes alerts accepted by the mail server, failed alerts and alerts that are off. Acceptance by the mail server does not prove delivery to the recipient. Failed alerts are not automatically retried; the saved message remains available in the inbox. A visitor sees success only after their submission has been stored, regardless of alert delivery.

Limit inbox access, document retention and deletion practices, and include the form and any mail provider in your site's privacy documentation. Backups also contain saved submissions. No third-party service is contacted until an operator explicitly enables email alerts.

## Plugin settings for developers

The Forms package declares its settings UI in the `settings` object of `plugin.json`. The CMS exposes that declaration on the plugin list and detail APIs and renders it at `/admin/plugins/{id}/settings` for administrators. Deactivated plugins show no settings controls. Global Settings contains only CMS-wide preferences.

Other plugins can use the same entry point without modifying the admin UI. Declare `title`, `description`, `endpoint`, and a `fields` array. Fields support `text`, `email`, `checkbox`, and `select`, with `name`, `label`, `default`, optional `help`, `required`, `requiredWhen`, and `maxLength`. Select fields provide `options` with `value` and `label`. Optional `saveLabel`, `savedMessage`, `privacyHelp` and `help` customize the text. Forms' manifest is a complete example.

The settings endpoint must be under `/api/{plugin-id}/`. It returns `{ "data": { ...values } }` on GET and accepts the same values on PUT, returning what was saved. An optional `action` declares an endpoint under the same plugin prefix, `label`, `successMessage`, and a `requires` array of nonempty saved field names. The CMS issues an explicit POST to that action. Unsaved changes disable the action.

Declarations contain data only: labels are escaped, no scripts or HTML are executed, and cross-origin or unrelated API targets are refused. The plugin must implement its endpoints, enforce the appropriate capability on reads and writes, validate input, report persistence failures, bound side effects, and store preferences in its private site-specific data directory. The kernel's authentication and CSRF gates still apply. UI visibility is not an API authorization boundary. Existing plugins without a settings declaration remain unchanged.
