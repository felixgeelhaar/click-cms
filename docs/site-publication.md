# Publishing Click's public documentation and update feed

The [public Click site](https://felixgeelhaar.github.io/click-cms/) publishes the README, every Markdown guide in `docs/`, images, and the signed update feed together. One workflow owns the entire site, so publishing documentation cannot accidentally remove update delivery.

## Release publication

The release workflow attaches verified packages, then uses `PAGES_DISPATCH_TOKEN` to dispatch `pages.yml` on `main`. It waits for that independent workflow and checks that the live feed offers the release and expected package checksum. The token must retain permission to dispatch and inspect workflows.

Pages accepts only pushes, schedules and independent manual dispatches. It cannot be called as a reusable workflow or publish in a release event context. This avoids the release-triggered stale-artifact behavior tracked in [upstream deploy-pages issue 383](https://github.com/actions/deploy-pages/issues/383). That upstream issue remains outside Click's control; Click's supported publication path uses the documented dispatch workaround.

After deployment, `scripts/docs/verify-site.py` compares every publicly served file with the live bytes, including the feed, detached signature, documentation and images. The `.nojekyll` control marker is consumed by Pages and excluded from HTTP checks. A stale document, stale feed, missing public file or HTTP failure prevents success. CDN propagation is allowed up to ten minutes. The verifier does not redeploy or hide a failed attempt.

## Investigate a failure

Keep the failed run and deployment logs before retrying. Confirm the release package exists, the dispatch token is valid, the child Pages run completed, and the live feed contains the release. A passing rerun does not establish that an upstream fault was fixed.

Documentation pushes and the weekly schedule use the same serialized deployment and verification. The schedule refreshes the feed's 30-day expiry. Never add a second workflow that publishes only documentation or only the feed.

## Local verification

```sh
php scripts/docs/build-site.php /tmp/click-docs
python3 -m unittest discover -s scripts/docs -p 'test_*.py'
```

The deployed artifact also needs `feed.json` and `feed.json.sig`; the publication workflow generates them with the signing key. Never copy private signing keys into the documentation output.
