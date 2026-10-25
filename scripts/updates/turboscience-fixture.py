#!/usr/bin/env python3
"""Seed an existing isolated Click root from TurboScience's checked-in export.

No network or credentials are used. This is a reconstructed test fixture, not a
production Click database. Destination must be outside the source repository.
"""
import argparse
import importlib
import hashlib
import json
from pathlib import Path
import shutil
import sys


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', type=Path, required=True)
    parser.add_argument('--root', type=Path, required=True)
    args = parser.parse_args()
    site, root = args.site.resolve(strict=True), args.root.resolve(strict=True)
    if root == site or site in root.parents or root in site.parents:
        parser.error('destination must be isolated from the site repository')
    if not (root / 'src').is_dir():
        parser.error('destination must already contain a Click installation')
    sys.path.insert(0, str(site / 'scripts'))
    migrate = importlib.import_module('migrate-to-clickcms')
    menus = importlib.import_module('migrate-menu-to-clickcms')
    strings = importlib.import_module('migrate-ui-strings-to-clickcms')
    export, export_dir = migrate.load_export(None)
    shutil.copytree(site / 'cms/config', root / 'config', dirs_exist_ok=True)
    partners = json.loads((site / 'src/data/content.json').read_text())['partners']
    media_dir = root / 'content/media'
    media_dir.mkdir(parents=True, exist_ok=True)
    media, urls = {}, {}
    for name, (relative, mime) in migrate.MEDIA.items():
        source = site / relative
        extension = source.suffix.lstrip('.')
        ident = 'fixture-' + name + '-' + hashlib.sha256(name.encode()).hexdigest()[:8]
        destination = media_dir / (ident + '.' + extension)
        shutil.copyfile(source, destination)
        metadata = dict(id=ident, extension=extension, mimeType=mime,
                        originalName=source.name, bytes=destination.stat().st_size,
                        variants=[], alt='TurboScience upgrade fixture',
                        uploadedAt='2026-07-26T00:00:00+00:00')
        (media_dir / (ident + '.json')).write_text(json.dumps(metadata))
        media[name] = ident
        urls[name] = '/2026/cms2/api/media/file/' + destination.name
    count = 0

    def save(kind, locale, slug, data):
        nonlocal count
        path = root / 'content' / kind / locale / (slug + '.json')
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(dict(key=f'{kind}:{locale}:{slug}', data=data,
            createdAt='2026-07-26T00:00:00+00:00', updatedAt='2026-07-26T00:00:00+00:00'),
            ensure_ascii=False, indent=2))
        count += 1

    for locale in ('de', 'en'):
        save('page', locale, 'home', dict(title='TurboScience', sections=
             migrate.home_sections(export, locale, media, urls, partners)))
        save('menu', locale, 'main', menus.menu_for(export, locale))
    for locale, key, text in strings.entries(export):
        save('ui-string', locale, key.replace('.', '-'), dict(key=key, text=text))
    print(json.dumps(dict(source='checked-in Bludit export reconstructed with site migrators',
                         export=str(export_dir), documents=count, media=len(media))))


if __name__ == '__main__':
    main()
