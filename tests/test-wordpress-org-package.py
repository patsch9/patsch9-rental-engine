"""Check that packaging changes translations only, and rejects unsafe archives."""
import importlib.util
import stat
import tempfile
import unittest
from pathlib import Path
from zipfile import ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('builder', ROOT / 'tools/build-wordpress-org-package.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
SLUG = builder.SLUG
BASE = {f'{SLUG}/{SLUG}.php': b'<?php // plugin', f'{SLUG}/readme.txt': b'Stable tag: 2026.10.2', f'{SLUG}/LICENSE': b'GPL', f'{SLUG}/assets/rental.js': b'window.rental = 1;', f'{SLUG}/languages/template.pot': b'msgid "Invoice"\nmsgstr ""', f'{SLUG}/languages/de.po': b'translation', f'{SLUG}/languages/de.mo': b'binary'}

class PackageTests(unittest.TestCase):
    def build_fixture(self, extra=None, missing=False):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        source, dest = Path(temp.name) / 'source.zip', Path(temp.name) / 'review.zip'
        with ZipFile(source, 'w') as archive:
            for name, content in BASE.items():
                if missing and name.endswith('/LICENSE'):
                    continue
                archive.writestr(name, content)
            if extra:
                archive.writestr(*extra)
        return source, dest

    def test_translations_only_removed(self):
        source, dest = self.build_fixture()
        builder.build(source, dest)
        with ZipFile(source) as original, ZipFile(dest) as result:
            expected = set(BASE) - {f'{SLUG}/languages/de.po', f'{SLUG}/languages/de.mo'}
            self.assertEqual(set(result.namelist()), expected)
            for name in expected:
                self.assertEqual(result.read(name), original.read(name))
        with ZipFile(source) as original:
            self.assertEqual(original.read(f'{SLUG}/languages/de.mo'), b'binary')

    def test_traversal_rejected(self):
        source, dest = self.build_fixture((f'{SLUG}/../evil.php', b'bad'))
        with self.assertRaises(ValueError): builder.build(source, dest)
        self.assertFalse(dest.exists())

    def test_symlink_rejected(self):
        item = ZipInfo(f'{SLUG}/link')
        item.external_attr = (stat.S_IFLNK | 0o777) << 16
        source, dest = self.build_fixture((item, b'/etc/passwd'))
        with self.assertRaises(ValueError): builder.build(source, dest)

    def test_missing_required_rejected(self):
        source, dest = self.build_fixture(missing=True)
        with self.assertRaises(ValueError): builder.build(source, dest)

if __name__ == '__main__': unittest.main()
