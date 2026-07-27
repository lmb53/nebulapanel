# App logos

Official brand marks for the Docker App Store and the Install Apps catalog,
vendored from [Simple Icons](https://github.com/simple-icons/simple-icons)
(CC0 1.0).

Each file is the upstream icon with the brand's official hex colour applied as a
`fill` on the root `<svg>` element, so it can be served as a plain `<img>` under
the panel's strict `img-src 'self' data:` CSP. Marks that are pure black or very
dark upstream (Vaultwarden, Coder/code-server, MariaDB, Let's Encrypt, OWASP)
carry a lightened colour instead so they stay legible on the panel's dark
surfaces.

Two files are named for the product rather than the upstream slug:

| File | Simple Icons slug | Why |
| --- | --- | --- |
| `certbot.svg` | `letsencrypt` | Certbot has no Simple Icons entry; it is the Let's Encrypt client |
| `modsecurity.svg` | `owasp` | ModSecurity and its Core Rule Set are OWASP projects |

Memcached and Fail2Ban have no Simple Icons entry and no redistributable mark,
so those two catalog entries keep their lucide glyph — the views fall back to
`icon` whenever `logo` is empty or the file fails to load.

To refresh a logo, re-download `icons/<slug>.svg` from Simple Icons and re-apply
the `fill` attribute.
