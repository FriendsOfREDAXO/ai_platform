#!/usr/bin/env bash
#
# REST routes for change requests — end to end over HTTP.
#
# Seeds its own api-addon token via the mysql client, then drives every route
# with curl. Nothing here calls PHP directly: the point is to exercise the same
# path an outside agent takes, including the api addon's bearer auth and scope
# check. A test that called the handlers in-process would pass even if the
# routes were never registered.
#
# Two things this checks that nothing else can:
#   - the routes really are registered (a missing route answers 404, a missing
#     scope answers 401 — the difference matters and is asserted)
#   - source_key is derived from the token, not from the request body
#
# BASE is overridable via env. DB credentials come from REDAXO's own config.
set -uo pipefail

BASE="${BASE:-https://redaxo.localhost}"
PASS=0
FAIL=0

REX_CONFIG="../../../data/core/config.yml"
# Both relative to the addon directory this script is run from, like REX_CONFIG.
REX_ROOT="../../../.."
STAGED_DIR="../../../data/addons/ai_platform/pending"
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

PREFIX=$(awk '/^\$config\[.table_prefix.\]/ {print}' "$REX_CONFIG" 2>/dev/null)
PREFIX=$(awk '/^table_prefix:/ { $1=""; sub(/^[[:space:]]+/,""); gsub(/["'"'"']/,""); print }' "$REX_CONFIG")
PREFIX="${PREFIX:-rex_}"

section() { echo; echo "--- $1 ---"; }
assert() {  # $1 actual  $2 expected  $3 label
    if [[ "$1" == "$2" ]]; then
        echo "  OK   $3"
        PASS=$((PASS + 1))
    else
        echo "  FAIL $3"
        echo "       expected: $2"
        echo "       actual:   $1"
        FAIL=$((FAIL + 1))
    fi
}
assert_contains() {  # $1 haystack  $2 needle  $3 label
    if [[ "$1" == *"$2"* ]]; then
        echo "  OK   $3"
        PASS=$((PASS + 1))
    else
        echo "  FAIL $3"
        echo "       missing: $2"
        echo "       in:      ${1:0:400}"
        FAIL=$((FAIL + 1))
    fi
}
assert_not_contains() {  # $1 haystack  $2 needle  $3 label
    if [[ "$1" != *"$2"* ]]; then
        echo "  OK   $3"
        PASS=$((PASS + 1))
    else
        echo "  FAIL $3"
        echo "       unexpectedly present: $2"
        echo "       in:                   ${1:0:400}"
        FAIL=$((FAIL + 1))
    fi
}

CURL=(curl -sS -k --max-time 30)

# --- seed two tokens: one with every scope, one with none of ours -------------
FULL_TOKEN="aitest_full_$(date +%s)_$RANDOM"
BARE_TOKEN="aitest_bare_$(date +%s)_$RANDOM"
SCOPES="ai_platform/changes/read,ai_platform/changes/requests,ai_platform/changes/upload,ai_platform/changes/propose,ai_platform/changes/propose_delete,ai_platform/changes/approve,ai_platform/changes/withdraw"

"${MYSQL[@]}" <<SQL >/dev/null
DELETE FROM ${PREFIX}api_token WHERE name LIKE 'AI_CHANGES_TEST%';
INSERT INTO ${PREFIX}api_token (name, token, status, scopes)
  VALUES ('AI_CHANGES_TEST_full', '${FULL_TOKEN}', 1, '${SCOPES}');
INSERT INTO ${PREFIX}api_token (name, token, status, scopes)
  VALUES ('AI_CHANGES_TEST_bare', '${BARE_TOKEN}', 1, 'system/clangs/list');
SQL

FULL_ID=$("${MYSQL[@]}" -N -B -e "SELECT id FROM ${PREFIX}api_token WHERE name='AI_CHANGES_TEST_full'")

cleanup() {
    "${MYSQL[@]}" <<SQL >/dev/null 2>&1
DELETE FROM ${PREFIX}ai_change_request WHERE source_key = 'api-token:${FULL_ID}';
DELETE FROM ${PREFIX}ai_changeset WHERE source_key = 'api-token:${FULL_ID}';
DELETE FROM ${PREFIX}api_token WHERE name LIKE 'AI_CHANGES_TEST%';
SQL
}
trap cleanup EXIT

# Suffix that makes every fixture name unique per run — see the note further
# down at the approval section for why a fixed name fails on the second run.
RUNTAG="$(date +%H%M%S)_$RANDOM"

# A run against a switched-off feature reports 92 failures, none of which say
# what is wrong: with `changes_enabled = 0` the routes are never registered and
# every path answers 404, which reads like a routing bug. Check once, up front.
probe=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${FULL_TOKEN}" \
    "$BASE/api/ai_platform/changes/describe")
if [[ "$probe" == "404" ]]; then
    echo
    echo "  ABORT: every change route answers 404."
    echo "  Either ai_platform change requests are switched off (changes_enabled = 0),"
    echo "  or the api addon is not installed. Both remove the routes entirely."
    echo "  Fix: backend »KI Platform > Einstellungen«, then delete redaxo/cache/core/config.cache."
    echo
    exit 1
fi

echo "=== REST routes for change requests ==="
echo "    base:  $BASE"
echo "    token: api-token:${FULL_ID}"

# --- scope enforcement -------------------------------------------------------
section "Scope enforcement"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/api/ai_platform/changes/describe")
assert "$code" "401" "no token at all is refused"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer ${BARE_TOKEN}" "$BASE/api/ai_platform/changes/describe")
assert "$code" "401" "a token without the scope is refused"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/describe")
assert "$code" "200" "a token with the scope gets through — so the route IS registered"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' \
    -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/nonexistent")
assert "$code" "404" "an unknown path under the prefix is a 404, not a 401"

# --- discovery ---------------------------------------------------------------
section "Discovery"

body=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/describe")
for t in slice article category meta media; do
    assert_contains "$body" "\"$t\"" "the catalogue lists the $t type"
done
assert_contains "$body" '"writable_fields"' "the catalogue names the writable fields"
assert_contains "$body" '"target_fields"' "the catalogue names the target fields"
# Stays in the response, and stays null: a caller that asks "how big may a batch
# be" gets an explicit "no limit" rather than a missing key it has to guess about.
assert_contains "$body" '"batch_limit":null' "the catalogue states plainly that a batch has no limit"

# --- the catalogue has to carry the module slots ------------------------------
# Measured, not hypothetical: an agent given only the endpoint descriptions could
# not find out which value slot a module reads. It fetched the module through
# another addon's API and parsed its HTML — which only worked because that token
# happened to hold `modules/get`. A token with just the change scopes could not
# build a usable slice at all, so the catalogue answers it now.
section "The catalogue names each module's slots"

body=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/describe")
assert_contains "$body" '"modules"' "the slice type carries a module list"
assert_contains "$body" '"slots"' "each module names its slots"
assert_contains "$body" '"executes_php"' "and whether its slice values run as PHP"

# The slot list must be the module's own, not the flat value1..value20 list —
# otherwise it repeats what "writable_fields" already said and helps nobody.
# read -r, not `set -- $counts`: this file runs under bash, but the shell these
# tests are usually driven from is zsh, which does no word splitting on an
# unquoted expansion — the same snippet silently yields one argument there.
counts=$(python3 -c "
import json,sys
d=json.loads(sys.argv[1])['data']
mods=d.get('slice',{}).get('modules',{})
narrow=[m for m in mods.values() if 0 < len(m['slots']) <= 4]
print('%d %d' % (len(mods), len(narrow)))
" "$body")
read -r MOD_TOTAL MOD_NARROW <<< "$counts"
assert "$([ "${MOD_TOTAL:-0}" -ge 1 ] && echo yes || echo no)" "yes" "at least one module is listed"
assert "$([ "${MOD_NARROW:-0}" -ge 1 ] && echo yes || echo no)" "yes" "and its slot list is the module's own, not all twenty"

# Target fields distinguish create from update, which is what the agent had to
# infer by analogy from an example.
# The needle stops before the slash: PHP's json_encode escapes it as
# "update\/delete", so matching the full phrase would fail on the encoding
# rather than on the content.
assert_contains "$body" 'slice_id (update' "target fields say which key belongs to which operation"
assert_contains "$body" 'parent_id (create)' "a category create is addressed by parent_id"

# --- the merge: two modes on one route, and no leftovers ----------------------
section "One route, two modes"

# `/types` and `/current` were folded into `/describe`, and `list` + `get` into
# one route with an optional id. The api addon authorises per route, so each
# merge merged two scopes into one — deliberate, because neither pair was two
# distinct privileges. No aliases were kept: a route that still answers is a
# route somebody keeps using.
for gone in types current; do
    code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${FULL_TOKEN}" \
        "$BASE/api/ai_platform/changes/${gone}")
    assert "$code" "404" "the old /${gone} path is gone, not aliased"
done

# A target without a type is a caller that meant to ask about one thing and
# forgot to say which kind. Answering with the catalogue would look like success.
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${FULL_TOKEN}" \
    --get --data-urlencode 'target={"article_id":1,"clang_id":1}' \
    "$BASE/api/ai_platform/changes/describe")
assert "$code" "400" "a target without a type is refused rather than answered with the catalogue"

# --- reading current state ---------------------------------------------------
section "Reading the current state"

ART=$("${MYSQL[@]}" -N -B -e \
    "SELECT id FROM ${PREFIX}article WHERE clang_id=1 AND startarticle=0 ORDER BY id LIMIT 1")
ART="${ART:-1}"
# A second target, because a new proposal supersedes older open ones for the
# SAME target. Sections that must not disturb each other need their own.
ART2=$("${MYSQL[@]}" -N -B -e \
    "SELECT id FROM ${PREFIX}article WHERE clang_id=1 AND startarticle=0 AND id <> ${ART} ORDER BY id LIMIT 1")
ART2="${ART2:-$ART}"

body=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" \
    --get --data-urlencode "type=article" --data-urlencode "target={\"article_id\":${ART},\"clang_id\":1}" \
    "$BASE/api/ai_platform/changes/describe")
assert_contains "$body" '"values"' "describe with a target returns its values"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${FULL_TOKEN}" \
    --get --data-urlencode "type=article" --data-urlencode 'target={"article_id":99999999,"clang_id":1}' \
    "$BASE/api/ai_platform/changes/describe")
assert "$code" "404" "describe answers 404 for a target that does not exist"

body=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" \
    --get --data-urlencode "type=nosuchtype" --data-urlencode 'target={}' \
    "$BASE/api/ai_platform/changes/describe")
assert_contains "$body" "Unknown change type" "an unknown type is named, with the available ones listed"

# --- offering a file for the media pool --------------------------------------
section "Offering a media file"

# A tiny PNG, generated here so the test carries no binary. The bytes are the
# point: this is the only route that accepts any.
PNGFILE="$(mktemp -t ai-upload).png"
python3 - "$PNGFILE" <<'PYPNG'
import struct, sys, zlib
w = h = 8
raw = b''.join(b'\x00' + bytes([40, 90, 160] * w) for _ in range(h))
def chunk(t, d):
    return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d))
open(sys.argv[1], 'wb').write(
    b'\x89PNG\r\n\x1a\n'
    + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
    + chunk(b'IDAT', zlib.compress(raw))
    + chunk(b'IEND', b''))
PYPNG

UPNAME="ai-rest-${RUNTAG}.png"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${BARE_TOKEN}" \
    -F "file=@${PNGFILE};filename=${UPNAME}" "$BASE/api/ai_platform/changes/uploads")
assert "$code" "401" "uploading needs its own scope — bytes are a separate privilege"

resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -F "file=@${PNGFILE};filename=${UPNAME}" "$BASE/api/ai_platform/changes/uploads")
code=$(echo "$resp" | tail -1)
body=$(echo "$resp" | sed '$d')
assert "$code" "201" "a PNG is staged"
assert_contains "$body" '"in_media_pool":false' "and the response says plainly that nothing was added yet"
assert_contains "$body" "\"filename\":\"${UPNAME}\"" "the reserved filename is returned"

HANDLE=$(echo "$body" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['upload'])")
assert_contains "$HANDLE" "up_" "a handle was issued"

# The reservation is the reason this route exists separately: without it the
# final name would only be decided at approval, and a slice proposal could never
# reference the image.
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -F "file=@${PNGFILE};filename=${UPNAME}" "$BASE/api/ai_platform/changes/uploads")
assert "$code" "422" "the same filename cannot be reserved twice"

# A .php file renamed to .jpg: the extension passes, the mime type does not.
BADFILE="$(mktemp -t ai-upload-bad)"
printf '<?php echo "x";' > "$BADFILE"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -F "file=@${BADFILE};filename=harmless-${RUNTAG}.jpg" "$BASE/api/ai_platform/changes/uploads")
assert "$code" "422" "PHP source renamed to .jpg is refused on its real mime type"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -F "file=@${PNGFILE};filename=evil-${RUNTAG}.php.png" "$BASE/api/ai_platform/changes/uploads")
assert "$code" "422" "a double extension carrying .php is refused"

# --- the proposal that uses it ----------------------------------------------
resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"media\",\"operation\":\"create\",\"target\":{\"category_id\":0,\"filename\":\"${UPNAME}\"},\"fields\":{\"upload\":\"${HANDLE}\",\"title\":\"REST test\"},\"reason\":\"staged file offered over REST\"}" \
    "$BASE/api/ai_platform/changes")
code=$(echo "$resp" | tail -1)
body=$(echo "$resp" | sed '$d')
assert "$code" "201" "a media create referencing the handle is accepted"
MEDIA_REQ=$(echo "$body" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['id'])")

# Wrong name for the right handle: the diff must not promise one name while
# another gets written.
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"media\",\"operation\":\"create\",\"target\":{\"category_id\":0,\"filename\":\"other-${RUNTAG}.png\"},\"fields\":{\"upload\":\"${HANDLE}\"},\"reason\":\"mismatched filename\"}" \
    "$BASE/api/ai_platform/changes")
assert "$code" "422" "a filename that disagrees with the upload is refused"

# A create without any file is not a media proposal at all.
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"media\",\"operation\":\"create\",\"target\":{\"category_id\":0,\"filename\":\"nofile-${RUNTAG}.png\"},\"fields\":{\"title\":\"no bytes\"},\"reason\":\"no upload named\"}" \
    "$BASE/api/ai_platform/changes")
assert "$code" "422" "a media create without an upload handle is refused"

# A slice referencing the pending image must be refused — and told *why*, in the
# way that leads somewhere. "The file no longer exists" is the correct answer for a
# deleted file and the wrong one for the mistake nearly every caller makes first:
# proposing an image and the slice that shows it in one go. Two situations that
# look identical from rex_media::get() and need opposite advice.
MODID=$("${MYSQL[@]}" -N -B -e \
    "SELECT id FROM ${PREFIX}module WHERE output LIKE '%REX_MEDIA%' ORDER BY id LIMIT 1")
if [[ -n "$MODID" ]]; then
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"type\":\"slice\",\"operation\":\"create\",\"target\":{\"article_id\":${ART},\"module_id\":${MODID},\"ctype_id\":1},\"fields\":{\"media1\":\"${UPNAME}\"},\"reason\":\"slice pointing at a pending image\"}" \
        "$BASE/api/ai_platform/changes")
    assert_contains "$body" "noch nicht im Medienpool" "a slice pointing at a pending image is told to get the image approved first"
    assert_not_contains "$body" "existiert nicht mehr" "and is NOT told the file was deleted — that sends it looking for the wrong thing"

    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"type\":\"slice\",\"operation\":\"create\",\"target\":{\"article_id\":${ART},\"module_id\":${MODID},\"ctype_id\":1},\"fields\":{\"media1\":\"nothing-${RUNTAG}.jpg\"},\"reason\":\"slice pointing at nothing\"}" \
        "$BASE/api/ai_platform/changes")
    assert_contains "$body" "existiert nicht mehr" "while a genuinely missing file still gets the plain answer"
fi

# Nothing may have reached the pool from any of this.
POOLED=$("${MYSQL[@]}" -N -B -e \
    "SELECT COUNT(*) FROM ${PREFIX}media WHERE filename = '${UPNAME}'")
assert "$POOLED" "0" "proposing put nothing in the media pool"

# --- self-approval writes it ------------------------------------------------
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' -d "{\"ids\":[${MEDIA_REQ}]}" \
    "$BASE/api/ai_platform/changes/approvals")
assert "$code" "200" "the media create can be approved over the API"

POOLED=$("${MYSQL[@]}" -N -B -e \
    "SELECT COUNT(*) FROM ${PREFIX}media WHERE filename = '${UPNAME}'")
assert "$POOLED" "1" "and now the file IS in the media pool"

CONSUMED=$("${MYSQL[@]}" -N -B -e \
    "SELECT COUNT(*) FROM ${PREFIX}ai_change_upload WHERE handle = '${HANDLE}' AND consumed_at IS NOT NULL")
assert "$CONSUMED" "1" "the staging row records that it was consumed"

# --- clean up the fixture ---------------------------------------------------
#
# The staged files go too, not just their rows. Deleting only the rows is how a
# staging directory quietly fills up with bytes nothing points at any more — the
# exact failure the cronjob exists to prevent, reintroduced by the test that is
# supposed to prove the feature works.
# The file names are collected first and reused for the assert below, so the
# check covers exactly this run's files rather than the whole directory.
STAGED_FILES=$("${MYSQL[@]}" -N -B -e \
    "SELECT CONCAT(handle, '.', extension) FROM ${PREFIX}ai_change_upload WHERE source_key = 'api-token:${FULL_ID}'")
while read -r staged; do
    [ -n "$staged" ] && rm -f "${STAGED_DIR}/${staged}"
done <<< "$STAGED_FILES"
"${MYSQL[@]}" -e "DELETE FROM ${PREFIX}media WHERE filename = '${UPNAME}'" >/dev/null 2>&1
"${MYSQL[@]}" -e "DELETE FROM ${PREFIX}ai_change_upload WHERE source_key = 'api-token:${FULL_ID}'" >/dev/null 2>&1
rm -f "${REX_ROOT}/media/${UPNAME}"
rm -f "$PNGFILE" "$BADFILE"

# Only this run's own handles are counted, not the whole directory. Counting
# everything made the assert fail whenever anything else on the installation had
# a file staged — a foreign leftover reported as a bug in the code under test,
# which cost real time twice before it was narrowed down.
LEFTOVER=0
while read -r staged; do
    [ -n "$staged" ] && [ -e "${STAGED_DIR}/${staged}" ] && LEFTOVER=$((LEFTOVER + 1))
done <<< "$STAGED_FILES"
assert "$LEFTOVER" "0" "no staged file of this run is left behind — deleting only the rows is how a staging directory fills up"

# --- proposing, single -------------------------------------------------------
section "Proposing a single change"

resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"operation\":\"update\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"priority\":3},\"reason\":\"REST smoke test\"}" \
    "$BASE/api/ai_platform/changes")
code=$(tail -n1 <<<"$resp")
body=$(sed '$d' <<<"$resp")
assert "$code" "201" "a valid proposal answers 201"
assert_contains "$body" '"id"' "the response carries the new id"
assert_contains "$body" '"applied":false' "the response states that nothing was applied"

NEW_ID=$(sed -n 's/.*"id":\([0-9]*\).*/\1/p' <<<"$body" | head -1)

# --- reading back own requests ----------------------------------------------
# Runs immediately after the proposal above and before anything else touches
# $ART: a later proposal for the same target would supersede this one, and the
# read-back would legitimately report "superseded" instead of "pending".
section "Reading back"

body=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/${NEW_ID}")
assert_contains "$body" '"status":"pending"' "the own request reads back as pending"
assert_contains "$body" '"situation"' "an open request carries the situation report"
assert_contains "$body" '"payload"' "the payload is readable"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${BARE_TOKEN}" \
    "$BASE/api/ai_platform/changes/${NEW_ID}")
assert "$code" "401" "a token without the requests scope cannot read it"

# Same route, same scope, both shapes: that is the whole point of the merge.
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${BARE_TOKEN}" \
    "$BASE/api/ai_platform/changes")
assert "$code" "401" "and cannot list them either — one scope covers both shapes"

body=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes")
assert_contains "$body" '"open"' "the list reports how many of its own requests are still open"
# A limit that is not enforced must not be announced: `changes_max_open_per_source`
# was removed, and the response kept reporting `max_open`/`remaining` afterwards —
# so a well-behaved agent could stop proposing at a ceiling that no longer existed.
assert_not_contains "$body" '"max_open"' "and does not announce a limit nothing enforces"
assert_not_contains "$body" '"remaining"' "nor a remaining count derived from it"
assert_contains "$body" "api-token:${FULL_ID}" "the list names the source it is scoped to"

# --- the source comes from the token, not the body ---------------------------
section "Source identity"

resp=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"target\":{\"article_id\":${ART2},\"clang_id\":1},\"fields\":{\"priority\":4},\"reason\":\"spoof attempt\",\"source_key\":\"someone-else\",\"source\":\"someone-else\"}" \
    "$BASE/api/ai_platform/changes")
SPOOF_ID=$(sed -n 's/.*"id":\([0-9]*\).*/\1/p' <<<"$resp" | head -1)

stored=$("${MYSQL[@]}" -N -B -e \
    "SELECT source_key FROM ${PREFIX}ai_change_request WHERE id=${SPOOF_ID:-0}")
assert "$stored" "api-token:${FULL_ID}" "source_key comes from the token, a body field cannot override it"

channel=$("${MYSQL[@]}" -N -B -e \
    "SELECT source_channel FROM ${PREFIX}ai_change_request WHERE id=${SPOOF_ID:-0}")
assert "$channel" "api" "the channel is recorded as api, distinguishable from agent and php"


# --- supersede ---------------------------------------------------------------
# The trap an agent walks into over REST: proposing twice for one target does
# not queue two decisions, it replaces the first. Worth asserting, because a
# client that resubmits on every run would otherwise never notice its earlier
# proposals quietly disappearing.
section "A second proposal for the same target supersedes the first"

first=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"priority\":7},\"reason\":\"first attempt\"}" \
    "$BASE/api/ai_platform/changes")
FIRST_ID=$(sed -n 's/.*"id":\([0-9]*\).*/\1/p' <<<"$first" | head -1)

"${CURL[@]}" -o /dev/null -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"priority\":8},\"reason\":\"second attempt\"}" \
    "$BASE/api/ai_platform/changes"

body=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/${FIRST_ID}")
assert_contains "$body" '"status":"superseded"' "the first proposal is marked superseded, and the API says so"

# --- creates do not supersede each other -------------------------------------
# A create target names a place, not a thing: every new category under parent 40
# serialises identically. Superseding on that basis meant proposing six
# subcategories left one alive and silently dropped five — found while building a
# real section, which is the only way this shows up.
section "Two creates in the same place both survive"

c1=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d "{\"type\":\"category\",\"operation\":\"create\",\"target\":{\"parent_id\":40,\"clang_id\":1},\"fields\":{\"catname\":\"AI_SUPERSEDE_A\",\"catpriority\":90,\"status\":0},\"reason\":\"first of two siblings\"}" \
    "$BASE/api/ai_platform/changes" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
"${CURL[@]}" -o /dev/null -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d "{\"type\":\"category\",\"operation\":\"create\",\"target\":{\"parent_id\":40,\"clang_id\":1},\"fields\":{\"catname\":\"AI_SUPERSEDE_B\",\"catpriority\":91,\"status\":0},\"reason\":\"second of two siblings\"}" \
    "$BASE/api/ai_platform/changes"

st=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/${c1}" \
    | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['status'])")
assert "$st" "pending" "the first create survives a second create in the same place"

# The update case must still supersede — that is what the mechanism is for.
u1=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"operation\":\"update\",\"target\":{\"article_id\":${ART2},\"clang_id\":1},\"fields\":{\"priority\":11},\"reason\":\"first correction\"}" \
    "$BASE/api/ai_platform/changes" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
"${CURL[@]}" -o /dev/null -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"operation\":\"update\",\"target\":{\"article_id\":${ART2},\"clang_id\":1},\"fields\":{\"priority\":12},\"reason\":\"second correction replaces the first\"}" \
    "$BASE/api/ai_platform/changes"
st=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/${u1}" \
    | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['status'])")
assert "$st" "superseded" "an update for the same target still supersedes the older one"

# --- batch -------------------------------------------------------------------
section "Batch"

resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"changeset\":\"rest-test-batch\",\"proposals\":[
        {\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"priority\":5},\"reason\":\"batch a\"},
        {\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"name\":\"Batch B\"},\"reason\":\"batch b\"}
    ]}" \
    "$BASE/api/ai_platform/changes")
code=$(tail -n1 <<<"$resp")
body=$(sed '$d' <<<"$resp")
assert "$code" "201" "an all-valid batch answers 201"
assert_contains "$body" '"submitted":2' "both elements were submitted"

# A batch with one bad element must report per element, not fail as a whole.
resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"proposals\":[
        {\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"priority\":6},\"reason\":\"good one\"},
        {\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"nosuchfield\":\"x\"},\"reason\":\"bad one\"}
    ]}" \
    "$BASE/api/ai_platform/changes")
code=$(tail -n1 <<<"$resp")
body=$(sed '$d' <<<"$resp")
assert "$code" "207" "a mixed batch answers 207, so a client cannot read it as all-fine"
assert_contains "$body" '"submitted":1' "the good element went through"
assert_contains "$body" '"failed":1' "the bad element is reported as failed"
assert_contains "$body" '"index":1' "the failing element is identified by index"

# Batches are no longer capped. What a batch can do is decided by the writes it
# triggers, not by how many are in it, so a large one is checked for being
# accepted rather than refused.
big='{"proposals":['
for i in $(seq 1 60); do
    [[ $i -gt 1 ]] && big+=','
    big+="{\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"priority\":$i},\"reason\":\"n$i\"}"
done
big+=']}'
resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' -d "$big" "$BASE/api/ai_platform/changes")
assert "$(tail -n1 <<<"$resp")" "201" "a batch of 60 goes through — no arbitrary cap"
assert_contains "$(sed '$d' <<<"$resp")" '"submitted":60' "and all 60 are recorded"

# --- deletions are a separate scope -----------------------------------------
section "Deletions"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${BARE_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"reason\":\"x\"}" \
    "$BASE/api/ai_platform/changes/deletions")
assert "$code" "401" "the deletions route needs its own scope"

body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"operation\":\"delete\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"priority\":9},\"reason\":\"x\"}" \
    "$BASE/api/ai_platform/changes")
assert_contains "$body" "deletions" "operation:delete on the propose route points at the deletions route"

# --- metainfo carrier --------------------------------------------------------
# A missing carrier used to default to "article" silently, so a media proposal
# that forgot it came back as 'Target field "article_id" is required.' — naming a
# field the caller was right not to send, and inviting it to invent an article id
# and write to the wrong carrier. Now the carrier is required, and the error
# names the one the given fields imply.
section "Metainfo carrier is required, not guessed"

body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d '{"type":"meta","target":{"filename":"x.jpg"},"fields":{"med_alt":"x"},"reason":"x"}' \
    "$BASE/api/ai_platform/changes")
# JsonResponse escapes quotes as \u0022, so the needle carries no quotes.
assert_contains "$body" 'metainfo target needs' "a missing carrier is named as the missing thing"
assert_contains "$body" 'suggest carrier' "and the fields' prefix points at the right one"
if [[ "$body" == *"article_id"* ]]; then
    echo "  FAIL the old misleading article_id message is back"
    FAIL=$((FAIL + 1))
else
    echo "  OK   the error does not mention article_id"
    PASS=$((PASS + 1))
fi

body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d '{"type":"meta","target":{},"fields":{"med_alt":"x","art_description":"y"},"reason":"x"}' \
    "$BASE/api/ai_platform/changes")
if [[ "$body" == *"suggest carrier"* ]]; then
    echo "  FAIL mixed prefixes should not produce a guess"
    FAIL=$((FAIL + 1))
else
    echo "  OK   mixed prefixes produce no guess — that would be a misleading hint"
    PASS=$((PASS + 1))
fi

# --- yform field names -------------------------------------------------------
# /describe has to qualify YForm field names ("rex_company.name"), because one flat
# list covers every allowed table and "name" exists in several. The setters take
# the bare column. The two used to disagree, so an agent doing exactly what the
# docs say — call /describe, then use the names it returned — failed on its first
# attempt. Both forms are accepted now; a prefix naming a different table is
# still an error, because that means the caller mixed up the target.
section "YForm field names: discovery and acceptance agree"

YTABLE=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/describe" \
    | python3 -c "
import json,sys
f = json.load(sys.stdin)['data'].get('yform',{}).get('writable_fields') or {}
names = list(f.keys()) if isinstance(f, dict) else list(f)
q = [n for n in names if '.' in n]
print(q[0] if q else '')
")

if [[ -z "$YTABLE" ]]; then
    echo "  SKIP  no YForm table is allowed for change requests"
else
    QUALIFIED="$YTABLE"
    TABLE="${QUALIFIED%%.*}"
    COLUMN="${QUALIFIED#*.}"

    code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
        -H 'Content-Type: application/json' \
        -d "{\"type\":\"yform\",\"operation\":\"create\",\"target\":{\"table\":\"${TABLE}\"},\"fields\":{\"${QUALIFIED}\":\"qualified form\"},\"reason\":\"field name exactly as /describe returned it\"}" \
        "$BASE/api/ai_platform/changes")
    assert "$code" "201" "the qualified name from /describe is accepted ($QUALIFIED)"

    code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
        -H 'Content-Type: application/json' \
        -d "{\"type\":\"yform\",\"operation\":\"create\",\"target\":{\"table\":\"${TABLE}\"},\"fields\":{\"${COLUMN}\":\"bare form\"},\"reason\":\"bare column name\"}" \
        "$BASE/api/ai_platform/changes")
    assert "$code" "201" "the bare column name is accepted too ($COLUMN)"

    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
        -H 'Content-Type: application/json' \
        -d "{\"type\":\"yform\",\"operation\":\"create\",\"target\":{\"table\":\"${TABLE}\"},\"fields\":{\"some_other_table.${COLUMN}\":\"wrong\"},\"reason\":\"prefix names a different table\"}" \
        "$BASE/api/ai_platform/changes")
    assert_contains "$body" "but the target is" "a prefix naming a different table is refused, not silently stripped"
fi

# --- rejections --------------------------------------------------------------
section "Rejections carry a usable message"

body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"type\":\"article\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"nosuchfield\":\"x\"},\"reason\":\"x\"}" \
    "$BASE/api/ai_platform/changes")
assert_contains "$body" "nosuchfield" "an unwritable field is named in the error"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' -d 'not json at all' "$BASE/api/ai_platform/changes")
assert "$code" "400" "a non-JSON body answers 400"

body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' -d '{"proposals":[]}' "$BASE/api/ai_platform/changes")
assert_contains "$body" "No proposals" "an empty batch is refused with a clear message"

# --- slices survive an approval that has no REDAXO user behind it -------------
# The gap this closes: the API approval path has no logged-in user, and
# structure/history (a stock plugin) listens on SLICE_UPDATE / SLICE_DELETE and
# calls rex::requireUser(). Every slice update and delete approved over the API
# therefore died with "User object does not exist" and wrote nothing, while
# article, category, media and metainfo went through. It went unnoticed because
# the handler tests approve through the backend path, where a user exists, and
# this file only ever approved non-slice types.
section "Slice writes work without a backend user"

SL_ART=$("${MYSQL[@]}" -N -B -e \
    "SELECT article_id FROM ${PREFIX}article_slice WHERE clang_id=1 GROUP BY article_id ORDER BY COUNT(*) DESC LIMIT 1")
SL_MOD=$("${MYSQL[@]}" -N -B -e "SELECT id FROM ${PREFIX}module ORDER BY id LIMIT 1")

if [[ -n "$SL_ART" && -n "$SL_MOD" ]]; then
    # Create, so the fixture is this run's own and nothing existing is touched.
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"type\":\"slice\",\"operation\":\"create\",\"target\":{\"article_id\":${SL_ART},\"clang_id\":1,\"module_id\":${SL_MOD},\"ctype_id\":1,\"priority\":99},\"fields\":{\"value1\":\"SLICEFIX_${RUNTAG}\"},\"reason\":\"slice fixture\"}" \
        "$BASE/api/ai_platform/changes")
    req=$(printf '%s' "$body" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"id\":${req}}" "$BASE/api/ai_platform/changes/approvals")
    assert_contains "$body" '"ok":true' "a slice create is approved over the API"
    SL_ID=$(printf '%s' "$body" | sed -n 's/.*"slice_id":\([0-9]*\).*/\1/p' | head -1)

    # The update — this is the call that used to fail.
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"type\":\"slice\",\"operation\":\"update\",\"target\":{\"article_id\":${SL_ART},\"clang_id\":1,\"slice_id\":${SL_ID}},\"fields\":{\"value1\":\"SLICEFIX_${RUNTAG}_edited\"},\"reason\":\"slice update\"}" \
        "$BASE/api/ai_platform/changes")
    req=$(printf '%s' "$body" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"id\":${req}}" "$BASE/api/ai_platform/changes/approvals")
    assert_contains "$body" '"ok":true' "a slice UPDATE is approved over the API — no backend user needed"
    assert_not_contains "$body" "User object does not exist" "and structure/history does not break the write"

    written=$("${MYSQL[@]}" -N -B -e \
        "SELECT value1 FROM ${PREFIX}article_slice WHERE id = ${SL_ID}")
    assert "$written" "SLICEFIX_${RUNTAG}_edited" "the new value really reached the slice"

    # And the delete, which used the core service that fires the EP itself.
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"type\":\"slice\",\"target\":{\"article_id\":${SL_ART},\"clang_id\":1,\"slice_id\":${SL_ID}},\"reason\":\"slice delete\"}" \
        "$BASE/api/ai_platform/changes/deletions")
    req=$(printf '%s' "$body" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"id\":${req}}" "$BASE/api/ai_platform/changes/approvals")
    assert_contains "$body" '"ok":true' "a slice DELETE is approved over the API"

    gone=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM ${PREFIX}article_slice WHERE id = ${SL_ID}")
    assert "$gone" "0" "and the slice is really gone"

    # organizePriorities() has to have run, or the article keeps a hole.
    holes=$("${MYSQL[@]}" -N -B -e \
        "SELECT COUNT(*) FROM ${PREFIX}article_slice WHERE article_id = ${SL_ART} AND clang_id = 1 AND priority > (SELECT COUNT(*) FROM ${PREFIX}article_slice s2 WHERE s2.article_id = ${SL_ART} AND s2.clang_id = 1)")
    assert "$holes" "0" "priorities were reorganised gapless, as the core service does"
fi

# --- dependencies are checked at submission, not at approval ------------------
# The documentation claimed three times that a changeset resolves a dependency
# by ordering — image first, then the slice; category first, then the article in
# it. It does not: checkReferences() runs at submission, so the dependent element
# is refused while the thing it points at is still only proposed. Nothing tested
# that, which is why the wrong claim survived three rewrites. It is asserted per
# dependency kind, because each is a different handler.
section "A dependency cannot be satisfied inside one batch"

# One batch, dependency order, both elements: the first goes through, the second
# is refused. 207, not 200 — a batch that answered 200 here would read as "all
# submitted" and the caller would wait for a slice that was never filed.
body=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"changeset\":\"dep_${RUNTAG}\",\"proposals\":[
        {\"type\":\"category\",\"operation\":\"create\",\"target\":{\"parent_id\":0,\"clang_id\":1},
         \"fields\":{\"catname\":\"DEP_${RUNTAG}\"},\"reason\":\"first level\"},
        {\"type\":\"article\",\"operation\":\"create\",\"target\":{\"category_id\":99999998,\"clang_id\":1},
         \"fields\":{\"name\":\"DEP_ART_${RUNTAG}\",\"template_id\":1},\"reason\":\"second level\"}
    ]}" \
    "$BASE/api/ai_platform/changes")
code="${body##*$'\n'}"
body="${body%$'\n'*}"
assert "$code" "207" "a batch whose second element depends on the first answers 207"
assert_contains "$body" '"ok":true' "the independent element was submitted"
DEP_ID=$(printf '%s' "$body" | sed -n 's/.*"ok":true,"id":\([0-9]*\).*/\1/p' | head -1)
assert_contains "$body" '"ok":false' "the dependent element was refused at submission"
assert_contains "$body" "99999998" "the refusal names the target that does not exist yet"

# The media variant of the same rule is asserted further up, in the staging
# section ("slice pointing at nothing") — it needs the fixture module from there.

# Withdraw the one element that did get filed, so the run leaves nothing open.
if [[ -n "$DEP_ID" ]]; then
    body=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
        -H 'Content-Type: application/json' -d "{\"id\":${DEP_ID}}" \
        "$BASE/api/ai_platform/changes/withdrawals")
    assert_contains "$body" '"status":"withdrawn"' "the filed half can be taken back"
fi

# --- approval over the API ---------------------------------------------------
# The one route that writes. It approves without a REDAXO user, so canApprove()
# — the privilege-escalation guard — cannot run. What bounds it is the scope
# itself: holding ai_platform/changes/approve is the decision, and there is no
# second switch asking the same question. Two invariants remain and are asserted
# below: own requests only, and no force.
#
# This section deliberately writes content and cleans up after itself.
section "Approval over the API"

# Unique per run. yrewrite creates a 301 forward from the old URL whenever a
# category is renamed, and the forward table has a unique key on (domain, url) —
# so a fixture with a fixed name works once and then fails on the duplicate,
# which looks like a bug in the approval and is not one.
#
# Defined near the top of the file, because the media section needs it too and a
# reserved media filename has exactly the same problem: the reservation survives
# the run, so a fixed name works once.

approve_id() {  # $1 = request id -> prints "code|body"
    local r
    r=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
        -H 'Content-Type: application/json' -d "{\"id\":$1,\"note\":\"approved by the test\"}" \
        "$BASE/api/ai_platform/changes/approvals")
    printf '%s|%s' "$(tail -n1 <<<"$r")" "$(sed '$d' <<<"$r")"
}
propose_id() {  # $1 = json body -> prints new id
    "${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "$1" "$BASE/api/ai_platform/changes" \
        | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1
}

# --- create, and published straight away. Both used to be refused by the allow
# --- list; the operator granting the scope wants exactly this.
CAT_ID=$(propose_id "{\"type\":\"category\",\"operation\":\"create\",\"target\":{\"parent_id\":40,\"clang_id\":1},\"fields\":{\"catname\":\"AI_APPROVE_${RUNTAG}\",\"catpriority\":99,\"status\":1},\"reason\":\"online right away — no offline-only rule any more\"}")
out=$(approve_id "$CAT_ID")
assert "${out%%|*}" "200" "a create that publishes immediately is approved"
assert_contains "${out#*|}" '"created"' "the response returns the ids the write produced"

NEW_CAT=$("${MYSQL[@]}" -N -B -e \
    "SELECT id FROM ${PREFIX}article WHERE catname='AI_APPROVE_${RUNTAG}' AND clang_id=1 LIMIT 1")
if [[ -n "$NEW_CAT" ]]; then
    online=$("${MYSQL[@]}" -N -B -e "SELECT status FROM ${PREFIX}article WHERE id=${NEW_CAT} AND clang_id=1")
    assert "$online" "1" "and it really is online — nothing forced it offline"
else
    echo "  FAIL the category was reported approved but does not exist"
    FAIL=$((FAIL + 1))
fi

# --- update, which the allow list used to refuse
if [[ -n "$NEW_CAT" ]]; then
    UPD=$(propose_id "{\"type\":\"category\",\"operation\":\"update\",\"target\":{\"category_id\":${NEW_CAT},\"clang_id\":1},\"fields\":{\"catname\":\"AI_APPROVE_${RUNTAG}_R\"},\"reason\":\"update is allowed now\"}")
    out=$(approve_id "$UPD")
    assert "${out%%|*}" "200" "an update is approved"
    name=$("${MYSQL[@]}" -N -B -e "SELECT catname FROM ${PREFIX}article WHERE id=${NEW_CAT} AND clang_id=1")
    assert "$name" "AI_APPROVE_${RUNTAG}_R" "and the rename actually happened"
fi

# --- a type the allow list used to exclude
META=$(propose_id '{"type":"meta","target":{"carrier":"media","filename":"coffee.jpg"},"fields":{"med_copyright":"AI_APPROVE_TEST"},"reason":"meta was excluded by the old allow list"}')
out=$(approve_id "$META")
assert "${out%%|*}" "200" "a metainfo change is approved — no type restriction left"

# --- delete, the furthest-reaching operation, now allowed
if [[ -n "$NEW_CAT" ]]; then
    DEL=$(propose_id "{\"type\":\"category\",\"target\":{\"category_id\":${NEW_CAT},\"clang_id\":1},\"reason\":\"deleting is allowed now, and this is the test fixture\"}" )
    DEL=$("${CURL[@]}" -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
        -d "{\"type\":\"category\",\"target\":{\"category_id\":${NEW_CAT},\"clang_id\":1},\"reason\":\"deleting is allowed now, and this is the test fixture\"}" \
        "$BASE/api/ai_platform/changes/deletions" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -1)
    out=$(approve_id "$DEL")
    assert "${out%%|*}" "200" "a delete is approved — which also removes this test's fixture"
    left=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM ${PREFIX}article WHERE id=${NEW_CAT}")
    assert "$left" "0" "and the category is really gone"
fi

# --- the two invariants that are NOT configurable
OTHER=$("${MYSQL[@]}" -N -B -e \
    "SELECT id FROM ${PREFIX}ai_change_request WHERE source_key <> 'api-token:${FULL_ID}' AND status='pending' LIMIT 1")
if [[ -n "$OTHER" ]]; then
    out=$(approve_id "$OTHER")
    assert "${out%%|*}" "422" "a request submitted by someone else cannot be approved"
    assert_contains "${out#*|}" "itself" "and the reason names the rule"
else
    echo "  SKIP  no foreign pending request to test against"
fi

# force is not reachable: a body field of that name must not change anything.
STALE=$(propose_id "{\"type\":\"article\",\"operation\":\"update\",\"target\":{\"article_id\":${ART},\"clang_id\":1},\"fields\":{\"name\":\"AI_FORCE_TEST\"},\"reason\":\"will be made stale\"}")
"${MYSQL[@]}" -e "UPDATE ${PREFIX}ai_change_request SET base_hash='deliberately-wrong' WHERE id=${STALE}" >/dev/null
r=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' -d "{\"id\":${STALE},\"force\":true}" \
    "$BASE/api/ai_platform/changes/approvals")
assert "$(tail -n1 <<<"$r")" "422" "a stale target still blocks, and a force field in the body changes nothing"
nm=$("${MYSQL[@]}" -N -B -e "SELECT name FROM ${PREFIX}article WHERE id=${ART} AND clang_id=1")
if [[ "$nm" == "AI_FORCE_TEST" ]]; then
    echo "  FAIL the stale change was written anyway"
    FAIL=$((FAIL + 1))
else
    echo "  OK   and nothing was written"
    PASS=$((PASS + 1))
fi

# recorded, in the database and through the API
via=$("${MYSQL[@]}" -N -B -e "SELECT reviewed_via FROM ${PREFIX}ai_change_request WHERE id=${CAT_ID}")
assert "$via" "api" "reviewed_via records that no human decided this"
by=$("${MYSQL[@]}" -N -B -e "SELECT IFNULL(reviewed_by,'NULL') FROM ${PREFIX}ai_change_request WHERE id=${CAT_ID}")
assert "$by" "NULL" "reviewed_by stays empty rather than naming a user who did nothing"
apivia=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" "$BASE/api/ai_platform/changes/${CAT_ID}" \
    | python3 -c "import json,sys;print(json.load(sys.stdin)['data'].get('reviewed_via'))")
assert "$apivia" "api" "and the API reports the channel back"
st=$("${MYSQL[@]}" -N -B -e "SELECT status FROM ${PREFIX}ai_change_request WHERE id=${CAT_ID}")
assert "$st" "applied" "the request ends up applied, and the row is still there — nothing is deleted"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${BARE_TOKEN}" \
    -H 'Content-Type: application/json' -d '{"id":1}' "$BASE/api/ai_platform/changes/approvals")
assert "$code" "401" "approving needs its own scope"

# leftovers from the fixtures above
"${MYSQL[@]}" <<SQL >/dev/null
DELETE FROM ${PREFIX}article WHERE catname LIKE 'AI_APPROVE_%';
SQL
# yrewrite records a 301 for every rename. Left behind, the next run collides on
# the unique (domain, url) key and the approval fails for a reason that has
# nothing to do with this addon.
"${MYSQL[@]}" -e "DELETE FROM ${PREFIX}yrewrite_forward WHERE url LIKE '%ai_approve_%'" >/dev/null 2>&1 || true

# --- withdrawing own proposals -----------------------------------------------
# The gap this closes: an agent that noticed its own mistake could only leave the
# misfire in an editor's inbox and hope someone rejected it, turning an agent's
# error into a person's chore. Nothing is deleted — the row moves to "withdrawn",
# which is kept apart from "rejected" because an editor saying no and a submitter
# taking something back say very different things.
section "Withdrawing own proposals"

W_ID=$(propose_id "{\"type\":\"article\",\"operation\":\"update\",\"target\":{\"article_id\":${ART2},\"clang_id\":1},\"fields\":{\"priority\":21},\"reason\":\"will be taken back\"}")

resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"id\":${W_ID},\"reason\":\"noticed the priority was already correct\"}" \
    "$BASE/api/ai_platform/changes/withdrawals")
assert "$(tail -n1 <<<"$resp")" "200" "an own pending proposal can be withdrawn"
assert_contains "$(sed '$d' <<<"$resp")" '"status":"withdrawn"' "and the response names the new status"

# The row has to survive. That is the whole design: status changes, nothing is
# deleted, so what happened stays readable.
exists=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM ${PREFIX}ai_change_request WHERE id=${W_ID}")
assert "$exists" "1" "the row still exists — withdrawing is a status change, not a delete"
st=$("${MYSQL[@]}" -N -B -e "SELECT status FROM ${PREFIX}ai_change_request WHERE id=${W_ID}")
assert "$st" "withdrawn" "the status is withdrawn, distinct from rejected"
note=$("${MYSQL[@]}" -N -B -e "SELECT review_note FROM ${PREFIX}ai_change_request WHERE id=${W_ID}")
assert_contains "$note" "already correct" "the given reason is kept on the row"

# Withdrawing frees the quota, because the request is no longer open. An agent
# that cleans up after itself should get that room back.
quota_before=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" --get -d 'per_page=1' \
    "$BASE/api/ai_platform/changes" | python3 -c "import json,sys;print(json.load(sys.stdin)['meta']['open'])")
W2=$(propose_id "{\"type\":\"article\",\"operation\":\"update\",\"target\":{\"article_id\":${ART2},\"clang_id\":1},\"fields\":{\"priority\":22},\"reason\":\"quota check\"}")
"${CURL[@]}" -o /dev/null -X POST -H "Authorization: Bearer ${FULL_TOKEN}" -H 'Content-Type: application/json' \
    -d "{\"id\":${W2}}" "$BASE/api/ai_platform/changes/withdrawals"
quota_after=$("${CURL[@]}" -H "Authorization: Bearer ${FULL_TOKEN}" --get -d 'per_page=1' \
    "$BASE/api/ai_platform/changes" | python3 -c "import json,sys;print(json.load(sys.stdin)['meta']['open'])")
assert "$quota_after" "$quota_before" "a withdrawn request is no longer counted as open"

# Twice is refused rather than silently accepted: the second call has nothing to do.
resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' -d "{\"id\":${W_ID}}" \
    "$BASE/api/ai_platform/changes/withdrawals")
assert "$(tail -n1 <<<"$resp")" "422" "withdrawing twice is refused"
assert_contains "$(sed '$d' <<<"$resp")" "withdrawn" "and the message says what state it is in"

# An applied request is past the point where its submitter decides.
if [[ -n "${CAT_ID:-}" ]]; then
    resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
        -H 'Content-Type: application/json' -d "{\"id\":${CAT_ID}}" \
        "$BASE/api/ai_platform/changes/withdrawals")
    assert "$(tail -n1 <<<"$resp")" "422" "an already applied request cannot be withdrawn"
fi

# Someone else's proposal: same rule as approving.
if [[ -n "${OTHER:-}" ]]; then
    resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
        -H 'Content-Type: application/json' -d "{\"id\":${OTHER}}" \
        "$BASE/api/ai_platform/changes/withdrawals")
    assert "$(tail -n1 <<<"$resp")" "422" "another submitter's proposal cannot be withdrawn"
fi

# Batch, with one id that is not withdrawable — has to report per element.
B1=$(propose_id "{\"type\":\"article\",\"operation\":\"update\",\"target\":{\"article_id\":${ART2},\"clang_id\":1},\"fields\":{\"priority\":23},\"reason\":\"batch withdraw\"}")
resp=$("${CURL[@]}" -w '\n%{http_code}' -X POST -H "Authorization: Bearer ${FULL_TOKEN}" \
    -H 'Content-Type: application/json' -d "{\"ids\":[${B1},999999999]}" \
    "$BASE/api/ai_platform/changes/withdrawals")
assert "$(tail -n1 <<<"$resp")" "207" "a mixed batch answers 207"
assert_contains "$(sed '$d' <<<"$resp")" '"withdrawn":1' "the valid one went through"

code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer ${BARE_TOKEN}" \
    -H 'Content-Type: application/json' -d '{"id":1}' "$BASE/api/ai_platform/changes/withdrawals")
assert "$code" "401" "withdrawing needs its own scope"

# --- nothing was written -----------------------------------------------------
section "Nothing reached the content"

# The point is no longer "nothing was applied" — the approval section above
# applies one on purpose. What must hold is that nothing was applied without a
# recorded decision: every write carries who or what decided it.
unexplained=$("${MYSQL[@]}" -N -B -e \
    "SELECT COUNT(*) FROM ${PREFIX}ai_change_request WHERE source_key='api-token:${FULL_ID}'
     AND applied_at IS NOT NULL AND reviewed_via IS NULL AND reviewed_by IS NULL")
assert "$unexplained" "0" "nothing was applied without a recorded decision behind it"

# Not a fixed count — the approval section deliberately makes several. What has
# to hold is that everything decided over the API is marked as such, and that
# nothing marked as api-decided is missing its outcome.
viaapi=$("${MYSQL[@]}" -N -B -e \
    "SELECT COUNT(*) FROM ${PREFIX}ai_change_request WHERE source_key='api-token:${FULL_ID}'
     AND reviewed_via = 'api'")
if [[ "$viaapi" -ge 1 ]]; then
    echo "  OK   the approvals this run made are marked as api-decided (${viaapi})"
    PASS=$((PASS + 1))
else
    echo "  FAIL no approval was marked as api-decided"
    FAIL=$((FAIL + 1))
fi

halfdone=$("${MYSQL[@]}" -N -B -e \
    "SELECT COUNT(*) FROM ${PREFIX}ai_change_request WHERE source_key='api-token:${FULL_ID}'
     AND reviewed_via='api' AND status NOT IN ('applied','failed','approved','withdrawn')")
assert "$halfdone" "0" "every api-decided request reached a real outcome"

echo
echo "REST change routes: ${PASS} passed, ${FAIL} failed"
[[ $FAIL -eq 0 ]]
