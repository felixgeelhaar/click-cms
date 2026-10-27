#!/usr/bin/env python3
"""Require the live Pages site to serve the complete artifact we uploaded."""
import argparse
import hashlib
from pathlib import Path
import time
import urllib.parse
import urllib.request


def expected_files(root):
    required = ['index.html']
    if any((root / name).exists() for name in ['feed.json', 'feed.json.sig']):
        required += ['feed.json', 'feed.json.sig']
    for name in required:
        if not (root / name).is_file():
            raise ValueError('Incomplete site artifact: ' + name)
    # Pages consumes .nojekyll as a control marker; it does not serve it.
    files = sorted(path for path in root.rglob('*')
                   if path.is_file() and path.name != '.nojekyll')
    # Check the feed first so a stale release is detected without fetching all docs.
    files.sort(key=lambda path: (path.name not in {'feed.json', 'feed.json.sig'}, str(path)))
    return {path.relative_to(root).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in files}


def fetch(url):
    request = urllib.request.Request(url, headers={'Cache-Control': 'no-cache'})
    with urllib.request.urlopen(request, timeout=15) as response:
        return response.read()


def verify_once(expected, base, read=fetch):
    for name, digest in expected.items():
        url = base.rstrip('/') + '/' + urllib.parse.quote(name) + '?_verify=' + str(time.time_ns())
        actual = hashlib.sha256(read(url)).hexdigest()
        if actual != digest:
            raise ValueError('Published file differs from artifact: ' + name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('base_url')
    parser.add_argument('--timeout', type=float, default=600)
    args = parser.parse_args()
    expected = expected_files(args.directory)
    deadline = time.monotonic() + args.timeout
    while True:
        try:
            verify_once(expected, args.base_url)
            print(f'Verified all {len(expected)} published files against the uploaded artifact.')
            return
        except (OSError, ValueError) as error:
            if time.monotonic() >= deadline:
                raise SystemExit('Pages verification failed; inspect this deployment before rerunning: ' + str(error))
            print('Waiting for Pages: ' + str(error), flush=True)
            time.sleep(min(20, max(0, deadline - time.monotonic())))


if __name__ == '__main__':
    main()
