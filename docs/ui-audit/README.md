# Nebula Panel UI/UX audit, October 2026

> **Status: all findings below have been addressed** on branch `claude/eager-lovelace-8w0vwo`
> (see *Resolution* at the end). Decisions taken with the owner: file deletes go to a
> recoverable trash with an option to skip it; the panel runs on PHP 8.5; narrow screens
> use an off-canvas labelled sidebar instead of an icon rail; the editor window follows
> theme changes made in the main window.

**Method.** I ran a copy of `panel/` under PHP 8.3's built-in server with a seeded bootstrap token and a sandbox `NEBULA_FM_ROOT`. The sandbox included long file names, 30 files and an empty folder. I drove the panel with headless Chromium (Playwright) and visited all 27 registered routes plus `service`, `file-edit`, `setup`, `setup-wizard`, `login`, logout, lockout and an unknown route. Each route was captured at four viewports: 1600×1000, 1366×768, 820×1180 and 390×844.

For every page I recorded console errors, failed requests, icons that never rendered, horizontal overflow, computed button, input, tab and header heights, form controls without a label, and icon-only controls without a name. I then opened the overlays, tabs, drawers, context menus and dropdowns, ran destructive flows such as delete and lockout, switched to the light theme and checked keyboard behaviour. Findings are tied to source lines where possible.

**Environment limits.** The container has no privileged helper, UFW, crontab, MySQL client, certbot or Docker daemon. Pages that depend on these only showed their "unavailable" state. For their populated state I reviewed the code but could not render it; these areas are marked *Partially inspected* in the coverage table.

Screenshots are in [`screenshots/`](screenshots/).

---

## Executive summary

**UI quality: about 6.5 / 10.** The design language is coherent and attractive. It has a consistent dark palette, a real token layer (`:root` / `html.light`), a uniform page header, a card/badge/table vocabulary, empty states with icons, and a well-made command palette and notification menu. Most screens look like the same product.

The polish breaks down in the details, and several of those breaks are **system-wide**:

- Every `<button>` renders in **Arial** with `line-height: normal`, while every `<a class="btn">` uses the app font. Equivalent buttons therefore differ by 5 px in height and use different typefaces.
- There are four tab variants, two notice variants, four stat-card variants and five date formats.
- The stylesheet uses 19 distinct font sizes (down to 9 px), 12 different border radii and about 600 inline `style=""` attributes in the views.

**Robustness: about 5.5 / 10.** Happy-path flows work, and some defensive touches are good: error toasts persist, the editor has a `beforeunload` guard, and drawers apply `inert` and a focus trap. However:

- **One P0:** deleting a file in the File Manager fires **two** confirmation dialogs with contradictory wording ("cannot be undone" vs "recovery trash") and sends **two** delete requests.
- On mobile and tablet, three hard-coded inline grids combine with `body{overflow-x:hidden}` to clip content (forms, buttons, cards) off-screen with **no way to scroll to it**.
- Many actions stay enabled when they cannot succeed. PHP Settings shows a blank form with an active Save button when `php.ini` is unreadable.

**Biggest problems**

1. Duplicate delete handler in the File Manager (P0).
2. Broken responsive layouts: inline grids, 420 px drawers, and an icon-only rail with no names on narrow screens.
3. Accessibility gaps: icon-only buttons with no name, form labels not associated with inputs, light-theme status colours at about 2:1 contrast, and a search trigger that cannot be focused.
4. Button font and height inconsistency, which comes from a missing form-control font reset.
5. Missing-data states rendered as blank or misleading UI (PHP Settings, PHP Health, Docker subtitle, the Security page gated on UFW).

**Strongest parts:** the command palette, the notification dropdown, the File Manager's feature set (tree, grid/list, pinned/recent, context menu, details drawer), the multi-tab editor popup, the Logs viewer, the Docker App Store cards, the consistent page-header pattern, and the token-based theming.

**Issue counts (this report):** P0 = 1 · P1 = 14 · P2 = 34 · P3 = 18 (67 total).

---

## Application coverage

| Area | Route | Status | Notes |
|---|---|---|---|
| Setup (first run) | `setup` | Inspected | Used to create the admin account |
| Setup wizard | `setup-wizard` | Inspected | |
| Login / logout / lockout | `login`, `logout` | Inspected | 6 bad attempts, 429 state |
| Dashboard | `dashboard` | Inspected | Chart, stats, services, processes, health |
| Websites | `websites` | Partially inspected | Only the "helper not installed" state renders; list and Git drawer reviewed in code |
| File Manager | `files` | Inspected | List, grid, Pinned, Recent, tree expand, context menu, details drawer, chmod, select-all, delete, empty folder, traversal attempt |
| File editor (popup) | `file-edit` | Inspected | Tabs, dirty guard |
| Domains | `domains` | Inspected (empty) | |
| DNS | `dns` | Inspected (empty) | Records table and add row reviewed in code |
| SSL | `ssl` | Partially inspected | Unavailable state only |
| PHP | `php` | Inspected | All 9 tabs |
| Databases | `databases` | Partially inspected | "Client not found" state |
| phpMyAdmin | `phpmyadmin` | Partially inspected | Not-installed state |
| Email | `mail` | Partially inspected | Install form only; mailbox, DKIM and stats views not rendered |
| Services | `services`, `service&name=` | Inspected | Overview, per-instance tabs, sub-tabs, quick actions |
| Install Apps | `apps` | Inspected | |
| Updates | `updates` | Inspected (0 updates) | |
| Users & access | `users` | Inspected | 3 tabs plus the Add user drawer. Non-admin roles reviewed in code only |
| SSH Keys | `sshkeys` | Partially inspected | Unavailable state |
| Cron Jobs | `cron` | Partially inspected | Unavailable; drawer and builder reviewed in code |
| Security / Firewall | `firewall` | Partially inspected | UFW missing hides the whole page (see P1-5); Fail2Ban and ModSecurity reviewed in code |
| Logs | `logs` | Inspected | |
| Docker | `docker` | Inspected | 6 tabs, New container and Pull drawers; Stack, Logs and Proxy drawers in code |
| Terminal | `terminal` | Inspected | |
| Backups | `backups` | Inspected (empty) | |
| System Info | `sysinfo` | Inspected | |
| Diagnostics | `diagnostics` | Inspected | |
| Notifications | `notifications` and top-bar menu | Inspected | |
| Panel Updates | `selfupdate` | Inspected | |
| Settings | `settings` | Inspected | |
| API tokens | `api` | Inspected | |
| Command palette, theme toggle, sidebar collapse | global | Inspected | |
| 404 / 403 | `?r=unknown`, role-blocked route | Inspected / code | |
| Light theme | global | Inspected | 10 pages |
| Viewports 1600 / 1366 / 820 / 390 | all routes | Inspected | |

---

## Issues

Categories: **[DW]** definitely wrong · **[LW]** likely wrong · **[PI]** potential improvement · **[CD]** cannot determine without clarification.

### P0: Critical

#### [P0] File delete fires twice with contradictory confirmations and two requests [DW]
- **Location:** File Manager › row Delete button (trash icon). Handlers in `panel/views/files.php:485` and `panel/assets/app.js:503`.
- **Problem:** Both scripts bind a click handler to `[data-fm-delete]`. One click produces two `confirm()` dialogs:
  1. `Delete "file5.log"? This cannot be undone.`
  2. `Move "file5.log" to the administrator recovery trash?`

  Cancelling the first dialog still shows the second. Accepting both sends **two** `POST file-delete` requests; the second fails with 404 and shows an error toast right after the success toast.
- **Why it matters:** Users get two conflicting statements about whether the delete is reversible. `api/file-delete.php` unlinks the file as the web user, so the "recovery trash" message is false for normal files. A user who believes the trash promise could lose data. The duplicate request also produces a spurious error.
- **Expected:** One confirmation with accurate wording, and one request.
- **Recommended fix:** Delete the `[data-fm-delete]` block from `wireFiles()` in `app.js`; the view already owns this behaviour. Decide what the backend actually guarantees and use a single message. If deletes are permanent, use "Permanently delete … ?". Longer term, route deletes through a recoverable trash.
- **Evidence:** Playwright captured both dialogs on a single click and counted 2 POSTs when both were accepted.

### P1: High

#### [P1-1] Buttons do not inherit the app font, so `<button>` and `<a>` buttons differ in typeface and height [DW]
- **Location:** Global. `assets/style.css` has no `button, input, select, textarea { font: inherit }` reset.
- **Problem:** Computed style for `button.btn` is `font-family: Arial; line-height: normal`. `<a class="btn">` inherits the system UI stack and `line-height: 1.5`. Measured heights: `button.btn` = **33 px**, `a.btn` = **38 px**. Examples: Services header "Install service" (a, 38) next to "Refresh" (button, 33); Domains "Add Domain" (38) vs every other primary button (33); Apps "Install" (27) vs "Remove" (26).
- **Why it matters:** Users notice this subconsciously. Adjacent buttons are misaligned and roughly half the controls use a different typeface.
- **Expected:** All `.btn` elements share one font and height per size.
- **Recommended fix:** Add `button,input,select,textarea{font:inherit;color:inherit}` and give `.btn` an explicit `line-height:20px` (or a fixed `height:36px` / `.btn-sm` `height:28px`). Do the same for `.icon-btn`, `.collapse-btn` and `.toast-close`.
- This appears to be a system-wide issue rather than an isolated component issue.

#### [P1-2] Inline fixed grids do not collapse on small screens, and `overflow-x:hidden` makes the clipped content unreachable [DW]
- **Location:** `views/dashboard.php:57` (`2fr 1fr`), `views/settings.php:17` (`1fr 1fr`), `views/backups.php:17`, `views/sysinfo.php:25`, `views/databases.php:51`, `views/ssl.php:44,50` and `views/websites.php:49`; `body{overflow-x:hidden}` in `style.css:115`.
- **Problem:** At 390 px wide:
  - The Settings "Change admin password" card is cut in half.
  - The Dashboard chart and Services cards extend 314 px off-screen.
  - The Backups site `<select>` shrinks to show only "Cl", and **Create backup** is off-screen.

  The `.grid-2/3/4` media queries only apply to the class names, not to these inline `grid-template-columns`. Because the body hides horizontal overflow, users cannot scroll to the clipped content.
- **Why it matters:** These forms cannot be used on a phone.
- **Expected:** Grids stack to a single column below about 700 px.
- **Recommended fix:** Replace the inline grids with utility classes, such as `.grid-2-1` and `.form-row` (`grid-template-columns:1fr 220px auto`), and add mobile rules that switch them to `1fr`. Remove `overflow-x:hidden` from `body` and keep it on `.app-shell` only if it is still needed.
- **Evidence:** `screenshots/mobile-settings-clipped.png`, `mobile-backups-clipped.png`; `scrollWidth` 704/485/555 against a 390 px viewport.
- This appears to be a system-wide issue rather than an isolated component issue.

#### [P1-3] Drawers are a fixed 420 px wide and are clipped on phones [DW]
- **Location:** `.drawer{width:420px}` (`style.css:383`). Affects every drawer: Docker (6), Users, Cron, Firewall, Websites Git.
- **Problem:** At 390 px the drawer's left edge, including labels such as "ame" and "inned image", is cut off.
- **Expected:** `width:min(420px,100vw)`; full screen below 480 px.
- **Evidence:** `screenshots/mobile-drawer-clipped.png`.

#### [P1-4] Icon-only navigation has no accessible names or tooltips, and narrow screens have no other navigation [DW]
- **Location:** `layout.php` nav links; `.sidebar.collapsed .nav-label{display:none}` and `@media (max-width:900px)`.
- **Problem:** When the sidebar is collapsed, and on every screen at or below 900 px, labels are `display:none`. That removes them from the accessibility tree. Links have no `title` or `aria-label`, so they are announced as unlabelled links and sighted users get no hover hint. On phones, about 20 % of the width goes to an unlabelled 27-icon rail. The **Collapse** button visibly does nothing at or below 900 px. The search trigger is hidden below 600 px, so phones have no visible route to the command palette.
- **Expected:** Named links, tooltips in rail mode, and an off-canvas labelled drawer on mobile.
- **Recommended fix:** Add `title` and `aria-label` to `nav_link()`. Below 600 px, hide the rail and add a hamburger button in the top bar that opens the full sidebar as an overlay. Hide the Collapse button when it cannot have an effect. Keep a search icon button on mobile.
- **Evidence:** `screenshots/mobile-dashboard-rail.png`; in collapsed state Playwright read `{title:'', aria-label:null, text:''}` from the nav items.

#### [P1-5] Security page: missing UFW hides Fail2Ban, ModSecurity and the audit log entirely [DW]
- **Location:** `views/firewall.php`, line 3: `if(!$available||empty($status['ok']))` wraps the whole tab set.
- **Problem:** If UFW is absent or its status read fails, the page shows only "UFW is not available". The Fail2Ban, ModSecurity and Audit tabs are never rendered, even though they have their own "not installed" empty states. The subtitle still shows a score ("35/100 · At risk") with no explanation.
- **Expected:** Always render the tab bar. Put the UFW empty state inside the Firewall tab only.
- **Evidence:** `screenshots/security-ufw-gate.png`.

#### [P1-6] File Manager table pushes the Actions column off-screen [DW]
- **Location:** `files.php` list view; `.fm-center-pane .data-table{min-width:950px}`; `.fm-name-cell` does not truncate.
- **Problem:** One long folder name widens the table. At 1440 px the Actions column ends at x = 1606 and only a sliver of one icon is visible. Type ("Log File") and Modified ("Oct 7, 09:20") wrap onto two lines, so rows grow from 48 to 56 px. Clicking a row's Details button scrolls the table sideways and hides every file name behind the open drawer.
- **Expected:** The name column truncates with an ellipsis and shows a `title`. Meta columns use `white-space:nowrap`. Actions stay visible, for example as a sticky right column.
- **Recommended fix:** Use `table-layout:fixed` with explicit widths. Give `.fm-name-cell .fname` a `max-width` and `min-width:0`. Add `td{white-space:nowrap}` for size, type, date and permissions. Use `position:sticky;right:0` on the actions cell.
- **Evidence:** `screenshots/files-actions-offscreen.png`, `files-details-scrolls-table.png`.

#### [P1-7] Light-theme status colours and tertiary text fail contrast [DW]
- **Location:** `html.light` does not redefine `--emerald-400`, `--orange-400`, `--red-400` or `--blue-400`, but badges, active nav, links and error text use these shades.
- **Problem:** Measured contrast on white:

  | Element | Contrast |
  |---|---|
  | Emerald badge text | 1.92:1 |
  | Orange | 2.26:1 |
  | Active-nav blue | 2.72:1 |
  | Red | 2.77:1 |
  | `--text-tertiary` on white (subtitles, help text, table headers, metadata) | 3.1:1 (2.86:1 on the canvas) |
  | `--text-tertiary` in the dark theme | 3.71:1 |

  WCAG AA requires 4.5:1 for small text.
- **Expected:** At least 4.5:1 for text, at least 3:1 for UI glyphs.
- **Recommended fix:** In `html.light`, map the status text tokens to the -600/-700 shades (for example emerald `#047857`, orange `#b45309`, red `#b91c1c`, blue `#1d4ed8`). Raise `--text-tertiary` to about `#64748b` in light and `#7d8aa0` in dark.
- This appears to be a system-wide issue rather than an isolated component issue.

#### [P1-8] Icon-only action buttons have no accessible name or tooltip [DW]
- **Location:** Services quick actions (`views/services.php`; 33 `btn btn-sm` buttons containing only an SVG), the Notifications page actions, and drawer close buttons.
- **Problem:** `wireAccessibility()` only auto-labels `.icon-btn`, so `.btn` buttons with only an icon get no name. Where it does apply, it falls back to the Lucide icon name, so drawer close buttons are announced as **"X"**. Sighted users get no tooltip to tell Start (▷), Restart (↻) and Stop (□) apart.
- **Expected:** Every icon-only control has an explicit `aria-label` and `title` ("Start nginx", "Close").
- **Recommended fix:** Add the labels in markup. Extend the fallback to `button:not(:has(:not(svg)))`. Map `x` to "Close".

#### [P1-9] Form labels are not associated with their controls [DW]
- **Location:** Settings (7 inputs), Backups, API tokens, Logs search and lines, Terminal, Apps PHP select, SSH user select, Login, Setup, Users drawer and the DNS add row.
- **Problem:** `<label class="field-label">` is a sibling of the input, with no `for`/`id` pair. Clicking a label does not focus its field and screen readers announce unnamed fields.
- **Recommended fix:** Give each input an `id` and each label a matching `for`. For grouped inline rows such as DNS, add `aria-label` to each field.
- This appears to be a system-wide issue rather than an isolated component issue.

#### [P1-10] API token form: role and expiry fields have no labels, and the submit button is mid-form [DW]
- **Location:** `views/api.php`.
- **Problem:** One "Automation identity" label sits over three fields. The second is a role select ("Auditor") and the third is a bare "30". Nothing says that 30 is the number of days until expiry. **Generate token** sits beside the Scopes field, above the optional "Allowed source IPs" field, so users may submit before seeing it. The Active tokens header shows a bare "0".
- **Expected:** Labelled Role and "Expires in (days)" fields, with the button at the end of the form.
- **Evidence:** `screenshots/api-token-form.png`.

#### [P1-11] PHP Settings shows a blank editable form with an enabled Save button when the ini cannot be read [LW]
- **Location:** PHP › Settings tab; `php_read_settings()` returns `[]`.
- **Problem:** All eight inputs (`memory_limit`, `upload_max_filesize`, …) are empty with no message. The Display errors and Log errors selects default to "Off". **Save changes** is enabled, so one click could write empty values. The php.ini tab correctly says "FPM php.ini is not readable"; the Settings tab does not.
- **Expected:** The same "not readable" state, with the form disabled.
- **Evidence:** `screenshots/php-settings-blank.png`.

#### [P1-12] Actions stay enabled in states where they cannot succeed [DW]
- **Location:** many pages.
- **Problem:**
  - **Docker:** App Store **Install** ×12, **New container** and **Pull image** are enabled while the page says "Docker Compose is not available / not responding".
  - **Updates:** **Upgrade all** is enabled when there are 0 updates.
  - **Services:** Start, Restart and Stop are always enabled. A stopped service offers Stop, and the per-instance page makes **Restart** the primary (blue) action on a stopped service.
  - **Backups:** **Create backup** is enabled with no site options.
  - **Install Apps:** the wizard's **Install selected** is enabled without the helper.
  - **Panel Updates:** **Update now** is the primary action while the baseline is "unknown".
- **Why it matters:** Users trigger a failure toast instead of being told up front.
- **Recommended fix:** Disable these actions with a `title` that explains why. Choose the primary action from state (Start when stopped).

#### [P1-13] Settings audit log is raw JSON that overflows its card [DW]
- **Location:** Settings › Audit log.
- **Problem:** JSON lines run past the card's right edge (`request_id` is cut off) and break mid-token ("2026-10-" / "07T09…"). On mobile the whole card overflows. The same data appears as a table in Security › Audit, so there are two audit-log UIs.
- **Expected:** One audit table (time, actor, role, action, detail, IP) with wrapping and filtering.
- **Evidence:** `screenshots/settings-audit-overflow.png`.

#### [P1-14] The command palette trigger cannot be reached by keyboard [DW]
- **Location:** `layout.php:65`, `<div class="search-trigger">`.
- **Problem:** It is a `div` with no `tabindex`, `role` or keyboard handler, so the palette is only reachable by mouse or ⌘K. When the palette closes, focus returns to `<body>`.
- **Recommended fix:** Make it a `<button type="button">` with `aria-haspopup="dialog"`.

### P2: Medium

#### [P2-1] Page titles do not match nav labels, and every browser tab is titled "Nebula Panel" [DW]
- **Mismatches:** Firewall → "Security"; DNS → "DNS Manager"; PHP → "PHP Manager"; SSL → "SSL Certificates"; Users → "Users & access"; API Tokens → "API tokens" (case differs).
- **Browser titles:** `<title>` is the panel name on every page, so tabs, history and bookmarks cannot be told apart.
- **Fix:** Make the nav label and H1 identical, or deliberately pair them (for example a "Firewall & security" label). Render `<title><?= page label ?> · Nebula Panel</title>`.

#### [P2-2] Unknown routes render the Dashboard silently, and sub-pages lose nav context [DW]
- **Unknown routes:** `?r=doesnotexist` returns HTTP 404 but shows the full Dashboard with no message and no active nav item.
- **Role-blocked routes:** these also render the Dashboard (with a small notice).
- **Sub-pages:** `service&name=…` and `file-edit` highlight nothing, when Services and File Manager respectively should be highlighted.
- **Fix:** Add a dedicated `views/error.php` for 404 and 403 with a "Back to dashboard" button. Map extra routes to a parent in `nebula_extra_routes()` and highlight that parent.

#### [P2-3] Input and select heights are inconsistent [DW]
- **Measured:** `select.select` 38 px vs `input.input` 36 px, visible side by side in Backups (site vs label) and API tokens. The Logs toolbar input is 34 px and the Terminal input 24 px. Selects use the native OS arrow, which renders differently per platform.
- **Fix:** Give controls a fixed height (`height:36px`), `appearance:none` and a CSS chevron.

#### [P2-4] There are four tab-bar variants [DW]
1. In a card header (Services, Docker): 38 px and 42 px tall.
2. Page level (Users).
3. Inside a bordered pill container (PHP).
4. File Manager tabs: 36 px with 9 px badges.

Docker tabs carry count badges; PHP and Users tabs do not.

- **Fix:** One `.tabs` component with one height, and one placement rule (page-level tabs under the header, card-level tabs in the card head).

#### [P2-5] Stat cards come in four variants [DW]
- Dashboard: icon, value, label and bar. The fourth card uses a 20 px value and no bar.
- Users: icon and value, no bar, taller.
- Docker: no icon.
- Fail2Ban and ModSecurity: no icon, and text values such as "Detect" or "Yes" in a monospaced numeric style.
- **Fix:** A single `.stat-card` with optional icon and footer slots and fixed internal spacing.

#### [P2-6] Side-by-side card headers have different heights [DW]
- Dashboard "Live resources" header is 54 px; "Services" is 63 px because the `btn-sm` adds height. The titles sit 4 px apart vertically.
- Other headers measure 54, 56, 60 and 75 px depending on content.
- **Fix:** `.card-header{min-height:56px}` and `.card-header .btn-sm{margin:-4px 0}`.

#### [P2-7] Status colour semantics are inconsistent [DW]
- **Thresholds:** The top-bar meters turn orange at 60 % and red at 85 % (`app.js colorFor`). The health checks warn at 80 % and go critical at 90 %, and those thresholds are configurable in Settings, but the top bar ignores the settings. At 88 % disk the top bar shows red while the dashboard health shows orange "Needs attention" and the dashboard disk bar is purple.
- **Warning notices with an info icon:** DNS and Diagnostics show an orange "warning" notice with an ⓘ icon.
- **DNS record badges:** NS records use the red "danger" badge.
- **Logs counters:** "0 errors" and "0 warnings" are coloured red and orange.
- **Fix:** Drive all meters from the configured thresholds, reserve red and orange for real states, and pair the notice icon with its severity.

#### [P2-8] `badge-slate` disappears on hovered table rows [DW]
- `.badge-slate` background (`--track`, slate-800) is the same colour as `tbody tr:hover` (`--bg-surface-hover`, slate-800). On hover, "Stopped", "N/A" and "Not installed" lose their pill.
- **Evidence:** the Redis Server row in `screenshots/services-table.png`.
- **Fix:** Give badges `--track-2` or a translucent border.

#### [P2-9] Service names are capitalised incorrectly [DW]
- `services.php:62` applies `ucwords(str_replace('-', ' ', …))`, which produces "Mariadb", "Mysql", "Php8.3 Fpm", "Ssh", "Ufw" and "Redis Server".
- **Fix:** Add a display-name map (MariaDB, MySQL, PHP 8.3-FPM, SSH, UFW, Redis).

#### [P2-10] Service installation state contradicts itself [LW]
- The Services subtitle says "2 installed instances" (Redis, Docker). The table and the dashboard Services card show nine other units as "Stopped" rather than "Not installed", including nginx, which the wizard calls "installed with the panel".
- The two detection paths disagree.

#### [P2-11] Docker App Store shows a literal `→` [DW]
- `docker.php:13` puts a JavaScript escape inside HTML, so users see "Stacks → Publish".
- **Fix:** Use `→` or `&rarr;`.
- **Evidence:** `screenshots/docker-appstore-escape.png`.

#### [P2-12] PHP Health and OPcache show missing values and a missing icon [DW]
- **Health:** "OPcache disabled — **MB configured**" (the number is empty).
- **OPcache:** the stat shows "— MB".
- **Missing icon:** the fourth OPcache card's icon `scan-clock` is not in Lucide 1.8, leaving an empty coloured square and a console warning.
- **Header contradiction:** the header badge says "Loaded" while the state card says "Off".
- **Fix:** Render "Not configured" when the value is empty. Use `clock-check` or `timer`.
- **Evidence:** `screenshots/php-health-missing-value.png`.

#### [P2-13] DNS page throws a JavaScript error when there are no zones [DW]
- `views/dns.php` `sync()` calls `priority.disabled=…` on a null element: "Cannot set properties of null (setting 'disabled')".
- **Fix:** Guard with `if (!type || !priority) return;`.

#### [P2-14] File Manager sorting, icons and selection have several faults [DW]
- **Sorting:** names sort lexically (file1, file10, file11 … file2).
- **Icons:** Edit is `pencil-line` and Rename is `pencil`; at 17 px they look the same. Use `file-pen` or `square-pen` for Edit and `text-cursor-input` for Rename.
- **Context menu:** a folder gets "Open / edit". Paste is enabled with an empty clipboard. Items are `div`s that cannot be reached by keyboard (no `role="menu"`, no arrow keys).
- **Toolbar:** Delete is always red and enabled, even with nothing selected. There are two Upload buttons (the toolbar icon and the primary button).
- **Selection:** right-clicking highlights a row as "selected", but the status bar still says "0 selected", so two selection models coexist.
- **Visibility:** row actions sit at 25 % opacity until hover, so they are nearly invisible and unreachable for touch.
- **Header:** the page has no H1, unlike every other page.
- **Fix:** Use `natcasesort` / `strnatcasecmp`. Disable selection-dependent tools until something is selected. Unify selection on the checkbox. Raise row actions to at least 60 % opacity.

#### [P2-15] Native `confirm()` and `prompt()` are used everywhere [PI]
- There are 43 `confirm()` and 7 `prompt()` calls. Folder creation, rename, chmod and archive naming all go through `prompt()`, which offers no validation, no styling and no context. Destructive confirms look like browser alerts, not like the app.
- **Fix:** Add a small `Nebula.confirm({title, body, danger})` and `Nebula.prompt({label, validate})` built on the existing `.drawer` / `.modal-overlay` plumbing (a focus trap already exists in `wireAccessibility`).

#### [P2-16] Five date and time formats are in use [DW]
- "10/7/2026, 9:21:32 AM" (Users, via `toLocaleString`)
- "Oct 7, 09:20" (files)
- "Oct 7, 2026 09:20" (file details)
- "2026-10-07T09:19:41Z" (Panel Updates)
- "2026-10-07 09:22:01 UTC" (System Info)
- **Fix:** One server and client formatter, with relative time where appropriate.

#### [P2-17] "Unavailable / helper missing" states use four patterns [DW]
1. A centred empty-state card (Websites, SSL, Firewall, Databases).
2. An orange notice above a disabled form (Email).
3. A card header, a disabled button and an empty state inside the same card (phpMyAdmin, which leaves large empty space).
4. Inline orange text (Install Apps › PHP versions, SSH Keys).

- **Fix:** One `requirement-missing` component: icon, title, explanation and a primary fix action (link to Diagnostics or Install Apps).

#### [P2-18] Notices come in two layouts [DW]
- **Title and body:** small 17 px icon (DNS, Email, Docker).
- **Single paragraph:** large icon, different padding (Terminal, Diagnostics).
- **Fix:** Standardise on title and body.

#### [P2-19] Notification dropdown: Esc does not close it, and labels differ from the page [DW]
- The menu ignores Escape and does not return focus.
- Its button says "Mark all read"; the Notifications page says "Mark all as read".
- Filter chips are plural on the Notifications page ("Warnings") and singular on Logs ("Warning").

#### [P2-20] Drawers do not close on backdrop click and focus the close button first [LW]
- Clicking the dimmed backdrop does nothing (Users, Docker), while Escape works.
- Initial focus lands on the close button rather than the first field, because `prepareDialog` uses a single `querySelector` with a combined selector list and gets the first match in document order.
- **Fix:** Close on overlay click. Prefer `[autofocus]`, then the first form field.

#### [P2-21] The avatar and server chip look clickable but do nothing [DW]
- Both have `cursor:pointer` but no handler.
- There is no account area: no profile and no role display apart from a tooltip. Because `role_route_allowed()` blocks Settings for every role except admin, operators, developers and auditors **cannot change their own password anywhere** in the UI.
- **Fix:** Add an account menu (signed in as, role, my password, theme, sign out), or remove the pointer cursor.

#### [P2-22] Dashboard copy and refresh behaviour are wrong [DW]
- The card says "Sampled every 3s"; the code polls every 5 s (`app.js:300`).
- The page **Refresh** button only re-polls metrics, not processes, health or services.
- The chart starts empty for about 10 s, with one point and no line.
- **Light theme:** Chart.js defaults are hard-coded for dark (grid `rgba(255,255,255,.05)`, text `#8296ab`, font `'Inter'`, which is not loaded), so gridlines vanish in light mode.
- **Fix:** Correct the copy. Make Refresh reload every widget. Seed the series from a short history. Read colours from CSS variables when the theme changes.
- **Evidence:** `screenshots/light-dashboard-chart.png`.

#### [P2-23] Updates page has an empty card and two similar refresh buttons [DW]
- The "Command output" card renders as a header with a 0-height body.
- "Refresh" and "Reload package list" use the same icon and their difference is unclear.
- **Fix:** Hide the output card until a command has run. Rename the buttons to "Re-check" and "apt update".

#### [P2-24] System accounts misclassify users and the list has no tools [DW]
- `mod_users.php:40` classifies any UID from 1000 to 65533 as "Human", so 32 `nixbld*` accounts with `/sbin/nologin` show as Human, and the stat card says "33 Linux users".
- There is no search, filter or pagination for a list of about 60 rows.
- **Fix:** Also require a login shell. Add filtering.

#### [P2-25] Install Apps copy is false, and app icons mix families [DW]
- The subtitle says "installed services appear in the sidebar for management". The sidebar is static.
- Icons mix families: brand logos (Apache, MariaDB, Redis, Docker, Certbot, Git) sit beside generic Lucide icons (Memcached `zap`, Fail2Ban `shield`).
- The setup wizard uses only Lucide icons for the same apps, and gives Redis and Memcached the same `zap` icon, which is also the panel logo.

#### [P2-26] The Domains "Add Domain" button only navigates to Websites [LW]
- The label promises a domain form. The empty state says "Add a website to begin."
- **Fix:** Rename it to "Add website", or open the website-create flow directly.

#### [P2-27] DNS zone list shows a meaningless green dot [DW]
- Every zone shows an emerald badge with only a dot and no text, and no check backs it. Its meaning is conveyed by colour alone.

#### [P2-28] Login alerts use the badge component, and failed logins clear the username [DW]
- The error uses `.badge-red` (an 11.5 px pill) as an alert banner. Setup does the same.
- The username is cleared after a failed attempt.
- The form stays fully active during lockout.
- The lockout message reads "10 minute(s)".

#### [P2-29] Information architecture: updates are split, and notifications duplicate the dashboard [PI]
- "Updates" (System, OS packages) and "Panel Updates" (Tools) are separate.
- The Panel Updates icon is `git-branch`.
- The Notifications page lists the same three items as Dashboard › System health.
- Audit logs exist in both Settings and Security.
- **Fix:** Group updates under one "Updates" page with OS and Panel tabs. Keep a single audit-log location.

#### [P2-30] There is no type or spacing scale [DW]
- **Font sizes:** 19 distinct values (9, 9.5, 10, 10.5, 11, 11.5, 12, 12.5, 13, 13.5, 14, 15, 16, 17, 18, 20, 22, 26, 27 px). Text at 9–10.5 px appears in the cron builder hints, FM tabs badges, service card meta and Docker port chips.
- **Border radii:** 12 distinct values (3–20 px). The `--radius-*` tokens are rarely used; 7, 9 and 11 px are ad hoc.
- **Inline styles:** about 600 `style=""` attributes in views, many of them repeated typography (`font-size:13px;font-weight:600`).
- **Fix:** Define a scale (11, 12, 13, 14, 16, 18, 22) and spacing tokens (4/8/12/16/24), then replace inline styles with classes.
- This appears to be a system-wide issue rather than an isolated component issue.

#### [P2-31] Prose is set in monospace [DW]
- Diagnostics "Detail" column: sentences such as "Not installed — re-run install.sh…".
- Panel Updates commit message.
- Users "ID 1 · current session".
- **Fix:** Use monospace only for paths, versions, IDs and code.

#### [P2-32] The terminal input's focus ring looks like a separate box [LW]
- The terminal command field gets the generic `.input:focus` blue glow inside a terminal surface. The placeholder ("type a command and press Enter") is lowercase, unlike every other placeholder.

#### [P2-33] The sidebar scroll hides the Tools section on laptops [LW]
- At 1366×768 the nav ends at "Users". The **Tools** section (8 items, including Settings) is below the fold, and the "TOOLS" heading is clipped by the footer.
- **Fix:** Use collapsible section groups, or tighten item spacing to 32 px.

#### [P2-34] There is no "no results" state in the command palette, and search is label-only [DW]
- Typing "zzz" shows an empty list.
- "security" and "fail2ban" return nothing, even though the Firewall page is titled Security and contains Fail2Ban.
- **Fix:** Add a "No matches" row and keywords per module (`'firewall' => […, 'keywords' => 'security ufw fail2ban modsecurity waf']`).

### P3: Low

| ID | Issue | Location | Fix |
|---|---|---|---|
| P3-1 | Sidebar collapse state not persisted across pages | `app.js wireChrome` | Store it in `localStorage` |
| P3-2 | Docker subtitle reads "Docker Engine – · 0 containers" when the version is null | `docker.php` header | Omit the segment when empty |
| P3-3 | Decorative macOS traffic-light dots on the Logs and Terminal windows; they look like window controls | `.term-titlebar` | Remove, or make them neutral |
| P3-4 | Native checkboxes render as bright white squares on dark surfaces | `.row-check`, chmod grid, wizard | Style with `appearance:none` and tokens |
| P3-5 | System Info shows "No interface data (Linux only)" on Linux | `sysinfo.php` | Say "Interface data unavailable" |
| P3-6 | At 820 px the search trigger text wraps onto two lines | `.search-trigger{width:190px}` | `white-space:nowrap; text-overflow:ellipsis` |
| P3-7 | The Diagnostics "1 issue" badge sits in the page-actions slot, misaligned with the title baseline | `diagnostics.php` | Move it into the subtitle |
| P3-8 | Docker App Store logos (Adminer, Nextcloud) have low contrast on dark | `assets/logos` | Add a light tile behind logos |
| P3-9 | Redis appears both as an apt app (Install Apps) and as a Docker app, with no hint of the difference | Apps vs Docker store | Add "(system package)" / "(container)" |
| P3-10 | Users drawer subtitle "Role changes apply on the next request" shows when *adding* a user | `users.php` drawer | Use add-specific copy |
| P3-11 | The Domains "Server addresses" IP badge is mono 11.5 px | `domains.php` | Use a copyable code chip |
| P3-12 | Log source list uses the same `server` icon for every unit | `logs.php` | Use the service icon map |
| P3-13 | Mail field help text at 11 px inherits a 21 px line-height, so three lines look gappy | `.field-help` | Set `line-height:1.45` |
| P3-14 | `.empty-state` padding of 60 px leaves cards very tall on helper-missing pages | `style.css:412` | 40 px |
| P3-15 | Editor background (`--cm-bg:#21252b`) does not match the panel palette in dark mode | `style.css:53` | Use `var(--bg-surface)` |
| P3-16 | Install Apps "Remove" (26 px) and the "Installed" pill are vertically misaligned with "Install" (27 px) | `apps.php` | Covered by P1-1 |
| P3-17 | `cmdk` items have hard-coded inline section labels (`font-size:11px`) | `layout.php` | Add a class |
| P3-18 | A full page reload after almost every action (`setTimeout(location.reload)` ×36) loses scroll position and tab state | many views | Re-render the affected block, or restore the active tab from the hash |

### Cannot determine without clarification [CD]
- **PHP versions:** the setup wizard offers **PHP 8.5** while Install Apps offers **8.2**. Is that intentional (newest for new installs, older for side-by-side)?
- **Delete semantics:** should file deletes be recoverable (trash) or permanent? This decides the copy for P0.
- **Rail navigation:** is an icon-only rail on tablets intentional? It is acceptable with tooltips, but not on phones.
- **File editor theme:** the popup has no theme toggle. It inherits the theme on open but will not follow a change made in the main window.

---

## Cross-application consistency issues

**Buttons**
- `<button>` vs `<a>` font and height (P1-1).
- The primary action is sometimes a destructive or irrelevant one (Restart on a stopped service, Update now with an unknown baseline).
- "Delete" styling varies: `btn-secondary` with red text in the FM drawer, `btn-danger` elsewhere, and a red `icon-btn` in the toolbar.

**Icons**
- Edit vs Rename look the same.
- `zap` serves as logo, Redis and Memcached at once.
- `scan-clock` is missing.
- An info icon appears on warning notices.
- Lucide icons and brand logos are mixed for the same apps across Apps and the Wizard.
- The Panel Updates icon is `git-branch`.
- The topbar memory icon (`memory-stick`) is tiny (12 px) next to its 11.5 px text.

**Spacing**
- Card header heights range from 54 to 75 px.
- Stat card variants differ.
- `.empty-state` is 60 px; FM empty hints are 28 px.
- Page padding is 24/28 px on desktop and 20/18 px on mobile, but File Manager is 0.
- There is no spacing token set.

**Typography**
- 19 font sizes.
- Monospace for prose.
- The 9–10.5 px micro text is below a comfortable reading size.
- Arial inside buttons.
- Chart.js uses 'Inter', which is not loaded.

**Modals and overlays**
- Drawers are used for creation; native `confirm()`/`prompt()` for everything else.
- No `.modal` CSS exists even though `app.js` targets `.modal-overlay`.
- Backdrop-click behaviour differs: the command palette closes, drawers do not.
- Escape handling differs: drawers and the palette close, the notification menu does not, the context menu does.

**Navigation**
- Nav labels differ from H1s.
- Sub-pages get no active state.
- 404 and 403 fall back to the Dashboard.
- Two "Updates" areas.
- No mobile drawer.

**Colour**
- Light-theme status tokens fail contrast.
- Meter colours follow different rules in different places.
- Red is used for neutral data (NS records, zero error counts).
- `badge-slate` vanishes on row hover.

**Tables**
- Header alignment is inconsistent: numeric columns are right-aligned on the Dashboard, while the Users dates, the Docker table and the Backups size column are left-aligned.
- Actions columns are sometimes unlabelled (PHP Extensions) and sometimes labelled "Actions" or "Quick actions".
- No table has sorting indicators or pagination. The long ones are System accounts (about 60 rows) and Processes (20 rows).

---

## Recommended fix order

1. **Critical functional:** P0 duplicate delete handler; P2-13 DNS crash; P1-11 blank PHP settings with Save enabled.
2. **Broken layouts:** P1-2 inline grids and `overflow-x`; P1-3 drawer width; P1-6 File Manager table; P1-13 audit log overflow; P1-5 Security tab gating.
3. **Navigation:** P1-4 mobile navigation and rail names; P1-14 search trigger; P2-1 titles; P2-2 404, 403 and active parent; P2-34 palette empty state and keywords; P2-29 IA consolidation.
4. **Accessibility:** P1-7 contrast tokens; P1-8 icon-button names; P1-9 label association; P1-10 API form labels; P2-19 and P2-20 overlay keyboard and backdrop behaviour.
5. **Component consistency:** P1-1 button and form font reset; P2-3 control heights; P2-4 tabs; P2-5 stat cards; P2-6 card headers; P2-17 and P2-18 unavailable and notice patterns; P2-15 confirm and prompt dialogs.
6. **Typography and spacing:** P2-30 scale and tokens, inline-style removal; P2-31 monospace usage; P2-8 badge hover.
7. **Icon corrections:** P2-12 `scan-clock`; P2-14 edit and rename; P2-25 icon families; Redis and Memcached icons.
8. **Data and state polish:** P1-12 disabled states; P2-7 thresholds; P2-9 names; P2-10 install-state source; P2-11 escape; P2-16 dates; P2-22 to P2-24; then all P3s.

---

## Top 20 fixes

1. **Remove the duplicate `[data-fm-delete]` handler in `app.js`**, and make the delete copy match what the backend does (P0).
2. **Add a global form-control reset** (`button,input,select,textarea{font:inherit}`) **and fixed heights for `.btn`, `.btn-sm`, `.input` and `.select`** (P1-1, P2-3).
3. **Replace every inline `grid-template-columns` with responsive classes and drop `body{overflow-x:hidden}`** (P1-2).
4. **Add a mobile off-canvas sidebar with a hamburger button, plus `title` and `aria-label` on every nav link** (P1-4).
5. **Make drawers `width:min(420px,100vw)`** (P1-3).
6. **Fix the File Manager table:** truncate names, keep meta columns on one line, make Actions sticky (P1-6).
7. **Always render the Security tab bar**; scope the UFW empty state to its own tab (P1-5).
8. **Use darker status text tokens in the light theme and raise `--text-tertiary`** (P1-7).
9. **Give every icon-only button an explicit `aria-label` and `title`** (P1-8).
10. **Associate every label with its control (`for`/`id`)** (P1-9).
11. **Disable or explain actions that cannot succeed**, including Upgrade all with 0 updates, Docker installs with the daemon down, Start/Stop by state, and Create backup with no sites (P1-12).
12. **Show "not readable" instead of a blank PHP Settings form**, and disable Save (P1-11).
13. **Turn the search trigger into a `<button>`; add "No matches" and keyword search to the palette** (P1-14, P2-34).
14. **Replace native `confirm()`/`prompt()` with in-app modal components** (P2-15).
15. **Unify the tab, stat-card, notice and "requirement missing" components** (P2-4, P2-5, P2-17, P2-18).
16. **Make nav labels and H1s match, give each page its own `<title>`, and add proper 404/403 pages with parent nav highlighting** (P2-1, P2-2).
17. **Drive all meters from the configured health thresholds** and stop using red or orange for neutral data (P2-7).
18. **Fix data and formatting defects:** the `→` escape, "MB configured", the `scan-clock` icon, service display names, the `nixbld` "Human" label and the Docker "– ·" subtitle (P2-9, P2-11, P2-12, P2-24, P3-2).
19. **Render the audit log as a single filterable table** and remove the raw JSON block from Settings (P1-13).
20. **Define and adopt a type scale and spacing tokens**, moving the about 600 inline styles into classes (P2-30).

---

## Resolution

| Area | What changed |
|---|---|
| P0 delete | One handler (in `views/files.php`). Deletes move to a recoverable trash (`data/trash/files`, with restore/purge/empty in a new **Trash** tab); the delete dialog has a *Skip the trash and delete permanently* option; `api/file-delete.php` takes `permanent`, `api/file-trash.php` is new. |
| Design system | Global form-control font reset; fixed control heights (`--control-h` 36px, `--control-h-sm` 28px); type scale (9 sizes) and radii (5 values); `:focus-visible` ring; themed checkboxes/radios/selects; shared modal, notice (info/warning/danger/success), stat-card, tab and `requirement_missing()` components. |
| Accessibility | Light-theme status tokens and `--text-tertiary` meet WCAG AA in both themes; every label is associated with its control; icon-only controls get names and tooltips; search trigger is a button; tabs have roles and arrow-key support; drawers/modals trap focus, close on Esc/backdrop and restore focus; context menu is keyboard-operable. |
| Responsive | Inline grids replaced with responsive classes; `body{overflow-x:hidden}` removed; drawers `min(420px,100vw)`; ≤900px uses an off-canvas, fully labelled sidebar with a menu button; search stays reachable on phones. |
| Navigation | Nav label = H1 = `<title>` for every page; sub-pages highlight their parent; real 404/403 page; collapsible, persisted nav sections; persisted sidebar collapse; account menu with *My account* (password change for every role); command palette has keywords and a no-results state; Panel Updates merged into Updates. |
| Data/state | Health thresholds from Settings drive every meter; disabled/explained actions when they cannot succeed; services report *Not installed* correctly with proper names; PHP settings show an unreadable state instead of a blank form; Docker surfaces daemon errors; one date format (`7 Oct 2026, 09:21`); natural sort; login-shell based *Login*/*System* account types; real process names. |
| Dialogs | All 50 native `confirm()`/`prompt()` calls replaced by `Nebula.confirm` / `Nebula.prompt` / `Nebula.dialog` with inline validation. |
| PHP 8.5 | `install.sh` runs the panel on `PANEL_PHP` (default 8.5 via the ondrej/php PPA, distro fallback, stale-pool cleanup); version pickers default to the latest release; the setup wizard always recommends 8.5. |
| Tests | `tests/smoke.php` gained regressions for the double delete, native dialogs, trash round-trip, natural order, nav parity, account access, display names and the PHP 8.5 default. |


After-fix screenshots are in [`after/`](after/).
