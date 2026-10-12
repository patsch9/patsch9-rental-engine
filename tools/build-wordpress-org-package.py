"""Derive a review ZIP from the verified GitHub ZIP, without bundled translations."""
import argparse
import stat
from pathlib import Path, PurePosixPath
from zipfile import ZipFile, ZIP_DEFLATED

SLUG = 'patsch9-rental-engine'

def build(source, destination):
    if source.resolve() == destination.resolve():
        raise ValueError('Source and destination must differ')
    with ZipFile(source) as archive:
        entries = []
        seen = set()
        for item in archive.infolist():
            path = PurePosixPath(item.filename)
            if path.is_absolute() or '..' in path.parts or '\\' in item.filename or not path.parts or path.parts[0] != SLUG:
                raise ValueError('Invalid archive path')
            if item.filename in seen:
                raise ValueError('Duplicate archive path')
            seen.add(item.filename)
            if stat.S_ISLNK(item.external_attr >> 16):
                raise ValueError('Symbolic links are not allowed')
            if path.suffix.lower() in ('.po', '.mo'):
                continue
            entries.append((item, archive.read(item)))
        for required in (f'{SLUG}/{SLUG}.php', f'{SLUG}/readme.txt', f'{SLUG}/LICENSE'):
            if required not in seen:
                raise ValueError('Missing plugin file: ' + required)
    destination.parent.mkdir(parents=True, exist_ok=True)
    with ZipFile(destination, 'w', compression=ZIP_DEFLATED) as archive:
        for item, contents in entries:
            archive.writestr(item, contents)
    return len(entries)

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    print(f'WordPress.org package built: {build(args.source, args.destination)} entries')
