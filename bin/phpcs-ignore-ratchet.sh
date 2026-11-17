#!/usr/bin/env bash
#
# Ratchet for unnamed phpcs suppressions.
#
# A suppression that names its sniff silences that sniff on that line. A bare
# one silences every sniff on the line, including sniffs added to the standard
# years later, so it hides defects nobody has written a rule for yet. The repo
# carries a large backlog of bare suppressions; clearing them is its own sprint.
# This script exists so the backlog cannot grow while that sprint is pending.
#
# Every file's current count is grandfathered in the baseline. The check fails
# when a file's count moves away from its baseline in either direction: growing
# means a new bare suppression, shrinking means somebody cleaned one up and the
# baseline needs to come down with it. Both are fixed the same way -- look at
# what changed, then run `--update` and commit the baseline.
#
# Usage:
#   bin/phpcs-ignore-ratchet.sh            Check the tree against the baseline.
#   bin/phpcs-ignore-ratchet.sh --update   Rewrite the baseline from the tree.
#
# What counts as unnamed:
#   // phpcs:ignore                                  <- bare
#   // phpcs:ignore -- reason.                       <- bare, a reason is not a sniff
#   /* phpcs:disable */                              <- bare
#   // @codingStandardsIgnoreLine                    <- legacy syntax, cannot name a sniff
#   // phpcs:ignore WordPress.Security.EscapeOutput  <- named, fine
#
# Scope mirrors the exclude-patterns in phpcs.xml: a suppression in a file phpcs
# never reads suppresses nothing, so counting it would be noise.

set -euo pipefail

cd "$(dirname "$0")/.."

BASELINE_FILE=".phpcs-ignore-baseline"

# Paths phpcs.xml excludes. A suppression there is inert.
EXCLUDED='^(vendor/|bin/|\.github/|tests/|libs/|cron/)'

# A directive is named when the next non-space token after it starts with a
# letter (a sniff code, a standard, or a comma-separated list of them). Anything
# else -- end of line, a `--` reason, a comment terminator -- means it named
# nothing and silences the whole line.
#
# Three shapes have to be caught beyond the obvious one, each verified against
# the bundled phpcs by feeding it a file with 9 errors and watching them vanish:
#
#   phpcs:ignoreFile   silences the ENTIRE file and cannot name a sniff. It has
#                      to be matched before the ignore/disable rule, whose
#                      "next char is not a letter" test would call the "F" a
#                      sniff name and wave it through.
#   @codingStandards*  the legacy family; no syntax for naming a sniff at all.
#   MiXeD case         phpcs reads its directives case-insensitively, so a
#                      case-sensitive check is a one-keystroke bypass. Hence -i
#                      on every grep that uses this pattern.
UNNAMED='(@?phpcs:ignorefile)|(@?phpcs:(ignore|disable)[ \t]*([^A-Za-z0-9 \t]|$))|(@codingStandardsIgnore(Line|Start|End|File)?)'

# Emit "<count> <path>" for every in-scope file carrying at least one unnamed
# suppression, sorted by path in byte order (LC_ALL=C) so the output matches the
# baseline under any locale and diffs cleanly. Counts occurrences,
# not lines, so two suppressions on one line cannot hide behind each other.
# Deliberately avoids `grep -z` / `xargs -0`: BSD grep reads -z as "decompress",
# so a null-delimited pipeline that works in CI would silently misbehave on a Mac.
current_counts() {
    git ls-files '*.php' | grep -Ev "$EXCLUDED" | while IFS= read -r file; do
        # grep exits 1 on no-match, and pipefail would turn that into a script
        # abort on the first clean file, so absorb it before the count pipeline.
        count=$( { grep -oiE "$UNNAMED" "$file" 2>/dev/null || true; } | wc -l | tr -d ' ' )
        if [ "$count" -gt 0 ]; then
            printf '%s %s\n' "$count" "$file"
        fi
    done | LC_ALL=C sort -k2
}

total_of() {
    printf '%s\n' "$1" | awk '{ total += $1 } END { print total + 0 }'
}

if [ "${1:-}" = "--update" ]; then
    current_counts > "$BASELINE_FILE"
    printf 'Baseline rewritten: %s unnamed suppressions across %s files.\n' \
        "$(awk '{ total += $1 } END { print total + 0 }' "$BASELINE_FILE")" \
        "$(wc -l < "$BASELINE_FILE" | tr -d ' ')"
    exit 0
fi

# --since <ref>: compare the working tree against the baseline as it exists at
# <ref>, not against the baseline in this checkout.
#
# Without this the gate is self-approving: the baseline lives in the same tree it
# guards, so a PR can add a bare suppression, bump that file's count (or just run
# --update), and the equality check below passes on the PR's own numbers. CI runs
# this mode with the merge base, which the PR cannot rewrite, so a per-file
# increase fails no matter what the PR did to the committed baseline.
#
# A decrease is fine here -- cleaning suppressions up is the goal. This mode only
# rejects growth; the equality check keeps the committed baseline honest.
if [ "${1:-}" = "--since" ]; then
    BASE_REF="${2:-}"
    [ -n "$BASE_REF" ] || { printf 'error: --since needs a git ref.\n' >&2; exit 1; }

    BASE_BASELINE="$( git show "${BASE_REF}:${BASELINE_FILE}" 2>/dev/null || true )"
    if [ -z "$BASE_BASELINE" ]; then
        printf 'phpcs ignore ratchet: no baseline at %s, nothing to compare against (skipping growth check).\n' "$BASE_REF"
        exit 0
    fi

    # Both lists go through one stream, tagged. `awk -v` cannot carry a multi-line
    # value (a literal newline in the assignment is a syntax error), and a temp
    # file would put the check back on a writable $TMPDIR it does not otherwise
    # need. The BASE lines are emitted first, so `was` is populated before any CUR
    # line is tested.
    GROWTH="$(
        {
            printf '%s\n' "$BASE_BASELINE" | sed 's/^/BASE /'
            current_counts | sed 's/^/CUR /'
        } | awk '
            $1 == "BASE" { was[ $3 ] = $2 }
            $1 == "CUR" && $2 + 0 > was[ $3 ] + 0 {
                printf "  %-58s %d -> %d\n", $3, was[ $3 ] + 0, $2 + 0;
            }
        ' | LC_ALL=C sort
    )"

    if [ -z "$GROWTH" ]; then
        printf 'phpcs ignore ratchet: OK (no new unnamed suppressions since %s).\n' "$BASE_REF"
        exit 0
    fi

    printf 'phpcs ignore ratchet: FAILED\n\n' >&2
    printf 'These files gained unnamed phpcs suppressions since %s (file, then, now):\n\n' "$BASE_REF" >&2
    printf '%s\n' "$GROWTH" >&2
    printf '\nName the sniff each one needs and it stops counting:\n' >&2
    printf '  // phpcs:ignore WordPress.Security.EscapeOutput -- reason.\n' >&2
    printf '\nEditing %s does not clear this check. It reads the baseline from %s.\n' "$BASELINE_FILE" "$BASE_REF" >&2
    exit 1
fi

if [ ! -f "$BASELINE_FILE" ]; then
    printf 'error: %s is missing. Run `bin/phpcs-ignore-ratchet.sh --update` to create it.\n' "$BASELINE_FILE" >&2
    exit 1
fi

# Held in a variable rather than a temp file: the check then needs nothing
# writable anywhere, so it runs the same in CI, in a sandbox, and on a read-only
# checkout.
CURRENT="$(current_counts)"

if [ "$CURRENT" = "$(cat "$BASELINE_FILE")" ]; then
    printf 'phpcs ignore ratchet: OK (%s unnamed suppressions, unchanged).\n' "$(total_of "$CURRENT")"
    exit 0
fi

printf 'phpcs ignore ratchet: FAILED\n\n' >&2
printf 'The unnamed-suppression count moved (file, baseline, now):\n\n' >&2

# Compared in awk against the baseline file rather than by diffing two streams:
# process substitution needs /dev/fd, which a sandboxed runner can refuse, and a
# check whose failure output degrades into an error message is worse than no check.
printf '%s\n' "$CURRENT" | awk -v baseline="$BASELINE_FILE" '
    BEGIN {
        while ( ( getline line < baseline ) > 0 ) {
            split( line, field, " " );
            was[ field[2] ] = field[1];
            seen[ field[2] ] = 1;
        }
    }
    {
        now[ $2 ] = $1;
        seen[ $2 ] = 1;
    }
    END {
        for ( file in seen ) {
            if ( was[ file ] + 0 != now[ file ] + 0 ) {
                printf "  %-58s %d -> %d\n", file, was[ file ] + 0, now[ file ] + 0;
            }
        }
    }
' | LC_ALL=C sort >&2

printf '\nA count that went UP means a new bare suppression. Name the sniff it needs\n' >&2
printf '  // phpcs:ignore WordPress.Security.EscapeOutput -- reason.\n' >&2
printf 'and it stops counting. A count that went DOWN means a suppression was cleaned\n' >&2
printf 'up, which is the point -- run `bin/phpcs-ignore-ratchet.sh --update` and commit\n' >&2
printf 'the baseline so the ratchet holds at its new position.\n' >&2
exit 1
