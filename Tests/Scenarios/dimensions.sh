#!/usr/bin/env bash
# Scenario check for content dimensions against a running bridge (Neos 8 and Neos 9 branch).
#
# Needs a Neos instance with this package, the Neos Demo site and two content dimensions:
#   language: en_US (default), de          country: nl (default), be
# (the dev setups neos8-dev / neos9-dev have them). The script creates a page in the "mcp"
# workspace, works on it in several dimension combinations and removes it again; it never
# publishes. Do not run it against a site with real content.
#
#   NEOS_MCP_URL=http://127.0.0.1:8083 NEOS_MCP_TOKEN=... bash Tests/Scenarios/dimensions.sh
set -u
URL="${NEOS_MCP_URL:?set NEOS_MCP_URL}"; TOKEN="${NEOS_MCP_TOKEN:?set NEOS_MCP_TOKEN}"
URL="${URL%/}/neos/mcp"
DEFAULT='{"language":"en_US","country":"nl"}'
DE_BE='{"language":"de","country":"be"}'
EN_BE='{"language":"en_US","country":"be"}'
FAILED=0
STATUS=0; BODY=""

call() { # method action [curl data args...]
  local method=$1 action=$2; shift 2
  local out
  if [ "$method" = GET ]; then
    out=$(curl -s -w $'\n%{http_code}' -H "Authorization: Bearer $TOKEN" -G "$URL/$action" "$@" 2>/dev/null)
  else
    out=$(curl -s -w $'\n%{http_code}' -H "Authorization: Bearer $TOKEN" -X POST "$URL/$action" "$@" 2>/dev/null)
  fi
  STATUS=${out##*$'\n'}; BODY=${out%$'\n'*}
}
get()  { call GET "$@"; }
post() { call POST "$@"; }
js()   { python3 -c "import sys,json
try:
    d=json.loads(sys.argv[1])
except Exception:
    print(''); sys.exit()
for k in sys.argv[2].split('.'):
    d=d.get(k) if isinstance(d,dict) else None
print('' if d is None else (json.dumps(d,sort_keys=True) if isinstance(d,(dict,list)) else d))" "$BODY" "$1"; }
check() { # description expected actual
  if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1 (expected '$2', got '$3')"; FAILED=$((FAILED+1)); fi
}
check_in() { # description actual allowed...
  local d=$1 a=$2; shift 2
  for x in "$@"; do [ "$a" = "$x" ] && { echo "  ok   $d"; return; }; done
  echo "  FAIL $d (got '$a', allowed: $*)"; FAILED=$((FAILED+1))
}

get getSiteContext; V=$(js apiVersion); [ "$V" = 2 ] && N9=1 || N9=0
echo "bridge: Neos $([ $N9 = 1 ] && echo 9 || echo 8)"
if [ $N9 = 1 ]; then ROOT=$(js siteNodeAggregateId); PARENT=parentNodeAggregateId; ID=nodeAggregateId; READ=nodeAggregateId
else ROOT=/sites/neosdemo; PARENT=parentPath; ID=contextPath; READ=nodePath; fi
check "site context lists the dimensions" "language,country" "$(python3 -c "import json,sys; print(','.join(d['name'] for d in json.loads(sys.argv[1]).get('dimensions',[])))" "$BODY")"
post setupWorkspace >/dev/null

echo "create a page in the default dimensions"
post createDocumentNode --data-urlencode "$PARENT=$ROOT" --data-urlencode nodeType=Neos.Demo:Document.Page --data-urlencode nodeName=scenario-dim --data-urlencode "properties[title]=Default title" --data-urlencode "properties[uriPathSegment]=scenario-dim"
check "created" 200 "$STATUS"
if [ $N9 = 1 ]; then PAGE=$(js node.nodeAggregateId); else PAGE=/sites/neosdemo/scenario-dim; fi
title() { get getDocumentProperties --data-urlencode "$READ=$PAGE" --data-urlencode "dimensions=$1" --data-urlencode workspace=mcp; [ "$STATUS" = 200 ] && js properties.title || echo "[$STATUS]"; }
stored() { get getDocumentProperties --data-urlencode "$READ=$PAGE" --data-urlencode "dimensions=$1" --data-urlencode workspace=mcp; js dimensions; }

check "readable in the default dimensions" "Default title" "$(title "$DEFAULT")"
check "not stored in {de,be} yet" "[404]" "$(title "$DE_BE")"
EN_BE_TITLE=$(title "$EN_BE")
check_in "{en_US,be} shows it only through a fallback (Neos 8) or not at all (Neos 9)" "$EN_BE_TITLE" "Default title" "[404]"

echo "create a variant in {de,be}"
SITE_VARIANT=0
post createNodeVariant --data-urlencode "$ID=$PAGE" --data-urlencode "dimensions=$DE_BE" --data-urlencode mode=copy
if [ "$STATUS" = 409 ]; then
  echo "  (parent missing: creating the variant of the site node first)"
  post createNodeVariant --data-urlencode "$ID=$ROOT" --data-urlencode "dimensions=$DE_BE" --data-urlencode mode=copy; SITE_VARIANT=1
  check "site node variant created" 200 "$STATUS"
  post createNodeVariant --data-urlencode "$ID=$PAGE" --data-urlencode "dimensions=$DE_BE" --data-urlencode mode=copy
fi
check "variant created" 200 "$STATUS"
post createNodeVariant --data-urlencode "$ID=$PAGE" --data-urlencode "dimensions=$DE_BE"
check "creating it twice is refused" 409 "$STATUS"
post createNodeVariant --data-urlencode "$ID=$PAGE"
check "a variant without target dimensions is refused" 400 "$STATUS"
check "the variant is stored in both dimensions" '{"country": "be", "language": "de"}' "$(python3 -c "import json,sys; print(json.dumps(json.loads(sys.argv[1]),sort_keys=True))" "$(stored "$DE_BE")")"

echo "write in {de,be}"
post updateNodeProperty --data-urlencode "$ID=$PAGE" --data-urlencode "dimensions=$DE_BE" --data-urlencode property=title --data-urlencode "value=Titel BE"
check "updated" 200 "$STATUS"
check "{de,be} has the new title" "Titel BE" "$(title "$DE_BE")"
check "the default dimensions are untouched" "Default title" "$(title "$DEFAULT")"

echo "a node that is not stored in the requested dimensions is not changed"
post updateNodeProperty --data-urlencode "$ID=$PAGE" --data-urlencode "dimensions=$EN_BE" --data-urlencode property=title --data-urlencode "value=must not happen"
check_in "refused (409 through a fallback on Neos 8, 404 on Neos 9)" "$STATUS" 409 404
check "the default title is still intact" "Default title" "$(title "$DEFAULT")"

echo "pending changes per dimension"
get listPendingChanges --data-urlencode workspace=mcp --data-urlencode "dimensions=$DE_BE"
pending_dimensions() {
  python3 - "$BODY" <<'PY'
import json, sys
changes = json.loads(sys.argv[1])["pendingChanges"]
seen = sorted({json.dumps(c["dimensions"], sort_keys=True) for c in changes})
print("[" + ", ".join(seen) + "]")
PY
}
check "only changes of {de,be}" '[{"country": "be", "language": "de"}]' "$(pending_dimensions)"

echo "remove the {de,be} variant"
post deleteNode --data-urlencode "$ID=$PAGE" --data-urlencode "dimensions=$DE_BE"
check "removed" 200 "$STATUS"
check "{de,be} is gone" "[404]" "$(title "$DE_BE")"
check "the default dimensions still have the page" "Default title" "$(title "$DEFAULT")"

echo "clean up"
post deleteNode --data-urlencode "$ID=$PAGE" --data-urlencode "dimensions=$DEFAULT"
check "page removed" 200 "$STATUS"
if [ $SITE_VARIANT = 1 ]; then post deleteNode --data-urlencode "$ID=$ROOT" --data-urlencode "dimensions=$DE_BE" >/dev/null; fi
[ $N9 = 1 ] && echo "  (Neos 9: the workspace keeps the history of this run; reset it with: ./flow workspace:discard mcp)"

if [ $FAILED -eq 0 ]; then echo "ALL OK"; else echo "$FAILED CHECK(S) FAILED"; exit 1; fi
