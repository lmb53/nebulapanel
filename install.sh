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
#   PANEL_PREFIX=myprefix    Fixed URL prefix (default: fresh random hex each run)
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
SUDOERS_TMP=""
cleanup() {
  [[ -n "$TMP_DL" && -d "$TMP_DL" ]] && rm -rf "$TMP_DL"
  [[ -n "$SUDOERS_TMP" && -f "$SUDOERS_TMP" ]] && rm -f "$SUDOERS_TMP"
  return 0
}
trap cleanup EXIT

# --------------------------------------------------------------------------
# 1. Install packages (needed for download + serving)
# --------------------------------------------------------------------------
log "Installing packages (Nginx, PHP-FPM, tooling)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-cli php-mysql php-curl php-mbstring php-xml php-zip \
  rsync ufw sudo curl ca-certificates tar \
  certbot python3-certbot-nginx >/dev/null
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

# Decide the deployed directory below the public HTML root:
#   PANEL_PREFIX unset / "random" -> fresh random hex prefix (DEFAULT)
#   PANEL_PREFIX=foo              -> use "foo" (fixed, e.g. to redeploy in place)
# `od` reads a fixed number of bytes, avoiding the SIGPIPE failure caused by
# `/dev/urandom | head` when `set -o pipefail` is active.
[[ "$WEBROOT" == /* ]] || die "WEBROOT must be an absolute path."
mkdir -p "$WEBROOT"
WEBROOT="$(readlink -f "$WEBROOT")"

if [[ -z "$PANEL_PREFIX" || "$PANEL_PREFIX" == "random" ]]; then
  for _attempt in {1..10}; do
    PANEL_PREFIX="$(od -An -N12 -tx1 /dev/urandom | tr -d '[:space:]')"
    [[ ! -e "$WEBROOT/$PANEL_PREFIX" ]] && break
    PANEL_PREFIX=""
  done
  [[ -n "$PANEL_PREFIX" ]] || die "Could not allocate a unique random panel directory."
elif [[ ! "$PANEL_PREFIX" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{2,63}$ ]]; then
  die "PANEL_PREFIX must be 3-64 letters, numbers, dashes, or underscores."
fi
DEST="$WEBROOT/$PANEL_PREFIX"

# --------------------------------------------------------------------------
# 3. Deploy the panel files
# --------------------------------------------------------------------------
log "Deploying panel to public directory $DEST…"
mkdir -p "$DEST"
rsync -a --delete \
  --exclude 'data/' \
  "$PANEL_SRC"/ "$DEST"/
# Runtime state survives a fixed-prefix reinstall. On a fresh random install,
# seed only the web-denial/ignore guards from the source data directory.
mkdir -p "$DEST/data"
for _guard in .htaccess .gitignore; do
  [[ -f "$PANEL_SRC/data/$_guard" ]] && cp -f "$PANEL_SRC/data/$_guard" "$DEST/data/$_guard"
done
ok "Files copied"

# Fail before configuring the server if a release archive is incomplete.
for _required in index.php lib/bootstrap.php lib/sys.php lib/mod_api.php \
                 api/apps.php api/updates.php api/provision.php api/tokens.php \
                 api/notifications.php api/sshkeys.php assets/app.js assets/style.css; do
  [[ -f "$DEST/$_required" ]] || die "Deployed source is incomplete: missing $_required"
done

# Integrity check: catch a stale/incomplete source (the usual cause of
# "View not found" errors in the panel). Every routed view must be present.
_missing=""
for v in setup-wizard dashboard websites domains dns files services databases phpmyadmin \
         ssl php cron firewall logs updates users sshkeys docker backups terminal \
         monitoring sysinfo diagnostics notifications api apps selfupdate settings service \
         file-view file-edit login setup layout; do
  [[ -f "$DEST/views/$v.php" ]] || _missing="$_missing $v"
done
if [[ -n "$_missing" ]]; then
  warn "Source is missing views:${_missing}"
  warn "Your repo (${REPO}@${REPO_REF}) is out of date — commit & push ALL files, then reinstall."
else
  ok "All panel views present ($(ls "$DEST"/views/*.php | wc -l | tr -d ' ') files)"
fi

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

# Record the installed commit SHA so the Panel Updates page has a baseline.
SHA="$(curl -fsSL -H 'User-Agent: NebulaPanel' \
        "https://api.github.com/repos/${REPO}/commits/${REPO_REF}" 2>/dev/null \
        | grep -m1 '"sha"' | sed -E 's/.*"sha": ?"([a-f0-9]+)".*/\1/')"
if [[ -n "$SHA" ]]; then
  printf '{\n  "sha": "%s",\n  "ref": "%s",\n  "applied_at": "%s"\n}\n' \
    "$SHA" "$REPO_REF" "$(date -Iseconds 2>/dev/null || date)" > "$DEST/data/version.json"
  chown www-data:www-data "$DEST/data/version.json"
  chmod 600 "$DEST/data/version.json"
  ok "Recorded version baseline (${SHA:0:12})"
fi

# Install the privileged helper root-owned, OUTSIDE the web-writable tree, so
# the web user can't modify what it runs as root.
if [[ -f "$DEST/bin/nebula-helper" ]]; then
  install -m 0755 -o root -g root "$DEST/bin/nebula-helper" /usr/local/bin/nebula-helper
  ok "Installed privileged helper (/usr/local/bin/nebula-helper)"
else
  warn "bin/nebula-helper missing from source — Websites/phpMyAdmin will be limited."
fi

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
    # Keep the panel as the IP-address fallback after hosted vhosts are added.
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${SERVER_NAME};
    root ${WEBROOT};
    index index.php index.html;

    # Never serve the panel's private directories.
    location ~ ^/${PANEL_PREFIX}/(api|data|lib|views|bin)/ { deny all; return 404; }
    location = /${PANEL_PREFIX}/config.php { deny all; return 404; }

    location /${PANEL_PREFIX}/ {
${ACCESS_RULES}
        try_files \$uri \$uri/ /${PANEL_PREFIX}/index.php\$is_args\$args;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
    }

    # Do not serve dotfiles from the panel or hosted websites.
    location ~ /\.(?!well-known).* { deny all; return 404; }
}
EOF

ln -sf /etc/nginx/sites-available/nebula /etc/nginx/sites-enabled/nebula
rm -f /etc/nginx/sites-enabled/default
nginx -t >/dev/null 2>&1 || die "Nginx config test failed. Run 'nginx -t' to see why."
systemctl reload nginx
ok "Nginx configured and reloaded"

# --------------------------------------------------------------------------
# 6. Grant scoped root to the web user via sudoers
# --------------------------------------------------------------------------
# The panel drives real tools (systemctl, ufw, apt-get, docker, mysql, tar,
# journalctl). Each needs passwordless sudo. NOTE: several of these (tar,
# docker, mysql, apt-get) effectively confer broad root power — that is
# inherent to a server control panel. This is exactly why the panel must sit
# behind the obscured prefix + HTTPS + an IP allow-list.
log "Granting www-data scoped root via sudoers…"
SUDOERS=/etc/sudoers.d/nebula-panel
SUDOERS_TMP="$(mktemp)"
{
  echo "# Nebula Panel — passwordless root for the web user, scoped to the"
  echo "# commands the panel uses. Keep the panel behind HTTPS + IP allow-list."
} > "$SUDOERS_TMP"

# systemctl: lifecycle actions exposed by the Services page.
SC="$(command -v systemctl || true)"
[[ -n "$SC" ]] && echo "www-data ALL=(root) NOPASSWD: $SC start *, $SC stop *, $SC restart *, $SC enable *, $SC disable *" >> "$SUDOERS_TMP"

# Add a rule for an installed binary, or its standard Ubuntu path so apps
# installed later by the panel are immediately manageable.
sudo_line() {
  local bin="$1" fallback="$2" tag="${3:-}" p
  p="$(command -v "$bin" 2>/dev/null || true)"
  [[ -n "$p" ]] || p="$fallback"
  echo "www-data ALL=(root) NOPASSWD:${tag:+$tag:} $p *" >> "$SUDOERS_TMP"
  return 0
}
sudo_line ufw        /usr/sbin/ufw
sudo_line docker     /usr/bin/docker
sudo_line mysql      /usr/bin/mysql
sudo_line journalctl /usr/bin/journalctl
sudo_line tar        /usr/bin/tar
sudo_line apt-get    /usr/bin/apt-get SETENV   # SETENV permits DEBIAN_FRONTEND

# The privileged helper: a single tight entry that covers vhost/SSL/phpMyAdmin
# operations, instead of granting tee/ln/mkdir/certbot broadly.
[[ -x /usr/local/bin/nebula-helper ]] && \
  echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/nebula-helper *" >> "$SUDOERS_TMP"

chmod 440 "$SUDOERS_TMP"
if ! visudo -cf "$SUDOERS_TMP" >/dev/null 2>&1; then
  die "Generated sudoers file is invalid; the existing configuration was left untouched."
fi
install -m 0440 -o root -g root "$SUDOERS_TMP" "$SUDOERS"
rm -f "$SUDOERS_TMP"
SUDOERS_TMP=""

# Catch a partial/ineffective sudoers deployment during installation rather
# than deferring the failure to the web UI.
sudo -u www-data sudo -n /usr/bin/apt-get --version >/dev/null 2>&1 \
  || die "apt-get sudo rule verification failed ($SUDOERS)."
sudo -u www-data sudo -n /usr/local/bin/nebula-helper php-versions >/dev/null 2>&1 \
  || die "privileged helper sudo rule verification failed ($SUDOERS)."
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
