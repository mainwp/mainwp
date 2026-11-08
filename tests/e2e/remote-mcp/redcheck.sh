#!/usr/bin/env bash
#
# Helper mutations establish verdict sensitivity; workflow fixtures exercise orchestration.
# Neither phase establishes live-site coverage. Each literal must match once and produce an
# assertion failure. Restore and verify every changed file, including on interruption.
# Finish with all 33 mutations detected and the restored self-test project green.

set -uo pipefail

# The script relies on associative arrays (bash 4+); macOS /bin/bash is 3.2.
if (( BASH_VERSINFO[0] < 4 )); then
    echo "redcheck.sh needs bash 4 or newer; run it with a current bash" >&2
    exit 2
fi
export RMCP_REDCHECK_BASH="$BASH"

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
H="$ROOT/tests/e2e/remote-mcp"
cd "$H" || exit 1

declare -A FILES=(
    [V]="lib/verdicts.ts"
    [M]="conformance/memory-suite.mjs"
    [Y]="conformance/baseline-2026-07-28.yml"
    [W]="lib/workflows.ts"
    [G]="lib/redcheck-status.sh"
)

BACKUP="$(mktemp -d "${TMPDIR:-/tmp}/rmcp-redcheck.XXXXXX")"
declare -A SHA
for key in "${!FILES[@]}"; do
    f="${FILES[$key]}"
    cp -p "$f" "$BACKUP/$key"
    SHA[$key]="$(shasum -a 256 "$f" | cut -d' ' -f1)"
done

restore_all() {
    local status=0
    for key in "${!FILES[@]}"; do
        f="${FILES[$key]}"
        cp -p "$BACKUP/$key" "$f"
        if [ "$(shasum -a 256 "$f" | cut -d' ' -f1)" != "${SHA[$key]}" ]; then
            echo "RESTORE FAILED: $f" >&2
            status=1
        fi
    done
    return $status
}
trap 'restore_all; rm -rf "$BACKUP"' EXIT
trap 'echo "interrupted, restoring" >&2; exit 130' INT TERM

# Literal substitution, exactly once, written to a temp file then moved. A literal backslash-n in
# the table stands for a newline.
mutate() {
    local file="$1" from="$2" to="$3"
    FROM="$from" TO="$to" perl -e '
        my $file = $ARGV[0];
        open my $in, "<", $file or die "open $file: $!";
        local $/; my $src = <$in>; close $in;
        my $from = $ENV{FROM}; my $to = $ENV{TO};
        s/\\n/\n/g for $from, $to;
        my $count = () = $src =~ /\Q$from\E/g;
        if ( $count != 1 ) { print STDERR "pattern matched $count times in $file\n"; exit 3; }
        $src =~ s/\Q$from\E/$to/;
        open my $out, ">", "$file.redcheck" or die; print $out $src; close $out;
        rename "$file.redcheck", $file or die;
    ' "$file"
}

# named check ||| file key ||| literal to remove ||| replacement
# Helper rows (file key V) run the verdict tests for their named check. The other rows run the
# self-tests whose titles start with the row's label, the text before the first colon.
MUTATIONS=$(cat <<'EOF'
full flow on a root install lists and calls a read tool|||V|||export function toolListing(tools: unknown[], read: string, write: string): Problems {|||export function toolListing(tools: unknown[], read: string, write: string): Problems {\n  return [];
a write call on a read-only grant gets the step-up challenge over HTTP|||V|||problems.push('error is not insufficient_scope');\n  if (c.scope !== BOTH_SCOPES)|||problems.push('error is not insufficient_scope');\n  if (false)
parallel refresh with one token yields one winner and a usable grace sibling|||V|||export function refreshRace(responses: RawResponse[]): Problems {|||export function refreshRace(responses: RawResponse[]): Problems {\n  return [];
refresh replay after grace revokes the family|||V|||export function tokenError(res: RawResponse, code: string): Problems {|||export function tokenError(res: RawResponse, code: string): Problems {\n  return [];
token is refused on wp-abilities, mainwp v2 and admin-ajax over real HTTP|||V|||return res.status === 401 || res.status === 403 ? [] : [|||return true ? [] : [
bearer token alone does not authenticate a dummy plugin route|||V|||return body.user_id === 0 ? [] : [|||return true ? [] : [
bearer token alone does not open the consent screen|||V|||export function loginRedirect(res: RawResponse): Problems {|||export function loginRedirect(res: RawResponse): Problems {\n  return [];
consent page cannot be framed|||V|||if (header(res, 'x-frame-options').trim().toUpperCase() !== 'DENY')|||if (false)
revocation from the panel takes effect on the next request|||V|||export function invalidTokenChallenge(res: RawResponse, prm: string): Problems {|||export function invalidTokenChallenge(res: RawResponse, prm: string): Problems {\n  return [];
copied tokens fail on a clone with a different home|||V|||problems.push('error is not invalid_token');\n  if (c.resource_metadata !== prm)|||problems.push('error is not invalid_token');\n  if (false)
parallel code redemption yields one grant and a valid replay revokes it|||V|||export function codeRace(responses: RawResponse[]): Problems {|||export function codeRace(responses: RawResponse[]): Problems {\n  return [];
well-known metadata is served on Local's stock nginx config|||V|||if (doc.issuer !== issuer) problems.push|||if (false) problems.push
an unregistered client_id is refused before login and nothing is fetched|||V|||export function errorPageBeforeLogin(res: RawResponse): Problems {|||export function errorPageBeforeLogin(res: RawResponse): Problems {\n  return [];
the kill-switch constant 404s every module path and refuses a live token|||V|||export function moduleNotFound(res: RawResponse): Problems {|||export function moduleNotFound(res: RawResponse): Problems {\n  return [];
malformed JSON gets a JSON-RPC parse error, not rest_invalid_json|||V|||export function jsonRpcParseError(res: RawResponse): Problems {|||export function jsonRpcParseError(res: RawResponse): Problems {\n  return [];
_envelope, _jsonp and _fields leave the 401 status, challenge and body unchanged|||V|||export function sameChallenge(plain: RawResponse, variant: RawResponse): Problems {|||export function sameChallenge(plain: RawResponse, variant: RawResponse): Problems {\n  return [];
no CORS headers on MCP, token or PRM responses, with or without Origin|||V|||.filter((name) => name.startsWith('access-control-'))|||.filter((name) => name.startsWith('access-control-allow-credentials-never'))
plain-HTTP requests to authorize, consent, token and MCP are refused without state change|||V|||export function httpRefusal(endpoint: 'authorize' \| 'consent' \| 'token' \| 'mcp', res: RawResponse): Problems {|||export function httpRefusal(endpoint: 'authorize' \| 'consent' \| 'token' \| 'mcp', res: RawResponse): Problems {\n  return [];
a plugin that blocks anonymous REST does not intercept the MCP challenge|||V|||export function missingTokenChallenge(res: RawResponse, prm: string): Problems {|||export function missingTokenChallenge(res: RawResponse, prm: string): Problems {\n  return [];
plain-permalink consent approval posts to the query authorize endpoint over HTTPS|||V|||if (posted.url !== authorizationEndpoint) problems.push|||if (false) problems.push
Claude Code lists and calls a read tool on a root, a subdirectory and a plain-permalink install|||V|||else if (results.some((b) => b.is_error === true)) problems.push|||else if (false) problems.push
official conformance: authorization server metadata and code grant pass|||V|||if (check.status !== 'SUCCESS') problems.push|||if (false) problems.push
official conformance: 2026-07-28 server requirements pass against the reviewed baseline|||V|||export function serverLeg(exit: { exitCode: number \| null; signal: string \| null }): Problems {|||export function serverLeg(exit: { exitCode: number \| null; signal: string \| null }): Problems {\n  return [];
official conformance: 2025-11-25 server requirements pass against the reviewed baseline|||V|||return exit.exitCode === 0 ? [] : [|||return true ? [] : [
baseline matching: keeps wire failures outside fixture exclusions|||Y|||  - tools-call-simple-text:tools-call-simple-text # (b) calls the fixture tool test_simple_text|||  - tools-call-simple-text # (b) calls the fixture tool test_simple_text
redaction: drops raw authorization details before persistence|||M|||checks.push(...result.checks.map(({ id, status }) => ({ id, status })));|||checks.push(...result.checks);
caching exclusions: excludes resource list caching only|||Y|||  - caching:sep-2549-resources-list-caching-hints # (a) needs resources/list; resources are not declared in v1|||# removed for mutation
clone workflow: verifies the copy before clone requests|||W|||  const source = await observe('source') as CloneObservation;|||  await sync(); await changedHome(); return;\n  const source = await observe('source') as CloneObservation;
consent workflow: authenticates the consent HTTP probe|||W|||Cookie: login.map((cookie) => `${cookie.name}=${cookie.value}`).join('; ')|||Cookie: ''
read workflow: requires a completed read payload|||W|||  requireValid(v.toolCallOk(message, ability));|||  // validation removed for mutation
kill-switch workflow: probes all disabled paths|||W|||if (permalinks === inst.permalinks) requests.unshift(|||if (true) requests.unshift(
kill-switch workflow: drops one surface probe|||W|||      [urls.prm, {}],\n|||
status gate: enforces mutation counts|||G|||redcheck_status() {|||redcheck_status() {\n    return 0
EOF
)

LOG_DIR="$H/.redcheck-logs"
mkdir -p "$LOG_DIR"
ok=0
miss=0
total=0

while IFS= read -r line; do
    [ -z "$line" ] && continue
    name="${line%%|||*}"; rest="${line#*|||}"
    key="${rest%%|||*}"; rest="${rest#*|||}"
    from="${rest%%|||*}"; to="${rest#*|||}"
    # The table escapes "|" in TypeScript unions as "\|" so it never reads as a separator.
    from="${from//\\|/|}"; to="${to//\\|/|}"
    file="${FILES[$key]}"
    total=$((total + 1))
    log="$LOG_DIR/$(printf '%s' "$name" | tr -c 'A-Za-z0-9' '_' | cut -c1-80).txt"

    if ! mutate "$file" "$from" "$to"; then
        echo "RED-MISS $name (mutation did not apply)"
        miss=$((miss + 1))
        restore_all || exit 1
        continue
    fi

    evidence="helper"
    grep_re="$(printf 'verdict for: %s ' "$name" | perl -pe 's/([^A-Za-z0-9 _:,-])/\\$1/g')"
    if [ "$key" != V ]; then
        evidence="workflow"
        grep_re="${name%%: *}: "
    fi
    ./node_modules/.bin/playwright test --project=selftest -g "$grep_re" --workers=1 --reporter=list >"$log" 2>&1
    status=$?

    restore_all || { echo "aborting: restore failed" >&2; exit 1; }

    if [ "$status" -eq 1 ] \
        && grep -qE '^  [0-9]+ failed' "$log" \
        && grep -q 'expect(received)' "$log" \
        && ! grep -qE 'SyntaxError|TypeError|ReferenceError|Cannot find module|No tests found' "$log"; then
        echo "RED-OK $evidence: $name"
        ok=$((ok + 1))
    else
        echo "RED-MISS $name (exit $status, see $log)"
        miss=$((miss + 1))
    fi
done <<< "$MUTATIONS"

echo "---"
echo "redcheck: $ok RED-OK, $miss RED-MISS, $total checks"
echo "--- full self-test project"
./node_modules/.bin/playwright test --project=selftest --workers=2 --reporter=line 2>&1 | tail -3
suite_status=${PIPESTATUS[0]}
source "$H/lib/redcheck-status.sh"
redcheck_status "$ok" "$miss" "$total" 33 "$suite_status"
