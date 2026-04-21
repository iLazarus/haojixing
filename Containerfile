FROM php:8.4-fpm-bookworm

ARG APP_UID=1000
ARG APP_GID=1000
ARG ENABLE_XDEBUG=0

# 安装 Laravel 常用依赖与 PostgreSQL 扩展
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates libpq-dev libzip-dev libonig-dev libfcgi-bin \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

# 关闭 php-fpm 访问日志，避免容器控制台输出 "POST /index.php" 这类访问行
RUN sed -ri 's|^access\.log[[:space:]]*=.*$|access.log = /dev/null|' /usr/local/etc/php-fpm.d/docker.conf

# 调试扩展默认关闭，仅在构建参数启用
RUN if [ "$ENABLE_XDEBUG" = "1" ]; then \
      pecl install xdebug && docker-php-ext-enable xdebug; \
    fi

# 引入 Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 创建与宿主机可对齐的非 root 用户
RUN groupmod -o -g "${APP_GID}" www-data \
    && usermod -o -u "${APP_UID}" -g "${APP_GID}" www-data

# PHP-FPM 健康探针相关配置
RUN { \
      echo '[www]'; \
    echo 'access.log = /dev/null'; \
      echo 'ping.path = /fpm-ping'; \
      echo 'pm.status_path = /fpm-status'; \
    } > /usr/local/etc/php-fpm.d/zz-healthcheck.conf

WORKDIR /var/www/html

# 先复制依赖声明，提升层缓存命中（源码接入后生效）
COPY --chown=www-data:www-data composer.json composer.lock* ./
RUN if [ -f composer.json ]; then composer install --no-interaction --prefer-dist --no-scripts || true; fi

# 再复制业务代码
COPY --chown=www-data:www-data . .

# Laravel 必需可写目录（最小权限，不使用 777）
RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm", "-F"]
