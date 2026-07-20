# Nebula Panel — PHP MVP

A working, self-hosted server control panel served from an **obscured URL prefix**
(the secret directory name), e.g. `http://YOUR_IP/2v9xzq4k2/`.

> The random directory name is *obscurity*, not real security. Always pair it with
> HTTPS, a strong admin password, and ideally IP allow-listing. See "Hardening".

## What works in this MVP

| Feature | Status |
|---|---|
| First-run admin setup (bcrypt) + login/logout | ✅ |
| Session auth, idle timeout, CSRF on all writes | ✅ |
| Dashboard with **live** CPU / memory / disk / load (3s poll) + chart | ✅ |
| Service control — start / stop / restart via `systemctl` | ✅ (needs sudoers rule) |
| File Manager — browse / view / download / delete, confined to a root | ✅ |
| System Info — OS, kernel, CPU, RAM, disk, network interfaces | ✅ |
| Audit log (`data/audit.log`) | ✅ |

Metrics read Linux `/proc` and use `systemctl`, so **run this on the Linux VPS**.
On macOS/Windows the pages load but most metrics show `n/a`.

## Requirements

- Linux VPS (Ubuntu/Debian assumed)
- PHP 8.0+ with FPM, and `proc_open` enabled (default)
- Nginx or Apache

## Install

```bash
# 1. Copy the secret directory into your web root.
sudo cp -r 2v9xzq4k2 /var/www/html/

# 2. Make data/ writable by the web user (setup + audit log live here).
sudo chown -R www-data:www-data /var/www/html/2v9xzq4k2/data
sudo chmod 700 /var/www/html/2v9xzq4k2/data

# 3. Choose which directory the File Manager may browse (default /var/www).
#    Either edit config.php ('fm_root') or set an env var in your FPM pool.
```

### Nginx

```nginx
# Inside your server { } block. No rewrites needed — routing is query-param based.
location /2v9xzq4k2/ {
    index index.php;
    try_files $uri $uri/ /2v9xzq4k2/index.php$is_args$args;
}
location ~ ^/2v9xzq4k2/(data|lib|views)/ { deny all; return 404; }
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

### Apache

`mod_php` or PHP-FPM + the bundled `.htaccess` files already deny `data/`, `lib/`,
`views/`. Ensure `AllowOverride All` for the directory.

## Making service control work (sudoers)

`systemctl start/stop/restart` needs root. Grant the **web user** permission for
*only* systemctl — nothing else:

```bash
sudo visudo -f /etc/sudoers.d/nebula-panel
```

```
# Let the web server control services without a password.
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start *, \
                              /usr/bin/systemctl stop *, \
                              /usr/bin/systemctl restart *
```

Verify the path with `which systemctl` (often `/usr/bin/systemctl` or `/bin/systemctl`).
Without this rule, status still shows correctly but start/stop/restart return a
permission error (surfaced in the UI).

## First run

1. Visit `http://YOUR_IP/2v9xzq4k2/`
2. You'll be redirected to **Setup** — create the admin username + password.
3. You're in. Credentials are hashed into `data/admin.json`.

To reset the admin account, delete `data/admin.json` and reload.

## Hardening (do this before exposing it)

- **HTTPS** — put it behind Let's Encrypt / a reverse proxy. Sessions set the
  `Secure` cookie flag automatically over HTTPS.
- **Change the secret prefix** — rename the directory to your own random string.
- **IP allow-list** the location block to your admin IPs.
- **Rate-limit** `/2v9xzq4k2/?r=login` (nginx `limit_req` or fail2ban).
- Keep `'debug' => false` in `config.php`.

## Architecture

```
2v9xzq4k2/
  index.php        front controller — routes ?r=<route>, dispatches API + views
  config.php       panel name, fm_root, service whitelist, timeouts
  lib/
    bootstrap.php  sessions, config, includes
    helpers.php    url()/asset()/e()/json_out()/csrf/render()/audit()
    auth.php       setup, login, logout, guards
    sys.php        /proc metrics + systemctl control
    files.php      path-safe file manager backend
  views/           layout + one file per page
  assets/          style.css, app.js (live polling, actions)
  data/            admin.json, audit.log (web-denied, not in git)
```

Routing is intentionally query-param based (`?r=...`) so it works identically
under Nginx, Apache, and `php -S` with **zero rewrite rules**.

## Not in this MVP (natural next steps)

- Live terminal / SSH (needs a WebSocket process — PHP is poor at this)
- File upload / rename / chmod / create
- Websites, Databases, DNS, SSL, Cron, Firewall pages (backends to add)
- Multi-user + roles, 2FA
```
