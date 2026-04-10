---
description: "Use when you need to bootstrap a Laravel project on Debian 12 with Podman, including local dev, debugging baseline, and production-oriented deployment skeleton."
name: "Laravel Podman Bootstrap"
argument-hint: "项目名、PHP版本、数据库类型、是否启用Redis/Xdebug、端口与域名约束"
agent: "agent"
---
基于以下输入，为 Laravel 项目生成一套 Debian 12 + Podman 启动脚手架方案（可直接落地到仓库）。

输入参数（若用户未提供，按默认值）：
- 项目名（默认 `laravel-app`）
- PHP 版本（默认 `8.4`）
- 数据库（`mysql` 或 `pgsql`，默认 `pgsql`）
- 是否启用 Redis（默认 `yes`）
- 是否启用 Xdebug（默认 `no`）
- HTTP 端口（默认 `8080`）
- 域名/主机名（默认 `localhost`）

执行要求：
1. 先检查并遵循工作区规范：
- [工作区指南](../copilot-instructions.md)
- [Debian12 Podman Laravel 规范](../instructions/debian12-podman-laravel.instructions.md)
- [Laravel 10k RPS 规范](../instructions/laravel-10k-rps.instructions.md)
- [数据库跨引擎规范](../instructions/database-portability-no-fk.instructions.md)

2. 生成最小可运行文件集（若文件已存在则合并而非粗暴覆盖）：
- `Containerfile`
- `compose.yaml`（默认使用 podman-compose 风格）
- `nginx/default.conf`
- `.env.example`（容器相关变量）
- `Makefile`（可选：封装常用 Podman 命令）
- `README.md` 的“本地开发/调试/部署”章节

3. 先只生成提案，不直接写入文件：
- 默认输出拟变更内容与示例文件正文。
- 明确标注“待确认后落盘”。
- 仅当用户明确确认“开始落盘/执行写入”时，才实际创建或修改文件。

4. 输出内容必须包含：
- rootless Podman 运行要点（卷权限、UID/GID、可写目录）
- 开发模式、调试模式、生产模式三套命令
- 健康检查与服务依赖说明（app/nginx/db/redis）
- 故障排查清单（日志、容器状态、网络、卷权限）

5. 命令约束：
- 优先输出 Podman / podman-compose 命令，不以 Docker 命令作为主路径。
- 避免 `chmod 777`，优先最小权限与可复现初始化步骤。

6. 输出格式：
- 先给“变更计划摘要”（3-6 条）
- 再给“将创建/修改的文件列表”
- 再给关键文件内容（使用代码块）
- 最后给“执行步骤”和“验证步骤”
- 末尾追加“确认指令示例”：如“确认落盘”或“按方案B落盘”

如果输入信息不足，先用最少问题补齐关键参数，再继续生成。
