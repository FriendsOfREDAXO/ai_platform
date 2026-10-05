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
# Set while the test points YCom at a different login article, so cleanup can put
# the real one back however this script ends. Leaving it wrong would break every
# frontend login on the installation, not just this suite.
LOGIN_ARTICLE_PREV=""

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

# Reads one value out of a JSON document on stdin: `json_get data status` walks
# into $doc['data']['status']. PHP rather than python3 because PHP is what this
# addon runs on -- it is guaranteed to be present wherever the suite makes sense,
# and whoever maintains the addon reads it. A missing key prints nothing, so the
# caller's assertion fails on the value instead of on a stack trace.
json_get() {
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

cleanup() {
    rm -f "$COOKIE_JAR"
    if [[ -n "$LOGIN_ARTICLE_PREV" ]]; then
        php .claude/tests/oauth-authorize-test-seed.php set-login-article "$LOGIN_ARTICLE_PREV" >/dev/null 2>&1 || true
    fi
    if [[ -n "$USER_ID" && -n "$GROUP_ID" ]]; then
        php .claude/tests/oauth-authorize-test-seed.php cleanup "$USER_ID" "$GROUP_ID" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# ---- Seed test user + group + scope mapping ----
SEED_OUTPUT=$(php .claude/tests/oauth-authorize-test-seed.php seed)
USER_ID=$(echo "$SEED_OUTPUT" | json_get user_id)
GROUP_ID=$(echo "$SEED_OUTPUT" | json_get group_id)
LOGIN=$(echo "$SEED_OUTPUT" | json_get login)
PASSWORD=$(echo "$SEED_OUTPUT" | json_get password)

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
CLIENT_ID=$(echo "$DCR_RESP" | json_get client_id)
[[ -n "$CLIENT_ID" ]] && assert "true" "true" "DCR returns client_id" || assert "false" "true" "DCR returns client_id"
assert_contains "$DCR_RESP" '"token_endpoint_auth_method":"none"' "DCR returns token_endpoint_auth_method=none"
assert_contains "$DCR_RESP" '"redirect_uris":["https://example.org/cb"]' "DCR echoes redirect_uris"

DCR_STATUS=$(curl -sk -o /dev/null -w "%{http_code}" -X POST "$BASE/oauth/register" \
    -H "Content-Type: application/json" \
    -d "{\"client_name\":\"x\",\"redirect_uris\":[\"https://example.org/cb\"]}")
assert "$DCR_STATUS" "201" "DCR returns 201"

# Negative cases
DCR_ERR=$(curl -sk -X POST "$BASE/oauth/register" -H "Content-Type: application/json" -d "{}")
ERR=$(echo "$DCR_ERR" | json_get error)
assert "$ERR" "invalid_redirect_uri" "DCR rejects missing redirect_uris"

DCR_ERR=$(curl -sk -X POST "$BASE/oauth/register" -H "Content-Type: application/json" -d "{\"redirect_uris\":[\"not-a-url\"]}")
ERR=$(echo "$DCR_ERR" | json_get error)
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

# ---- 4. GET /oauth/authorize — anonymous visitors go to YCom ----
#
# There is no password form in this addon any more. Signing in is YCom's job, so an
# anonymous visitor is redirected to YCom's login article and returned through
# `returnTo`. That is what keeps SAML, CAS and OAuth2 usable, and what puts YCom's
# injections (OTP, forced password change, terms of use) back in front of the token.
echo ""
echo "--- anonymous visitor is sent to YCom ---"
LOGIN_ARTICLE=$(php .claude/tests/oauth-authorize-test-seed.php login-article)
ARTICLE_ID=$(echo "$LOGIN_ARTICLE" | json_get id)
ARTICLE_EXISTS=$(echo "$LOGIN_ARTICLE" | json_get exists)
ARTICLE_MISSING=$(echo "$LOGIN_ARTICLE" | json_get missing)

if [[ "$ARTICLE_EXISTS" != "1" ]]; then
    echo "  FAIL YCom has no usable login article (article_id_login=$ARTICLE_ID)."
    echo "       Set one under YCom > Settings; without it nobody can sign in for MCP."
    FAIL=$((FAIL + 1))
fi

AUTHORIZE_QUERY="response_type=code&client_id=$CLIENT_ID&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=$STATE&scope=mcp%3Atools%3Aread+mcp%3Atools%3Acall"

REDIR_HEADERS=$(curl -sk -o /dev/null -D - "$BASE/oauth/authorize?$AUTHORIZE_QUERY")
REDIR_STATUS=$(echo "$REDIR_HEADERS" | head -1 | awk '{print $2}')
REDIR_LOC=$(echo "$REDIR_HEADERS" | grep -i "^location:" | head -1)
assert "$REDIR_STATUS" "302" "no session -> redirect to the YCom login article"
assert_contains "$REDIR_LOC" "returnTo" "redirect carries a returnTo parameter"
# The OAuth parameters have to survive the detour, or the flow cannot resume.
assert_contains "$REDIR_LOC" "$CLIENT_ID" "returnTo preserves client_id"
assert_contains "$REDIR_LOC" "$STATE" "returnTo preserves state"
assert_contains "$REDIR_LOC" "$CHALLENGE" "returnTo preserves code_challenge"

# Nothing this addon renders may ask for a password any more -- that was the form an
# SSO installation could not use and that skipped every YCom injection.
NO_FORM=$(curl -sk "$BASE/oauth/authorize?$AUTHORIZE_QUERY")
if [[ "$NO_FORM" != *'_action=login'* && "$NO_FORM" != *'name="password"'* ]]; then
    echo "  OK   the addon no longer renders a password form"; PASS=$((PASS + 1))
else
    echo "  FAIL the addon still renders a password form"; FAIL=$((FAIL + 1))
fi

# ---- 4b. No usable login article → a named server error, not a blank page ----
SET_OUT=$(php .claude/tests/oauth-authorize-test-seed.php set-login-article "$ARTICLE_MISSING")
LOGIN_ARTICLE_PREV=$(echo "$SET_OUT" | json_get previous)
MISSING_STATUS=$(curl -sk -o /dev/null -w "%{http_code}" "$BASE/oauth/authorize?$AUTHORIZE_QUERY")
MISSING_BODY=$(curl -sk "$BASE/oauth/authorize?$AUTHORIZE_QUERY")
assert "$MISSING_STATUS" "500" "a missing login article answers 500"
assert_contains "$MISSING_BODY" "login_unavailable" "and names the reason"
php .claude/tests/oauth-authorize-test-seed.php set-login-article "$LOGIN_ARTICLE_PREV" >/dev/null
LOGIN_ARTICLE_PREV=""

# ---- 5. Sign in through YCom's own form ----
#
# Deliberately the real route: follow the redirect, fill in the form YCom serves, post
# it back. That exercises the return trip end to end -- the form carries our returnTo
# in a hidden field, and YCom sends the browser back to /oauth/authorize once the
# credentials check out.
echo ""
echo "--- sign in via YCom's login form ---"
LOGIN_PAGE=$(curl -skL -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE/oauth/authorize?$AUTHORIZE_QUERY")
assert_contains "$LOGIN_PAGE" "/oauth/authorize" "YCom's form carries the return address"

# Parsed generically rather than by field index: YForm names its inputs
# FORM[<form>][<n>] and those numbers differ per installation. One text field is the
# login, one password field is the password, everything hidden rides along unchanged.
FORM_PARSED=$(echo "$LOGIN_PAGE" | LOGIN="$LOGIN" PASSWORD="$PASSWORD" php .claude/tests/parse-login-form.php)
PARSE_STATUS=$?

# The exit code, not just an empty result: a parser that dies halfway still prints
# the action line, and reading only that would report success on a broken parse.
if [[ $PARSE_STATUS -ne 0 || -z "$FORM_PARSED" ]]; then
    echo "  FAIL could not parse YCom's login form (exit $PARSE_STATUS)"; FAIL=$((FAIL + 1))
else
    echo "  OK   YCom's login form parsed"; PASS=$((PASS + 1))
fi

FORM_ACTION=$(echo "$FORM_PARSED" | head -1)
CURL_FIELDS=()
while IFS= read -r pair; do
    [[ -z "$pair" ]] && continue
    CURL_FIELDS+=(--data-urlencode "$pair")
done <<< "$(echo "$FORM_PARSED" | tail -n +2)"

# The form posts to its own article; YCom then redirects to returnTo, which is the
# authorize URL we started from -- so following redirects lands on the consent screen.
CONSENT_HTML=$(curl -skL -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE$FORM_ACTION" "${CURL_FIELDS[@]}")

echo ""
echo "--- consent screen ---"
assert_contains "$CONSENT_HTML" 'name="decision"' "signing in returns to the consent screen"
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
ACCESS=$(echo "$TOKEN_RESP" | json_get access_token)
SCOPE=$(echo "$TOKEN_RESP" | json_get scope)
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

# ---- 11. A user in no group at all ----
#
# Scopes come from groups, so this user can hold none -- the normal state of an
# installation that does not use YCom groups. The endpoint has to cope: it once
# built `... WHERE ycom_group_id IN ()` from the empty group list and answered a
# 500 right after a successful login, while every test above stayed green,
# because the user they share always has a group.
echo ""
echo "--- consent for a user without any group ---"
NOGROUP_OUT=$(php .claude/tests/oauth-authorize-test-seed.php seed-nogroup)
NOGROUP_ID=$(echo "$NOGROUP_OUT" | json_get user_id)
NOGROUP_LOGIN=$(echo "$NOGROUP_OUT" | json_get login)
NOGROUP_PASSWORD=$(echo "$NOGROUP_OUT" | json_get password)
USER_ID="$USER_ID,$NOGROUP_ID"   # so the trap cleans this one up too

NOGROUP_JAR="$(mktemp)"
NOGROUP_PAGE=$(curl -skL -c "$NOGROUP_JAR" -b "$NOGROUP_JAR" "$BASE/oauth/authorize?$AUTHORIZE_QUERY")
NOGROUP_FORM=$(echo "$NOGROUP_PAGE" | LOGIN="$NOGROUP_LOGIN" PASSWORD="$NOGROUP_PASSWORD" php .claude/tests/parse-login-form.php)

NOGROUP_ACTION=$(echo "$NOGROUP_FORM" | head -1)
NOGROUP_FIELDS=()
while IFS= read -r pair; do
    [[ -z "$pair" ]] && continue
    NOGROUP_FIELDS+=(--data-urlencode "$pair")
done <<< "$(echo "$NOGROUP_FORM" | tail -n +2)"

NOGROUP_CONSENT=$(curl -skL -c "$NOGROUP_JAR" -b "$NOGROUP_JAR" -X POST "$BASE$NOGROUP_ACTION" "${NOGROUP_FIELDS[@]}")
assert_contains "$NOGROUP_CONSENT" "_action" "a user without groups reaches the consent screen"
if [[ "$NOGROUP_CONSENT" == *"rex_sql_exception"* || "$NOGROUP_CONSENT" == *"IN ()"* ]]; then
    echo "  FAIL consent screen free of SQL errors"; FAIL=$((FAIL + 1))
else
    echo "  OK   consent screen free of SQL errors"; PASS=$((PASS + 1))
fi
rm -f "$NOGROUP_JAR"

echo ""
echo "=== Summary ==="
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
exit $FAIL
