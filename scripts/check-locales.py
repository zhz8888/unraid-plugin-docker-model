#!/usr/bin/env python3
"""Check the translation files of the plugin.

Every locale file has to be valid JSON and has to cover exactly the keys of
the English base file. A key that is missing from a translation falls back to
the English text, so an incomplete translation is not a crash, but it is a
gap that is worth reporting before a release.

Usage:
    python3 scripts/check-locales.py <locales dir>
"""

import json
import os
import sys


BASE = 'en_US.json'


def flatten(node, prefix=''):
    """Return a flat {dotted key: value} map of a nested translation file."""
    result = {}
    for key, value in node.items():
        name = prefix + key if not prefix else prefix + '.' + key
        if isinstance(value, dict):
            result.update(flatten(value, name + '.'))
        else:
            result[name] = value
    return result


def load(path):
    with open(path, encoding='utf-8') as handle:
        return json.load(handle)


def main():
    roots = sys.argv[1:] or ['src/usr/local/emhttp/plugins/unraid-plugin-docker-model/locales']
    failures = 0

    for root in roots:
        if not os.path.isdir(root):
            print('FAIL %s is not a directory' % root)
            failures += 1
            continue

        base_path = os.path.join(root, BASE)
        if not os.path.isfile(base_path):
            print('FAIL %s is missing' % base_path)
            failures += 1
            continue

        try:
            base = flatten(load(base_path))
        except ValueError as error:
            print('FAIL %s is not valid JSON: %s' % (base_path, error))
            failures += 1
            continue

        if not base:
            print('FAIL %s is empty' % base_path)
            failures += 1
            continue

        print('ok   %s (%d keys)' % (base_path, len(base)))

        for name in sorted(os.listdir(root)):
            if not name.endswith('.json') or name == BASE:
                continue
            path = os.path.join(root, name)
            try:
                locale = flatten(load(path))
            except ValueError as error:
                print('FAIL %s is not valid JSON: %s' % (path, error))
                failures += 1
                continue

            missing = sorted(set(base) - set(locale))
            extra = sorted(set(locale) - set(base))
            if missing:
                print('FAIL %s is missing %d key(s): %s' % (path, len(missing), ', '.join(missing)))
                failures += 1
            elif extra:
                print('FAIL %s has %d unknown key(s): %s' % (path, len(extra), ', '.join(extra)))
                failures += 1
            else:
                print('ok   %s (%d keys)' % (path, len(locale)))

    if failures:
        print('\n%d locale problem(s) found' % failures)
        return 1

    print('\nall locale files are valid and complete')
    return 0


if __name__ == '__main__':
    sys.exit(main())
