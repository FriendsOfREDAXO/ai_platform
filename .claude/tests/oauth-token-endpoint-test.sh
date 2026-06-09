#!/bin/bash
# End-to-end test for /oauth/token + the Authenticator's Bearer-token
# validation. Seeds a client + authorization_code directly into the DB via
# a small PHP helper, then drives the token endpoint via curl.
#
# Requires the live REDAXO instance at https://redaxo.localhost/ (the same
# instance used for the storage-layer test). Cleans up everything it
# created on exit.

set -euo pipefail
cd "$(dirname "$0")/../.."  # → addon root

# Base URL of the live REDAXO instance — override via env if different.
BASE="${BASE:-https://redaxo.localhost}"
PASS=0
FAIL=0

# Derive the DB connection from REDAXO's own config so no credentials are
# hardcoded here. Override via env (DB_HOST/DB_USER/DB_PASS/DB_NAME) if needed.
REX_CONFIG="../../../data/core/config.yml"
cfg_db() {  # $1 = key (host|login|password|name) of DB connection "1"
    local v
    v=$(awk -v key="$1:" '
        /^    1:/ { inblk = 1; next }
        /^    [0-9]+:/ { inblk = 0 }
        inblk && $1 == key { $1 = ""; sub(/^[[:space:]]+/, ""); print; exit }
    ' "$REX_CONFIG" 2>/dev/null)
    v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
    printf '%s' "$v"
}
DB_HOST="${DB_HOST:-$(cfg_db host)}"
DB_USER="${DB_USER:-$(cfg_db login)}"
DB_PASS="${DB_PASS:-$(cfg_db password)}"
DB_NAME="${DB_NAME:-$(cfg_db name)}"
MYSQL=(mysql -h"${DB_HOST:-localhost}" -u"${DB_USER:-root}")
if [[ -n "$DB_PASS" ]]; then MYSQL+=("-p${DB_PASS}"); fi
MYSQL+=("${DB_NAME:-redaxo}")

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

assert_contains() {
    if [[ "$1" == *"$2"* ]]; then
        echo "  OK   $3"
        PASS=$((PASS + 1))
    else
        echo "  FAIL $3"
        echo "       expected substring: $2"
        echo "       got:                $1"
        FAIL=$((FAIL + 1))
    fi
}

# Generate PKCE verifier + challenge (S256 base64url(sha256(verifier))).
# 64 char verifier, 43 char challenge.
VERIFIER=$(openssl rand -hex 32)
CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')

CLIENT_ID="ai_test_client_$$"
CODE="test_authcode_$$"
CODE_HASH=$(printf '%s' "$CODE" | shasum -a 256 | awk '{print $1}')
REDIRECT_URI="https://example.org/cb"

cleanup() {
    "${MYSQL[@]}" -e "
        DELETE FROM rex_ai_oauth_token WHERE client_id = '$CLIENT_ID';
        DELETE FROM rex_ai_oauth_authorization_code WHERE client_id = '$CLIENT_ID';
        DELETE FROM rex_ai_oauth_client WHERE client_id = '$CLIENT_ID';
    " 2>/dev/null || true
}
trap cleanup EXIT

# Seed client + auth code directly into the DB. mysql client used to keep
# this script self-contained.
"${MYSQL[@]}" <<SQL
INSERT INTO rex_ai_oauth_client (client_id, client_secret_hash, client_name, redirect_uris, type, created_by_dcr, createdate, createuser, updatedate, updateuser)
VALUES ('$CLIENT_ID', NULL, 'Stage 2b test client', '["$REDIRECT_URI"]', 'public', 1, NOW(), 'test', NOW(), 'test');

INSERT INTO rex_ai_oauth_authorization_code (code_hash, client_id, ycom_user_id, scopes, code_challenge, code_challenge_method, redirect_uri, expires_at, createdate, createuser, updatedate, updateuser)
VALUES ('$CODE_HASH', '$CLIENT_ID', 1, '["mcp:tools:read","mcp:tools:call"]', '$CHALLENGE', 'S256', '$REDIRECT_URI', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW(), 'test', NOW(), 'test');
SQL

echo ""
echo "=== Stage 2b — /oauth/token end-to-end ==="
echo "client_id  = $CLIENT_ID"
echo "code       = $CODE"
echo "verifier   = $VERIFIER"
echo "challenge  = $CHALLENGE"
echo ""

# --- 1. successful authorization_code exchange ---
RESP=$(curl -sk -X POST "$BASE/oauth/token" \
    -d "grant_type=authorization_code" \
    -d "code=$CODE" \
    -d "redirect_uri=$REDIRECT_URI" \
    -d "client_id=$CLIENT_ID" \
    -d "code_verifier=$VERIFIER")

ACCESS=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('access_token',''))")
REFRESH=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('refresh_token',''))")
TOKEN_TYPE=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('token_type',''))")
EXPIRES=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('expires_in',''))")
SCOPE=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('scope',''))")

assert "$TOKEN_TYPE" "Bearer" "token_type is Bearer"
assert "$EXPIRES" "3600" "expires_in is 3600"
assert "$SCOPE" "mcp:tools:read mcp:tools:call" "scopes round-trip space-delimited"
[[ -n "$ACCESS" ]] && assert "true" "true" "access_token present" || assert "false" "true" "access_token present"
[[ -n "$REFRESH" ]] && assert "true" "true" "refresh_token present" || assert "false" "true" "refresh_token present"

# --- 2. authorization_code replay must fail ---
RESP=$(curl -sk -X POST "$BASE/oauth/token" \
    -d "grant_type=authorization_code" \
    -d "code=$CODE" \
    -d "redirect_uri=$REDIRECT_URI" \
    -d "client_id=$CLIENT_ID" \
    -d "code_verifier=$VERIFIER")
ERR=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('error',''))")
assert "$ERR" "invalid_grant" "replayed code returns invalid_grant"

# --- 3. bad PKCE verifier rejected ---
"${MYSQL[@]}" <<SQL
INSERT INTO rex_ai_oauth_authorization_code (code_hash, client_id, ycom_user_id, scopes, code_challenge, code_challenge_method, redirect_uri, expires_at, createdate, createuser, updatedate, updateuser)
VALUES ('$(printf 'pkce_test_code' | shasum -a 256 | awk '{print $1}')', '$CLIENT_ID', 1, '["mcp:tools:read"]', '$CHALLENGE', 'S256', '$REDIRECT_URI', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW(), 'test', NOW(), 'test');
SQL

RESP=$(curl -sk -X POST "$BASE/oauth/token" \
    -d "grant_type=authorization_code" \
    -d "code=pkce_test_code" \
    -d "redirect_uri=$REDIRECT_URI" \
    -d "client_id=$CLIENT_ID" \
    -d "code_verifier=wrong_verifier_definitely_not_43_chars_or_more_long")
ERR=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('error',''))")
assert "$ERR" "invalid_grant" "wrong PKCE verifier returns invalid_grant"

# --- 4. unknown client ---
RESP=$(curl -sk -X POST -o /dev/null -w "%{http_code}" "$BASE/oauth/token" \
    -d "grant_type=authorization_code" \
    -d "code=foo" \
    -d "redirect_uri=$REDIRECT_URI" \
    -d "client_id=ai_does_not_exist" \
    -d "code_verifier=$VERIFIER")
assert "$RESP" "401" "unknown client_id returns 401"

# --- 5. unsupported grant_type ---
RESP=$(curl -sk -X POST "$BASE/oauth/token" \
    -d "grant_type=password" \
    -d "client_id=$CLIENT_ID")
ERR=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('error',''))")
assert "$ERR" "unsupported_grant_type" "unsupported grant_type rejected"

# --- 6. missing grant_type ---
RESP=$(curl -sk -X POST "$BASE/oauth/token" -d "client_id=$CLIENT_ID")
ERR=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('error',''))")
assert "$ERR" "invalid_request" "missing grant_type returns invalid_request"

# --- 7. GET /oauth/token returns 405 ---
RESP=$(curl -sk -o /dev/null -w "%{http_code}" "$BASE/oauth/token")
assert "$RESP" "405" "GET /oauth/token returns 405"

# --- 8. use access token against /mcp tools/list and verify protected tool visible ---
RESP=$(curl -sk -X POST "$BASE/mcp" \
    -H "Authorization: Bearer $ACCESS" \
    -H "Content-Type: application/json" \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
assert_contains "$RESP" "redaxo_status" "tools/list with Bearer returns public tools"

# --- 9. invalid bearer token returns 401 + WWW-Authenticate invalid_token ---
HDRS=$(curl -sk -X POST -D - -o /dev/null "$BASE/mcp" \
    -H "Authorization: Bearer total_garbage_token" \
    -H "Content-Type: application/json" \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
assert_contains "$HDRS" "401" "invalid token returns 401"
assert_contains "$HDRS" "WWW-Authenticate: Bearer" "401 carries WWW-Authenticate"
assert_contains "$HDRS" 'error="invalid_token"' "WWW-Authenticate signals invalid_token"

# --- 10. refresh_token rotation issues new pair ---
RESP=$(curl -sk -X POST "$BASE/oauth/token" \
    -d "grant_type=refresh_token" \
    -d "refresh_token=$REFRESH" \
    -d "client_id=$CLIENT_ID")
NEW_ACCESS=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('access_token',''))")
NEW_REFRESH=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('refresh_token',''))")
[[ -n "$NEW_ACCESS" && "$NEW_ACCESS" != "$ACCESS" ]] && assert "true" "true" "refresh issues NEW access_token" || assert "false" "true" "refresh issues NEW access_token"
[[ -n "$NEW_REFRESH" && "$NEW_REFRESH" != "$REFRESH" ]] && assert "true" "true" "refresh issues NEW refresh_token" || assert "false" "true" "refresh issues NEW refresh_token"

# --- 11. old refresh token cannot be reused after rotation ---
RESP=$(curl -sk -X POST "$BASE/oauth/token" \
    -d "grant_type=refresh_token" \
    -d "refresh_token=$REFRESH" \
    -d "client_id=$CLIENT_ID")
ERR=$(echo "$RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read()).get('error',''))")
assert "$ERR" "invalid_grant" "old refresh token rejected after rotation"

# --- 12. old access token is revoked after refresh rotation ---
HDRS=$(curl -sk -X POST -D - -o /dev/null "$BASE/mcp" \
    -H "Authorization: Bearer $ACCESS" \
    -H "Content-Type: application/json" \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
assert_contains "$HDRS" "401" "old access token returns 401 after rotation"

# --- 13. new access token still works ---
RESP=$(curl -sk -X POST "$BASE/mcp" \
    -H "Authorization: Bearer $NEW_ACCESS" \
    -H "Content-Type: application/json" \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
assert_contains "$RESP" "redaxo_status" "new access token works after rotation"

echo ""
echo "=== Summary ==="
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
exit $FAIL
