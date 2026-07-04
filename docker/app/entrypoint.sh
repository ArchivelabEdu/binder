#!/bin/sh
set -e
cd /app

# Runtime config files (these are gitignored, so create them on first boot).
[ -f config/config.php ] || cp /usr/local/share/binder-config.php config/config.php
if [ ! -f apps/qubit/config/settings.yml ]; then
  sed '/^[[:space:]]\{1,\}no_script_name:/ s/false/true/' \
    apps/qubit/config/settings.yml.tmpl > apps/qubit/config/settings.yml
fi

# Point sfMemcacheCache and gearman at their compose services (defaults are
# localhost). Regenerated on every boot — these are dev-only overrides.
cat > apps/qubit/config/app.yml <<'YML'
all:
  cache_engine:
    class: sfMemcacheCache
    param:
      storeCacheInfo: true
      host: memcached
      port: 11211
  gearman_job_server: gearmand:4730
  # SWORD deposits by reference (Content-Location file://<basename>) resolve
  # under this dir — a shared bind mount so app and worker see the same path.
  sword_deposit_dir: /app/uploads/sword-deposits
YML

cat > apps/qubit/config/gearman.yml <<'YML'
all:
  server:
    default:
      host: gearmand
      port: 4730
  worker:
    sword: [qtSwordPluginWorker]
YML

# Point Elastica at the compose ES service (plugin default is 127.0.0.1).
if [ ! -f config/search.yml ]; then
  cat > config/search.yml <<'YML'
all:
  server:
    host: es
    port: 9200
YML
fi

# Symfony needs these writable. Clear stale config caches: the overrides
# regenerated above are compiled into cache/ and would otherwise stick.
mkdir -p cache log uploads downloads
rm -rf cache/qubit
chmod -R 0777 cache log uploads downloads 2>/dev/null || true

# Let container env (ARCHIVEMATICA_SS_*, etc.) reach php-fpm workers.
echo "clear_env = no" > /usr/local/etc/php-fpm.d/zz-binder-env.conf

# php-fpm in background, nginx in foreground (keeps container alive).
php-fpm -D
exec nginx
