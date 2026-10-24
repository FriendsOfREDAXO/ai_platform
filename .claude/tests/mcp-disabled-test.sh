#!/bin/bash
# With the MCP server switched off in the backend (mcp_enabled = 0), nothing may
# be possible: not /mcp, and not the OAuth server that exists only for it —
# no discovery, no client registration, no login, no token.
#
# Switches the live instance off for the duration of the run and restores the
# previous value on exit, together with any client the run managed to register.

set -euo pipefail
cd "$(dirname "$0")"

BASE="${BASE:-https://redaxo.localhost}"
HELPER=(php mcp-disabled-test-switch.php)
CLIENT_NAME="ai_platform_mcp_disabled_test"
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

status() {  # $1 = method, $2 = path, rest = extra curl args
    local method="$1" path="$2"
    shift 2
    curl -sk -m 20 -o /dev/null -w '%{http_code}' -X "$method" "$@" "$BASE$path"
}

PREVIOUS=$("${HELPER[@]}" get)
restore() {
    "${HELPER[@]}" set "$PREVIOUS"
    "${HELPER[@]}" cleanup "$CLIENT_NAME"
}
trap restore EXIT

"${HELPER[@]}" cleanup "$CLIENT_NAME"
"${HELPER[@]}" set 0

echo "MCP switched off:"
JSON=(-H 'Content-Type: application/json')
assert "$(status POST /mcp "${JSON[@]}" --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')" 503 "POST /mcp initialize"
assert "$(status POST /mcp "${JSON[@]}" -H 'Authorization: Bearer x' --data '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')" 503 "POST /mcp tools/list with a bearer token"
assert "$(status GET /.well-known/oauth-protected-resource)" 503 "GET /.well-known/oauth-protected-resource"
assert "$(status GET /.well-known/oauth-protected-resource/mcp)" 503 "GET /.well-known/oauth-protected-resource/mcp"
assert "$(status GET /.well-known/oauth-authorization-server)" 503 "GET /.well-known/oauth-authorization-server"
assert "$(status GET '/oauth/authorize?response_type=code&client_id=x&redirect_uri=http%3A%2F%2Flocalhost%2Fcb&code_challenge=abc&code_challenge_method=S256')" 503 "GET /oauth/authorize"
assert "$(status POST /oauth/token --data 'grant_type=authorization_code&code=x')" 503 "POST /oauth/token"
assert "$(status POST /oauth/register "${JSON[@]}" --data "{\"client_name\":\"$CLIENT_NAME\",\"redirect_uris\":[\"http://localhost/cb\"]}")" 503 "POST /oauth/register"
assert "$("${HELPER[@]}" clients "$CLIENT_NAME")" 0 "no client was registered"
assert "$(status GET /oauth/unknown)" 503 "GET /oauth/unknown"

echo "MCP switched on (the gate must not catch anything else):"
"${HELPER[@]}" set 1
assert "$(status GET /.well-known/oauth-authorization-server)" 200 "GET /.well-known/oauth-authorization-server"
assert "$([[ "$(status POST /mcp "${JSON[@]}" --data '{"jsonrpc":"2.0","id":4,"method":"ping"}')" != 503 ]] && echo yes || echo no)" yes "POST /mcp is not 503"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
