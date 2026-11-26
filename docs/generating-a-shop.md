# Generating a new demo shop

A demo shop ("vertical") is one directory: `verticals/<id>/catalog.json` with the texts and
`verticals/<id>/pictures/` with the pictures. This guide makes a new vertical `garden` from nothing.
Time: about one hour. Cost of the pictures: about 0.03 USD for 29 pictures.

## 1. Plan the shop

Decide on:

- 4–6 top-level categories
- 3–5 fictional manufacturers (do not use real brand names)
- 3–5 products per category
- the price level and which products are "featured" (start page)

## 2. Write catalog.json

Create `verticals/garden/catalog.json` in the format of
[catalog-format.md](catalog-format.md). Copy an existing catalog as a template, for example
`verticals/fashion/catalog.json`.

You can let an LLM write the first draft. A prompt that works:

> Write a catalog.json for a demo online shop "Mall Garden" in exactly this JSON format: <paste
> verticals/fashion/catalog.json>. 5 categories, 4 fictional manufacturers, 4 products per category.
> German and English texts, `short` one sentence, `long` one or two sentences in `<p>`. Realistic
> EUR gross prices. Article numbers GA-1001, GA-1002, ... `image_prompt`: describe only the object
> (colour, material, shape), no background, no text. `logo_prompt`: a minimal emblem without letters.
> Keys: lowercase words with `-`.

Then check the draft yourself:

- prices, product names and German texts are plausible
- no real brands, no claims about health or safety effects (medical products)
- every `category` and `manufacturer` value is a key that exists
- `image_prompt` describes one product, not a scene, and has no text in it

## 3. Generate the pictures

You need Python 3.9+, Pillow (`pip install pillow`) and a Replicate API token.

```bash
export REPLICATE_API_TOKEN=r8_...
python3 tools/generate_images.py garden
```

The tool:

- validates the catalog (keys, references) before it calls the API
- generates one picture per product and category with
  `black-forest-labs/flux-2-klein-4b` (the cheapest Black Forest Labs model on Replicate,
  about 0.001 USD per picture): the `image_prompt` of the entry plus the `image_style` of the catalog
- generates an emblem per manufacturer from `logo_prompt` and draws the brand name next to it with a
  font (DejaVu Sans Bold if installed, `--font path.ttf` to choose one), so the name is always spelled correctly
- writes the files into `verticals/garden/pictures/master/...`, the same layout as
  `source/out/pictures/master` of the shop
- keeps files that exist, so you can run it again after an error

Look at every picture. To make one again, change its `image_prompt` and run:

```bash
python3 tools/generate_images.py garden --only spade --force
```

Typical corrections:

| problem                                   | fix in the prompt                                         |
|-------------------------------------------|-----------------------------------------------------------|
| text or a logo on the product             | add "plain, no print, no lettering"                         |
| wrong object (e.g. a watch for a ring)    | describe shape and material exactly, say what it is not    |
| several objects for one product           | "a single ..."                                              |
| cut-off product                           | the catalog `image_style` asks for "the whole product visible" |

`--model` selects another Replicate model, for example `black-forest-labs/flux-schnell`.

## 4. Test the vertical in a shop

The commands read the verticals from the package directory, so test in a shop that has this
repository as a path repository (or copy the directory into `vendor/dantweb/oxideshop_demodata_mall`).

Use a copy of the shop database. With the Docker SDK the shop reads the database name from
`OXID_DB_NAME`:

```bash
mysql -e "CREATE DATABASE shop_test" && mysqldump example | mysql shop_test
OXID_DB_NAME=shop_test vendor/bin/oe-console oe:demodata-mall:list
OXID_DB_NAME=shop_test vendor/bin/oe-console oe:demodata-mall:install garden
```

Check in the shop: category pages, product details in German and English, manufacturer list,
pictures. Run the install a second time: it must update, not duplicate.

## 5. Publish

Commit `verticals/garden/` (catalog and pictures) and push. Shops get the vertical with
`composer update dantweb/oxideshop_demodata_mall` and install it with
`vendor/bin/oe-console oe:demodata-mall:install garden`.

## Limits

- one category level (no subcategories), no variants, no attributes or selection lists
- prices in the default currency only
- the start page CMS content of the theme is not changed
