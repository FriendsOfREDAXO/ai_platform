#!/bin/bash
# A 401 from /mcp has to say why. A bare "Authentication required" reads like a
# broken client, when the cause is usually the "Authentifizierung erforderlich"
# setting on the server (issue #10). So the JSON-RPC error carries error.data
# with a reason, a hint and the metadata URL, next to the WWW-Authenticate header
# that clients act on.
#
# Switches mcp_enabled and mcp_require_auth on the live instance for the run and
# restores both on exit.

set -euo pipefail
cd "$(dirname "$0")"

BASE="${BASE:-https://redaxo.localhost}"
HELPER=(php mcp-disabled-test-switch.php)
PASS=0
FAIL=0

assert() {
    if [[ "$1" == "$2" ]]; then
        echo "  OK   $3"
        PASS=$((PASS + 1))
    else
        echo "  FAIL $3"
        echo "       expected: $2"
        echo "       got:      $1"
        FAIL=$((FAIL + 1))
    fi
}

json_get() {  # same helper as in api-changes-test.sh
    php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        foreach (array_slice($argv, 1) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                exit;
            }
            $data = $data[$key];
        }
        echo is_bool($data) ? ($data ? "1" : "0") : (is_scalar($data) ? $data : json_encode($data));
    ' "$@"
}

rpc() {  # $1 = JSON-RPC body, rest = extra curl args; prints "<status>\n<body>"
    local body="$1"
    shift
    curl -sk -m 20 -w '\n%{http_code}' -X POST "$BASE/mcp" -H 'Content-Type: application/json' "$@" --data "$body"
}

PREV_ENABLED=$("${HELPER[@]}" get)
PREV_AUTH=$("${HELPER[@]}" get-auth)
restore() {
    "${HELPER[@]}" set "$PREV_ENABLED"
    "${HELPER[@]}" set-auth "$PREV_AUTH"
}
trap restore EXIT

"${HELPER[@]}" set 1
METADATA="$BASE/.well-known/oauth-protected-resource/mcp"

echo "Authentication required for every request:"
"${HELPER[@]}" set-auth 1
OUT=$(rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')
BODY=$(echo "$OUT" | sed '$d')
assert "$(echo "$OUT" | tail -1)" 401 "initialize is challenged"
assert "$(echo "$BODY" | json_get error message)" "Authentication required" "the message stays what clients already know"
assert "$(echo "$BODY" | json_get error data reason)" server_requires_auth "the reason names the server setting"
HINT=$(echo "$BODY" | json_get error data hint)
assert "$([[ "$HINT" == *"Authentifizierung erforderlich"* ]] && echo yes || echo no)" yes "the hint names the setting as the backend labels it"
assert "$(echo "$BODY" | json_get error data resource_metadata)" "$METADATA" "and points at the metadata document"

echo "An invalid token:"
OUT=$(rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' -H 'Authorization: Bearer not-a-token')
assert "$(echo "$OUT" | tail -1)" 401 "is challenged"
assert "$(echo "$OUT" | sed '$d' | json_get error data reason)" invalid_token "with its own reason"

echo "Anonymous clients allowed, protected tool called:"
"${HELPER[@]}" set-auth 0
OUT=$(rpc '{"jsonrpc":"2.0","id":3,"method":"initialize","params":{}}')
assert "$(echo "$OUT" | tail -1)" 200 "initialize is not challenged"
# project_whoami is the example tool in the project addon. Not every installation
# has a protected tool, so this part says so instead of failing.
OUT=$(rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"project_whoami","arguments":{}}}')
if [[ "$(echo "$OUT" | tail -1)" == 401 ]]; then
    assert "$(echo "$OUT" | sed '$d' | json_get error data reason)" tool_requires_auth "the reason names the tool, not the server"
else
    echo "  SKIP no protected tool project_whoami on this installation"
fi

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
