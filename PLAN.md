# PLAN.md — survos/installer

Current and near-term work. Append decision records as work progresses.

## Status

Forked from `endroid/installer` (a pure file-copier) and extended toward a
Flex-recipe-equivalent: `.env`/`.gitignore` merging, post-install output, and
removal reversal. The code is **mid-refactor and not yet stable** — it was
pulled from the Survos bundle set (`survos/bunny-bundle` etc.) because of it.

The goal is to faithfully simulate `symfony/recipes-contrib` from a recipe
embedded in each bundle, so that publishing to [survos/recipes](https://github.com/survos/recipes)
later is a copy, not a rewrite.

## Why it wasn't stable (root cause)

Three competing directory conventions are live at once, so nothing agrees:

| Reads / ships | Path | Used by |
| --- | --- | --- |
| `Installer.php` (env/gitignore/post-install) | `.install/symfony/*.txt` | code, `processInstall()` |
| `Installer.php` (yaml copy + manifest) | `.installer/symfony/`, `.installer/manifest.yaml` | code, `processInstall()` |
| `bunny-bundle` actually ships | `recipe/symfony/{env,post-install}.txt` | the only real consumer |

Result: `bunny-bundle/recipe/symfony/env.txt` (`BUNNY_API_KEY=`) is **never found**
by the installer, which looks under `.install/`. File-copying "worked at one
point" because it ran through a different code path. **Decision below collapses
this to one convention.**

## Decisions (settled)

- **2026-06-10 — Canonical recipe format is Flex `manifest.json` under `recipe/`.**
  Bundles ship `recipe/manifest.json` in standard Symfony Flex format (`bundles`,
  `copy-from-recipe`, `env`, `gitignore`, `post-install-output`) plus the files to
  copy. The installer parses `manifest.json`. Chosen over the simpler `*.txt`
  convention and over dropping the plugin entirely, because the endgame is
  recipes-contrib: with this format, **publishing = `cp -r recipe/
  survos/recipes/<vendor>/<pkg>/<x.y>/`** with no conversion. Retires the
  `.install/`, `.installer/`, and `recipe/symfony/*.txt` paths.
- **2026-06-10 — Testing = extracted unit logic + one integration smoke.**
  File-manipulation logic moves into a composer-agnostic service unit-tested
  against temp dirs (the real coverage), plus a single end-to-end `composer
  require`/`remove` test for event wiring. See Phase 3.
- **2026-06-10 — Removal must reverse install.** Subscribe to
  `pre-package-uninstall` (recipe files still present in vendor/) and strip the
  scoped `.env`/`.gitignore` blocks + remove unchanged copied files.

## Architecture target

Split composer glue from logic so the logic is testable:

```
Survos\Installer\Installer            # PluginInterface — thin: subscribes events,
                                      #   resolves recipeDir + projectDir, delegates
Survos\Installer\Recipe\Manifest      # value object: parses recipe/manifest.json
Survos\Installer\Recipe\RecipeApplier # apply(Manifest, projectDir): env, gitignore,
                                      #   copy, post-install — and the inverse remove()
Survos\Installer\Recipe\ScopedBlock   # ###> pkg ### block read/write/remove on a file
```

`RecipeApplier` and `ScopedBlock` know nothing about Composer — they take paths
and strings. `Installer` is the only class that touches `Composer`/`IOInterface`.

## Active: Phase 1 — Stabilize and clean up

1. Resolve the in-flight merge (`composer.json`, `src/Installer.php` conflicts are
   still `UU`). Keep survos identity + `symfony/finder`+`symfony/yaml`;
   `git add` composer.json.
2. Delete dead code from the upstream architecture: `install()`,
   `installProjectType()`, `copy()`, `copyFile()`, `insertIntoFile()`,
   `applyLinesToFile()`, and the scattered `die()` debug calls. The active path is
   only `post-package-*` → `processInstall()`.
3. Fix CI: `.github/workflows/CI.yml` calls `vendor/bin/unit-test` /
   `functional-test` / `code-quality` from `endroid/quality`, which the fork
   dropped. Either re-add the quality tooling or replace with plain
   `vendor/bin/phpunit` + a chosen static-analysis tool.

## Phase 2 — Single recipe format

4. Add `Recipe\Manifest` — parse `recipe/manifest.json`; tolerate a missing recipe
   dir (no-op).
5. Add `Recipe\ScopedBlock` — write/remove `###> pkg ###`…`###< pkg ###` on a target
   file. **Idempotent**: re-applying replaces the block, never appends a duplicate
   (today `writeScopedBlock` has the de-dupe `preg_replace` commented out, so
   re-runs duplicate the block — fix this).
6. Add `Recipe\RecipeApplier`:
   - `applyEnv` / `removeEnv` — VAR=value lines as a scoped block;
     **preserve comment notation** (see open question).
   - `applyGitignore` / `removeGitignore`.
   - `copyFiles` / `removeFiles` — honor `copy-from-recipe` placeholders
     (`%CONFIG_DIR%`, `%SRC_DIR%`, `%ROOT_DIR%`); copy-if-absent; on remove, delete
     only if the file still matches what was copied (hash/compare) so user edits
     survive.
   - `postInstallOutput` — print `post-install-output` lines.
7. Rewire `Installer`: subscribe `post-package-install`, `post-package-update`,
   and **`pre-package-uninstall`**; resolve `recipeDir = installPath . '/recipe'`;
   delegate to `RecipeApplier`. Honor `extra.survos.installer.enabled|exclude`.

## Phase 3 — Tests

8. Unit tests (`tests/Recipe/`) against a temp project dir + fixture recipes:
   - env block added; idempotent on re-run; comment lines preserved.
   - gitignore block added/removed.
   - copy-if-absent; pre-existing file untouched.
   - **remove reverses apply** — `.env`/`.gitignore`/files return to prior state.
9. One integration smoke (`tests/Functional/`): fixture host project + fixture
   bundle as a path repo; run real `composer require` then `composer remove`;
   assert `.env`, `.gitignore`, copied files before and after.
10. Restore a green CI matrix (8.4, 8.5) running both suites.

## Phase 4 — Reintroduce + dogfood

11. Move `survos/bunny-bundle` to `recipe/manifest.json` (`env.BUNNY_API_KEY`,
    `post-install-output` from the existing `post-install.txt`). Re-add
    `survos/installer` to the bundle set.
12. Verify in `showcase`: `composer require survos/bunny-bundle` →
    `.env` gains `BUNNY_API_KEY=`, next-steps print; `composer remove` reverts.
13. Migrate the remaining bundles' recipes to the single format.

## Phase 5 — Publish to recipes-contrib (later)

14. A monorepo task that, for stabilized bundles, copies `recipe/` →
    `survos/recipes/<vendor>/<pkg>/<x.y>/`, regenerates `index.json`, and (the big
    win) lets projects drop `survos/installer` in favor of the Flex endpoint.
    The `recipe/manifest.json` format means this is a copy + index regen, not a
    rewrite.

## Open questions

- **Comment-notation preservation in `.env`.** Flex's `env` is a flat
  `VAR => value` object with no slot for comments, but bunny's old `env.txt` had
  human notes. Decide how a recipe attaches a comment to a var — e.g. an optional
  `env-comments` map keyed by VAR, or allow `#`-prefixed entries in a list form —
  and render them inside the scoped block. Needs a fixture + test.
- **`copy-from-recipe` placeholder set.** Confirm which Flex placeholders we honor
  (`%CONFIG_DIR%`, `%SRC_DIR%`, `%ROOT_DIR%`, `%PUBLIC_DIR%`) and their defaults.
- **`bundles` registration.** Flex registers bundles in `config/bundles.php`. With
  Symfony Flex already present in a project, do we let Flex own that and only
  handle env/gitignore/copy/output, or replicate bundle registration too? Likely
  defer to Flex; document it.

## Out of scope (for now)

- Reimplementing Flex aliases / `composer-scripts` / `conflict` keys.
- Anything in the `survos/recipes` repo itself (Phase 5 touches it, but its
  maintenance is separate).
