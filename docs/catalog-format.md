# catalog.json reference

One file per vertical: `verticals/<id>/catalog.json`. The directory name is the vertical id
(lowercase letters, digits and `-`). Texts are objects with one entry per language abbreviation
(`{"de": "...", "en": "..."}`); the installer writes each text into the shop language with the same
abbreviation and the English text into all other shop languages.

```json
{
  "id": "garden",
  "shop": {
    "name": "Mall Garden",
    "title": {"de": "Garten", "en": "Garden"}
  },
  "image_style": "Photorealistic e-commerce studio product photograph, ..., pure white background, no text.",
  "manufacturers": [ ... ],
  "categories": [ ... ],
  "products": [ ... ]
}
```

| field         | used for                                                                     |
|---------------|------------------------------------------------------------------------------|
| `id`          | must be the directory name; part of the picture file names and record ids  |
| `shop.name`   | name of the subshop that `--mode=subshop` creates                            |
| `shop.title`  | short description of the vertical                                            |
| `image_style` | appended to every product and category prompt by `tools/generate_images.py`  |

## manufacturers

```json
{"key": "greenleaf", "title": "Greenleaf",
 "short": {"de": "Gartengeräte", "en": "Garden tools"},
 "logo_prompt": "minimal emblem of a single leaf, fresh green"}
```

| field         | required | meaning                                                            |
|---------------|----------|--------------------------------------------------------------------|
| `key`         | yes      | unique in the file; picture `mall-<id>-<key>.png`                  |
| `title`       | yes      | brand name (also drawn into the logo)                              |
| `short`       | no       | short description                                                  |
| `logo_prompt` | for pictures | emblem only; the generator adds the name with a font, because the model spells text badly |

## categories

Top-level categories, in the order of the list.

```json
{"key": "tools", "title": {"de": "Werkzeug", "en": "Tools"},
 "description": {"de": "...", "en": "..."},
 "image_prompt": "a spade, a rake and pruning shears arranged together"}
```

| field          | required | meaning                                             |
|----------------|----------|-----------------------------------------------------|
| `key`          | yes      | unique in the file; picture `mall-<id>-<key>.jpg`   |
| `title`        | yes      | category name                                       |
| `description`  | no       | text under the category title                       |
| `image_prompt` | for pictures | what the category picture shows              |

## products

```json
{"key": "spade", "artnum": "GA-1001", "category": "tools", "manufacturer": "greenleaf",
 "price": 39.90, "tprice": 49.90, "stock": 25, "featured": true,
 "title": {"de": "Spaten Classic", "en": "Classic Spade"},
 "short": {"de": "...", "en": "..."},
 "long": {"de": "<p>...</p>", "en": "<p>...</p>"},
 "image_prompt": "a garden spade with an ash wood handle"}
```

| field          | required | meaning                                                         |
|----------------|----------|-----------------------------------------------------------------|
| `key`          | yes      | unique in the file; picture `mall-<id>-<key>.jpg`               |
| `artnum`       | yes      | article number                                                  |
| `category`     | yes      | key of a category                                               |
| `manufacturer` | no       | key of a manufacturer                                           |
| `price`        | yes      | gross price (shop default currency)                             |
| `tprice`       | no       | recommended retail price, shown crossed out                     |
| `stock`        | no       | stock, default 10                                               |
| `featured`     | no       | `true` puts the product into the start page promotions (`oxtopstart`, `oxbargain`, `oxtop5`, `oxnewest`) |
| `title`        | yes      | product name                                                    |
| `short`        | no       | short description                                               |
| `long`         | no       | long description (HTML)                                         |
| `image_prompt` | for pictures | what the product picture shows                              |

The installer checks the file before it writes anything: duplicate keys, missing required fields,
unknown category or manufacturer keys stop the install. `oe:demodata-mall:list` reads every catalog
and so also checks all of them.
