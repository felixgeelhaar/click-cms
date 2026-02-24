# Marketplace registry

This folder holds registry assets for the Marketplace.

## Manifests

Place plugin manifests in `marketplace/manifests/`. Each manifest must include:

```json
{
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.2.3",
  "description": "Plugin description",
  "author": "Acme",
  "packageUrl": "https://example.com/my-plugin-1.2.3.zip",
  "sha256": "...",
  "manifestUrl": "https://example.com/my-plugin/manifest.json"
}
```

The `manifestUrl` is required so the registry can link to the manifest.

## Registry build (GitHub Actions)

The workflow `Build Marketplace Registry` signs each manifest and builds:

```
marketplace/registry.json
```

### Required secret

Add this GitHub Actions secret:

- `MARKETPLACE_PRIVATE_KEY` (RSA private key, PEM format)

## Local build

```bash
php scripts/marketplace/build-registry-from-manifests.php marketplace/manifests path/to/private_key.pem marketplace/registry.json
```
