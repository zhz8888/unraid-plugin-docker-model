#!/usr/bin/env python3
"""Heuristic syntax check for the plugin's PHP files.

The Unraid host runs the WebGUI page, and a syntax error there breaks the
Settings page. This script is not a PHP parser; it tokenises the file well
enough to catch the mistakes that actually happen in practice:

  * unbalanced braces, parentheses or brackets
  * an unterminated string or heredoc
  * a PHP block that is never closed
  * a stray closing tag

It is a supplement to `php -l`, not a replacement for it: run `php -l` on the
files when a PHP binary is available.

Usage:
    python3 scripts/check-php-syntax.py src/
"""

import os
import sys

OPEN = {'{': '}', '(': ')', '[': ']'}
CLOSE = {'}': '{', ')': '(', ']': '['}


def check(path):
    """Return a list of problems found in path."""
    problems = []
    with open(path, encoding='utf-8') as handle:
        text = handle.read()

    stack = []
    line = 1
    index = 0
    length = len(text)
    in_php = False
    saw_close_tag = False
    in_string = None
    in_line_comment = False
    in_block_comment = False

    while index < length:
        char = text[index]
        nxt = text[index + 1] if index + 1 < length else ''

        if char == '\n':
            line += 1
            in_line_comment = False
            index += 1
            continue

        if in_line_comment:
            index += 1
            continue

        if in_block_comment:
            if char == '*' and nxt == '/':
                in_block_comment = False
                index += 2
                continue
            index += 1
            continue

        if in_string:
            if char == '\\':
                index += 2
                continue
            if char == in_string:
                in_string = None
            index += 1
            continue

        # not in a string or comment
        if char == '/' and nxt == '/':
            in_line_comment = True
            index += 2
            continue
        if char == '#':
            in_line_comment = True
            index += 1
            continue
        if char == '/' and nxt == '*':
            in_block_comment = True
            index += 2
            continue
        if char in ('"', "'"):
            in_string = char
            index += 1
            continue
        if char == '<' and text[index:index + 2] == '<?':
            in_php = True
            index += 2
            continue
        if char == '?' and nxt == '>':
            if not in_php:
                problems.append(f"{path}:{line}: closing tag without an opening tag")
            in_php = False
            saw_close_tag = True
            index += 2
            continue

        if in_php:
            if char in OPEN:
                stack.append((char, line))
            elif char in CLOSE:
                if not stack:
                    problems.append(f"{path}:{line}: unexpected '{char}'")
                elif stack[-1][0] != CLOSE[char]:
                    opener, opened_at = stack[-1]
                    problems.append(
                        f"{path}:{line}: '{char}' closes '{opener}' opened at line {opened_at}")
                    stack.pop()
                else:
                    stack.pop()

        index += 1

    if in_string:
        problems.append(f"{path}: unterminated string")
    if in_block_comment:
        problems.append(f"{path}: unterminated block comment")
    for opener, opened_at in stack:
        problems.append(f"{path}:{opened_at}: '{opener}' is never closed")
    # A file that consists of a single PHP block does not need a closing tag.
    # Only an unterminated block that follows a closing tag is a problem.
    if in_php and saw_close_tag:
        problems.append(f"{path}: PHP block is never closed")

    return problems


def main():
    roots = sys.argv[1:] or ['src']
    files = []
    for root in roots:
        if os.path.isfile(root):
            files.append(root)
            continue
        for dirpath, _, filenames in os.walk(root):
            for name in sorted(filenames):
                if name.endswith('.php') or name.endswith('.page'):
                    files.append(os.path.join(dirpath, name))

    if not files:
        print("no PHP files found")
        return 1

    failures = 0
    for path in sorted(files):
        problems = check(path)
        if problems:
            failures += 1
            for problem in problems:
                print(f"FAIL {problem}")
        else:
            print(f"ok   {path}")

    if failures:
        print(f"\n{failures} file(s) with problems")
        return 1

    print(f"\nall {len(files)} file(s) look syntactically sound")
    return 0


if __name__ == '__main__':
    sys.exit(main())
