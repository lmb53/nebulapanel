#!/usr/bin/env bash
#
# Nebula Panel — one-shot installer for a blank Ubuntu 22.04 box.
# Installs Nginx + PHP-FPM, deploys the panel to an obscured URL prefix,
# sets permissions, wires up systemd service control (sudoers), and the
# firewall. Optionally provisions HTTPS with certbot if a domain is given.
#
# Usage (run on the server as root or with sudo, from the folder that
# contains this script AND the panel source directory):
#
#   sudo ./install.sh
#
# Optional overrides (environment variables):
#   PANEL_PREFIX=myprefix   Use a specific URL prefix (default: keep source
#                           folder name; set PANEL_PREFIX=random to regenerate)
#   FM_ROOT=/var/www        Directory the File Manager may browse
#   DOMAIN=panel.example.com Provision HTTPS via certbot for this domain
#   ADMIN_IP=203.0.113.7    Restrict access to this IP (recommended)
#   WEBROOT=/var/www/html   Nginx document root
#   PANEL_SRC=/path/to/src  Path to the panel source dir (auto-detected otherwise)
#
set -euo pipefail

# --------------------------------------------------------------------------
# 0. Preconditions & configuration
# --------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
  echo "This script must run as root. Try: sudo $0" >&2
  exit 1
fi

WEBROOT="${WEBROOT:-/var/www/html}"
FM_ROOT="${FM_ROOT:-/var/www}"
PANEL_PREFIX="${PANEL_PREFIX:-}"
DOMAIN="${DOMAIN:-}"
ADMIN_IP="${ADMIN_IP:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  ! \033[0m%s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# --------------------------------------------------------------------------
# 1. Locate the panel source directory
# --------------------------------------------------------------------------
find_panel_src() {
  [[ -n "${PANEL_SRC:-}" ]] && { echo "$PANEL_SRC"; return; }
  # A valid source dir contains index.php and lib/bootstrap.php.
  local d
  for d in "$SCRIPT_DIR"/*/; do
    if [[ -f "${d}index.php" && -f "${d}lib/bootstrap.php" ]]; then
      echo "${d%/}"; return
    fi
  done
  return 1
}

PANEL_SRC="$(find_panel_src)" || die "Could not find the panel source dir (a folder with index.php + lib/bootstrap.php) next to this script. Set PANEL_SRC=/path."
SRC_NAME="$(basename "$PANEL_SRC")"
ok "Found panel source: $PANEL_SRC"

# Decide the deployed prefix (obscured directory name):
#   PANEL_PREFIX unset  -> keep the source folder name
#   PANEL_PREFIX=random -> generate a fresh 12-char random prefix
#   PANEL_PREFIX=foo    -> use "foo"
if [[ "$PANEL_PREFIX" == "random" ]]; then
  PANEL_PREFIX="$(tr -dc 'a-z0-9' </dev/urandom | head -c 12)"
elif [[ -z "$PANEL_PREFIX" ]]; then
  PANEL_PREFIX="$SRC_NAME"
fi
DEST="$WEBROOT/$PANEL_PREFIX"

# --------------------------------------------------------------------------
# 2. Install packages
# --------------------------------------------------------------------------
log "Installing Nginx, PHP-FPM and tooling…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-cli rsync ufw >/dev/null
ok "Packages installed"

# Detect PHP-FPM version, socket and service name.
FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
[[ -z "$FPM_SOCK" ]] && { systemctl start "php*-fpm" 2>/dev/null || true; FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"; }
[[ -z "$FPM_SOCK" ]] && die "Could not find a PHP-FPM socket in /run/php/."
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SVC="php${PHP_VER}-fpm"
ok "PHP $PHP_VER  (socket: $FPM_SOCK)"

systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl enable --now "$FPM_SVC" >/dev/null 2>&1 || true

# --------------------------------------------------------------------------
# 3. Deploy the panel files
# --------------------------------------------------------------------------
log "Deploying panel to $DEST…"
mkdir -p "$DEST"
rsync -a --delete --exclude 'data/admin.json' --exclude 'data/audit.log' "$PANEL_SRC"/ "$DEST"/
ok "Files copied"

# Set the File Manager root in config.php if it differs from the default.
if [[ "$FM_ROOT" != "/var/www" ]]; then
  # Replace the fallback default; use | as delimiter since paths contain /.
  sed -i "s|?: '/var/www'|?: '${FM_ROOT}'|" "$DEST/config.php"
  ok "File Manager root set to $FM_ROOT"
fi

# --------------------------------------------------------------------------
# 4. Permissions
# --------------------------------------------------------------------------
log "Setting ownership and permissions…"
chown -R www-data:www-data "$DEST"
find "$DEST" -type d -exec chmod 755 {} \;
find "$DEST" -type f -exec chmod 644 {} \;
mkdir -p "$DEST/data"
chown www-data:www-data "$DEST/data"
chmod 700 "$DEST/data"
ok "Permissions applied (data/ is private, web-writable)"

# --------------------------------------------------------------------------
# 5. Nginx site
# --------------------------------------------------------------------------
log "Writing Nginx configuration…"
SERVER_NAME="${DOMAIN:-_}"
ACCESS_RULES=""
if [[ -n "$ADMIN_IP" ]]; then
  ACCESS_RULES=$'        allow '"$ADMIN_IP"$';\n        deny all;'
fi

cat > /etc/nginx/sites-available/nebula <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};
    root ${WEBROOT};
    index index.php index.html;

    # Never serve the panel's private directories.
    location ~ ^/${PANEL_PREFIX}/(data|lib|views)/ { deny all; return 404; }

    location /${PANEL_PREFIX}/ {
${ACCESS_RULES}
        try_files \$uri \$uri/ /${PANEL_PREFIX}/index.php\$is_args\$args;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
    }
}
EOF

ln -sf /etc/nginx/sites-available/nebula /etc/nginx/sites-enabled/nebula
rm -f /etc/nginx/sites-enabled/default
nginx -t >/dev/null 2>&1 || die "Nginx config test failed. Run 'nginx -t' to see why."
systemctl reload nginx
ok "Nginx configured and reloaded"

# --------------------------------------------------------------------------
# 6. Service control via sudoers (start/stop/restart only)
# --------------------------------------------------------------------------
log "Granting www-data permission to control services…"
SYSTEMCTL="$(command -v systemctl)"
SUDOERS=/etc/sudoers.d/nebula-panel
cat > "$SUDOERS" <<EOF
# Allow the web server to start/stop/restart services (no password),
# and nothing else. Installed by Nebula Panel installer.
www-data ALL=(root) NOPASSWD: ${SYSTEMCTL} start *, ${SYSTEMCTL} stop *, ${SYSTEMCTL} restart *
EOF
chmod 440 "$SUDOERS"
if ! visudo -cf "$SUDOERS" >/dev/null 2>&1; then
  rm -f "$SUDOERS"
  die "Generated sudoers file was invalid and has been removed."
fi
ok "sudoers rule installed ($SUDOERS)"

# --------------------------------------------------------------------------
# 7. Firewall
# --------------------------------------------------------------------------
log "Configuring UFW firewall…"
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 'Nginx Full' >/dev/null 2>&1 || true
yes | ufw enable >/dev/null 2>&1 || true
ok "Firewall allows SSH + HTTP/HTTPS"

# --------------------------------------------------------------------------
# 8. Optional HTTPS
# --------------------------------------------------------------------------
if [[ -n "$DOMAIN" ]]; then
  log "Provisioning HTTPS for $DOMAIN via certbot…"
  apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
  if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect >/dev/null 2>&1; then
    ok "HTTPS enabled for $DOMAIN"
  else
    warn "certbot failed (DNS not pointed at this box yet?). You can re-run: certbot --nginx -d $DOMAIN"
  fi
fi

# --------------------------------------------------------------------------
# 9. Summary
# --------------------------------------------------------------------------
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
SCHEME="http"; HOST="${IP:-YOUR_IP}"
[[ -n "$DOMAIN" ]] && { SCHEME="https"; HOST="$DOMAIN"; }

echo
echo "============================================================"
echo "  Nebula Panel is installed."
echo "------------------------------------------------------------"
echo "  URL:        ${SCHEME}://${HOST}/${PANEL_PREFIX}/"
echo "  Deployed:   ${DEST}"
echo "  FM root:    ${FM_ROOT}"
echo "  PHP-FPM:    ${FPM_SVC}"
[[ -n "$ADMIN_IP" ]] && echo "  Access:     restricted to ${ADMIN_IP}"
[[ -z "$DOMAIN"  ]] && echo "  (No domain given — running over HTTP by IP. Set DOMAIN=... for HTTPS.)"
echo "------------------------------------------------------------"
echo "  NEXT: open the URL and complete the one-time admin setup."
echo "============================================================"
