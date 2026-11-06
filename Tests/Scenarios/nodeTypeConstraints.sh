#!/usr/bin/env bash
# Scenario check for the node type constraints against a running Neos 8 bridge (branch neos-8).
#
# On Neos 8 createNodeFromTemplate() and the move methods do not check the constraints of the
# node types, the bridge has to do it (422). Needs a Neos instance with this package and the
# Neos Demo site, which has the constraints used here:
#   - Document.Blog only allows Document.BlogPosting below it
#   - Document.BlogPosting does not allow documents below it
#   - the "main" collection of a page only allows nodes marked Constraint.Content.Main
# (the dev setup neos8-dev has them). The script creates a few pages in the "mcp" workspace and
# removes them again; it never publishes. Do not run it against a site with real content.
#
#   NEOS_MCP_URL=http://127.0.0.1:8083 NEOS_MCP_TOKEN=... bash Tests/Scenarios/nodeTypeConstraints.sh
set -u
URL="${NEOS_MCP_URL:?set NEOS_MCP_URL}"; TOKEN="${NEOS_MCP_TOKEN:?set NEOS_MCP_TOKEN}"
URL="${URL%/}/neos/mcp"
ROOT=/sites/neosdemo
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
page()  { post createDocumentNode --data-urlencode "parentPath=$1" --data-urlencode "nodeType=$2" --data-urlencode "nodeName=$3" --data-urlencode "properties[title]=$3" --data-urlencode "properties[uriPathSegment]=$3" "${@:4}"; }
exists() { get getDocumentProperties --data-urlencode "nodePath=$1" --data-urlencode workspace=mcp; [ "$STATUS" = 200 ] && echo yes || echo no; }

get getSiteContext
if [ "$(js apiVersion)" = 2 ]; then
  echo "This scenario is for the Neos 8 bridge: on Neos 9 the content repository checks the constraints itself."; exit 2
fi
post setupWorkspace >/dev/null

echo "set up a blog and a blog posting"
page $ROOT Neos.Demo:Document.Blog scenario-blog;                 check "blog created" 200 "$STATUS"
page $ROOT/scenario-blog Neos.Demo:Document.BlogPosting scenario-posting; check "blog posting created in the blog (allowed)" 200 "$STATUS"
page $ROOT Neos.Demo:Document.Page scenario-page;                 check "page created below the site (allowed)" 200 "$STATUS"
BLOG=$ROOT/scenario-blog; POSTING=$BLOG/scenario-posting; PAGE=$ROOT/scenario-page

echo "create a document node where it is not allowed"
page $BLOG Neos.Demo:Document.Page scenario-denied
check "a page directly in the blog is refused" 422 "$STATUS"
check "the answer names the node type and the parent" "yes" "$(js error | grep -q 'Neos.Demo:Document.Page is not allowed in /sites/neosdemo/scenario-blog' && echo yes || echo no)"
check "and no node was created" no "$(exists $BLOG/scenario-denied)"
page $POSTING Neos.Demo:Document.Page scenario-denied
check "a page below a blog posting is refused" 422 "$STATUS"
page $BLOG Neos.Demo:Document.Page scenario-denied --data-urlencode "insertAfter=$POSTING"
check "refused with insertAfter as well (the parent of the sibling counts)" 422 "$STATUS"
page $PAGE Neos.Demo:Document.Page scenario-sub --data-urlencode "insertBefore=$BLOG/scenario-nope"
check "a sibling that does not exist is a 404" 404 "$STATUS"
check "and no node was created before that" no "$(exists $PAGE/scenario-sub)"
page $ROOT Neos.Demo:Document.Page scenario-page2 --data-urlencode "insertBefore=$PAGE"
check "allowed with insertBefore" 200 "$STATUS"

echo "create content where it is not allowed"
post createContentNode --data-urlencode "parentPath=$PAGE/main" --data-urlencode "nodeType=Neos.Demo:Content.Column" --data-urlencode "properties[layout]=50;50"
check "a node type that the main collection does not allow is refused" 422 "$STATUS"
post createContentNode --data-urlencode "parentPath=$PAGE/main" --data-urlencode "nodeType=Neos.Demo:Content.Text" --data-urlencode "properties[text]=<p>ok</p>"
check "an allowed content node is created" 200 "$STATUS"

echo "move a node where it is not allowed"
post moveNode --data-urlencode "contextPath=$PAGE" --data-urlencode "newParentPath=$BLOG"
check "a page into the blog is refused (newParentPath)" 422 "$STATUS"
post moveNode --data-urlencode "contextPath=$PAGE" --data-urlencode "insertAfter=$POSTING"
check "a page next to a blog posting is refused (insertAfter)" 422 "$STATUS"
post moveNode --data-urlencode "contextPath=$PAGE" --data-urlencode "insertBefore=$POSTING"
check "a page before a blog posting is refused (insertBefore)" 422 "$STATUS"
check "the page did not move" yes "$(exists $PAGE)"
post moveNode --data-urlencode "contextPath=$PAGE" --data-urlencode "newParentPath=$POSTING"
check "a page below a blog posting is refused" 422 "$STATUS"

echo "move a node where it is allowed"
post moveNode --data-urlencode "contextPath=$POSTING" --data-urlencode "newParentPath=$ROOT"
check "a blog posting below the homepage is refused (it is not a sub page)" 422 "$STATUS"
post moveNode --data-urlencode "contextPath=$ROOT/scenario-page2" --data-urlencode "newParentPath=$PAGE"
check "a page below another page is allowed" 200 "$STATUS"
post moveNode --data-urlencode "contextPath=$PAGE/scenario-page2" --data-urlencode "insertAfter=$PAGE"
check "and next to it again (insertAfter)" 200 "$STATUS"
page $BLOG Neos.Demo:Document.BlogPosting scenario-posting2
check "a second blog posting in the blog" 200 "$STATUS"
post moveNode --data-urlencode "contextPath=$BLOG/scenario-posting2" --data-urlencode "insertBefore=$POSTING"
check "reordering inside the same parent is allowed" 200 "$STATUS"
post moveNode --data-urlencode "contextPath=$BLOG/scenario-posting2" --data-urlencode "newParentPath=$BLOG"
check "moving into the current parent is allowed" 200 "$STATUS"

echo "clean up"
for p in "$BLOG" "$PAGE" "$ROOT/scenario-page2"; do post deleteNode --data-urlencode "contextPath=$p"; check "removed $p" 200 "$STATUS"; done

if [ $FAILED -eq 0 ]; then echo "ALL OK"; else echo "$FAILED CHECK(S) FAILED"; exit 1; fi
