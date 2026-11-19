#!/usr/bin/env bash
# Template variables are consumed through eval (render), which static
# analysis cannot follow:
# shellcheck disable=SC2034
# Renders the Nginx configurations the helper and installer write (website
# vhosts, the separate web-app server, the panel server) and checks them with
# `nginx -t`, then exercises the pure text filters the security migration
# uses on configurations written by older releases. Needs no root.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HELPER="$ROOT/panel/bin/nebula-helper"
INSTALLER="$ROOT/install.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fails=0
ok()  { echo "ok - $1"; }
bad() { echo "not ok - $1"; fails=$((fails + 1)); }
check() { local why="$1"; shift; if "$@"; then ok "$why"; else bad "$why"; fi; }

# Pull a top-level function or a heredoc body out of a script.
func() { sed -n "/^$1() {/,/^}/p" "$HELPER"; }
heredoc() { # file, exact opening line, occurrence (1-based)
  awk -v start="$2" -v want="${3:-1}" '$0 == start {n++; if (n == want) {on=1; next}} on && $0 == "EOF" {exit} on {print}' "$1"
}
render() { # expand a template's variables in the current shell
  local body; body="$(cat)"
  eval "cat <<EOF
${body}
EOF"
}

eval "$(grep -m1 '^SITE_DISABLE_FUNCTIONS=' "$HELPER")"
eval "$(func merge_disabled_functions)"
eval "$(func panel_vhost_lockdown_filter)"
eval "$(func site_vhost_symlink_filter)"
eval "$(func site_pool_harden_filter)"

# --- templates ---------------------------------------------------------------
SITE_ID=0123456789abcdef0123456789abcdef
DOMAIN=site.example.test
SITE_PUBLIC="/srv/nebula/sites/$SITE_ID/public"
SOCK="/run/php/nebula-site-$SITE_ID.sock"
heredoc "$HELPER" '    cat > "$NGINX_AV/$DOMAIN" <<EOF' 1 | render > "$WORK/site.conf"
check "site vhost template found" grep -q "Site ID: $SITE_ID" "$WORK/site.conf"
check "site vhost refuses cross-owner symlinks" grep -Eq '^[[:space:]]*disable_symlinks if_not_owner from=\$document_root;' "$WORK/site.conf"

PFX="$WORK/nginx"; mkdir -p "$PFX/conf/snippets" "$PFX/sites" "$PFX/logs" "$PFX/www"
openssl req -x509 -nodes -newkey rsa:2048 -days 1 -subj /CN=test -keyout "$PFX/key.pem" -out "$PFX/cert.pem" >/dev/null 2>&1
listen="    listen ${WEBAPPS_PORT:-2096} ssl;
    listen [::]:${WEBAPPS_PORT:-2096} ssl;"
cert="$PFX/cert.pem"; key="$PFX/key.pem"; webroot="$PFX/www"
access="        allow 203.0.113.7;
        deny all;"
heredoc "$HELPER" '  cat > "$tmp" <<EOF' 1 | render > "$WORK/webapps.conf"
check "web-app server template found" grep -q 'bundled web apps' "$WORK/webapps.conf"
check "web-app server uses its own port" grep -q 'listen 2096 ssl;' "$WORK/webapps.conf"
check "web-app server never serves the panel" grep -q 'location / { return 404; }' "$WORK/webapps.conf"
check "phpMyAdmin keeps the admin IP allowlist" grep -q 'allow 203.0.113.7;' "$WORK/webapps.conf"

PANEL_PREFIX=abcdef0123456789abcdef01
PANEL_LISTEN=$'    listen 80 default_server;\n    listen 443 ssl default_server;'
PANEL_TLS="    ssl_certificate $PFX/cert.pem;
    ssl_certificate_key $PFX/key.pem;"
PANEL_HTTP_REDIRECT='    if ($scheme = http) { return 308 https://$host$request_uri; }'
SERVER_NAME=_; WEBROOT="$PFX/www"; ACCESS_RULES="$access"
PANEL_FPM_SOCK=/run/php/nebula-panel.sock; WEBAPPS_FPM_SOCK=/run/php/nebula-webapps.sock
heredoc "$INSTALLER" 'cat > /etc/nginx/sites-available/nebula <<EOF' 1 | render > "$WORK/panel.conf"
check "panel server template found" grep -q "location /${PANEL_PREFIX}/ {" "$WORK/panel.conf"
check "panel server refuses cross-owner symlinks" grep -q 'NEBULA_DISABLE_SYMLINKS' "$WORK/panel.conf"
check "panel server does not serve the web apps" grep -q 'NEBULA_WEBAPPS_DENY' "$WORK/panel.conf"

# --- nginx -t ------------------------------------------------------------------
nginx_test() {
  cat > "$PFX/conf/nginx.conf" <<CONF
pid $PFX/nginx.pid;
error_log $PFX/logs/error.log;
events {}
http {
    access_log off;
    include $PFX/sites/*;
}
CONF
  printf 'fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n' > "$PFX/conf/snippets/fastcgi-php.conf"
  nginx -t -q -p "$PFX/" -c "$PFX/conf/nginx.conf" -g "error_log $PFX/logs/error.log;" 2>"$WORK/nginx.err"
}
# nginx -t binds every listen address, so the check runs on unprivileged
# ports (and IPv4 only: some CI hosts have no IPv6).
use_site() {
  sed -E -e "s#/var/log/nginx/#$PFX/logs/#g" -e "/listen \[::/d" \
    -e "s/listen (127\.0\.0\.1:)?80([ ;])/listen \1${TEST_PORT_BASE}80\2/" \
    -e "s/listen (127\.0\.0\.1:)?443([ ;])/listen \1${TEST_PORT_BASE}43\2/" \
    -e "s/listen (127\.0\.0\.1:)?2096([ ;])/listen \1${TEST_PORT_BASE}96\2/" \
    "$1" > "$PFX/sites/$(basename "$1")"
}
TEST_PORT_BASE="$(( (RANDOM % 300) + 200 ))"
if command -v nginx >/dev/null 2>&1; then
  use_site "$WORK/site.conf"; use_site "$WORK/webapps.conf"; use_site "$WORK/panel.conf"
  if nginx_test; then ok "nginx accepts the site, web-app and panel servers together"; else bad "nginx accepts the site, web-app and panel servers together"; cat "$WORK/nginx.err"; fi
else
  echo "skip - nginx is not installed; configuration syntax not checked"
fi

# --- migration filters ------------------------------------------------------------
# A panel server written by an older installer: no markers.
grep -v 'NEBULA_DISABLE_SYMLINKS\|NEBULA_WEBAPPS_DENY\|Bundled web apps are served' "$WORK/panel.conf" > "$WORK/panel-old.conf"
panel_vhost_lockdown_filter < "$WORK/panel-old.conf" > "$WORK/panel-locked.conf"
check "lockdown adds the web-app deny once" test "$(grep -c NEBULA_WEBAPPS_DENY "$WORK/panel-locked.conf")" = 1
check "lockdown adds the symlink guard once" test "$(grep -c NEBULA_DISABLE_SYMLINKS "$WORK/panel-locked.conf")" = 1
panel_vhost_lockdown_filter < "$WORK/panel-locked.conf" > "$WORK/panel-locked2.conf"
check "lockdown is idempotent" cmp -s "$WORK/panel-locked.conf" "$WORK/panel-locked2.conf"
panel_vhost_lockdown_filter < "$WORK/panel.conf" > "$WORK/panel-new.conf"
check "lockdown leaves a current panel server unchanged" cmp -s "$WORK/panel.conf" "$WORK/panel-new.conf"
deny_line="$(grep -n NEBULA_WEBAPPS_DENY "$WORK/panel-locked.conf" | cut -d: -f1)"
php_line="$(grep -n 'location ~ \\.php\$ {' "$WORK/panel-locked.conf" | tail -1 | cut -d: -f1)"
check "the web-app deny precedes the generic PHP location" test "$deny_line" -lt "$php_line"

# A website vhost from an older release, after certbot added an HTTPS block.
grep -v disable_symlinks "$WORK/site.conf" > "$WORK/site-old.conf"
cat >> "$WORK/site-old.conf" <<OLD
server {
    listen 443 ssl;
    server_name $DOMAIN;
    root $SITE_PUBLIC;
    ssl_certificate $PFX/cert.pem;
    ssl_certificate_key $PFX/key.pem;
}
OLD
site_vhost_symlink_filter "$SITE_PUBLIC" < "$WORK/site-old.conf" > "$WORK/site-hardened.conf"
check "every server block of a site gains the symlink guard" test "$(grep -c 'disable_symlinks if_not_owner' "$WORK/site-hardened.conf")" = 2
site_vhost_symlink_filter "/srv/nebula/sites/ffffffffffffffffffffffffffffffff/public" < "$WORK/site-old.conf" > "$WORK/site-other.conf"
check "the filter only touches the site's own root" cmp -s "$WORK/site-old.conf" "$WORK/site-other.conf"
if command -v nginx >/dev/null 2>&1; then
  rm -f "$PFX/sites/"*; use_site "$WORK/site-hardened.conf"
  if nginx_test; then ok "nginx accepts a migrated website vhost"; else bad "nginx accepts a migrated website vhost"; cat "$WORK/nginx.err"; fi
  rm -f "$PFX/sites/"*; use_site "$WORK/panel-locked.conf"; use_site "$WORK/webapps.conf"
  if nginx_test; then ok "nginx accepts a migrated panel server"; else bad "nginx accepts a migrated panel server"; cat "$WORK/nginx.err"; fi
fi

# An FPM pool written by an older release.
BASE="/srv/nebula/sites/$SITE_ID"
cat > "$WORK/pool-old.conf" <<POOL
[nebula-site-$SITE_ID]
user = nebula-s-0123456789ab
php_admin_value[open_basedir] = $BASE:/tmp
php_admin_value[upload_tmp_dir] = $BASE/tmp
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
POOL
merged="$(merge_disabled_functions "exec,passthru,shell_exec,system,proc_open,popen")"
site_pool_harden_filter "$BASE" "$merged" yes < "$WORK/pool-old.conf" > "$WORK/pool-new.conf"
check "open_basedir no longer includes the shared /tmp" grep -qx "php_admin_value\[open_basedir\] = $BASE" "$WORK/pool-new.conf"
check "putenv is disabled" grep -q '^php_admin_value\[disable_functions\] = .*putenv' "$WORK/pool-new.conf"
check "a private sys_temp_dir is set" grep -qx "php_admin_value\[sys_temp_dir\] = $BASE/tmp" "$WORK/pool-new.conf"
site_pool_harden_filter "$BASE" "$merged" yes < "$WORK/pool-new.conf" > "$WORK/pool-again.conf"
check "pool hardening is idempotent" cmp -s "$WORK/pool-new.conf" "$WORK/pool-again.conf"
check "an administrator's extra disabled functions are kept" test "$(merge_disabled_functions 'exec,mail')" = "exec,mail,passthru,shell_exec,system,proc_open,popen,putenv,pcntl_exec"
sed 's#:/tmp$#:/tmp:/opt/shared#' "$WORK/pool-old.conf" > "$WORK/pool-custom.conf"
site_pool_harden_filter "$BASE" "$merged" no < "$WORK/pool-custom.conf" > "$WORK/pool-custom-new.conf"
check "a customised open_basedir is left alone" grep -q ":/tmp:/opt/shared$" "$WORK/pool-custom-new.conf"
check "no sys_temp_dir without a private temp folder" bash -c "! grep -q sys_temp_dir '$WORK/pool-custom-new.conf'"

# --- tenant-path guard (SFTP .ssh) ----------------------------------------------
eval "$(grep -m1 '^die() ' "$HELPER")"
eval "$(func site_own_dir_check)"
SITE_USER="$(id -un)"
G="$WORK/guard"; mkdir -p "$G/real" "$G/target"
ln -s "$G/target" "$G/link"; : > "$G/file"
guard() { ( site_own_dir_check "$1" ) >/dev/null 2>&1; }
check "a missing .ssh is accepted (it is then created as the site user)" guard "$G/missing"
check "a real folder of the site's own is accepted" guard "$G/real"
refused() { if ( SITE_USER="${2:-$SITE_USER}"; site_own_dir_check "$1" ) >/dev/null 2>&1; then return 1; fi; }
check "a symlinked .ssh is refused" refused "$G/link"
check "a file named .ssh is refused" refused "$G/file"
check "a folder owned by someone else is refused" refused "$G/real" nebula-nobody-here

if (( fails )); then echo "$fails helper configuration check(s) failed" >&2; exit 1; fi
echo "helper configuration checks passed"
