#!/usr/bin/env python3
"""
Build script for Deliverables module.
Usage: python3 bin/build.py <version>
  e.g. python3 bin/build.py 0.1.0

Updates version strings in source files FIRST, then builds the zip.
Never build the zip before updating the source.
"""

import re
import sys
import zipfile
import pathlib

ROOT = pathlib.Path(__file__).parent.parent
MODULE = ROOT / 'module'
BIN = ROOT / 'bin'

VERSION_FILES = {
    MODULE / 'core' / 'modules' / 'modDeliverables.class.php':
        (r"(\$this->version\s*=\s*')[^']+(')", r'\g<1>{version}\g<2>'),
}


def set_versions(version):
    for path, (pattern, replacement) in VERSION_FILES.items():
        text = path.read_text()
        new_text = re.sub(pattern, replacement.format(version=version), text)
        if new_text == text:
            print(f'  WARNING: no version string found in {path.name}')
        else:
            path.write_text(new_text)
            print(f'  updated {path.name}')


def verify_versions(version):
    ok = True
    for path in VERSION_FILES:
        text = path.read_text()
        if f"'{version}'" not in text:
            print(f'  ERROR: {version} not found in {path.name}')
            ok = False
        else:
            print(f'  verified {path.name} = {version}')
    return ok


def build_zip(version):
    out = BIN / f'module_deliverables-{version}.zip'
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
        for f in sorted(MODULE.rglob('*')):
            if f.is_file():
                zf.write(f, 'deliverables/' + str(f.relative_to(MODULE)))
    with zipfile.ZipFile(out) as zf:
        content = zf.read('deliverables/core/modules/modDeliverables.class.php').decode()
        if f"'{version}'" not in content:
            print(f'  ERROR: {version} not in zip')
            return None
    print(f'  created {out.name} ({out.stat().st_size // 1024} KB)')
    return out


def main():
    if len(sys.argv) != 2:
        print('Usage: python3 bin/build.py <version>')
        sys.exit(1)

    version = sys.argv[1]
    print(f'\n=== Building v{version} ===\n')

    print('1. Updating version strings...')
    set_versions(version)

    print('\n2. Verifying source files...')
    if not verify_versions(version):
        print('\nABORTED.')
        sys.exit(1)

    print('\n3. Building zip...')
    out = build_zip(version)
    if not out:
        sys.exit(1)

    print(f'\nDone: {out}')


if __name__ == '__main__':
    main()
