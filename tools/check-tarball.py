#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Check an Audiolog app tarball before signing it.

Usage: tools/check-tarball.py <tarball> <expected-version>

Fails (exit 1) unless the tarball has exactly one top-level directory
`audiolog/`, the expected <version> in appinfo/info.xml, and none of the
files that must never ship: macOS AppleDouble (`._*`), `.DS_Store`, session
notes (`.remember/`), VCS/dev tooling, tests and build output.
"""
import re
import sys
import tarfile

FORBIDDEN_SEGMENTS = {
    '.remember', '.git', '.github', '.idea', '.vscode', '.phpunit.cache',
    'tests', 'build', 'dist', 'vendor', 'node_modules', 'docs', 'tools',
}
FORBIDDEN_FILES = {
    '.DS_Store', '.gitignore', '.gitattributes', 'Makefile', 'PUBLISHING.md',
    'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
}
REQUIRED = ['audiolog/appinfo/info.xml', 'audiolog/appinfo/routes.php', 'audiolog/lib/AppInfo/Application.php']


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    path, version = sys.argv[1], sys.argv[2]
    problems = []
    with tarfile.open(path) as tar:
        names = [m.name.rstrip('/') for m in tar.getmembers()]
        tops = {n.split('/')[0] for n in names}
        if tops != {'audiolog'}:
            problems.append(f'top-level entries must be exactly audiolog/, got {sorted(tops)}')
        for name in names:
            parts = name.split('/')
            base = parts[-1]
            if base.startswith('._'):
                problems.append(f'AppleDouble file: {name}')
            if base in FORBIDDEN_FILES:
                problems.append(f'dev-only file: {name}')
            bad = FORBIDDEN_SEGMENTS.intersection(parts[1:])
            if bad:
                problems.append(f'forbidden directory {sorted(bad)}: {name}')
        for req in REQUIRED:
            if req not in names:
                problems.append(f'missing {req}')
        if 'audiolog/appinfo/info.xml' in names:
            xml = tar.extractfile('audiolog/appinfo/info.xml').read().decode('utf-8')
            m = re.search(r'<version>([^<]+)</version>', xml)
            if not m or m.group(1) != version:
                problems.append(f'info.xml version is {m.group(1) if m else None}, expected {version}')
    if problems:
        print(f'✗ {path}:')
        for p in sorted(set(problems)):
            print('  - ' + p)
        return 1
    print(f'✓ {path}: {len(names)} entries, version {version}, nothing forbidden')
    return 0


if __name__ == '__main__':
    sys.exit(main())
