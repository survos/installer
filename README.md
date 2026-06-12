# survos/installer

*By [survos](https://github.com/survos)*

A Composer plugin that applies a bundle's **recipe** to the host project on
install/update, and reverses it on removal — a lightweight, in-bundle stand-in
for [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib).

Forked from [endroid/installer](https://github.com/endroid/installer), which
only copies files. This fork adds the rest of what a Flex recipe does: merging
`.env` and `.gitignore`, printing post-install notes, and undoing all of it when
the package is removed.

## Why this exists

Recipes-contrib is the "real" mechanism, but it lives in a **separate repo** and
must be kept in lockstep with every bundle release. During active development
that round-trip is painful. This plugin lets a bundle **carry its own recipe**
(`recipe/` at the package root), so the recipe ships and versions with the code.

The recipe format is **identical to a Symfony Flex recipe** (`recipe/manifest.json`).
That is deliberate: when a bundle stabilizes, publishing to
[survos/recipes](https://github.com/survos/recipes) is just copying the `recipe/`
directory into the recipes repo — no format conversion. The plugin is the
stopgap; recipes-contrib is the destination.

## What it does

On `composer require` / `composer update` of a package that contains a `recipe/`:

* **`env`** — adds variables to the project `.env` inside a scoped block
  `###> vendor/package ###` … `###< vendor/package ###` (comments preserved).
* **`gitignore`** — adds patterns to `.gitignore` in the same scoped-block style.
* **`copy-from-recipe`** — copies recipe files into the project, **never
  overwriting** files the user already has.
* **`post-install-output`** — prints the next-steps block to the console.

On `composer remove`, each of the above is **reversed**: scoped blocks are
stripped from `.env`/`.gitignore` and recipe-copied files are removed (only if
unchanged), leaving user edits intact.

## Recipe layout

```
my-bundle/
└── recipe/
    ├── manifest.json
    └── config/
        └── packages/
            └── survos_bunny.yaml
```

### `recipe/manifest.json`

Standard Flex manifest format:

```json
{
    "bundles": {
        "Survos\\BunnyBundle\\SurvosBunnyBundle": ["all"]
    },
    "copy-from-recipe": {
        "config/": "%CONFIG_DIR%/"
    },
    "env": {
        "BUNNY_API_KEY": ""
    },
    "gitignore": [
        "/var/bunny/"
    ],
    "post-install-output": [
        "  * <fg=yellow>Next steps:</>",
        "    1. Get your main API key at https://dash.bunny.net/account/api-key",
        "    2. bin/console bunny:config <apiKey> (or set BUNNY_API_KEY)",
        "    3. bin/console bunny:list"
    ]
}
```

The resulting `.env` block:

```dotenv
###> survos/bunny-bundle ###
BUNNY_API_KEY=
###< survos/bunny-bundle ###
```

## Installation

```bash
composer config allow-plugins.survos/installer true
composer require survos/installer
```

For local development against a path checkout:

```bash
composer config repositories.survos_installer '{"type": "path", "url": "../installer"}'
composer require survos/installer:dev-main
```

## Disabling for specific packages

```json
{
    "extra": {
        "survos": {
            "installer": {
                "enabled": true,
                "exclude": [
                    "survos/asset",
                    "survos/embed"
                ]
            }
        }
    }
}
```

## Status

Under active development — see [PLAN.md](PLAN.md). The recipe-format decision
(Flex `manifest.json`) and testing strategy are settled there; the code is
mid-migration from an older multi-directory convention.

## Versioning

MAJOR.MINOR.PATCH. Lock your dependencies for production and test on upgrade.

## License

MIT. See the [LICENSE](LICENSE) file.
