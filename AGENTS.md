# MIGUD Media Manager – Agent Instructions

Permanent, project-wide rules only. Do not use this file as a changelog or work log;
release notes belong in `readme.txt`, task notes in the commit message.

## Project

- Standalone WordPress plugin, independent of any theme (no theme functions, mods or paths).
- Virtual media folders: private, hierarchical attachment taxonomy `migud_mm_folder`.
  Files, URLs, attachment IDs and metadata are never changed. No custom tables.
- Prefixes: PHP `MIGUD_MM_` / `Migud_MM_`; storage keys, hooks, actions, parameters and error
  codes `migud_mm_`; REST namespace `migud-mm/v1`; text domain `migud-media-manager`;
  CSS classes/ids, asset handles and files `migud-mm-`, custom properties `--migud-mm-`,
  JS globals and model keys `migudMM*`.
- Formerly "Fena Media Manager" (slug `fena-media-manager`, prefix `fmm_`); the main file is
  `migud-media-manager.php`. Former identifiers are accepted only in `Migud_MM_Legacy`
  (documented adapters), `Migud_MM_Migration` and the former-plugin check in the main file.
- No build step and no runtime dependencies; the repository is the shippable plugin.

## Architecture

- `includes/class-migud-mm-taxonomy.php`: taxonomy, capabilities, assign/move, folder tree.
- `includes/class-migud-mm-rest.php`: REST routes (every route has a permission callback).
- `includes/class-migud-mm-library.php`: assets, summary counts, media query filter, upload assignment.
- `includes/class-migud-mm-list-mode.php`: list view filter and bulk actions.
- `includes/class-migud-mm-attachment-fields.php`: folder checkboxes in attachment details.
- `includes/class-migud-mm-legacy.php`: transition adapters for the former `fmm` identifiers
  (REST `fmm/v1` with former error codes, deprecated filters, request parameters, admin-post
  actions, settings page slug). The bundled scripts still depend on them.
- `includes/class-migud-mm-migration.php`: explicit, resumable `fmm_*` -> `migud_mm_*` data
  migration. Never wire it to activation, `admin_init` or any other automatic hook.
- `includes/class-migud-mm-maintenance.php`: pauses all folder features and writes while
  former data is not migrated or a migration is unfinished.
- `assets/js/migud-mm-media-filter.js`: shared folder store, query/upload integration, modal filter.
  The only place that extends core Backbone media views; feature-detect every extension point.
- `assets/js/migud-mm-folder-sidebar.js`: grid sidebar (tree, CRUD, assign/move, drag and drop).

## Stored data and compatibility

- Saved folders and assignments are user data. Do not rename stored keys (taxonomy, term/user
  meta, options) without a migration step; keep REST routes/params, hooks and query props
  stable or provide a documented adapter.
- Nothing may write plugin data while `Migud_MM_Maintenance::is_active()`; new write paths
  must respect it.
- Exported folder files of the former name (`fena-media-manager/folders`) stay importable
  (`Migud_MM_Transfer::LEGACY_FORMATS`).
- Deactivation and uninstall delete nothing.
- Warn before any change to stored-data meaning; recommend a database backup first.
- Git rollback does not roll back the database.

## Security

- Check capabilities server-side for every attachment (`edit_post`) and every folder action.
- Verify nonces, sanitize input, escape output. Core prints bulk action labels unescaped:
  escape them before returning.
- Never commit credentials, cookies, `.env`/`*.cnf` files or local test helpers.

## Versioning and releases

- Bump the version in three places together: plugin header `Version`, `MIGUD_MM_VERSION`,
  `Stable tag` in `readme.txt`; add a changelog entry.
- Production asset cache busting relies on `MIGUD_MM_VERSION`; local/development environments
  append the file modification time automatically.
- Release ZIP: `git archive --prefix=migud-media-manager/ -o migud-media-manager-<version>.zip v<version>`
  (`.gitattributes` excludes development files).

## Coding standards and verification

- WordPress Coding Standards (`phpcs.xml`). New code must pass PHPCS without new warnings.
- PHP: `php -l <file>` and `phpcs` from the plugin root. JavaScript: `node --check <file>`.
- Keep verification proportional; behaviour changes need a functional check in the media
  library (grid, list view, media modal), including uploads and drag and drop when affected.
- Keep PHP output, JS behaviour and server-side checks in sync (e.g. folder filter semantics:
  a folder shows only its own media, not its subfolders).

## Git

- Default branch `main`. No commits or pushes without explicit request.
- Do not delete or update the plugin through the WordPress admin in a working copy:
  it replaces the folder including `.git`.

## Regression safety (binding)

Applies to every code change in the Fena Theme, Fena Toolkit and MIGUD Media Manager.
A fix must not break anything that worked before.

1. **Before the change:** inspect the affected code and its callers/dependencies and record
   the current behaviour. For anything beyond a trivial CSS/text change, take a suitable
   baseline first (render output, screenshots, PHPStan/PHPCS state of the touched files).
2. **Change only what is necessary:** touch only the files and functions the task requires.
3. **After the change:** re-check the original behaviour, then related features for
   regressions: blocks, settings, module toggles, editor and frontend, responsive views.
   Visual changes: compare before/after screenshots.
4. **Preserve** existing features, stored data (options, theme mods, post/user/term meta,
   taxonomy data) and saved block content. Block changes must keep existing saved blocks
   valid (no block validation errors in the editor) - use a deprecation when markup changes.
5. **Static analysis is not a goal in itself:** never remove or alter working behaviour just
   to satisfy PHPStan, PHPCS, Plugin Check or Semgrep. Document existing findings in the
   baselines instead; new code must not add findings.
6. **Unexpected side effects:** stop, investigate the cause, report to the user and revert
   the change if needed. Do not continue on top of a broken state.

Especially sensitive: the Theme <-> Toolkit interplay (toolkit bridge/handshake), block
registration, module toggles, and editor vs. frontend rendering.

### Known open item: Fena Video block

- The Fena Video block (`fena/video`, Toolkit `blocks/video/`) has a known bug that will be
  investigated separately. Do NOT fix or restructure it as part of other work.
- Do not change it accidentally. If a necessary change could affect the Video block
  (shared helpers, shared CSS/JS, block registration, embed handling), point this out and
  get the user's approval before making the change.

### Verification scope by risk

- **A – CSS/text only:** before/after screenshots of the affected views (editor and frontend,
  375/768/1440 px).
- **B – PHP/JS logic:** A plus `php -l` (PHP 7.4 and 8.5), `node --check`, PHPCS and PHPStan
  against the baselines (no new findings), render comparison, empty `debug.log`.
- **C – security/data/blocks** (REST/AJAX, uploads/SVG, database, capabilities, block
  attributes/save, module registration, Theme <-> Toolkit): B plus Plugin Check, Semgrep,
  manual review (nonce, capability, sanitizing, escaping, `prepare`), negative tests
  (other role, invalid nonce, malicious SVG), block validation, both themes, module toggles.
- **Before a commit:** review `git diff` (only intended files, no test helpers or secrets).
- **Before a release:** full run including Plugin Check/Theme Check, PHPCompatibilityWP
  (`testVersion 7.4-`), minimum and current WordPress version, install and update with
  existing data.

### QA tooling (local, never shipped)

- Central tools: `~/.local/share/wp-dev-tools` (see its `README.md`): PHPStan level 5 with
  WordPress stubs and baselines, PHPCS/WPCS with line-independent baselines
  (`tools/phpcs-baseline.php compare <project>`), Semgrep (`bin/semgrep-wp <project>`),
  WP-CLI, `tools/wp-since-check.php`.
- Regression tests and baselines: `~/fmm-dev/qa-tools` (see its `README.md`), test site
  `/Applications/MAMP/htdocs/fmm-qa` (separate database). Test copies must be synced with
  `sync-check.sh` before testing. Never run tests against the `secondary` site database.
