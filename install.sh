#!/usr/bin/env bash
#
# Nebula Panel — one-shot installer for a blank Ubuntu 22.04 box.
# Downloads the panel from GitHub, installs Nginx + PHP-FPM, deploys to an
# obscured URL prefix, sets permissions, wires up systemd service control
# (sudoers), and the firewall. Optionally provisions HTTPS via certbot.
#
# REVIEWED INSTALL:
#
#   git clone https://github.com/lmb53/nebulapanel.git
#   cd nebulapanel && git checkout <reviewed-tag-or-commit>
#   sudo DOMAIN=panel.example.com ADMIN_IP=203.0.113.7 ./install.sh
#
# QUICK REMOTE INSTALL:
#
#   curl -fsSL https://raw.githubusercontent.com/lmb53/nebulapanel/main/install.sh |
#     sudo env SOURCE=remote bash
#
# Optional overrides (environment variables):
#   REPO=lmb53/nebulapanel   GitHub repo to pull from
#   REPO_REF=main            Branch, tag, or commit to install
#   SOURCE=auto|remote|local Where to get files (default: auto = local else remote)
#   PANEL_PREFIX=myprefix    Fixed URL prefix (default: reuse active install, random on first run)
#   FM_ROOT=/srv/nebula/sites Directory the File Manager may browse
#   DOMAIN=panel.example.com Provision HTTPS via certbot for this domain
#   ADMIN_IP=203.0.113.7     Restrict panel access to this IP (recommended)
#   PUBLIC_IP=203.0.113.8    Explicit public address for mail/DNS guidance
#   WEBROOT=/var/www/html    Nginx document root
#   PANEL_SRC=/path/to/src   Explicit path to the panel source dir (skips download)
#
set -euo pipefail

# --------------------------------------------------------------------------
# 0. Preconditions & configuration
# --------------------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
  echo "This script must run as root. Try: sudo $0" >&2
  exit 1
fi

REPO="${REPO:-lmb53/nebulapanel}"
REPO_REF="${REPO_REF:-main}"
SOURCE="${SOURCE:-auto}"
WEBROOT="${WEBROOT:-/var/www/html}"
FM_ROOT="${FM_ROOT:-/srv/nebula/sites}"
PANEL_PREFIX="${PANEL_PREFIX:-}"
DOMAIN="${DOMAIN:-}"
ADMIN_IP="${ADMIN_IP:-}"
PUBLIC_IP="${PUBLIC_IP:-}"
PANEL_USER="${PANEL_USER:-nebula-panel}"
WEBAPPS_USER="${WEBAPPS_USER:-nebula-webapps}"
SITES_ROOT="${SITES_ROOT:-/srv/nebula/sites}"
RESOLVED_SHA=""
BOOTSTRAP_TOKEN=""
[[ "$REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || {
  echo "REPO must use the form owner/name." >&2
  exit 1
}
[[ "$REPO_REF" =~ ^[A-Za-z0-9._/-]{1,200}$ && "$REPO_REF" != *..* ]] || {
  echo "REPO_REF contains unsupported characters." >&2
  exit 1
}

# Resolve the script's own directory.
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
  rsync ufw sudo curl ca-certificates tar zip openssl git acl \
  certbot python3-certbot-nginx >/dev/null
ok "Packages installed"
if [[ -n "$PUBLIC_IP" ]] && ! php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) ? 0 : 1);' "$PUBLIC_IP"; then
  die "PUBLIC_IP must be a public IPv4 or IPv6 address."
fi

# Detect PHP-FPM version, socket and service name.
#
# The installed PHP version is the source of truth: we derive it from the FPM
# config tree (/etc/php/<ver>/fpm), picking the HIGHEST, then locate that
# version's socket and service so the three always agree.
#
# We deliberately do NOT derive the version from the socket file name. The
# default pool can listen on a generic, unversioned socket like
# /run/php/php-fpm.sock (which the `php*-fpm.sock` glob also matches), and a
# name like that has no version to parse — that mismatch is what broke a fresh
# install with "Could not parse a PHP version from socket /run/php/php-fpm.sock".

# Highest PHP version that has an installed FPM config tree.
detect_php_version() {
  local d ver=""
  for d in $(ls -d /etc/php/*/fpm 2>/dev/null | sort -V); do
    ver="$(basename "$(dirname "$d")")"
  done
  # Fall back to a php-fpm binary on PATH if no config tree was found.
  if [[ ! "$ver" =~ ^[0-9]+\.[0-9]+$ ]]; then
    ver="$(php-fpm -v 2>/dev/null | sed -nE 's/^PHP ([0-9]+\.[0-9]+).*/\1/p' | head -1)"
  fi
  echo "$ver"
}
PHP_VER="$(detect_php_version)"
[[ "$PHP_VER" =~ ^[0-9]+\.[0-9]+$ ]] || die "Could not determine an installed PHP-FPM version (looked in /etc/php/*/fpm)."
FPM_SVC="php${PHP_VER}-fpm"

# Start the service so its socket appears, then locate the socket. Prefer the
# versioned socket; else read the pool's configured `listen` path; else fall
# back to any socket present in /run/php.
systemctl start "$FPM_SVC" 2>/dev/null || true
find_fpm_socket() {
  local sock
  sock="/run/php/php${PHP_VER}-fpm.sock"
  [[ -S "$sock" ]] && { echo "$sock"; return 0; }
  # Pull the listen path straight from the pool config(s).
  sock="$(sed -nE 's#^[[:space:]]*listen[[:space:]]*=[[:space:]]*(/[^[:space:];]+\.sock).*#\1#p' \
            /etc/php/"${PHP_VER}"/fpm/pool.d/*.conf 2>/dev/null | head -1)"
  [[ -n "$sock" && -S "$sock" ]] && { echo "$sock"; return 0; }
  # Last resort: whatever socket exists in /run/php.
  ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1 || true
}
FPM_SOCK="$(find_fpm_socket)"
[[ -z "$FPM_SOCK" ]] && die "Could not find a PHP-FPM socket for PHP ${PHP_VER} in /run/php/."
ok "PHP $PHP_VER  (socket: $FPM_SOCK)"

# Keep the ondrej/php PPA (added later when installing extra PHP versions) from
# hijacking the system-default, unversioned php meta-packages. Without this pin
# they track ondrej's newest stable, so installing e.g. PHP 8.5 also drags in
# 8.4 and the updater keeps trying to pull a second PHP. Writing it here repairs
# a box that already hit the bug on the next installer run; it is a no-op until
# the PPA is present.
PHP_PIN=/etc/apt/preferences.d/nebula-ondrej-php
cat > "$PHP_PIN" <<'PINEOF'
# Managed by Nebula Panel. Keeps unversioned php meta-packages on the distro
# default so adding extra PHP versions never pulls in another one.
Package: php php-fpm php-cli php-mysql php-curl php-mbstring php-xml php-zip php-gd php-bcmath php-intl php-soap php-imap php-json php-opcache php-dev php-pear php-readline php-redis php-imagick php-xdebug php-memcached php-apcu
Pin: release o=LP-PPA-ondrej-php
Pin-Priority: -1
PINEOF
chmod 0644 "$PHP_PIN"
ok "Pinned unversioned php meta-packages to the distro default"

systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl enable --now "$FPM_SVC" >/dev/null 2>&1 || true

# --------------------------------------------------------------------------
# 2. Obtain the panel source (local checkout or download from GitHub)
# --------------------------------------------------------------------------

# Find a dir that looks like the panel (has index.php + lib/bootstrap.php).
detect_panel_dir() {
  local root="$1" hit
  hit="$(find "$root" -maxdepth 4 -type f -path '*/lib/bootstrap.php' 2>/dev/null | head -1 || true)"
  [[ -z "$hit" ]] && return 1
  local dir; dir="$(dirname "$(dirname "$hit")")"
  [[ -f "$dir/index.php" ]] && { echo "$dir"; return 0; }
  return 1
}

resolve_remote_sha() {
  local remote="https://github.com/${REPO}.git" sha=""
  if [[ "$REPO_REF" =~ ^[a-f0-9]{40}$ ]]; then
    printf '%s\n' "$REPO_REF"
    return
  fi
  sha="$(git ls-remote "$remote" "refs/heads/$REPO_REF" 2>/dev/null | awk 'NR==1 {print $1}')"
  if [[ -z "$sha" ]]; then
    sha="$(git ls-remote "$remote" "refs/tags/$REPO_REF^{}" 2>/dev/null | awk 'NR==1 {print $1}')"
  fi
  if [[ -z "$sha" ]]; then
    sha="$(git ls-remote "$remote" "refs/tags/$REPO_REF" 2>/dev/null | awk 'NR==1 {print $1}')"
  fi
  [[ "$sha" =~ ^[a-f0-9]{40}$ ]] || die "Could not resolve ${REPO}@${REPO_REF} through GitHub."
  printf '%s\n' "$sha"
}

download_source() {
  local url top retry_sha
  RESOLVED_SHA="$(resolve_remote_sha)"
  url="https://codeload.github.com/${REPO}/tar.gz/${RESOLVED_SHA}"
  TMP_DL="$(mktemp -d)"
  log "Downloading ${REPO}@${REPO_REF} from GitHub…"
  if ! curl -fsSL "$url" -o "$TMP_DL/src.tar.gz"; then
    # A force-push can move a branch between resolution and codeload. Resolve
    # once more through Git rather than relying on cached API metadata.
    retry_sha="$(resolve_remote_sha)"
    if [[ "$retry_sha" != "$RESOLVED_SHA" ]]; then
      warn "${REPO_REF} moved during install; retrying the newly advertised commit."
      RESOLVED_SHA="$retry_sha"
      url="https://codeload.github.com/${REPO}/tar.gz/${RESOLVED_SHA}"
      curl -fsSL "$url" -o "$TMP_DL/src.tar.gz" \
        || die "Download failed after ref refresh: $url"
    else
      die "Download failed: $url  (check REPO/REPO_REF and that the repo is public)"
    fi
  fi
  top="$(tar -tzf "$TMP_DL/src.tar.gz" | awk -F/ 'NF {print $1}' | sort -u)"
  [[ -n "$top" && "$top" != *$'\n'* ]] || die "Archive must contain exactly one top-level directory."
  if tar -tzf "$TMP_DL/src.tar.gz" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
    die "Archive contains an unsafe path."
  fi
  if tar -tvzf "$TMP_DL/src.tar.gz" | grep -Eq '^[^d-]'; then
    die "Archive contains links or special files."
  fi
  tar --no-same-owner --no-same-permissions -xzf "$TMP_DL/src.tar.gz" -C "$TMP_DL" \
    || die "Failed to extract the downloaded archive."
  PANEL_SRC="$TMP_DL/$top/panel"
  [[ -f "$PANEL_SRC/index.php" && -f "$PANEL_SRC/lib/bootstrap.php" ]] \
    || die "Downloaded archive did not contain the expected top-level panel/ directory."
}

resolve_source() {
  if [[ -n "${PANEL_SRC:-}" ]]; then
    [[ -f "$PANEL_SRC/index.php" ]] || die "PANEL_SRC=$PANEL_SRC has no index.php."
    RESOLVED_SHA="$(git -C "$PANEL_SRC" rev-parse HEAD 2>/dev/null || true)"
    return
  fi
  local local_dir=""
  if [[ "$SOURCE" != "remote" ]]; then
    local_dir="$(detect_panel_dir "$SCRIPT_DIR" || true)"
  fi
  if [[ -n "$local_dir" && "$SOURCE" != "remote" ]]; then
    log "Using local panel source (no download needed)."
    PANEL_SRC="$local_dir"
    RESOLVED_SHA="$(git -C "$SCRIPT_DIR" rev-parse HEAD 2>/dev/null || true)"
  else
    download_source
  fi
}

resolve_source
SRC_NAME="$(basename "$PANEL_SRC")"
ok "Panel source: $PANEL_SRC"

# Decide the deployed directory below the public HTML root:
#   PANEL_PREFIX unset    -> reuse the active Nebula prefix, or random on first install
#   PANEL_PREFIX=random   -> intentionally rotate to a fresh prefix and migrate state
#   PANEL_PREFIX=foo      -> use "foo" (fixed, e.g. to redeploy in place)
# `od` reads a fixed number of bytes, avoiding the SIGPIPE failure caused by
# `/dev/urandom | head` when `set -o pipefail` is active.
[[ "$WEBROOT" == /* ]] || die "WEBROOT must be an absolute path."
mkdir -p "$WEBROOT"
WEBROOT="$(readlink -f "$WEBROOT")"

ACTIVE_PREFIX=""
if [[ -f /etc/nginx/sites-available/nebula ]]; then
  ACTIVE_PREFIX="$(grep -m1 -E '^[[:space:]]*location /[A-Za-z0-9_-]+/[[:space:]]*\{' /etc/nginx/sites-available/nebula 2>/dev/null \
    | sed -E 's#^[[:space:]]*location /([A-Za-z0-9_-]+)/[[:space:]]*\{.*#\1#' || true)"
fi
if [[ -z "$PANEL_PREFIX" && -n "$ACTIVE_PREFIX" && -d "$WEBROOT/$ACTIVE_PREFIX" ]]; then
  PANEL_PREFIX="$ACTIVE_PREFIX"
  ok "Reusing active panel prefix: $PANEL_PREFIX"
fi

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

# A random-prefix reinstall should retain private runtime state from the panel
# currently referenced by Nginx. Without this, Nginx vhosts/docroots survive
# but sites.json, the admin account, settings, and other panel metadata appear
# to vanish in the new URL.
PREVIOUS_DEST=""
if [[ -n "$ACTIVE_PREFIX" ]]; then
  if [[ "$ACTIVE_PREFIX" != "$PANEL_PREFIX" && -d "$WEBROOT/$ACTIVE_PREFIX/data" ]]; then
    PREVIOUS_DEST="$WEBROOT/$ACTIVE_PREFIX"
  fi
fi

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
if [[ -n "$PREVIOUS_DEST" ]]; then
  rsync -a "$PREVIOUS_DEST/data/" "$DEST/data/"
  ok "Migrated runtime state from $PREVIOUS_DEST"
fi
for _guard in .htaccess .gitignore; do
  [[ -f "$PANEL_SRC/data/$_guard" ]] && cp -f "$PANEL_SRC/data/$_guard" "$DEST/data/$_guard"
done
ok "Files copied"

# Fail before configuring the server if a release archive is incomplete.
for _required in index.php lib/bootstrap.php lib/sys.php \
                 api/apps.php api/updates.php api/provision.php \
                 api/notifications.php api/sshkeys.php api/file-state.php \
                 api/file-owner.php api/file-compress.php assets/app.js assets/style.css \
                 bin/nebula-helper bin/nebula-recovery bin/recovery.php; do
  [[ -f "$DEST/$_required" ]] || die "Deployed source is incomplete: missing $_required"
done

# Integrity check: catch a stale/incomplete source (the usual cause of
# "View not found" errors in the panel). Every routed view must be present.
_missing=""
for v in setup-wizard dashboard websites domains dns files services databases phpmyadmin \
         ssl php cron firewall logs updates users sshkeys docker backups terminal \
         sysinfo diagnostics notifications apps selfupdate settings service \
         file-edit login setup layout; do
  [[ -f "$DEST/views/$v.php" ]] || _missing="$_missing $v"
done
if [[ -n "$_missing" ]]; then
  warn "Source is missing views:${_missing}"
  warn "Your repo (${REPO}@${REPO_REF}) is out of date — commit & push ALL files, then reinstall."
else
  ok "All panel views present ($(ls "$DEST"/views/*.php | wc -l | tr -d ' ') files)"
fi

# Set the File Manager root in config.php if it differs from the default.
if [[ "$FM_ROOT" != "/srv/nebula/sites" ]]; then
  sed -i "s|?: '/srv/nebula/sites'|?: '${FM_ROOT}'|" "$DEST/config.php"
  ok "File Manager root set to $FM_ROOT"
fi

# Root-owned confinement policy used by privileged File Manager metadata
# operations. The web user can request chmod/chown only beneath this root.
mkdir -p "$FM_ROOT" /etc/nebula-panel
FM_ROOT="$(readlink -f "$FM_ROOT")"
printf '%s\n' "$FM_ROOT" > /etc/nebula-panel/fm-root
chown root:root /etc/nebula-panel/fm-root
chmod 0644 /etc/nebula-panel/fm-root
printf '%s\n' "$(readlink -f "$DEST")" > /etc/nebula-panel/panel-root
chown root:root /etc/nebula-panel/panel-root
chmod 0644 /etc/nebula-panel/panel-root
if [[ -n "$PUBLIC_IP" ]]; then
  printf '%s\n' "$PUBLIC_IP" > /etc/nebula-panel/public-ip
  chown root:root /etc/nebula-panel/public-ip
  chmod 0644 /etc/nebula-panel/public-ip
fi

# --------------------------------------------------------------------------
# 4. Permissions
# --------------------------------------------------------------------------
log "Setting ownership and permissions…"
if ! getent passwd "$PANEL_USER" >/dev/null 2>&1; then
  useradd --system --home-dir /var/lib/nebula-panel --create-home \
    --shell /usr/sbin/nologin "$PANEL_USER"
fi
if ! getent passwd "$WEBAPPS_USER" >/dev/null 2>&1; then
  useradd --system --home-dir /var/lib/nebula-webapps --create-home \
    --shell /usr/sbin/nologin "$WEBAPPS_USER"
fi
install -d -m 0750 -o root -g "$PANEL_USER" /etc/nebula-panel
install -d -m 0711 -o root -g root /srv/nebula
install -d -m 0711 -o root -g root "$SITES_ROOT"
install -d -m 0700 -o root -g root /srv/nebula/trash /etc/nebula-panel/sites
setfacl -m "u:${PANEL_USER}:rx" "$SITES_ROOT"
for _site_base in "$SITES_ROOT"/*; do
  [[ -d "$_site_base" && ! -L "$_site_base" && "$(basename "$_site_base")" =~ ^[a-f0-9]{32}$ ]] || continue
  setfacl -m "u:${PANEL_USER}:rx" "$_site_base"
  if [[ -d "$_site_base/public" && ! -L "$_site_base/public" ]]; then
    setfacl -R -m "u:${PANEL_USER}:rX" "$_site_base/public"
    find "$_site_base/public" -type d -exec setfacl -m "d:u:${PANEL_USER}:rX" {} +
  fi
done
chown -R root:root "$DEST"
find "$DEST" -type d -exec chmod 755 {} \;
find "$DEST" -type f -exec chmod 644 {} \;
mkdir -p "$DEST/data"
chown -R "$PANEL_USER:$PANEL_USER" "$DEST/data"
chmod 700 "$DEST/data"
if [[ ! -f "$DEST/data/panel-users.json" && ! -f "$DEST/data/admin.json" ]]; then
  BOOTSTRAP_TOKEN="$(openssl rand -hex 32)"
  BOOTSTRAP_HASH="$(printf '%s' "$BOOTSTRAP_TOKEN" | sha256sum | awk '{print $1}')"
  printf '{\n  "hash": "%s",\n  "expires_at": %s\n}\n' \
    "$BOOTSTRAP_HASH" "$(( $(date +%s) + 3600 ))" > "$DEST/data/bootstrap.json"
  chown "$PANEL_USER:$PANEL_USER" "$DEST/data/bootstrap.json"
  chmod 0600 "$DEST/data/bootstrap.json"
fi
ok "Permissions applied (data/ is private, web-writable)"

# Record the exact commit that supplied the deployed bytes. Never resolve the
# mutable ref a second time after download.
SHA="$RESOLVED_SHA"
if [[ -n "$SHA" ]]; then
  printf '{\n  "sha": "%s",\n  "ref": "%s",\n  "applied_at": "%s"\n}\n' \
    "$SHA" "$REPO_REF" "$(date -Iseconds 2>/dev/null || date)" > "$DEST/data/version.json"
  chown "$PANEL_USER:$PANEL_USER" "$DEST/data/version.json"
  chmod 600 "$DEST/data/version.json"
  ok "Recorded version baseline (${SHA:0:12})"
fi

# Install the privileged helper root-owned, OUTSIDE the web-writable tree, so
# the web user can't modify what it runs as root.
if [[ -f "$DEST/bin/nebula-helper" && -f "$DEST/bin/nebula-recovery" && -f "$DEST/bin/recovery.php" ]]; then
  install -m 0755 -o root -g root "$DEST/bin/nebula-helper" /usr/local/bin/nebula-helper
  install -m 0755 -o root -g root "$DEST/bin/nebula-recovery" /usr/local/sbin/nebula-recovery
  ok "Installed privileged helper (/usr/local/bin/nebula-helper)"
else
  warn "bin/nebula-helper missing from source — Websites/phpMyAdmin will be limited."
fi

# --------------------------------------------------------------------------
# 5. Nginx site
# --------------------------------------------------------------------------
log "Writing Nginx configuration…"
PANEL_FPM_SOCK="/run/php/nebula-panel.sock"
WEBAPPS_FPM_SOCK="/run/php/nebula-webapps.sock"
cat > "/etc/php/${PHP_VER}/fpm/pool.d/nebula-panel.conf" <<EOF
[nebula-panel]
user = ${PANEL_USER}
group = ${PANEL_USER}
listen = ${PANEL_FPM_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 20s
pm.max_requests = 500
clear_env = yes
catch_workers_output = yes
security.limit_extensions = .php
rlimit_files = 4096
php_admin_value[session.save_path] = ${DEST}/data/sessions
php_admin_value[upload_tmp_dir] = ${DEST}/data/tmp
EOF
cat > "/etc/php/${PHP_VER}/fpm/pool.d/nebula-webapps.conf" <<EOF
[nebula-webapps]
user = ${WEBAPPS_USER}
group = ${WEBAPPS_USER}
listen = ${WEBAPPS_FPM_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 20s
pm.max_requests = 300
clear_env = yes
security.limit_extensions = .php
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
EOF
install -d -m 0700 -o "$PANEL_USER" -g "$PANEL_USER" "$DEST/data/sessions" "$DEST/data/tmp"
systemctl restart "$FPM_SVC"
[[ -S "$PANEL_FPM_SOCK" ]] || die "Dedicated panel FPM socket was not created."

SERVER_NAME="${DOMAIN:-_}"
ACCESS_RULES=""
if [[ -n "$ADMIN_IP" ]]; then
  ACCESS_RULES=$'        allow '"$ADMIN_IP"$';\n        deny all;'
fi

if [[ -n "$DOMAIN" ]]; then
  PANEL_LISTEN=$'    listen 80 default_server;\n    listen [::]:80 default_server;'
else
  PANEL_LISTEN=$'    listen 127.0.0.1:80 default_server;\n    listen [::1]:80 default_server;'
fi
NGINX_BACKUP="$(mktemp)"
NGINX_HAD_CONFIG=no
if [[ -f /etc/nginx/sites-available/nebula ]]; then
  cp -a /etc/nginx/sites-available/nebula "$NGINX_BACKUP"
  NGINX_HAD_CONFIG=yes
fi
DEFAULT_TARGET="$(readlink /etc/nginx/sites-enabled/default 2>/dev/null || true)"
cat > /etc/nginx/sites-available/nebula <<EOF
server {
    # Keep the panel as the IP-address fallback after hosted vhosts are added.
${PANEL_LISTEN}
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

    location ~ ^/${PANEL_PREFIX}/.*\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PANEL_FPM_SOCK};
    }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${WEBAPPS_FPM_SOCK};
    }

    # Do not serve dotfiles from the panel or hosted websites.
    location ~ /\.(?!well-known).* { deny all; return 404; }
}
EOF

ln -sf /etc/nginx/sites-available/nebula /etc/nginx/sites-enabled/nebula
rm -f /etc/nginx/sites-enabled/default
if ! nginx -t >/dev/null 2>&1 || ! systemctl reload nginx; then
  if [[ "$NGINX_HAD_CONFIG" == yes ]]; then cp -a "$NGINX_BACKUP" /etc/nginx/sites-available/nebula
  else rm -f /etc/nginx/sites-available/nebula /etc/nginx/sites-enabled/nebula
  fi
  [[ -n "$DEFAULT_TARGET" ]] && ln -sf "$DEFAULT_TARGET" /etc/nginx/sites-enabled/default
  nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
  rm -f "$NGINX_BACKUP"
  die "Nginx validation/reload failed; previous configuration restored."
fi
rm -f "$NGINX_BACKUP"
ok "Nginx configured and reloaded"

# --------------------------------------------------------------------------
# 6. Grant only the root-owned broker to the dedicated panel identity
# --------------------------------------------------------------------------
# The panel receives no direct tool grants. Every privileged request crosses
# the single root-owned helper, which validates a typed action and arguments.
log "Granting $PANEL_USER access to the privileged broker…"
SUDOERS=/etc/sudoers.d/nebula-panel
SUDOERS_TMP="$(mktemp)"
{
  echo "# Nebula Panel: the validating helper is the only privileged surface."
} > "$SUDOERS_TMP"

# The privileged helper: a single tight entry that covers vhost/SSL/phpMyAdmin
# operations, instead of granting tee/ln/mkdir/certbot broadly.
[[ -x /usr/local/bin/nebula-helper ]] && \
  echo "$PANEL_USER ALL=(root) NOPASSWD: /usr/local/bin/nebula-helper *" >> "$SUDOERS_TMP"

chmod 440 "$SUDOERS_TMP"
if ! visudo -cf "$SUDOERS_TMP" >/dev/null 2>&1; then
  die "Generated sudoers file is invalid; the existing configuration was left untouched."
fi
install -m 0440 -o root -g root "$SUDOERS_TMP" "$SUDOERS"
rm -f "$SUDOERS_TMP"
SUDOERS_TMP=""

# Catch a partial/ineffective sudoers deployment during installation rather
# than deferring the failure to the web UI.
sudo -u "$PANEL_USER" sudo -n /usr/local/bin/nebula-helper php-versions >/dev/null 2>&1 \
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
SCHEME="http"; HOST="127.0.0.1"
[[ -n "$DOMAIN" ]] && { SCHEME="https"; HOST="$DOMAIN"; }

echo
echo "============================================================"
echo "  Nebula Panel is installed."
echo "------------------------------------------------------------"
echo "  URL:        ${SCHEME}://${HOST}/${PANEL_PREFIX}/"
echo "  Deployed:   ${DEST}"
echo "  Source:     ${REPO}@${REPO_REF}"
echo "  FM root:    ${FM_ROOT}"
[[ -n "$PUBLIC_IP" ]] && echo "  Public IP:  ${PUBLIC_IP}"
echo "  PHP-FPM:    ${FPM_SVC}"
[[ -n "$ADMIN_IP" ]] && echo "  Access:     restricted to ${ADMIN_IP}"
[[ -n "$BOOTSTRAP_TOKEN" ]] && echo "  Bootstrap:  $BOOTSTRAP_TOKEN  (single-use; expires in 1 hour)"
[[ -z "$DOMAIN"  ]] && echo "  (No domain: loopback only. Use an SSH port-forward for setup.)"
echo "------------------------------------------------------------"
echo "  NEXT: open the URL and enter the single-use bootstrap token."
echo "============================================================"
