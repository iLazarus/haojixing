---
description: "Use when developing, debugging, containerizing, or deploying Laravel on Debian 12 with Podman, podman-compose, rootless containers, Nginx, PHP-FPM, MySQL, Redis, and CI/CD container workflows."
name: "Debian12 Podman Laravel Workflow"
---
# Debian 12 + Podman Laravel 规范

- 目标环境固定为 Debian 12；容器工作流默认使用 Podman，不使用 Docker 专有命令或语法。
- 默认采用 rootless Podman；涉及权限时，优先通过 UID/GID 映射与卷挂载参数解决，不以 `chmod 777` 作为常规方案。
- 生成容器方案时，优先给出 `Containerfile` 与 `podman-compose`（或等价 Podman 原生命令），并明确开发、调试、部署三种运行方式。
- Laravel 目录挂载必须保证 `storage` 与 `bootstrap/cache` 可写；优先使用最小权限原则与可复现的初始化命令。
- 开发模式应支持热更新与常用命令：`composer install`、`php artisan migrate`、`php artisan queue:work`、前端构建命令。
- 调试模式需明确 Xdebug（如启用）与端口映射策略；仅在调试环境开启调试扩展，避免污染生产镜像。
- 服务编排默认包含 `app`、`nginx`、`db`、`redis`，并提供健康检查与启动顺序建议，避免“容器已启动但服务不可用”。
- 数据持久化必须使用命名卷或绑定挂载，升级或重建容器时不得丢失数据库与关键业务数据。
- 部署建议优先使用 Podman 原生能力（如 systemd/quadlet）；若给出 CI/CD 示例，命令应可在 Debian 12 上直接执行。
- 生产建议默认开启：只读根文件系统（可行时）、最小镜像、非 root 用户、明确暴露端口、环境变量最小化。

## 输出要求

- 给出命令时，默认提供 Podman 版本，不以 Docker 命令作为主路径。
- 涉及多服务运行时，优先提供可直接执行的最小示例（命令或编排片段）。
- 涉及故障排查时，至少覆盖日志查看、容器状态、网络连通、卷权限四类检查点。
