#!/usr/bin/env bash
#
# Nebula Panel — one-shot installer for a blank Ubuntu 22.04 box.
# Downloads the panel from GitHub, installs Nginx + PHP-FPM, deploys to an
# obscured URL prefix, sets permissions, wires up systemd service control
# (sudoers), and the firewall. Optionally provisions HTTPS via certbot.
#
# QUICK INSTALL (downloads everything, no need to clone first):
#
#   curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh | sudo bash
#
# With options (pass env vars before `bash`):
#
#   curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh \
#     | sudo PANEL_PREFIX=random ADMIN_IP=203.0.113.7 DOMAIN=panel.example.com bash
#
# Or clone the repo and run ./install.sh locally (uses local files if found).
#
# Optional overrides (environment variables):
#   REPO=lmb53/nebulapanel   GitHub repo to pull from
#   REPO_REF=main            Branch, tag, or commit to install
#   SOURCE=auto|remote|local Where to get files (default: auto = local else remote)
#   PANEL_PREFIX=myprefix    URL prefix (default: source folder name; "random" = generate)
#   FM_ROOT=/var/www         Directory the File Manager may browse
#   DOMAIN=panel.example.com Provision HTTPS via certbot for this domain
#   ADMIN_IP=203.0.113.7     Restrict panel access to this IP (recommended)
#   WEBROOT=/var/www/html    Nginx document root
#   PANEL_SRC=/path/to/src   Explicit path to the panel source dir (skips download)
#
set -euo pipefail

# --------------------------------------------------------------------------
# 0. Preconditions & configuration
# --------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
  echo "This script must run as root. Try: sudo $0  (or pipe to 'sudo bash')" >&2
  exit 1
fi

REPO="${REPO:-lmb53/nebulapanel}"
REPO_REF="${REPO_REF:-main}"
SOURCE="${SOURCE:-auto}"
WEBROOT="${WEBROOT:-/var/www/html}"
FM_ROOT="${FM_ROOT:-/var/www}"
PANEL_PREFIX="${PANEL_PREFIX:-}"
DOMAIN="${DOMAIN:-}"
ADMIN_IP="${ADMIN_IP:-}"

# Resolve the script's own directory (handles being piped via curl | bash).
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
else
  SCRIPT_DIR="$(pwd)"
fi

# All progress output goes to stderr so functions can safely echo paths.
log()  { printf '\033[1;34m==>\033[0m %s\n' "$*" >&2; }
ok()   { printf '\033[1;32m  ✓\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33m  ! \033[0m%s\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# Clean up any downloaded temp files on exit.
TMP_DL=""
cleanup() { [[ -n "$TMP_DL" && -d "$TMP_DL" ]] && rm -rf "$TMP_DL"; }
trap cleanup EXIT

# --------------------------------------------------------------------------
# 1. Install packages (needed for download + serving)
# --------------------------------------------------------------------------
log "Installing packages (Nginx, PHP-FPM, tooling)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-cli rsync ufw curl ca-certificates tar >/dev/null
ok "Packages installed"

# Detect PHP-FPM version, socket and service name.
FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
if [[ -z "$FPM_SOCK" ]]; then
  systemctl start "php*-fpm" 2>/dev/null || true
  FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
fi
[[ -z "$FPM_SOCK" ]] && die "Could not find a PHP-FPM socket in /run/php/."
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SVC="php${PHP_VER}-fpm"
ok "PHP $PHP_VER  (socket: $FPM_SOCK)"

systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl enable --now "$FPM_SVC" >/dev/null 2>&1 || true

# --------------------------------------------------------------------------
# 2. Obtain the panel source (local checkout or download from GitHub)
# --------------------------------------------------------------------------

# Find a dir that looks like the panel (has index.php + lib/bootstrap.php).
detect_panel_dir() {
  local root="$1" hit
  hit="$(find "$root" -type f -path '*/lib/bootstrap.php' 2>/dev/null | head -1 || true)"
  [[ -z "$hit" ]] && return 1
  local dir; dir="$(dirname "$(dirname "$hit")")"
  [[ -f "$dir/index.php" ]] && { echo "$dir"; return 0; }
  return 1
}

download_source() {
  local url="https://codeload.github.com/${REPO}/tar.gz/${REPO_REF}"
  TMP_DL="$(mktemp -d)"
  log "Downloading ${REPO}@${REPO_REF} from GitHub…"
  if ! curl -fsSL "$url" -o "$TMP_DL/src.tar.gz"; then
    die "Download failed: $url  (check REPO/REPO_REF and that the repo is public)"
  fi
  tar -xzf "$TMP_DL/src.tar.gz" -C "$TMP_DL" || die "Failed to extract the downloaded archive."
  local dir
  dir="$(detect_panel_dir "$TMP_DL")" || die "Downloaded archive did not contain a panel source dir."
  echo "$dir"
}

resolve_source() {
  if [[ -n "${PANEL_SRC:-}" ]]; then
    [[ -f "$PANEL_SRC/index.php" ]] || die "PANEL_SRC=$PANEL_SRC has no index.php."
    echo "$PANEL_SRC"; return
  fi
  local local_dir=""
  if [[ "$SOURCE" != "remote" ]]; then
    local_dir="$(detect_panel_dir "$SCRIPT_DIR" || true)"
  fi
  if [[ -n "$local_dir" && "$SOURCE" != "remote" ]]; then
    log "Using local panel source (no download needed)."
    echo "$local_dir"
  else
    download_source
  fi
}

PANEL_SRC="$(resolve_source)"
SRC_NAME="$(basename "$PANEL_SRC")"
ok "Panel source: $PANEL_SRC"

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
# 3. Deploy the panel files
# --------------------------------------------------------------------------
log "Deploying panel to $DEST…"
mkdir -p "$DEST"
rsync -a --delete \
  --exclude 'data/admin.json' --exclude 'data/audit.log' \
  "$PANEL_SRC"/ "$DEST"/
ok "Files copied"

# Set the File Manager root in config.php if it differs from the default.
if [[ "$FM_ROOT" != "/var/www" ]]; then
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
  if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
       --register-unsafely-without-email --redirect >/dev/null 2>&1; then
    ok "HTTPS enabled for $DOMAIN"
  else
    warn "certbot failed (is DNS for $DOMAIN pointed at this box yet?). Re-run: certbot --nginx -d $DOMAIN"
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
echo "  Source:     ${REPO}@${REPO_REF}"
echo "  FM root:    ${FM_ROOT}"
echo "  PHP-FPM:    ${FPM_SVC}"
[[ -n "$ADMIN_IP" ]] && echo "  Access:     restricted to ${ADMIN_IP}"
[[ -z "$DOMAIN"  ]] && echo "  (No domain given — running over HTTP by IP. Set DOMAIN=... for HTTPS.)"
echo "------------------------------------------------------------"
echo "  NEXT: open the URL and complete the one-time admin setup."
echo "============================================================"
