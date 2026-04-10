# 部署方案（推荐流程）

### 1. 构建镜像（推荐 root 权限）

```sh
podman build -t haojixing-php:8.4-fpm-pgsql -f Containerfile .
```

### 2. 启动服务

```sh
podman-compose up -d
```

### 3. 环境变量说明

- 参考 .env.example，按需调整数据库、Redis、端口等参数
- APP_ENV/APP_DEBUG/APP_URL/DB_* 等均可覆盖

### 4. 健康检查

- app：php-fpm 配置检查（容器健康）
- nginx：GET /healthz 返回 200
- db：pg_isready
- redis：redis-cli ping

### 5. 一键健康自检

```sh
make smoke-verbose
```

### 6. 常见访问入口

- http://localhost:9001  （nginx 反代入口）
- http://localhost:9001/healthz
- http://localhost:9001/api/v1/groups

---

如遇构建/权限/依赖问题，详见上方“故障排查清单”与“rootless Podman 运行要点”。

更多 API 说明见 docs/api.md。
# haojixing

## 本地开发（Podman rootless）

说明：当前环境若 `podman build` 不可用，会使用预构建 `php:8.4-fpm` 镜像启动；`Containerfile` 仍保留用于可构建环境。

1. 准备变量：
   cp .env.example .env

2. 初始化可写目录权限（避免 777）：
   make init-perms

3. 启动开发环境：
   make dev-up

4. 首次安装与初始化（Laravel 源码接入后执行）：
   make composer cmd="install --no-interaction --prefer-dist"
   make artisan cmd="key:generate"
   make migrate

5. 访问：
   http://localhost:9001

## 调试模式（Xdebug）

1. 启动调试环境：
   make debug-up

2. IDE 监听端口：
   9003

3. 常见设置：
   XDEBUG_CLIENT_HOST=host.containers.internal
   XDEBUG_MODE=debug,develop

## 生产模式（本地模拟）

1. 以生产变量启动（默认关闭调试）：
   make prod-up

2. 建议（上线环境）：
   - 使用只读代码镜像，不挂载源码目录
   - 使用非 root 用户
   - 仅暴露必要端口
   - 环境变量最小化

## 健康检查与服务依赖

- app：php-fpm 配置检查（容器健康）
- nginx：GET /healthz 返回 200
- db：pg_isready
- redis：redis-cli ping

依赖顺序建议：
- app 依赖 db/redis 健康
- nginx 依赖 app 健康

说明：
部分 podman-compose 版本对 depends_on.condition 支持差异较大；若未严格等待健康状态，可先手动确认 db/redis 健康后再执行 Laravel 初始化命令。

## rootless Podman 运行要点

- rootless 模式建议使用 userns keep-id；若为 rootful 模式，使用最小权限 `chown` 初始化写目录
- APP_UID/APP_GID 与宿主机用户对齐（默认 1000，可按实际 id -u/id -g 调整）
- Laravel 仅需 storage 与 bootstrap/cache 可写
- 卷权限优先通过 podman unshare chown 初始化，不使用 chmod 777
- 数据库与 Redis 使用命名卷持久化，避免重建容器丢数据
- 当前编排为兼容部分主机 DNS 差异，容器间默认通过 `host.containers.internal` + 端口映射通信

## 故障排查清单

1. 日志：
   podman-compose logs -f --tail=200
   podman logs haojixing-nginx
   podman logs haojixing-app

2. 容器状态/健康：
   podman-compose ps
   podman inspect haojixing-app --format '{{.State.Health.Status}}'

3. 网络连通：
   podman network ls
   podman exec -it haojixing-app sh -lc "getent hosts host.containers.internal"

4. 卷与权限：
   podman volume ls
   ls -ld storage bootstrap/cache
   make init-perms

5. 端口占用：
   ss -lntp | grep 9001
