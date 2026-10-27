"""Fault-injection coverage for apparently successful but stale deployments."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
import urllib.error
import urllib.parse

spec = importlib.util.spec_from_file_location('verify_site', Path(__file__).with_name('verify-site.py'))
verify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verify)


class VerifySiteTest(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        self.root = Path(self.workspace.name)
        self.files = {'index.html': b'new homepage', 'feed.json': b'new release',
                      'feed.json.sig': b'new signature', 'handbook-de/index.html': b'new handbook'}
        for name, data in self.files.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        self.expected = verify.expected_files(self.root)

    def read(self, url):
        path = urllib.parse.urlparse(url)
        self.assertEqual('/click-cms/', path.path[:11])
        self.assertTrue(path.query.startswith('_verify='))
        return self.files[urllib.parse.unquote(path.path.removeprefix('/click-cms/'))]

    def test_complete_deployment_passes(self):
        verify.verify_once(self.expected, 'https://example.test/click-cms/', self.read)

    def test_stale_feed_fails_even_when_other_files_match(self):
        self.files['feed.json'] = b'previous release'
        with self.assertRaisesRegex(ValueError, 'feed.json'):
            verify.verify_once(self.expected, 'https://example.test/click-cms', self.read)

    def test_stale_docs_fail_even_when_feed_matches(self):
        self.files['handbook-de/index.html'] = b'previous handbook'
        with self.assertRaisesRegex(ValueError, 'handbook-de/index.html'):
            verify.verify_once(self.expected, 'https://example.test/click-cms', self.read)

    def test_stale_signature_fails(self):
        self.files['feed.json.sig'] = b'previous signature'
        with self.assertRaisesRegex(ValueError, 'feed.json.sig'):
            verify.verify_once(self.expected, 'https://example.test/click-cms', self.read)

    def test_http_failure_is_not_success(self):
        def unavailable(url):
            raise urllib.error.HTTPError(url, 404, 'Not found', {}, None)
        with self.assertRaises(urllib.error.HTTPError):
            verify.verify_once(self.expected, 'https://example.test/click-cms', unavailable)

    def test_pages_control_marker_is_not_a_public_file(self):
        (self.root / '.nojekyll').write_bytes(b'')
        self.assertNotIn('.nojekyll', verify.expected_files(self.root))

    def test_first_documentation_only_publish_can_have_no_feed(self):
        (self.root / 'feed.json').unlink()
        (self.root / 'feed.json.sig').unlink()
        self.assertIn('index.html', verify.expected_files(self.root))

    def test_incomplete_artifact_is_rejected(self):
        (self.root / 'feed.json.sig').unlink()
        with self.assertRaisesRegex(ValueError, 'Incomplete site artifact'):
            verify.expected_files(self.root)


if __name__ == '__main__':
    unittest.main()
