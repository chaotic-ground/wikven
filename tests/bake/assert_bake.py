#!/usr/bin/env python3
"""Assert that a directory holds a complete, self-contained wikven site.

    npm run test:bake                       # against ./dist, with this repository's expectations
    python3 tests/bake/assert_bake.py --expect tests/bake/docs.toml some/other/dist

The checks themselves live in checks.py and know nothing about where they are run. This file reads
the site's expectations, decides which checks have the input they asked for, runs them all, and
prints one line per check plus whatever each had to say. It exits 1 if any of them found a problem.

Every check runs even after one fails, which the shell step this replaces could not do: it ran
under `set -e`, so the first failure hid the rest and a broken bake took as many pushes to
understand as it had problems.

--github additionally emits a ::error:: workflow command per problem, so the annotations that used
to be written into the assertions are now the runner's business rather than theirs.
"""

from __future__ import annotations

import argparse
import os
import sys
import tomllib
import traceback

import checks

# What each optional input is called on the command line, for the line a skipped check prints.
FLAGS = {'source': '--source', 'logs': '--log', 'other': '--compare-with'}


def parse_args(argv: list[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='assert_bake.py',
        description=__doc__.splitlines()[0],
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('dist', help='the baked site to read')
    parser.add_argument(
        '--expect',
        required=True,
        metavar='FILE',
        help='TOML file naming what is this site\'s rather than wikven\'s',
    )
    parser.add_argument(
        '--source',
        metavar='DIR',
        help='the source tree the site was baked from; three checks derive their target from it',
    )
    parser.add_argument(
        '--log',
        action='append',
        default=[],
        metavar='FILE',
        help='a log the bake wrote; repeatable',
    )
    parser.add_argument(
        '--compare-with',
        metavar='DIR',
        help='a second bake of the same source, for the checks that compare the two',
    )
    parser.add_argument(
        '--github',
        action='store_true',
        help='also emit ::error:: workflow commands, for a GitHub Actions runner',
    )
    return parser.parse_args(argv)


def missing_input(site: checks.Site, needs: tuple[str, ...]) -> str | None:
    """Which of a check's declared inputs was not supplied, if any."""
    for need in needs:
        if not getattr(site, need):
            return need
    return None


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)

    if not os.path.isdir(args.dist):
        print(f'{args.dist} is not a directory, so there is no bake to read', file=sys.stderr)
        return 2
    with open(args.expect, 'rb') as handle:
        expect = tomllib.load(handle)

    site = checks.Site(
        dist=args.dist.rstrip(os.sep) or args.dist,
        expect=expect,
        source=args.source,
        logs=args.log,
        other=args.compare_with,
    )

    failed, skipped, problems = 0, 0, []
    for check in checks.CHECKS:
        site.notes.clear()
        absent = missing_input(site, check.needs)
        if absent:
            skipped += 1
            print(f'skip  {check.summary}')
            print(f'        no {FLAGS[absent]} given, so this check has nothing to read')
            continue
        try:
            found = check.run(site)
        except Exception:  # noqa: BLE001 -- a check that raises is a check that failed.
            found = ['the check itself raised:', *traceback.format_exc().strip().splitlines()]
        if found:
            failed += 1
            print(f'FAIL  {check.summary}')
            problems.extend((check.name, line) for line in found)
        else:
            print(f'ok    {check.summary}')
            found = site.notes
        for line in found:
            print(f'        {line}')

    total = len(checks.CHECKS)
    print()
    print(f'{total - failed - skipped} of {total} checks passed, {failed} failed, {skipped} skipped')
    if args.github:
        for name, line in problems:
            print(f'::error::{name}: {line}')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
