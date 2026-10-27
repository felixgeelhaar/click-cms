# Dependency maintenance

The October 2026 refresh upgrades the admin application, PHP test tooling, browser tests, screenshot tools, TypeScript SDK, GitHub Actions and Nox to current stable versions. Lock files record the tested dependency tree. Container base image digests were checked against current Composer 2, Node 24 LTS and PHP 8.5 Apache tags and were already current.

The CMS runtime still supports PHP 8.1+. PHPUnit 13.4 requires a newer PHP for development only; the minimum-runtime check runs separately in CI.

The SDK compiles with TypeScript 7.0.2 and has a committed lock file plus a CI typecheck/build job. All four npm workspaces are audited by CI and tracked by Dependabot, along with Composer, Actions and Docker images. Obsolete admin dependency overrides have been removed; upstream packages now resolve the current versions directly.

Pages upload/deploy actions move together to v5. Issue #26 remains tracked: releases dispatch the Pages workflow, wait for completion and verify the live feed, while Pages independently checks the served digest. A green PR check alone does not establish a successful Pages deployment.

Security checks use Warden 0.46.0 and Nox 1.54.0. See [SECURITY.md](../SECURITY.md) for local gate setup. Nox's action commit and downloaded scanner version must be updated together. Existing security triage reports retain the scanner version used to produce them.

After upgrading, run Composer tests/lint, the admin tests/build, the SDK typecheck/build, npm/Composer audits, the security gate, the Docker smoke/routing checks and browser tests. Never use forced peer dependency resolution to hide incompatible packages.
