# 记账数据结构确认稿

更新时间：2026-04-09

## 范围

- 该文档用于和 Telegram 机器人后端的数据结构核对。
- 目标兼容数据库：PostgreSQL、MySQL、SQLite。
- 关联一致性采用应用层校验，不使用数据库外键。
- 所有时间字段统一按 +8（Asia/Shanghai）时区计算后，以 date 格式存储（YYYY-MM-DD）。

## 表结构

### TG_GROUP

- tg_gid: BIGINT，唯一
- tg_oid: BIGINT
- is_open: BOOLEAN，默认 true
- base_currency: CHAR(3)，默认 R
- quote_currency: CHAR(3)，默认 U
- exchange_rate: DECIMAL(20,10)，默认 1
- fee_rate: DECIMAL(7,4)，默认 0
- period_point: UNSIGNED TINYINT，默认 0（0-23，24 小时制）
- period_duration: UNSIGNED INT，单位为分钟，默认 1440
- created_at: DATE
- updated_at: DATE

规则说明：
- fee_rate 由用户输入 0-100，支持小数，表示百分比（以 100 为底）。
- 计算时使用 fee_rate / 100。

### TG_USER

- tg_uid: BIGINT，唯一
- tg_username: VARCHAR(64)，可空
- tg_nickname: VARCHAR(128)，可空
- created_at: DATE
- updated_at: DATE

### APP_MEMBER

- tg_gid: BIGINT
- tg_uid: BIGINT
- role: VARCHAR(16)，仅允许 operator、consumer
- is_active: BOOLEAN，默认 false
- created_at: DATE
- updated_at: DATE

唯一约束：
- (tg_gid, tg_uid)

### APP_LEDGER

- id: BIGINT 自增主键
- tg_gid: BIGINT
- tg_uid: BIGINT
- tg_belong_uid: BIGINT，不能为空
- tg_msg_id: BIGINT，不能为空
- is_delete: BOOLEAN，默认 false
- amount: BIGINT，单位为分
- created_at: DATE
- updated_at: DATE

规则说明：
- 用户输入金额单位为元，落库前换算成分。
- 推荐换算规则：amount_cent = round(amount_yuan * 100, 0, PHP_ROUND_HALF_UP)。
- 为保证 Telegram 消息幂等，使用唯一约束 (tg_gid, tg_msg_id)。

## 索引建议

- TG_GROUP: unique(tg_gid), index(tg_oid), index(is_open, updated_at)
- TG_USER: unique(tg_uid), index(tg_username)
- APP_MEMBER: unique(tg_gid, tg_uid), index(tg_uid, is_active), index(tg_gid, role, is_active)
- APP_LEDGER:
  - unique(tg_gid, tg_msg_id)
  - index(tg_gid, created_at)
  - index(tg_uid, created_at)
  - index(tg_belong_uid, created_at)
  - index(tg_gid, is_delete, created_at)

## 数据校验口径

- role 仅允许 operator、consumer。
- fee_rate 仅允许 0-100（可小数）。
- period_point 仅允许 0-23，表示 24 小时制时间。
- tg_msg_id、tg_belong_uid 必填且必须为正整数。
- period_duration 必须大于 0，单位固定为分钟。
