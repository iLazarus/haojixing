# 工作区指南

## 适用范围

- 本仓库是 AI 指令工作区，当前可复用规则位于 [instructions](instructions)。
- 本仓库后续会纳入真实 Laravel 源码；从现在起按 Laravel 应用仓库标准组织与执行任务。
- 在源码接入前，允许先维护规范与模板；源码接入后，编码任务默认直接在本仓库完成。

## 核心规范入口

- 交流语言规范：见 [instructions/chinese-first.instructions.md](instructions/chinese-first.instructions.md)。
- Laravel 高并发规范：见 [instructions/laravel-10k-rps.instructions.md](instructions/laravel-10k-rps.instructions.md)。
- 数据库跨引擎与无外键规范：见 [instructions/database-portability-no-fk.instructions.md](instructions/database-portability-no-fk.instructions.md)。
- Debian 12 + Podman Laravel 规范：见 [instructions/debian12-podman-laravel.instructions.md](instructions/debian12-podman-laravel.instructions.md)。
- Git 自动提交规范：见 [instructions/git-auto-commit.instructions.md](instructions/git-auto-commit.instructions.md)。

## 工作流入口

- 启动脚手架提案：见 [prompts/laravel-podman-bootstrap.prompt.md](prompts/laravel-podman-bootstrap.prompt.md)。
- 故障排查提案：见 [prompts/laravel-podman-troubleshoot.prompt.md](prompts/laravel-podman-troubleshoot.prompt.md)。
- 发布运维代理：见 [agents/release-op.agent.md](agents/release-op.agent.md)。

## 全局执行约束

- 默认先提案后落盘：先给计划与变更草案，用户明确确认后再执行写入或副作用命令。
- 容器相关方案默认使用 Podman/podman-compose 路径，不以 Docker 命令作为主路径。
- 权限处理遵循最小权限原则，避免使用 `chmod 777` 作为常规修复手段。

## 构建与测试

- 在源码接入前，指令文件校验默认检查以下内容：
  - YAML frontmatter 语法是否正确
  - `description` 是否清晰且包含可检索关键词
  - 多个指令文件之间是否存在职责重叠或冲突
- 在源码接入后，默认按以下顺序执行（若对应文件存在）：
  - `composer install`
  - `php artisan key:generate`
  - `php artisan migrate --force`
  - `php artisan test`
  - 前端存在时执行 `npm ci && npm run build`

## 目录与边界约定（源码接入后）

- 业务代码优先放在 `app/`、`routes/`、`database/`、`tests/`。
- 容器与部署相关文件优先放在项目根目录（如 `Containerfile`、`compose.yaml`）及 `deploy/`（如后续创建）。
- 新增规范文档优先放在 `instructions/`，避免与业务代码混放。

## 新增指令编写要求

- 一个指令文件只处理一个关注点。
- `description` 使用明确的 "Use when ..." 触发语句，提升按需发现命中率。
- 仅在确有必要时设置 `applyTo`；避免滥用全局 `"**"`。
- 优先链接已有指令文件，不在工作区指南中重复粘贴长篇规则。

## 常见误区

- 在同一作用域同时维护 `copilot-instructions.md` 与 `AGENTS.md`。
- 在本仓库写入其他业务仓库的构建/部署细节，导致信息失真。
- 用泛化描述覆盖已有专项指令，削弱规则可执行性。
