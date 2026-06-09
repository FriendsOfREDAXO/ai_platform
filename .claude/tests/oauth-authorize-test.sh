#!/bin/bash
# End-to-end test for /oauth/authorize, /oauth/register (DCR) and the full
# auth-code+PKCE flow that ends in a usable access token against /mcp.
#
# Boots REDAXO via a PHP helper to seed a YCom user + group + scope
# mapping, then drives the OAuth flow with curl + a cookie jar.

set -uo pipefail
cd "$(dirname "$0")/../.."

BASE="${BASE:-https://redaxo.localhost}"
COOKIE_JAR="$(mktemp)"
SEED_OUTPUT=""
USER_ID=""
GROUP_ID=""
LOGIN=""
PASSWORD=""

PASS=0
FAIL=0

assert() {
    if [[ "$1" == "$2" ]]; then
        echo "  OK   $3"; PASS=$((PASS + 1))
    else
        echo "  FAIL $3"; echo "       expected: $2"; echo "       got:      $1"
        FAIL=$((FAIL + 1))
    fi
}

assert_contains() {
    if [[ "$1" == *"$2"* ]]; then
        echo "  OK   $3"; PASS=$((PASS + 1))
    else
        echo "  FAIL $3"; echo "       expected substring: $2"; echo "       got: $(echo "$1" | head -c 300)..."
        FAIL=$((FAIL + 1))
    fi
}

cleanup() {
    rm -f "$COOKIE_JAR"
    if [[ -n "$USER_ID" && -n "$GROUP_ID" ]]; then
        php .claude/tests/oauth-authorize-test-seed.php cleanup "$USER_ID" "$GROUP_ID" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# ---- Seed test user + group + scope mapping ----
SEED_OUTPUT=$(php .claude/tests/oauth-authorize-test-seed.php seed)
USER_ID=$(echo "$SEED_OUTPUT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['user_id'])")
GROUP_ID=$(echo "$SEED_OUTPUT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['group_id'])")
LOGIN=$(echo "$SEED_OUTPUT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['login'])")
PASSWORD=$(echo "$SEED_OUTPUT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['password'])")

echo ""
echo "=== Stage 2c — /oauth/{register,authorize,token} end-to-end ==="
echo "seeded user_id  = $USER_ID"
echo "seeded group_id = $GROUP_ID"
echo "login           = $LOGIN"
echo ""

# ---- 1. DCR — POST /oauth/register ----
echo "--- DCR ---"
DCR_RESP=$(curl -sk -X POST "$BASE/oauth/register" \
    -H "Content-Type: application/json" \
    -d "{\"client_name\":\"AI Platform OAuth Test\",\"redirect_uris\":[\"https://example.org/cb\"]}")
CLIENT_ID=$(echo "$DCR_RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('client_id',''))")
[[ -n "$CLIENT_ID" ]] && assert "true" "true" "DCR returns client_id" || assert "false" "true" "DCR returns client_id"
assert_contains "$DCR_RESP" '"token_endpoint_auth_method":"none"' "DCR returns token_endpoint_auth_method=none"
assert_contains "$DCR_RESP" '"redirect_uris":["https://example.org/cb"]' "DCR echoes redirect_uris"

DCR_STATUS=$(curl -sk -o /dev/null -w "%{http_code}" -X POST "$BASE/oauth/register" \
    -H "Content-Type: application/json" \
    -d "{\"client_name\":\"x\",\"redirect_uris\":[\"https://example.org/cb\"]}")
assert "$DCR_STATUS" "201" "DCR returns 201"

# Negative cases
DCR_ERR=$(curl -sk -X POST "$BASE/oauth/register" -H "Content-Type: application/json" -d "{}")
ERR=$(echo "$DCR_ERR" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('error',''))")
assert "$ERR" "invalid_redirect_uri" "DCR rejects missing redirect_uris"

DCR_ERR=$(curl -sk -X POST "$BASE/oauth/register" -H "Content-Type: application/json" -d "{\"redirect_uris\":[\"not-a-url\"]}")
ERR=$(echo "$DCR_ERR" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('error',''))")
assert "$ERR" "invalid_redirect_uri" "DCR rejects relative URIs"

DCR_405=$(curl -sk -o /dev/null -w "%{http_code}" "$BASE/oauth/register")
assert "$DCR_405" "405" "DCR GET returns 405"

# ---- 2. PKCE prep ----
VERIFIER=$(openssl rand -hex 32)
CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')
STATE=$(openssl rand -hex 8)
REDIRECT_URI="https://example.org/cb"

# ---- 3. Pre-flight authorize errors (no login required) ----
echo ""
echo "--- /oauth/authorize negative paths ---"
# Unknown client
STATUS=$(curl -sk -o /dev/null -w "%{http_code}" "$BASE/oauth/authorize?response_type=code&client_id=ai_does_not_exist&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE")
assert "$STATUS" "400" "unknown client_id -> 400"

# redirect_uri mismatch
STATUS=$(curl -sk -o /dev/null -w "%{http_code}" "$BASE/oauth/authorize?response_type=code&client_id=$CLIENT_ID&redirect_uri=https%3A%2F%2Fattacker.example%2Fcb&code_challenge=$CHALLENGE")
assert "$STATUS" "400" "redirect_uri mismatch -> 400"

# bad response_type — redirect with error
LOC=$(curl -sk -o /dev/null -D - "$BASE/oauth/authorize?response_type=token&client_id=$CLIENT_ID&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&state=$STATE" | grep -i "^location:" | head -1)
assert_contains "$LOC" "unsupported_response_type" "bad response_type -> redirect with error"
assert_contains "$LOC" "state=$STATE" "error redirect echoes state"

# missing code_challenge
LOC=$(curl -sk -o /dev/null -D - "$BASE/oauth/authorize?response_type=code&client_id=$CLIENT_ID&redirect_uri=$REDIRECT_URI" | grep -i "^location:" | head -1)
assert_contains "$LOC" "invalid_request" "missing code_challenge -> redirect with error"

# ---- 4. GET /oauth/authorize — login screen rendered ----
echo ""
echo "--- login screen ---"
LOGIN_HTML=$(curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    "$BASE/oauth/authorize?response_type=code&client_id=$CLIENT_ID&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=$STATE&scope=mcp%3Atools%3Aread+mcp%3Atools%3Acall")
assert_contains "$LOGIN_HTML" 'name="login"' "login form rendered (login input)"
assert_contains "$LOGIN_HTML" 'name="password"' "login form rendered (password input)"
assert_contains "$LOGIN_HTML" "value=\"$CLIENT_ID\"" "login form preserves client_id"
assert_contains "$LOGIN_HTML" "value=\"$STATE\"" "login form preserves state"

# ---- 5. POST wrong credentials → still on login screen with error ----
WRONG_HTML=$(curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE/oauth/authorize" \
    --data-urlencode "_action=login" \
    --data-urlencode "login=$LOGIN" \
    --data-urlencode "password=wrong-password" \
    --data-urlencode "response_type=code" \
    --data-urlencode "client_id=$CLIENT_ID" \
    --data-urlencode "redirect_uri=$REDIRECT_URI" \
    --data-urlencode "code_challenge=$CHALLENGE" \
    --data-urlencode "code_challenge_method=S256" \
    --data-urlencode "state=$STATE" \
    --data-urlencode "scope=mcp:tools:read mcp:tools:call")
assert_contains "$WRONG_HTML" 'name="password"' "wrong-password keeps login screen"

# ---- 6. POST correct credentials → consent screen ----
echo ""
echo "--- consent screen ---"
CONSENT_HTML=$(curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE/oauth/authorize" \
    --data-urlencode "_action=login" \
    --data-urlencode "login=$LOGIN" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "response_type=code" \
    --data-urlencode "client_id=$CLIENT_ID" \
    --data-urlencode "redirect_uri=$REDIRECT_URI" \
    --data-urlencode "code_challenge=$CHALLENGE" \
    --data-urlencode "code_challenge_method=S256" \
    --data-urlencode "state=$STATE" \
    --data-urlencode "scope=mcp:tools:read mcp:tools:call")
assert_contains "$CONSENT_HTML" 'name="decision"' "consent screen renders"
assert_contains "$CONSENT_HTML" "mcp:tools:read" "consent screen lists granted scope"
assert_contains "$CONSENT_HTML" "mcp:tools:call" "consent screen lists granted scope"
assert_contains "$CONSENT_HTML" "$LOGIN" "consent screen shows logged-in user"

# ---- 7. POST consent deny → redirect with access_denied ----
DENY_LOC=$(curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null -D - -X POST "$BASE/oauth/authorize" \
    --data-urlencode "_action=consent" \
    --data-urlencode "decision=deny" \
    --data-urlencode "response_type=code" \
    --data-urlencode "client_id=$CLIENT_ID" \
    --data-urlencode "redirect_uri=$REDIRECT_URI" \
    --data-urlencode "code_challenge=$CHALLENGE" \
    --data-urlencode "code_challenge_method=S256" \
    --data-urlencode "state=$STATE" | grep -i "^location:" | head -1)
assert_contains "$DENY_LOC" "access_denied" "deny -> redirect with access_denied"
assert_contains "$DENY_LOC" "state=$STATE" "deny redirect echoes state"

# ---- 8. POST consent allow → redirect with code ----
ALLOW_HEADERS=$(curl -sk -c "$COOKIE_JAR" -b "$COOKIE_JAR" -o /dev/null -D - -X POST "$BASE/oauth/authorize" \
    --data-urlencode "_action=consent" \
    --data-urlencode "decision=allow" \
    --data-urlencode "response_type=code" \
    --data-urlencode "client_id=$CLIENT_ID" \
    --data-urlencode "redirect_uri=$REDIRECT_URI" \
    --data-urlencode "code_challenge=$CHALLENGE" \
    --data-urlencode "code_challenge_method=S256" \
    --data-urlencode "state=$STATE" \
    --data-urlencode "scope=mcp:tools:read mcp:tools:call")
ALLOW_LOC=$(echo "$ALLOW_HEADERS" | grep -i "^location:" | head -1)
assert_contains "$ALLOW_LOC" "code=" "allow -> redirect with code"
assert_contains "$ALLOW_LOC" "state=$STATE" "allow redirect echoes state"

CODE=$(echo "$ALLOW_LOC" | sed -nE 's/.*[?&]code=([^&[:space:]]+).*/\1/p')
[[ -n "$CODE" ]] && assert "true" "true" "code extracted" || assert "false" "true" "code extracted"

# ---- 9. Exchange code for tokens ----
echo ""
echo "--- token exchange ---"
TOKEN_RESP=$(curl -sk -X POST "$BASE/oauth/token" \
    -d "grant_type=authorization_code" \
    -d "code=$CODE" \
    -d "redirect_uri=$REDIRECT_URI" \
    -d "client_id=$CLIENT_ID" \
    -d "code_verifier=$VERIFIER")
ACCESS=$(echo "$TOKEN_RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('access_token',''))")
SCOPE=$(echo "$TOKEN_RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('scope',''))")
[[ -n "$ACCESS" ]] && assert "true" "true" "token exchange yields access_token" || assert "false" "true" "token exchange yields access_token"
assert "$SCOPE" "mcp:tools:read mcp:tools:call" "issued scopes match consented scopes"

# ---- 10. Use access token against /mcp ----
echo ""
echo "--- use token against /mcp ---"
MCP_RESP=$(curl -sk -X POST "$BASE/mcp" \
    -H "Authorization: Bearer $ACCESS" \
    -H "Content-Type: application/json" \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
assert_contains "$MCP_RESP" "redaxo_status" "tools/list with bearer returns public tools"

echo ""
echo "=== Summary ==="
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
exit $FAIL
