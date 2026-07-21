# Vendored browser dependencies

These exact, locally served builds keep the panel functional without third-party
runtime requests and support a restrictive Content Security Policy:

- Chart.js 4.4.9 (`chart-4.4.9.umd.min.js`)
- CodeMirror 5.65.16 core, theme, modes, and editor add-ons
- Lucide 1.8.0 (`lucide-1.8.0.min.js`)

When upgrading, replace every file in a dependency's set together, keep the
version in the filename, test the dashboard and editor, and update this manifest.
