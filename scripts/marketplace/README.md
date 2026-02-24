# Marketplace signing scripts

## Sign a manifest

```bash
php scripts/marketplace/sign-manifest.php path/to/manifest.json path/to/private_key.pem https://example.com/my-plugin/manifest.json
```

This prints:
- `Signature (base64)` for the manifest
- A registry entry JSON snippet if you pass a `manifestUrl`

## Build a registry JSON

```bash
php scripts/marketplace/build-registry.php entry1.json entry2.json > registry.json
```

## Example entry file

```json
{
  "id": "my-plugin",
  "manifestUrl": "https://example.com/my-plugin/manifest.json",
  "signature": "BASE64_SIGNATURE"
}
```
