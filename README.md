# Installer

*By [survos](https://github.com/survos)*

This plugin was forked from https://github.com/endroid/installer, and functionality to update the .env and .gitignore files was added.  It is a simple way to get some of the functionality of https://github.com/symfony/recipes-contrib but is a bit easier to set up, since the bundle configuration is in the bundle itself, rather than a separate repo.

In short, this utility modifies the application when a bundle is installed by reading the .install/symfony directory and

* copy the config/packages/<bundle>.yaml and config/routes/<bundle>.yaml files.
* add env.txt to .env
* add from gitignore.txt to .gitignore
* display the post-install contents 

An experimental feature was to put all the actions above into a single manifest.yaml file and parse it.  If we have a bundle that needs this feature, we can implement it.

```yaml
bundles:
  Survos\Bundle\SurvosFlickrBundle: [all]
copy-from-recipe:
  config/: '%CONFIG_DIR%/'
  src/: '%SRC_DIR%/'
env: |
  FLICKR_API_KEY=
  FLICKR_SECRET=
copy:
  - filename: config/packages/survos_flickr.yaml
    content: |
      survos_flickr:
        api_key: '%env(FLICKR_API_KEY)%'
        secret: '%env(FLICKR_SECRET)%'
  - filename: config/routes/survos_flickr.yaml
    content: |
      survos_flickr:
        resource: '@SurvosFlickrBundle/config/routes.yaml'
        prefix: '/admin/flickr'

```



Composer plugin for installing configuration files. The installer automatically
detects the project type in which your library is installed and installs the
corresponding configuration files from your package.

Read the [blog](https://medium.com/@endroid/auto-package-configuration-for-symfony-e14780e29d81)
for more information on why this plugin was originally created.

## Installation

``` bash
composer config allow-plugins.survos/installer true

# dev
composer config repositories.survos_installer '{"type": "path", "url": "../installer"}' 
composer require survos/installer:dev-main
```

Production

```bash
composer require survos/installer
```

## Usage

Add the configuration files you want to be copied upon installation and update
of the package to the .install directory in the root of your package. The files
will be copied to the corresponding directories in the project.

It tried to use the same structure as the Symfony recipes-config, in the recipe folder at the root of a bundle.
It uses manifest.yaml instead of manifest.json

```
fun-bundle
    └── recipe
        ├── config
        │ ├── packages
        │ │ └── fun.yaml
        │ └── routes
        │     └── fun.yaml
        ├── manifest.yaml
        └── post-install.txt
```

```yaml
# manifest.yaml
fun:
  bundles:
    'Vendor\\Bundle\\VendorFunBundle\\FunBundle': all
    "copy-from-recipe": 
        "config/": "%CONFIG_DIR%/"
    "env": 
        "FUN_ID": "",
        "FUN_SECRET": ""
    ".gitignore":
      - /fun-temp-files
```

```
.recipe
    symfony
        env.txt
        gitignore.txt
        post-install.txt
        config
            packages
                package_name.yaml
            routes
                package_name.yaml
```

Please note that the installer will only copy files that are not yet present in
the project to make sure user made changes will not be overwritten. If you want
the latest default configuration just remove the files locally before update.

## Disabling auto installation for a package

Generally you want the files to be installed automatically but if you
experience issues with the installer or just don't want some package to be
auto installed you can specify this via your composer.json.

```
"extra": {
    "survos": {
        "installer": {
            "enabled": false,
            "exclude": [
                "survos/asset",
                "survos/embed"
            ]
        }
    }
}
```

## Versioning

Version numbers follow the MAJOR.MINOR.PATCH scheme. Backwards compatible
changes will be kept to a minimum but be aware that these can occur. Lock
your dependencies for production and test your code when upgrading.

## License

This bundle is under the MIT license. For the full copyright and license
information please view the LICENSE file that was distributed with this source code.
