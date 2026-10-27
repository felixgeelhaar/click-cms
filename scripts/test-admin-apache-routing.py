"""Verify shipped admin rewrites under root and nested Apache deployments."""
import argparse
import subprocess
import tempfile
import time
import urllib.request
import uuid
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--image', default='click-cms:ci')
args = parser.parse_args()
source = Path(__file__).resolve().parents[1] / 'admin-ui/public/.htaccess'
name = 'click-routing-' + uuid.uuid4().hex[:10]
with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    web = root / 'web'
    for prefix in ('admin', '2026/cms2/admin'):
        target = web / prefix
        target.mkdir(parents=True)
        (target / '.htaccess').write_bytes(source.read_bytes())
        (target / 'index.html').write_text('CLICK_ADMIN_ROUTING_OK')
        (target / 'asset.js').write_text('CLICK_ASSET_OK')
    alias = root / 'alias'
    (alias / 'admin').mkdir(parents=True)
    (alias / 'admin/.htaccess').write_bytes(source.read_bytes())
    (alias / 'admin/index.html').write_text('CLICK_ALIASED_ADMIN_OK')
    conf = root / 'site.conf'
    conf.write_text('<VirtualHost *:80>\nAlias /external/cms /routing-alias\n<Directory /routing-alias>\nOptions FollowSymLinks\nAllowOverride All\nRequire all granted\n</Directory>\nDocumentRoot /routing\n<Directory /routing>\nOptions FollowSymLinks\nAllowOverride All\nRequire all granted\n</Directory>\n</VirtualHost>\n')
    try:
        subprocess.run(['docker', 'run', '-d', '--rm', '--name', name, '-p', '127.0.0.1::80',
            '-v', str(web) + ':/routing:ro', '-v', str(alias) + ':/routing-alias:ro', '-v', str(conf) + ':/etc/apache2/sites-enabled/000-default.conf:ro', args.image, 'apache2-foreground'], check=True, stdout=subprocess.DEVNULL)
        port = subprocess.check_output(['docker', 'port', name, '80/tcp'], text=True).strip().rsplit(':', 1)[1]
        base = 'http://127.0.0.1:' + port
        for attempt in range(30):
            try:
                urllib.request.urlopen(base + '/admin/', timeout=2).close()
                break
            except OSError:
                time.sleep(1)
        for prefix in ('/admin/', '/2026/cms2/admin/'):
            for route in ('', 'updates', 'pages/new', 'pages/edit/home?locale=de'):
                with urllib.request.urlopen(base + prefix + route, timeout=5) as response:
                    assert response.status == 200 and response.read() == b'CLICK_ADMIN_ROUTING_OK', prefix + route
            with urllib.request.urlopen(base + prefix + 'asset.js', timeout=5) as response:
                assert response.read() == b'CLICK_ASSET_OK'
        for route in ('', 'updates', 'pages/new', 'pages/edit/home?locale=de'):
            with urllib.request.urlopen(base + '/external/cms/admin/' + route, timeout=5) as response:
                assert response.status == 200 and response.read() == b'CLICK_ALIASED_ADMIN_OK'
        print('Passed 14 Apache admin routing checks at root and nested installation prefixes')
    finally:
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
