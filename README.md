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

## Create a whole mall (all subshops on their domains)

One command creates — or brings up to date — one subshop per vertical on its own domain, ready to sell: catalog,
start page CMS, the base shop's modules **and module settings**, shipping methods and cost rules for the new
categories, a theme per shop, mall mode and (optionally) shared customers.

```bash
vendor/bin/oe-console oe:demodata-mall:create-mall --domain=example.com --dry-run      # plan: shop1/2/3.example.com
vendor/bin/oe-console oe:demodata-mall:create-mall --domain=example.com --shared-customers \
  --shop=fashion:apparel:apex-child-3 --shop=electronics:electronics:apex-child-2 --shop=medical-devices:med:apex-child-1
vendor/bin/oe-console oe:demodata-mall:create-mall --config=docs/examples/mall.example.json
```

Re-runnable (shops are found by URL). Options, JSON format and the per-shop steps:
[docs/managing-a-mall.md#create-mall](docs/managing-a-mall.md#create-mall). How to run and check a mall — domains,
admin, themes, modules, shipping, payment, customers, one-page checkout, troubleshooting:
[docs/managing-a-mall.md](docs/managing-a-mall.md).

## Commands

```bash
vendor/bin/oe-console oe:demodata-mall:list
vendor/bin/oe-console oe:demodata-mall:install <vertical> [options]
vendor/bin/oe-console oe:demodata-mall:create-mall [options]
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
| `--no-cms`         | subshop, replace | keep the shop's start page CMS content                  |
| `--cms`            | add     | also install the start page CMS content                          |

Every install can run again: records have stable ids, so a second run updates them.

### Start page CMS content

Each vertical ships its start page content in `verticals/<id>/cms.json`: intro teasers
(`oxstartslot1`–`oxstartslot4`), the USP bars (`oxheaderusps`, `oxstartusps`), start page meta texts and
the footer line, in German and English, rendered in the APEX shortcode markup. Teaser links point to the
vertical's own categories (resolved to the shop's SEO URLs at install time). The car landing pages of the
EE demo data are deactivated in that shop.

- `subshop` and `replace` install it (skip with `--no-cms`)
- `add` installs it only with `--cms`, because it replaces the start page of the target shop

Mood images are optional: `tools/generate_cms_images.py <vertical>` generates them from the `images`
block of `cms.json` into `pictures/ddmedia/mall-<vertical>/`. Without them the teasers use the category
pictures; run the install again after generating to switch to the mood images.

### What is not changed

- users, payment and delivery settings, languages and currencies
- texts: language `de` and `en` of the shop get the catalog texts; other shop languages get the English text

## Make a new vertical

See [docs/generating-a-shop.md](docs/generating-a-shop.md) for the steps and
[docs/catalog-format.md](docs/catalog-format.md) for the `catalog.json` reference.

## Layout

```
verticals/<id>/catalog.json        texts, prices, structure
verticals/<id>/cms.json            start page CMS content and mood image prompts
verticals/<id>/pictures/master/... pictures in the layout of source/out/pictures/master
src/                               console commands and installers
tools/generate_images.py           picture generator (Replicate, FLUX)
tools/generate_cms_images.py       start page mood images from cms.json (Replicate, FLUX)
docs/managing-a-mall.md            running an EE mall: domains, per-shop vs shared, create-mall, checklist
docs/examples/mall.example.json    mall file for create-mall --config
services.yaml                      command registration
```
