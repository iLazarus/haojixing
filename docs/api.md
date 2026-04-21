# HTTP API 文档（按当前工程实现）

更新时间：2026-04-16

## 基础信息

- Base URL：`http://127.0.0.1:9001`
- 路由前缀：`/api/v1`
- 当前 API 路由总数：32（`php artisan route:list --path=api`）

## 统一响应结构

成功：

~~~json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "trace_id": "uuid"
}
~~~

失败（示例）：

~~~json
{
  "code": 50000,
  "message": "internal server error",
  "trace_id": "uuid"
}
~~~

## 测试前准备

先定义变量，后续 curl 可直接复制执行。

~~~bash
BASE="http://127.0.0.1:9001"
TG_GID=900001
TG_UID=900002
TG_MSG_ID=700001
LEDGER_ID=1
~~~

## 健康检查

### GET /healthz

~~~bash
curl -i "$BASE/healthz"
~~~

## Group 接口

### GET /api/v1/groups

~~~bash
curl -sS "$BASE/api/v1/groups"
~~~

### POST /api/v1/groups

字段规则：

- tg_gid: required, integer, not_in:0（支持负数群 ID）
- tg_oid: required, integer, min:1
- is_open: boolean
- base_currency: string, size:1
- quote_currency: string, size:1
- exchange_rate: numeric, gt:0
- fee_rate: numeric, between:0,100
- period_point: integer, between:0,23
- period_duration: integer, min:1

~~~bash
curl -sS -X POST "$BASE/api/v1/groups" \
  -H 'Content-Type: application/json' \
  -d '{
    "tg_gid": 900001,
    "tg_oid": 900001,
    "is_open": true,
    "base_currency": "R",
    "quote_currency": "U",
    "exchange_rate": 1,
    "fee_rate": 0.5,
    "period_point": 0,
    "period_duration": 1440
  }'
~~~

### GET /api/v1/groups/{tgGid}

~~~bash
curl -sS "$BASE/api/v1/groups/$TG_GID"
~~~

### PATCH /api/v1/groups/{tgGid}

~~~bash
curl -sS -X PATCH "$BASE/api/v1/groups/$TG_GID" \
  -H 'Content-Type: application/json' \
  -d '{"fee_rate":1.25,"period_point":8}'
~~~

### DELETE /api/v1/groups/{tgGid}

~~~bash
curl -sS -X DELETE "$BASE/api/v1/groups/$TG_GID"
~~~

## User 接口

### GET /api/v1/users

~~~bash
curl -sS "$BASE/api/v1/users"
~~~

### POST /api/v1/users

字段规则：

- tg_uid: required, integer, min:1
- tg_username: nullable, string, max:64
- tg_nickname: nullable, string, max:128

~~~bash
curl -sS -X POST "$BASE/api/v1/users" \
  -H 'Content-Type: application/json' \
  -d '{"tg_uid":900002,"tg_username":"u900002","tg_nickname":"测试用户"}'
~~~

### GET /api/v1/users/{tgUid}

~~~bash
curl -sS "$BASE/api/v1/users/$TG_UID"
~~~

### PATCH /api/v1/users/{tgUid}

~~~bash
curl -sS -X PATCH "$BASE/api/v1/users/$TG_UID" \
  -H 'Content-Type: application/json' \
  -d '{"tg_nickname":"测试用户更新"}'
~~~

### DELETE /api/v1/users/{tgUid}

~~~bash
curl -sS -X DELETE "$BASE/api/v1/users/$TG_UID"
~~~

## Member 接口

### POST /api/v1/members

字段规则：

- tg_gid: required, integer, min:1
- tg_uid: required, integer, min:1
- role: required, in:operator,consumer
- is_active: boolean

~~~bash
curl -sS -X POST "$BASE/api/v1/members" \
  -H 'Content-Type: application/json' \
  -d '{"tg_gid":900001,"tg_uid":900002,"role":"operator","is_active":true}'
~~~

### GET /api/v1/groups/{tgGid}/members

~~~bash
curl -sS "$BASE/api/v1/groups/$TG_GID/members"
~~~

### GET /api/v1/groups/{tgGid}/members/{tgUid}

~~~bash
curl -sS "$BASE/api/v1/groups/$TG_GID/members/$TG_UID"
~~~

### PATCH /api/v1/groups/{tgGid}/members/{tgUid}

~~~bash
curl -sS -X PATCH "$BASE/api/v1/groups/$TG_GID/members/$TG_UID" \
  -H 'Content-Type: application/json' \
  -d '{"role":"consumer","is_active":true}'
~~~

### DELETE /api/v1/groups/{tgGid}/members/{tgUid}

~~~bash
curl -sS -X DELETE "$BASE/api/v1/groups/$TG_GID/members/$TG_UID"
~~~

## Rule 接口

### GET /api/v1/rules

~~~bash
curl -sS "$BASE/api/v1/rules"
~~~

### POST /api/v1/rules

字段规则：

- is_default: sometimes, boolean（默认 false；true 时不可删除）

示例（reply 文本 + API payload 模板）：

~~~bash
curl -sS -X POST "$BASE/api/v1/rules" \
  -H 'Content-Type: application/json' \
    "is_active":true,
    "is_default":false
    "remark":"金额提取回复",
    "regular":"/买\\s*(\\d+(?:\\.\\d+)?)/u",
    "api":null,
    "data_map":"{\"reply_template\":\"收到金额 {{1}}\",\"api_payload\":{\"amount\":\"{{1}}\",\"from\":\"{{sender}}\"}}",
    "is_active":true
  }'
~~~

示例（支持 `+100 @someone` 记账）：

- 推荐正则：`/^\+(\d+)([RrUu]?)?(?:\s+@(\S+))?/u`
- 说明：`{{1}}` 为金额；`{{2}}` 为币种（R/U，可空）；`{{3}}` 为用户名（不含 `@`，可空）。
- 系统行为：
  - 当 `tg_belong_uid` 为空时，自动回退为 `tg_uid`（自己记自己）。
  - 当 `tg_belong_uid` 为用户名时，执行器会自动在 `tg_user` 中解析为 `tg_uid`；未解析到会返回 422。
  - 当 `currency_type` 为空时默认 `R`；匹配到 `r/u` 时会统一转为大写 `R/U`。

~~~bash
curl -sS -X POST "$BASE/api/v1/rules" \
  -H 'Content-Type: application/json' \
  -d '{
    "remark":"+100 @someone 记账",
    "regular":"/^\\+(\\d+)([RrUu]?)?(?:\\s+@(\\S+))?/u",
    "method":"POST",
    "api":"http://localhost/api/v1/ledgers",
    "data_map":"{\"api_payload\":{\"tg_gid\":\"{{tg_gid}}\",\"tg_uid\":\"{{tg_uid}}\",\"tg_belong_uid\":\"{{3}}\",\"tg_msg_id\":\"{{tg_msg_id}}\",\"amount\":\"{{1}}\",\"currency_type\":\"{{2}}\",\"is_delete\":false},\"reply_template\":\"记账成功：金额 {{result.amount}}，币种 {{result.currency_type}}，归属UID {{result.tg_belong_uid}}\"}",
    "is_active":true
  }'
~~~

### GET /api/v1/rules/{id}

~~~bash
curl -sS "$BASE/api/v1/rules/$RULE_ID"
~~~

### PATCH /api/v1/rules/{id}

~~~bash
curl -sS -X PATCH "$BASE/api/v1/rules/$RULE_ID" \
  -H 'Content-Type: application/json' \
  -d '{"remark":"金额提取回复-更新","is_active":true}'
~~~

### DELETE /api/v1/rules/{id}

说明：当规则 `is_default=true` 时，接口会拒绝删除并返回 422。

~~~bash
curl -sS -X DELETE "$BASE/api/v1/rules/$RULE_ID"
~~~

## Group Rule 接口

### GET /api/v1/groups/{tgGid}/rules

~~~bash
curl -sS "$BASE/api/v1/groups/$TG_GID/rules"
~~~

### POST /api/v1/groups/{tgGid}/rules

字段规则：

- app_rule_id: required, integer, min:1
- priority: sometimes, integer, min:0
- stop_on_match: sometimes, boolean
- is_active: sometimes, boolean

~~~bash
curl -sS -X POST "$BASE/api/v1/groups/$TG_GID/rules" \
  -H 'Content-Type: application/json' \
  -d '{"app_rule_id":'$RULE_ID',"priority":10,"stop_on_match":true,"is_active":true}'
~~~

### PATCH /api/v1/groups/{tgGid}/rules/{appRuleId}

~~~bash
curl -sS -X PATCH "$BASE/api/v1/groups/$TG_GID/rules/$RULE_ID" \
  -H 'Content-Type: application/json' \
  -d '{"priority":5,"stop_on_match":true,"is_active":true}'
~~~

### DELETE /api/v1/groups/{tgGid}/rules/{appRuleId}

~~~bash
curl -sS -X DELETE "$BASE/api/v1/groups/$TG_GID/rules/$RULE_ID"
~~~

## Rule Engine 接口

### POST /api/v1/groups/{tgGid}/rules/match

字段规则：

- tg_msg_id: required, integer, min:1
- message: required, string, min:1, max:5000
- execute_api: sometimes, boolean（默认 false）
- context: sometimes, array（可用于模板变量，例如 sender）

首次匹配示例：

~~~bash
curl -sS -X POST "$BASE/api/v1/groups/$TG_GID/rules/match" \
  -H 'Content-Type: application/json' \
  -d '{
    "tg_msg_id":700100,
    "message":"买 12.34",
    "execute_api":false,
    "context":{"sender":"900002"}
  }'
~~~

响应重点：`data.hit_count=1`，并在 `data.hits[0].action.reply_text` 中拿到模板渲染结果。

幂等重放示例（同一 tg_msg_id 再次请求）：

~~~bash
curl -sS -X POST "$BASE/api/v1/groups/$TG_GID/rules/match" \
  -H 'Content-Type: application/json' \
  -d '{
    "tg_msg_id":700100,
    "message":"买 12.34",
    "execute_api":false,
    "context":{"sender":"900002"}
  }'
~~~

响应重点：`data.hit_count=0`（已命中过同一规则会跳过）。

## Ledger 接口

### POST /api/v1/ledgers

字段规则：

- tg_gid: required, integer, min:1
- tg_uid: required, integer, min:1
- tg_belong_uid: required, integer, min:1
- tg_msg_id: required, integer, min:1
- amount: required, integer（单位：分）
- currency_type: sometimes, string, in:R,U（默认 R）
- is_delete: boolean

~~~bash
curl -sS -X POST "$BASE/api/v1/ledgers" \
  -H 'Content-Type: application/json' \
  -d '{
    "tg_gid":900001,
    "tg_uid":900002,
    "tg_belong_uid":900002,
    "tg_msg_id":700001,
    "amount":12345,
    "currency_type":"R",
    "is_delete":false
  }'
~~~

### GET /api/v1/ledgers/{id}

~~~bash
curl -sS "$BASE/api/v1/ledgers/$LEDGER_ID"
~~~

### PATCH /api/v1/ledgers/{id}

~~~bash
curl -sS -X PATCH "$BASE/api/v1/ledgers/$LEDGER_ID" \
  -H 'Content-Type: application/json' \
  -d '{"amount":22345}'
~~~

### DELETE /api/v1/ledgers/{id}

~~~bash
curl -sS -X DELETE "$BASE/api/v1/ledgers/$LEDGER_ID"
~~~

### PATCH /api/v1/ledgers/{id}/soft-delete

~~~bash
curl -sS -X PATCH "$BASE/api/v1/ledgers/$LEDGER_ID/soft-delete" \
  -H 'Content-Type: application/json' \
  -d '{"is_delete":true}'
~~~

### GET /api/v1/groups/{tgGid}/ledgers

~~~bash
curl -sS "$BASE/api/v1/groups/$TG_GID/ledgers"
~~~

### POST /api/v1/ledger/ingest

字段规则：

- tg_gid: required, integer, min:1
- tg_uid: required, integer, min:1
- tg_belong_uid: required, integer, min:1
- tg_msg_id: required, integer, min:1
- exchange_rate: nullable, numeric, gt:0
- fee_rate: nullable, numeric, between:0,100
- amount_yuan: required, numeric（单位：元）

~~~bash
curl -sS -X POST "$BASE/api/v1/ledger/ingest" \
  -H 'Content-Type: application/json' \
  -d '{
    "tg_gid":900001,
    "tg_uid":900002,
    "tg_belong_uid":900002,
    "tg_msg_id":700002,
    "exchange_rate":1,
    "fee_rate":0.2,
    "amount_yuan":12.34
  }'
~~~

## 一键全接口测试

工程已内置脚本，可直接覆盖所有 API：

~~~bash
chmod +x scripts/curl_all_api.sh
./scripts/curl_all_api.sh
~~~

脚本位置：`scripts/curl_all_api.sh`
