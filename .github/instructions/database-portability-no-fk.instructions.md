---
description: "Use when designing database schemas, Laravel migrations, repositories, and data integrity logic that must run on PostgreSQL, MySQL, and SQLite without foreign keys."
name: "Database Portability Without Foreign Keys"
applyTo:  "**/*.php"
---
# 数据库跨引擎兼容与无外键约束规范

- 数据结构设计必须同时兼容 PostgreSQL、MySQL、SQLite 三种数据库。
- 任何场景下都禁止使用数据库外键约束；关联一致性由业务代码、应用层校验与后台修复任务共同保障。
- 主键与关联键优先使用统一类型与长度，避免不同引擎下隐式类型转换导致查询退化。
- 避免依赖单一数据库方言特性；若必须使用，必须提供三库可运行的降级实现。
- 迁移脚本必须保证幂等与可回滚，并在三种数据库上都可执行通过。
- 索引设计必须跨引擎可落地：优先普通索引、联合索引、唯一索引，避免不可移植索引语法。
- 删除与更新关联数据时，必须显式编排业务级联策略（软删除、延迟删除、补偿任务）。
- 写入流程必须进行存在性校验与状态校验，防止产生悬挂引用或脏关联。
- 并发写场景必须采用一致性保护手段（唯一约束、幂等键、版本号或应用级锁）。
- 查询与分页必须使用跨引擎稳定语义，避免依赖特定排序规则或未定义行为。

## 实现要求

- 给出数据模型方案时，同时说明在 PostgreSQL、MySQL、SQLite 的兼容性注意点。
- 给出关联写入代码时，必须包含完整校验路径与失败补偿路径。
- 涉及删除策略时，必须明确历史数据清理与一致性巡检机制。
