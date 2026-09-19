# Theme Builder

Theme Builder is a secure PHP theme source workspace for
[Jyavani CMS](https://jyavani.com/). It lets the Site Owner create theme drafts,
inspect installed themes, fork themes for safer customization, edit guarded PHP
source, retain revisions, and export source without executing editable PHP
inside the builder.

Theme Builder is a source-code tool, not a visual or no-code page builder.

## Requirements

- Jyavani Core 2.3.87 or newer
- PHP 8.1 or newer
- PHP extensions: PDO, JSON, ZIP, mbstring, and tokenizer
- `proc_open()` and a PHP CLI binary matching the web runtime's PHP version
- Writable private workspace and temporary storage

Set `THEME_BUILDER_PHP_CLI` when the matching PHP CLI binary cannot be found
automatically.

## Features

- Create private theme drafts from a bundled starter theme.
- Edit canonical PHP theme slots with a CodeMirror source editor.
- Edit the bundled CSS and JavaScript assets and selected `theme.json` fields.
- Build, verify, download, and install flat theme ZIP packages.
- Inspect registered installed themes without executing their PHP source.
- Review file hashes, sizes, line counts, permissions, ownership, dependencies,
  UTF-8 state, and canonical slot resolution.
- Fork an installed theme into a distinct inactive theme before customization.
- Perform advanced direct PHP edits with explicit risk acknowledgements.
- Record durable revisions before changed installed PHP is replaced.
- Restore revisions through the same validation and atomic-write pipeline.
- Track protected PHP baselines and detect modified, added, deleted, or
  untracked files before Store updates.
- Export current installed PHP, revisions, and baseline metadata for recovery.
- Navigate to related Core Theme Templates and authorized owner workspaces.

## Installation

Install Theme Builder through Jyavani's Plugin Store or upload its plugin
package through the Jyavani plugin manager. Activate it there so Core can check
the requirements, register the Site Owner routes, and publish the plugin's
static stylesheet.

Open **Tools > Theme Builder** after activation. Every page and API route is
restricted to the Jyavani Site Owner.

## Draft Workflow

1. Open **Tools > Theme Builder**.
2. Create a draft with a lowercase slug, name, and optional theme metadata.
3. Edit the canonical PHP slots and supported assets.
4. Update the allowlisted `theme.json` fields when needed.
5. Build the theme ZIP. Theme Builder lints PHP and verifies the source tree
   against the completed archive.
6. Download the verified ZIP or install it through Jyavani Core.
7. Activate or assign the installed theme from Core's Themes interface.

Installing a draft does not activate it automatically. Drafts remain separate
from installed theme trees.

### Editable Draft Files

The editor exposes the canonical PHP slots used by the starter theme, including
header, footer, sidebar, homepage, search, error, list, index, and single views.
It also supports these assets:

```text
assets/css/style.css
assets/css/blocks.css
assets/js/script.js
```

Draft source must be valid UTF-8, contain no NUL bytes, and remain within the
workspace's bounded file and package limits.

## Installed Theme Inspection

Use **Inspect Installed Themes** to browse PHP source from themes that are both
physically present and registered in Jyavani Core. The inspector categorizes
canonical slots, sections, shortcode partials, helpers, and other views. It also
shows whether a canonical slot is physical, inherited from the default theme,
or missing.

Inspection uses opaque file identifiers, strict path containment, and bounded
source reads. Literal local `require` and `include` relationships are reported
without executing the inspected file.

Theme Builder can link to related owner workspaces when their routes are
installed and authorized, including Core Theme Templates, Jy Builder layouts,
and Content Translation theme resources. Those records remain owned by their
respective systems.

## Fork and Edit

**Fork & Edit** is the safer way to customize an installed theme:

1. Select a registered installed theme.
2. Choose a new lowercase folder, runtime name, and display title.
3. Theme Builder copies and verifies the complete physical theme tree.
4. The fork receives a distinct identity, is detached from Store metadata, and
   is registered as inactive.
5. Edit the fork while it remains inactive and unassigned.
6. Activate or assign it through Jyavani Core after review.

Forking copies physical theme files. It does not copy database Theme Templates,
assignments, customizations, translations, or Theme Zone records.

## Direct Editing

Advanced direct editing is available for eligible installed themes. It is not
available for the Core default or system themes. A direct-edit candidate opens
read-only until the Site Owner explicitly accepts the relevant risks:

- Saved PHP can execute with the web process's privileges.
- Changes to an active or assigned theme can affect live requests immediately.
- A later Store update can replace local PHP changes.

Use a fork when live direct editing is unnecessary.

## Revisions

Before replacing changed installed PHP, Theme Builder stores the exact displaced
bytes as a private revision. Revision metadata includes source hashes, actor,
time, file identity, operation, ownership, permissions, baseline identity, and
the physical theme root identity.

- An unchanged save does not create a revision.
- Restoring a revision first records the current source as an undo revision.
- Unsaved browser-buffer changes are not revisioned.
- Revision history is bounded by count and storage limits.
- Revisions are valid only for their registered theme and physical root.

## Store Update Protection

Theme Builder records protected baselines for physical PHP after Core theme
installation and updates. It compares current PHP against that baseline before
a Store-managed theme update.

Modified or untracked PHP creates a blocking preflight issue. Continuing
requires an explicit destructive decision to replace local PHP with the incoming
version. The decision is bound to the current hashes and update identity; stale
or malformed decisions fail closed. Theme Builder does not merge or reapply
local PHP after an update.

The plugin also blocks its own disable or deletion when Store-managed theme PHP
is modified, untracked, or cannot be verified.

## Export Types

Theme Builder provides two different exports:

### Draft Build ZIP

- Contains the complete draft theme tree.
- Places `theme.json` and theme files at the archive root.
- Is suitable for installation through Jyavani Core.
- Becomes stale after the draft source changes.

### Installed PHP Source Export

- Contains current physical PHP, valid revisions, export metadata, and the
  protected baseline when available.
- Excludes CSS, JavaScript, images, fonts, `theme.json`, assignments, Theme Zone
  records, and database customizations.
- Is a recovery/source-review archive, not a complete installable theme.

## Security Model

- All routes are Site Owner-only and enforce access in depth.
- Mutations are POST-only and require Core CSRF validation.
- Reads, saves, restores, forks, and exports use strict path containment and
  reject symlinks or special filesystem entries.
- SHA-256 state tokens prevent stale writes.
- Installed writes use Core lifecycle locks and atomic same-directory
  replacement while preserving ownership and permissions.
- Forks, packages, and exports are verified before publication or download.
- Private metadata, revisions, baselines, and exports stay outside the public
  and installed-theme roots.
- Failures to verify source or storage state fail closed.

Theme Builder parses PHP with `php -l`; it does not execute editable source in
its editor, inspector, or export pipeline. Syntax validation is not a malware
scanner, sandbox, or proof that code is safe. Installed or activated theme PHP
is later executed normally by Jyavani Core.

## Private Storage

The default draft workspace is under Jyavani's private `cfg/var/theme-builder`
directory. It can be overridden with `THEME_BUILDER_WORKSPACE`.

The configured workspace must not be world-writable or located below the public
directory or installed theme root.

## Development

Tests are standalone PHP contract scripts. From the repository root, run:

```bash
for test in tests/*_contract.php; do
  php "$test" || exit 1
done
```

Lint all PHP sources with:

```bash
while IFS= read -r -d '' file; do
  php -l "$file" || exit 1
done < <(find . -name '*.php' -print0)
```

The contract suite uses temporary directories and PDO SQLite. Some filesystem
checks can report `SKIP` when the host does not permit symlink creation.

Bug reports and focused contributions are welcome through
[GitHub Issues](https://github.com/adammuizweb/theme-builder/issues).
