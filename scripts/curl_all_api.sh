#!/usr/bin/env bash
set -eo pipefail
BASE="http://127.0.0.1:9001"

TMP_BODY="/tmp/resp_body.json"

extract_first_int_by_key() {
  local key="$1"
  sed -n "s/.*\"$key\":\([0-9][0-9]*\).*/\1/p" "$TMP_BODY" | head -n1
}

assert_body_contains() {
  local needle="$1"
  if ! grep -q "$needle" "$TMP_BODY"; then
    echo "断言失败：响应未包含 $needle"
    echo "当前响应："
    cat "$TMP_BODY"
    exit 1
  fi
}

request() {
  local method="$1"
  local path="$2"
  local data="${3:-}"
  local code
  if [[ -n "$data" ]]; then
    code=$(curl -sS -o "$TMP_BODY" -w "%{http_code}" -X "$method" "$BASE$path" -H 'Content-Type: application/json' -d "$data")
  else
    code=$(curl -sS -o "$TMP_BODY" -w "%{http_code}" -X "$method" "$BASE$path")
  fi
  echo "$method $path => $code"
  head -c 220 "$TMP_BODY"; echo
  echo "-----"
}

echo "[A] healthz"
request GET "/healthz"

echo "[B] 准备测试数据"
request POST "/api/v1/groups" '{"tg_gid":900001,"tg_oid":900001,"is_open":true,"base_currency":"R","quote_currency":"U","exchange_rate":1,"fee_rate":0.5,"period_point":0,"period_duration":1440}'
request POST "/api/v1/users" '{"tg_uid":900002,"tg_username":"u900002","tg_nickname":"测试用户"}'
request POST "/api/v1/members" '{"tg_gid":900001,"tg_uid":900002,"role":"operator","is_active":true}'
request POST "/api/v1/ledgers" '{"tg_gid":900001,"tg_uid":900002,"tg_belong_uid":900002,"tg_msg_id":700001,"amount":12345,"is_delete":false}'
request POST "/api/v1/ledger/ingest" '{"tg_gid":900001,"tg_uid":900002,"tg_belong_uid":900002,"tg_msg_id":700002,"exchange_rate":1,"fee_rate":0.2,"amount_yuan":12.34}'

LEDGER_ID=$(curl -sS "$BASE/api/v1/groups/900001/ledgers" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p' | head -n1)
if [[ -z "$LEDGER_ID" ]]; then
  LEDGER_ID=1
fi
echo "LEDGER_ID=$LEDGER_ID"

echo "[C] 覆盖规则模块"
request POST "/api/v1/rules" '{"remark":"金额提取回复","regular":"/买\\s*(\\d+(?:\\.\\d+)?)/u","api":null,"data_map":"{\"reply_template\":\"收到金额 {{1}}\",\"api_payload\":{\"amount\":\"{{1}}\",\"from\":\"{{sender}}\"}}","is_active":true}'
RULE_ID=$(extract_first_int_by_key "id")
if [[ -z "$RULE_ID" ]]; then
  echo "未能从规则创建响应中提取 RULE_ID"
  cat "$TMP_BODY"
  exit 1
fi
echo "RULE_ID=$RULE_ID"

request GET "/api/v1/rules"
request GET "/api/v1/rules/$RULE_ID"
request PATCH "/api/v1/rules/$RULE_ID" '{"remark":"金额提取回复-更新","is_active":true}'

request POST "/api/v1/groups/900001/rules" "{\"app_rule_id\":$RULE_ID,\"priority\":10,\"stop_on_match\":true,\"is_active\":true}"
request GET "/api/v1/groups/900001/rules"
request PATCH "/api/v1/groups/900001/rules/$RULE_ID" '{"priority":5,"stop_on_match":true,"is_active":true}'

request POST "/api/v1/groups/900001/rules/match" '{"tg_msg_id":700100,"message":"买 12.34","execute_api":false,"context":{"sender":"900002"}}'
assert_body_contains '"hit_count":1'

request POST "/api/v1/groups/900001/rules/match" '{"tg_msg_id":700100,"message":"买 12.34","execute_api":false,"context":{"sender":"900002"}}'
assert_body_contains '"hit_count":0'

echo "[D] 覆盖所有 API 路由"
request GET "/api/v1/groups"
request GET "/api/v1/groups/900001"
request PATCH "/api/v1/groups/900001" '{"fee_rate":1.25,"period_point":8}'
request GET "/api/v1/groups/900001/ledgers"
request GET "/api/v1/groups/900001/members"
request GET "/api/v1/groups/900001/members/900002"
request PATCH "/api/v1/groups/900001/members/900002" '{"role":"consumer","is_active":true}'
request DELETE "/api/v1/groups/900001/members/900002"

request GET "/api/v1/users"
request GET "/api/v1/users/900002"
request PATCH "/api/v1/users/900002" '{"tg_nickname":"测试用户更新"}'
request DELETE "/api/v1/users/900002"

request GET "/api/v1/ledgers/$LEDGER_ID"
request PATCH "/api/v1/ledgers/$LEDGER_ID" '{"amount":22345}'
request PATCH "/api/v1/ledgers/$LEDGER_ID/soft-delete" '{"is_delete":true}'
request DELETE "/api/v1/ledgers/$LEDGER_ID"

request DELETE "/api/v1/groups/900001/rules/$RULE_ID"
request DELETE "/api/v1/rules/$RULE_ID"
request DELETE "/api/v1/groups/900001"

echo "[E] route:list"
podman exec haojixing-app php artisan route:list --path=api | sed -n '1,40p'
