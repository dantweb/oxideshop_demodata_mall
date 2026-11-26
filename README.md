# oxideshop_demodata_mall

Demo shops for OXID eShop 7, installed with `oe-console` commands into a running shop:

| vertical          | shop name              | categories | products | manufacturers |
|-------------------|------------------------|-----------:|---------:|--------------:|
| `fashion`         | Mall Fashion & Apparel | 5          | 20       | 4             |
| `electronics`     | Mall Electronics       | 5          | 20       | 4             |
| `medical-devices` | Mall Medical Devices   | 5          | 20       | 4             |

Each vertical has German and English texts, prices, stock, manufacturers with logos and product
pictures generated with [FLUX](https://replicate.com/black-forest-labs/flux-2-klein-4b).
All brands and products are fictional.

A vertical can be installed in two ways:

- **as an EE subshop** ("mall"): a new shop with its own catalog; shop 1 is not changed
- **as a replacement** of the catalog of an existing shop, optionally together with its orders

## Install the package

Add the repository to the root `composer.json` of the shop project and require the package:

```json
"repositories": {
    "oxideshop_demodata_mall": {
        "type": "vcs",
        "url": "https://github.com/dantweb/oxideshop_demodata_mall"
    }
}
```

```bash
composer require dantweb/oxideshop_demodata_mall:dev-main
```

The package is an `oxideshop-component`: the OXID composer plugin registers its console commands
(`Updating component dantweb/oxideshop_demodata_mall` in the composer output). Nothing changes in
the shop until you run a command.

## Commands

```bash
vendor/bin/oe-console oe:demodata-mall:list
vendor/bin/oe-console oe:demodata-mall:install <vertical> [options]
```

(`bin/oe-console` in a project that has the shop as root package.)

### Subshop (default, Enterprise Edition)

```bash
vendor/bin/oe-console oe:demodata-mall:install fashion
vendor/bin/oe-console oe:demodata-mall:install electronics --shop-url=https://electronics.example.com
vendor/bin/oe-console oe:demodata-mall:install medical-devices --shop-name="Medical Store"
```

The command does what the admin does for *Master Settings > Shops > New shop*: it copies the base
shop fields and configuration, the CMS pages and static SEO URLs, generates the module
configuration and the database views. Then it activates the modules that are active in shop 1
(`--no-modules` skips this), installs the catalog into the new shop and copies the pictures.

If a subshop with this name exists, the command updates its catalog instead of creating a second shop.
Open the subshop with `https://<shop>/?shp=<id>`, or give it its own URL with `--shop-url`
(saved as `sMallShopURL` / `sMallSSLShopURL`). The subshop is active but not productive; switch it
to productive in the admin when needed. The shop license must allow another shop.

### Replace the catalog of a shop

```bash
# shop 1, keep orders
vendor/bin/oe-console oe:demodata-mall:install fashion --mode=replace --yes

# shop 3, delete its orders too
vendor/bin/oe-console oe:demodata-mall:install electronics --mode=replace --purge-orders --shop-id=3 --yes
```

`--mode=replace` deletes all products (with variants), categories and manufacturers of the shop
through the shop models (SEO URLs, shop mappings and relations go with them) and installs the vertical.

`--purge-orders` deletes all orders of the shop and every row that refers to them: each table with an
`OXORDERID` or `ORDERID` column (order articles, order files, payment module contracts and
transactions, ...). Vouchers are not deleted; used vouchers are released.

Without `--yes` the command asks for confirmation; in non-interactive mode (`-n`) it stops.

### Add to a shop

```bash
vendor/bin/oe-console oe:demodata-mall:install fashion --mode=add --shop-id=1
```

Adds the vertical next to the existing catalog.

### Options

| option             | mode    | meaning                                                         |
|--------------------|---------|-----------------------------------------------------------------|
| `--mode`           | all     | `subshop` (default), `replace` or `add`                          |
| `--shop-id`        | replace, add | target shop (global `oe-console` option, default 1)        |
| `--purge-orders`   | replace | also delete the orders of the shop                               |
| `--yes`, `-y`      | replace | do not ask for confirmation                                     |
| `--shop-name`      | subshop | shop name (default: name from the catalog)                       |
| `--shop-url`       | subshop | own URL of the subshop                                           |
| `--no-modules`     | subshop | do not activate the modules of shop 1 in the subshop             |
| `--no-pictures`    | all     | do not copy pictures                                            |

Every install can run again: records have stable ids, so a second run updates them.

### What is not changed

- the start page CMS content (banners and teasers of the theme)
- users, payment and delivery settings, languages and currencies
- texts: language `de` and `en` of the shop get the catalog texts; other shop languages get the English text

## Make a new vertical

See [docs/generating-a-shop.md](docs/generating-a-shop.md) for the steps and
[docs/catalog-format.md](docs/catalog-format.md) for the `catalog.json` reference.

## Layout

```
verticals/<id>/catalog.json        texts, prices, structure
verticals/<id>/pictures/master/... pictures in the layout of source/out/pictures/master
src/                               console commands and installers
tools/generate_images.py           picture generator (Replicate, FLUX)
services.yaml                      command registration
```
