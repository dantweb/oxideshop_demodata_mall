#!/usr/bin/env python3
"""Generate the start page (CMS) mood images of one vertical from the "images" block of its cms.json.

Usage:
    REPLICATE_API_TOKEN=r8_... python3 tools/generate_cms_images.py <vertical> [--force] [--only name1,name2]

Writes verticals/<vertical>/pictures/ddmedia/mall-<vertical>/<name> (JPEG), generated with FLUX at the
given aspect ratio and then cropped/resized to exactly width x height. They are installed with the other
pictures and referenced by the CMS snippets as /out/pictures/ddmedia/mall-<vertical>/<name>.
Existing files are kept unless --force is given.
"""
import argparse
import json
import os
import sys

from PIL import ImageOps

from generate_images import DEFAULT_MODEL, ROOT, generate


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("vertical")
    parser.add_argument("--force", action="store_true", help="regenerate existing files")
    parser.add_argument("--only", default="", help="comma separated file names to generate")
    parser.add_argument("--model", default=DEFAULT_MODEL)
    args = parser.parse_args()

    token = os.environ.get("REPLICATE_API_TOKEN")
    if not token:
        sys.exit("error: set REPLICATE_API_TOKEN")

    vertical_dir = os.path.join(ROOT, "verticals", args.vertical)
    with open(os.path.join(vertical_dir, "cms.json"), encoding="utf-8") as handle:
        images = json.load(handle).get("images", {})
    target_dir = os.path.join(vertical_dir, "pictures", "ddmedia", f"mall-{args.vertical}")
    os.makedirs(target_dir, exist_ok=True)

    only = {name for name in args.only.split(",") if name}
    for name, spec in images.items():
        target = os.path.join(target_dir, name)
        if (only and name not in only) or (os.path.exists(target) and not args.force):
            continue
        print(f"{args.vertical}/{name} ...", flush=True)
        image = generate(token, args.model, spec["prompt"], aspect_ratio=spec["aspect_ratio"])
        image = ImageOps.fit(image, (spec["width"], spec["height"]), centering=(0.5, 0.45))
        image.save(target, "JPEG", quality=84, optimize=True, progressive=True)


if __name__ == "__main__":
    main()
