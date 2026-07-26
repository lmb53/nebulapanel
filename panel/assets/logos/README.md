# App Store logos

Official brand marks for the Docker App Store, vendored from
[Simple Icons](https://github.com/simple-icons/simple-icons) (CC0 1.0).

Each file is the upstream icon with the brand's official hex colour applied as a
`fill` on the root `<svg>` element, so it can be served as a plain `<img>` under
the panel's strict `img-src 'self' data:` CSP. Two marks are pure black upstream
(Vaultwarden, Coder/code-server) and carry a lightened colour instead so they
stay legible on the panel's dark surfaces.

To refresh a logo, re-download `icons/<slug>.svg` from Simple Icons and re-apply
the `fill` attribute.
