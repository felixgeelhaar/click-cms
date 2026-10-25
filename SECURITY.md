# Security policy

## Reporting a vulnerability

Please report suspected vulnerabilities privately through GitHub's
[Report a vulnerability](https://github.com/felixgeelhaar/click-cms/security/advisories/new)
feature. Please do not post vulnerability details in public issues or pull
requests. No separate security email is published here.

Include the affected component and version or commit, impact, reproduction
steps, and any suggested mitigation. Please avoid including real credentials,
personal data, or production content in a report.

## Supported versions

The project has not published a release support window or a list of supported
versions. Please test against the latest source on the default branch when
possible and identify the release or commit you tested. This request does not
promise that any particular older release is maintained.

## Response expectations

No response or remediation time commitment is published. The repository does
not currently state an expected response time or a supported-version policy.

## Deployment and dependency scope

Configure the web server to serve only the `public/` directory, and use HTTPS
for deployed sites. Plugins are trusted PHP code loaded by the application;
they are not sandboxed and can exercise the privileges of the PHP process.

The repository runs dependency audits against its Composer and admin npm lock
files, including development dependencies. Audits report known findings from
their advisory sources at the time they run; they do not guarantee that a
dependency or deployment is free of vulnerabilities.

## Development security checks

Install [Warden](https://github.com/klarlabs-studio/warden) and
[Nox](https://github.com/Nox-HQ/nox) before contributing. The checked configuration
uses Warden 0.45.0 and Nox 1.48.1; CI pins Nox's action and binary version.
PHP/Composer, Node/npm and Python 3.9+ are also needed for the configured checks.
From the repository root:

```sh
warden init --hooks=pre-commit,pre-push  # install hooks in a fresh clone
warden hooks repin                   # update existing hooks after a Warden upgrade
warden status                        # confirm both hooks are armed
python3 scripts/security/nox_check.py --offline
python3 scripts/security/nox_check.py
```

Warden checks PHP syntax and offline Nox secrets/code/IaC patterns before a
commit. Before a push it also runs PHP tests, admin tests, security-gate tests
and a full online Nox scan. The offline check does not query vulnerability
advisories. CI independently runs the full scan alongside dependency audits.
No Nox hook is installed separately: Warden owns both hooks.

`.nox.yaml` keeps all severities visible and blocks high/critical findings.
There is no blanket baseline. The report wrapper also blocks missing/malformed
reports, malformed findings, and failed analyzers or advisory lookups. It also
enforces the high/critical policy independently of the scanner exit status.
Advisory notices about
unselected globally installed plugins remain visible but do not block. Only
built-in analyzers are selected; executable plugins are not auto-installed.
The one inline test exception documents a mocked browser fetch with no network
request. It does not exclude the test file or a production rule family.

Ignored reports live under `.nox/reports/`; CI retains them as workflow artifacts
for 14 days without posting PR comments. Treat reports as potentially sensitive.
Medium/low findings need review even when the gate passes. PHP scanning is static
pattern/taint analysis, not proof of endpoint exploitability, complete reachability
or dynamic verification. A Warden configuration or passing scan alone does not
establish signed commit provenance; inspect `warden status` and `warden verify`.

## Cache configuration

Click marks authentication, management, authenticated API and preview responses
with `Cache-Control: no-store, private`. The Apache rules also cover the built
admin HTML when `mod_headers` and `mod_setenvif` are enabled. Other web servers
or static hosting setups must apply an equivalent rule to the admin HTML.
Reverse proxies and CDNs must honor these headers and avoid serving cached
public API responses to authenticated requests. The PHP development server
serves existing static files directly and does not read `.htaccess`.

### Restricting extension execution

Set the process environment `CLICK_ALLOWED_PLUGINS` to a comma-separated list of
reviewed plugin IDs (for example `backup,rest-api`). An explicitly empty value
blocks every plugin; an unset value preserves existing installations. Malformed
lists fail closed. Admission is checked before bootstrap execution, including
activation requests and persisted active state. Configure it in deployment/server
settings, outside content and the admin UI. Include the first-party plugins your
site needs; exclusion can remove their routes and rendering features.

This is an execution admission control, not a PHP sandbox. Allowed extensions
retain the application's filesystem, network and database privileges. Run
untrusted code in a separate process/container with a deliberately restricted
protocol; do not install it into this PHP process. Protect the reviewed code and
server configuration with deployment ownership. A writable application image is
required by the current self-updater; use deployment-managed upgrades and disable
updates/marketplace when deploying immutable code. Site state still needs writes.

### Deployment-managed container profile

`docker compose -f docker-compose.hardened.yml up --build` selects the optional
`hardened` image target: Apache runs as www-data on port 8080, application code is
root-owned, the filesystem is read-only except content/data and bounded temporary
mounts, all Linux capabilities are dropped, and privilege escalation is disabled.
`CLICK_DEPLOYMENT_MANAGED=1` blocks update and marketplace installation at the
installer boundary, even when an administrator sends a direct request. Supply
updates by rebuilding/redeploying this image. The default image retains the
self-updating ownership and port-80 behavior. Back up named volumes and deploy
configuration separately. The hardened profile is single-site; multi-site state
needs explicit separately writable mounts. Allowed plugins still execute in the
application process. Configure `CLICK_ALLOWED_PLUGINS` through your deployment
when you also want extension admission restrictions.
