# Theme Builder

Theme Builder is a Site Owner authoring workspace for
[Jyavani CMS](https://jyavani.com/). It creates private theme drafts, builds and
installs verified packages, creates complete physical forks of installed themes,
tracks fork provenance, and links related owner workspaces.

Installed-theme PHP inspection, direct editing, revisions, baselines, exports,
and Store update protection are owned by Jyavani Core.

Theme Builder 1.8.1 uses the native theme source editor introduced in Jyavani
Core 2.3.164. Install or update Core first; activation remains blocked on older
Core versions rather than restoring the former duplicate installed-source editor.

## Requirements

- Jyavani Core 2.3.164 or newer
- PHP 8.1 or newer
- PHP extensions: PDO, JSON, ZIP, mbstring, and tokenizer
- `proc_open()` and a PHP CLI binary matching the web runtime's PHP version
- Writable private workspace and temporary storage

Set `THEME_BUILDER_PHP_CLI` when the matching PHP CLI binary cannot be found
automatically.

## Ownership

Theme Builder owns:

- Private draft workspaces and the bundled starter theme.
- Draft PHP, CSS, JavaScript, and structured `theme.json` editing.
- Verified draft ZIP build, download, and Core-backed installation.
- Complete physical installed-theme forks with detached Store identity.
- Managed-fork provenance and safe deletion.
- Navigation to Core Theme Templates, Jy Builder, and Content Translation.

Jyavani Core owns:

- Installed PHP inventory and source inspection.
- Existing-file PHP save and revision restore.
- Installed source revisions and protected baselines.
- Store update source preflight and replacement decisions.
- Current source, revision, and baseline export.
- Native Theme Manager and `admin/themes/source` interfaces.

Theme Builder does not register fallback installed-source mutation routes. If
Core's `theme_source_service()` contract is unavailable, installed inventory and
fork creation fail clearly instead of restoring the former plugin editor.

## Draft Workflow

1. Open **Tools > Theme Builder**.
2. Create a draft with a lowercase slug, name, and optional metadata.
3. Edit canonical PHP slots, supported assets, and allowlisted `theme.json`
   fields.
4. Build the theme ZIP. Theme Builder lints PHP and verifies source against the
   completed archive.
5. Download the verified ZIP or install it through Jyavani Core.
6. Activate or assign the installed theme from Core's Themes interface.

Installing a draft does not activate it automatically. Drafts remain separate
from installed theme trees.

### Editable Draft Files

The editor supports canonical starter slots and these assets:

```text
assets/css/style.css
assets/css/blocks.css
assets/js/script.js
```

Draft source must be valid UTF-8, contain no NUL bytes, and remain within the
workspace's bounded file and package limits.

## Installed Themes

Use **Installed Themes & Forks** to open Core's native source editor, start a
complete fork, delete an eligible managed fork, or navigate to related owner
workspaces. Theme Builder obtains PHP inventory and opaque file identities from
Core's `ThemeSourceService`; it does not render another source editor.

Core's native editor is available at `admin/themes/source` with the registered
`folder` query parameter. Core provides inspection, direct editing, revisions,
restore, dirty-state reporting, and source export there.

Theme Section owner navigation reads bounded wrapper bytes through Core's
`ThemeSourceService::source()` and resolves literal local dependencies against
opaque identities from Core's inventory. Theme Builder does not scan
`VIEWS_BASE`, execute source, or accept browser filesystem paths.

## Fork And Edit

**Fork & Edit** creates a complete physical copy before opening Core's source
editor:

1. Select a registered installed theme.
2. Choose a new lowercase folder, runtime name, and display title.
3. Theme Builder verifies and copies the complete physical tree.
4. The fork receives a distinct identity, loses Store metadata, and is
   registered inactive.
5. Core edits the fork while Theme Builder's policy requires it to remain
   inactive and unassigned.
6. Activate or assign the fork through Jyavani Core after review.

Forking does not copy database Theme Templates, assignments, customizations,
translations, or Theme Zone records.

Theme Builder contributes a monotonic `theme_source_edit_policy` filter. It can
deny Core `edit`, `save`, and `restore` operations for managed forks that are
active, assigned, or whose provenance cannot be verified. It never reverses an
earlier denial and does not claim Store baseline ownership.

Theme Builder contributes one purple, owner-labelled action group containing
`Fork & Edit` and `Owner Workspaces` to Core Theme Manager cards through
`theme_manager_theme_actions`, and to the source editor through
`theme_source_editor_actions`. It does not add a direct-save or export action.

## Managed Fork Deletion

Only an inactive, unassigned Theme Builder fork with matching provenance and
physical root identity can be deleted. Deletion removes the registered fork,
its physical tree, Theme Builder provenance, and associated Theme Zone rows.

Legacy data under `cfg/var/theme-builder/.baselines` and `.revisions` is retained
and is not used as current authority. Managed-fork deletion does not remove that
legacy history.

### Legacy History Export

The installed-theme page offers **Export Legacy History** for retained Theme
Builder baseline and revision records. This is a global, read-only recovery
export and is intentionally separate from Core's current installed-source
export.

The export requires both `core.themes.manage` and Site Owner authority, plus POST
and CSRF. It rejects symlinks, special files, unsafe paths, oversized records,
and changed file identities. The verified ZIP is created in private temporary
storage, streamed with no-store headers, and removed after the request. Export
does not parse legacy records as current authority and never writes installed
theme source.

## Security Model

- All plugin routes require both `core.themes.manage` and Site Owner authority.
  The manifest's non-delegable Site Owner guard conceals the route before its
  included page or API handler performs the capability check.
- Mutations are POST-only and require Core CSRF validation.
- Draft writes use stale-hash checks and PHP linting.
- Fork creation copies a bounded, symlink-free complete tree through private
  staging, verifies hashes, and publishes under Core lifecycle locks.
- Fork deletion rechecks inactive and unassigned state under lifecycle and
  database locks.
- Installed source requests use Core's opaque identities and writer pipeline.
- PHP lint is syntax validation, not a sandbox or malware scan.

## Private Storage

The default workspace is under Jyavani's private `cfg/var/theme-builder`
directory. It can be overridden with `THEME_BUILDER_WORKSPACE`.

The workspace must not be world-writable or located below the public directory
or installed-theme root. Current Theme Builder state includes drafts, build
artifacts, managed-fork provenance, and operation locks. Existing legacy
baseline and revision directories are deliberately left untouched. Temporary
`.legacy-exports` archives are removed after download or failed construction.

## Development

Run the standalone contract suite from the repository root:

```bash
for test in tests/*_contract.php; do
  php "$test" || exit 1
done
```

Lint every PHP source:

```bash
while IFS= read -r -d '' file; do
  php -l "$file" || exit 1
done < <(find . -name '*.php' -print0)
```

The tests use temporary directories and PDO SQLite. Some filesystem checks can
report `SKIP` when the host does not permit symlink creation.
