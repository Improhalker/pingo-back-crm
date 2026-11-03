#!/bin/sh
set -eu
umask 077
cd "$(dirname "$0")/.."
target=.env.production
if [ -e "$target" ]; then
    echo 'Ambiente existente preservado. Não regenere APP_KEY ou senhas de serviços em uso.' >&2
    exit 1
fi
command -v openssl >/dev/null
# noclobber also protects against a concurrent invocation.
set -C
cat deploy/production.env.example > "$target"
set +C
key="$(openssl rand -base64 32 | tr -d '\n')"
db_password="$(openssl rand -hex 32)"
root_password="$(openssl rand -hex 32)"
redis_password="$(openssl rand -hex 32)"
sed -i \
    -e "s|^APP_KEY=.*|APP_KEY=base64:$key|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|" \
    -e "s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=$root_password|" \
    -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$redis_password|" \
    "$target"
chmod 600 "$target"
echo 'Criado .env.production com segredos locais. Preencha release, domínios e proxies antes de iniciar.'
