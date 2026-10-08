#!/usr/bin/env python3
"""Audiolog l10n helper (dev-only, not shipped).

The l10n/<lang>.json files are the source of truth; l10n/<lang>.js is
generated from them in the format Nextcloud loads (OC.L10N.register).

  tools/l10n.py sync     regenerate every l10n/*.js from its .json
  tools/l10n.py check    exit 1 when a string used in lib/, templates/ or js/
                         is missing or empty in any language, or the .js files
                         are out of date
  tools/l10n.py unused   list keys no source file uses
"""
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
L10N = ROOT / 'l10n'
LANGS = ['en', 'pt_BR', 'es']

# t('audiolog', '...') in JS, $l->t('...') / $this->l10n->t('...') / $l10n->t('...') in PHP.
PATTERNS = [
    re.compile(r"""\bt\(\s*['"]audiolog['"]\s*,\s*(?P<q>['"])(?P<s>(?:\\.|(?!(?P=q)).)*)(?P=q)""", re.S),
    re.compile(r"""\bn\(\s*['"]audiolog['"]\s*,\s*(?P<q>['"])(?P<s>(?:\\.|(?!(?P=q)).)*)(?P=q)""", re.S),
    re.compile(r"""(?:\$l|\$this->l10n|\$l10n|\$this->l)->t\(\s*(?P<q>['"])(?P<s>(?:\\.|(?!(?P=q)).)*)(?P=q)""", re.S),
]


def unescape(s: str, quote: str) -> str:
    return s.replace('\\' + quote, quote).replace('\\\\', '\\')


def used_strings() -> dict:
    found = {}
    files = list((ROOT / 'lib').rglob('*.php')) + list((ROOT / 'templates').rglob('*.php')) + list((ROOT / 'js').glob('*.js'))
    for f in files:
        text = f.read_text(encoding='utf-8')
        for pat in PATTERNS:
            for m in pat.finditer(text):
                found.setdefault(unescape(m.group('s'), m.group('q')), str(f.relative_to(ROOT)))
    return found


def load(lang: str) -> dict:
    return json.loads((L10N / f'{lang}.json').read_text(encoding='utf-8'))


def render_js(data: dict) -> str:
    lines = ['OC.L10N.register(', '    "audiolog",', '    {']
    for k, v in data['translations'].items():
        lines.append('        ' + json.dumps(k, ensure_ascii=False) + ': ' + json.dumps(v, ensure_ascii=False) + ',')
    lines += ['    },', '    ' + json.dumps(data['pluralForm'], ensure_ascii=False), ');']
    return '\n'.join(lines)


def write_json(lang: str, data: dict) -> None:
    (L10N / f'{lang}.json').write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


def sync() -> None:
    for lang in LANGS:
        data = load(lang)
        write_json(lang, data)
        (L10N / f'{lang}.js').write_text(render_js(data), encoding='utf-8')


def check() -> int:
    used = used_strings()
    problems = []
    for lang in LANGS:
        data = load(lang)
        tr = data['translations']
        for s, where in sorted(used.items()):
            if not tr.get(s):
                problems.append(f'{lang}: missing "{s}" ({where})')
        if (L10N / f'{lang}.js').read_text(encoding='utf-8') != render_js(data):
            problems.append(f'{lang}.js is out of date: run tools/l10n.py sync')
    for p in problems:
        print(p)
    print(f'{len(used)} strings used, {len(problems)} problems')
    return 1 if problems else 0


def unused() -> None:
    used = used_strings()
    for k in load('pt_BR')['translations']:
        if k not in used:
            print(k)


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'check'
    if cmd == 'sync':
        sync()
    elif cmd == 'unused':
        unused()
    else:
        sys.exit(check())
