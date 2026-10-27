# Contact forms

Enable **Forms** under Plugins, then add a Contact form section to a published page. Visitors can send a name, email address and message. Editors read saved messages under **Submissions**. Visitor text is displayed as text, including the confirmation message.

The form's page language selects English or German validation, sending, connection-error and confirmation messages. Authored field labels and confirmations remain as written by the editor. A separate SPA should send `locale: "en"` or `locale: "de"` and display the returned `error` on failure.

## Limits and spam protection

The server accepts at most 32 KiB per request, 200 bytes for the name, 254 bytes for the email and 10,000 bytes for the message. HTML field limits assist visitors; server limits also apply to API clients. Oversized requests receive HTTP 413.

A persistent, site-scoped limiter allows five attempts per client address and 100 attempts across the site in each ten-minute window. Excess attempts receive HTTP 429. Failed validation and honeypot attempts count too. The limiter does not trust forwarded-IP headers. Behind a reverse proxy, configure the web server's trusted-proxy handling so `REMOTE_ADDR` identifies the visitor; otherwise visitors share the proxy's limit.

The limiter stores keyed address hashes rather than raw IP addresses, discards counters at the next window and caps its state size. If its storage cannot be locked or written, the form temporarily refuses submissions with HTTP 503. It never accepts unbounded writes. The hidden honeypot silently discards ordinary bot submissions.

## Optional email alerts

Submissions stay in Click even if an email alert fails. Email alerts are off by default. An operator can create the private, site-specific `config/forms.json` file:

```json
{
  "notifications": {
    "enabled": true,
    "to": "editor@example.com",
    "from": "cms@example.com",
    "locale": "de"
  }
}
```

Use an address your mail provider permits for `from`. The server must have PHP `mail()` connected to a working local mail transport. Click does not provide an SMTP service. Test this configuration on the actual host before relying on alerts. Disable alerts by setting `enabled` to `false` or removing this file.

Recipients and sender are controlled only by the private configuration. Visitor input cannot set mail headers. The alert contains a reference and a request to open the inbox; it does not copy the visitor's name, email address or message into email. `locale` selects English or German alert wording.

The inbox distinguishes alerts accepted by the mail server, failed alerts and alerts that are off. Acceptance by the mail server does not prove delivery to the recipient. Failed alerts are not automatically retried; the saved message remains available in the inbox. A visitor sees success only after their submission has been stored, regardless of alert delivery.

Limit inbox access, document retention and deletion practices, and include the form and any mail provider in your site's privacy documentation. Backups also contain saved submissions. No third-party service is contacted until an operator explicitly enables email alerts.
