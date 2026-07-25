# Documentation screenshots

Every picture in `docs/images` is produced by `capture.mjs`, driven against a
real running instance showing the seeded example site. Nothing here is a
mock-up: a mock-up drifts from the product silently, while a capture drifts
loudly — the next run simply looks different.

## Recapturing

```bash
# 1. A fresh instance with the example content
docker build -t click-cms:shots .
docker run -d --name click-shots -p 8199:80 click-cms:shots
docker exec -u www-data click-shots php bin/click-seed.php

# 2. Once, to install Playwright
cd scripts/screenshots && npm install

# 3. Capture
node capture.mjs
```

Then check `git diff --stat docs/images` and look at what changed.

**The instance must be freshly installed.** Two of the shots are the sign-in
screen and the forced password change, and the second only exists before the
installed password has been replaced. On a second run against the same
container those two are skipped — recreate it to capture them.

Useful flags:

| | |
|---|---|
| `--only=users,settings` | just those shots |
| `--base=http://localhost:8080` | a different instance |
| `--out=/tmp/shots` | somewhere other than `docs/images` |

## Two things it does on purpose

**Every shot asserts the heading it expects, and a mismatch fails the run.**
This is the point of the tool, not a nicety. An account without permission for a
screen is redirected to the Dashboard, and *a screenshot of the wrong screen
still looks like a screenshot* — so without the assertion you find out when a
reader tells you the picture does not match the words. It also catches a screen
that has been renamed, which is exactly when the surrounding prose needs
revisiting.

**It waits for content, not for a timeout.** Screens whose state arrives
asynchronously are waited on by their settled text. This was learned the hard
way: an early capture of the page editor caught the publication banner before
its data arrived and documented a published page as *"not saved yet"*, beside a
note telling an administrator they could not publish. Both false, and both
shipped into the documentation before anyone noticed.

## Why 1400 pixels wide and no downscaling

The first version captured at 2× and shrank the files with `sips`, which is
macOS-only — so the committed images could not be reproduced anywhere else.
Rendering straight to the width the documentation displays is portable, is one
step instead of two, and turned out to be far smaller: the same 23 screenshots
went from 7.6 MB to 2.1 MB. Text rasterised at its final size reads no worse
than text resampled down to it.

## When the admin UI changes

Recapture, and then **check the alt text still describes the picture**. The
documentation build requires alt text on every image and the generator renders it
as the visible caption, so it is read by everyone rather than only by a screen
reader. A caption describing a screen that has moved on is a worse defect than a
missing one.

## Adding a screen

One entry in the `SHOTS` array, and a reference to the new file from a page in
`docs/`. Keep the array in the order a reader meets the screens; the first-run
shots must stay first, because the tool changes the password as it goes.
