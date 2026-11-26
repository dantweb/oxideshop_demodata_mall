#!/usr/bin/env python3
"""Generate the pictures of one vertical from its catalog.json with a Black Forest Labs FLUX model on Replicate.

Usage:
    REPLICATE_API_TOKEN=r8_... python3 tools/generate_images.py <vertical> [--force] [--only key1,key2]
                                                                 [--model owner/name] [--font path.ttf]

Writes (relative to verticals/<vertical>/pictures/):
    master/product/1/mall-<vertical>-<product key>.jpg      1024x1024, white background
    master/category/icon/mall-<vertical>-<category key>.jpg  1024x1024, white background
    master/manufacturer/icon/mall-<vertical>-<key>.png      800x400, transparent background,
                                                             generated emblem + brand name drawn with a font

Existing files are kept unless --force is given, so a second run only fills the gaps.
Requires Python 3.9+ and Pillow.
"""
import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request
from io import BytesIO

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_MODEL = "black-forest-labs/flux-2-klein-4b"  # cheapest BFL model on Replicate (~$0.001 per megapixel)
FONT_CANDIDATES = [
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf",
    "/Library/Fonts/Arial Bold.ttf",
    "C:\\Windows\\Fonts\\arialbd.ttf",
]
LOGO_STYLE = ("Flat minimal vector emblem icon, single solid colour shapes, centered, on a pure white background, "
              "no text, no letters, no words, no watermark.")


def api(token, method, url, body=None, wait=False):
    headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
    if wait:
        headers["Prefer"] = "wait=60"
    data = json.dumps(body).encode() if body is not None else None
    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(request, timeout=120) as response:
        return json.load(response)


def generate(token, model, prompt, aspect_ratio="1:1", megapixels="1", seed=None):
    """Run one prediction and return the image as a PIL image. Retries rate limits and transient errors."""
    payload = {"input": {"prompt": prompt, "aspect_ratio": aspect_ratio, "output_megapixels": megapixels,
                         "output_format": "png"}}
    if seed is not None:
        payload["input"]["seed"] = seed
    last_error = None
    for attempt in range(6):
        try:
            prediction = api(token, "POST", f"https://api.replicate.com/v1/models/{model}/predictions", payload, True)
            while prediction["status"] in ("starting", "processing"):
                time.sleep(2)
                prediction = api(token, "GET", prediction["urls"]["get"])
            if prediction["status"] != "succeeded":
                raise RuntimeError(f"prediction {prediction['status']}: {prediction.get('error')}")
            output = prediction["output"]
            url = output[0] if isinstance(output, list) else output
            with urllib.request.urlopen(url, timeout=120) as response:
                return Image.open(BytesIO(response.read())).convert("RGB")
        except urllib.error.HTTPError as error:
            last_error = f"HTTP {error.code}: {error.read().decode(errors='replace')[:200]}"
            time.sleep(10 if error.code == 429 else 3 * (attempt + 1))
        except (urllib.error.URLError, RuntimeError, TimeoutError) as error:
            last_error = str(error)
            time.sleep(3 * (attempt + 1))
    raise RuntimeError(f"giving up after retries: {last_error}")


def white_to_transparent(image, tolerance=22):
    """Make the white background that touches the border transparent."""
    marker = (255, 0, 255)
    rgb = image.convert("RGB")
    width, height = rgb.size
    border = ([(x, 0) for x in range(0, width, 8)] + [(x, height - 1) for x in range(0, width, 8)]
              + [(0, y) for y in range(0, height, 8)] + [(width - 1, y) for y in range(0, height, 8)])
    for point in border:
        pixel = rgb.getpixel(point)
        if pixel != marker and min(pixel) >= 228:
            ImageDraw.floodfill(rgb, point, marker, thresh=tolerance)
    alpha = Image.new("L", rgb.size)
    alpha.putdata([0 if pixel == marker else 255 for pixel in rgb.getdata()])
    result = image.convert("RGBA")
    result.putalpha(alpha)
    return result


def load_font(path, size):
    for candidate in [path] + FONT_CANDIDATES:
        if candidate and os.path.exists(candidate):
            return ImageFont.truetype(candidate, size)
    return ImageFont.load_default(size=size)  # Pillow >= 10.1


def compose_logo(emblem, name, font_path):
    """Emblem left, brand name right, on an 800x400 transparent canvas."""
    canvas = Image.new("RGBA", (800, 400), (0, 0, 0, 0))
    icon = white_to_transparent(emblem.resize((260, 260), Image.LANCZOS))
    canvas.alpha_composite(icon, (30, 70))
    colour = dominant_colour(icon)
    draw = ImageDraw.Draw(canvas)
    size = 96
    font = load_font(font_path, size)
    while draw.textlength(name, font=font) > 470 and size > 30:
        size -= 4
        font = load_font(font_path, size)
    box = draw.textbbox((0, 0), name, font=font)
    draw.text((310, 200 - (box[1] + box[3]) // 2), name, font=font, fill=colour)
    return canvas


def dominant_colour(image):
    """Average colour of the opaque, not-too-light pixels, darkened a bit for readable text."""
    pixels = [p for p in image.getdata() if p[3] > 0 and sum(p[:3]) < 600]
    if not pixels:
        return (40, 40, 40, 255)
    r, g, b = (sum(p[i] for p in pixels) // len(pixels) for i in range(3))
    return (int(r * 0.8), int(g * 0.8), int(b * 0.8), 255)


def validate(catalog):
    errors = []
    for section in ("manufacturers", "categories", "products"):
        keys = [entry["key"] for entry in catalog.get(section, [])]
        duplicates = {key for key in keys if keys.count(key) > 1}
        if duplicates:
            errors.append(f"{section}: duplicate keys {sorted(duplicates)}")
    categories = {c["key"] for c in catalog["categories"]}
    manufacturers = {m["key"] for m in catalog["manufacturers"]}
    for product in catalog["products"]:
        if product["category"] not in categories:
            errors.append(f"product {product['key']}: unknown category {product['category']}")
        if product.get("manufacturer") and product["manufacturer"] not in manufacturers:
            errors.append(f"product {product['key']}: unknown manufacturer {product['manufacturer']}")
    return errors


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("vertical")
    parser.add_argument("--force", action="store_true", help="regenerate existing files")
    parser.add_argument("--only", default="", help="comma separated keys to generate")
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--font", default="", help="TTF font for the brand name in logos")
    args = parser.parse_args()

    token = os.environ.get("REPLICATE_API_TOKEN")
    if not token:
        sys.exit("error: set REPLICATE_API_TOKEN")
    base = os.path.join(ROOT, "verticals", args.vertical)
    with open(os.path.join(base, "catalog.json"), encoding="utf-8") as handle:
        catalog = json.load(handle)
    errors = validate(catalog)
    if errors:
        sys.exit("catalog errors:\n  " + "\n  ".join(errors))

    vertical = catalog["id"]
    style = catalog.get("image_style", "")
    only = {key for key in args.only.split(",") if key}
    pictures = os.path.join(base, "pictures", "master")

    jobs = []
    for product in catalog["products"]:
        jobs.append(("product", product, os.path.join(pictures, "product", "1", f"mall-{vertical}-{product['key']}.jpg")))
    for category in catalog["categories"]:
        jobs.append(("category", category, os.path.join(pictures, "category", "icon", f"mall-{vertical}-{category['key']}.jpg")))
    for manufacturer in catalog["manufacturers"]:
        jobs.append(("logo", manufacturer, os.path.join(pictures, "manufacturer", "icon", f"mall-{vertical}-{manufacturer['key']}.png")))

    done = skipped = 0
    for kind, entry, path in jobs:
        if only and entry["key"] not in only:
            continue
        if os.path.exists(path) and not args.force:
            skipped += 1
            continue
        os.makedirs(os.path.dirname(path), exist_ok=True)
        if kind == "logo":
            emblem = generate(token, args.model, f"{entry['logo_prompt']}. {LOGO_STYLE}", megapixels="0.25")
            compose_logo(emblem, entry["title"], args.font).save(path, "PNG", optimize=True)
        else:
            image = generate(token, args.model, f"{entry['image_prompt']}. {style}", megapixels="1")
            image.resize((1024, 1024), Image.LANCZOS).save(path, "JPEG", quality=86, optimize=True, progressive=True)
        done += 1
        print(f"{kind:8} {entry['key']:24} -> {os.path.relpath(path, ROOT)}", flush=True)
    print(f"generated {done}, kept {skipped} existing")


if __name__ == "__main__":
    main()
