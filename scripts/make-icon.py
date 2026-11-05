#!/usr/bin/env python3
"""Generate the plugin icon.

The icon is a source asset of the repository, but it is generated rather than
drawn by hand so that it stays reproducible and reviewable.

Usage:
    python3 scripts/make-icon.py [output.png]
"""

import os
import sys

from PIL import Image, ImageDraw

SIZE = 256
RADIUS = 44

TOP = (36, 150, 237)
BOTTOM = (18, 84, 168)


def rounded_gradient(width, height, radius, top, bottom):
    """Return an RGBA image with a vertical gradient inside a rounded square."""
    image = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    gradient = Image.new("RGBA", (width, height))

    pixels = gradient.load()
    for y in range(height):
        ratio = y / float(height - 1)
        colour = tuple(int(round(top[i] + (bottom[i] - top[i]) * ratio)) for i in range(3))
        for x in range(width):
            pixels[x, y] = colour + (255,)

    mask = Image.new("L", (width, height), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, width - 1, height - 1), radius=radius, fill=255)
    image.paste(gradient, (0, 0), mask)
    return image


def draw_cube(draw, centre_x, centre_y, half_width, depth, height):
    """Draw an isometric wireframe cube."""
    top_y = centre_y - height - depth
    left = (centre_x - half_width, top_y + depth)
    right = (centre_x + half_width, top_y + depth)
    front = (centre_x, top_y + 2 * depth)

    bottom_left = (left[0], left[1] + height)
    bottom_front = (front[0], front[1] + height)
    bottom_right = (right[0], right[1] + height)

    # Left and right faces, filled with different shades of white.
    draw.polygon([left, front, bottom_front, bottom_left], fill=(255, 255, 255, 70))
    draw.polygon([front, right, bottom_right, bottom_front], fill=(255, 255, 255, 40))

    # Outline of the cube.
    draw.line([(centre_x, top_y), left, bottom_left, bottom_front, bottom_right, right,
               (centre_x, top_y)], fill=(255, 255, 255, 255), width=7, joint="curve")
    draw.line([left, front, right], fill=(255, 255, 255, 255), width=7, joint="curve")
    draw.line([front, bottom_front], fill=(255, 255, 255, 255), width=7)


def main():
    default = os.path.join(
        os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
        "src",
        "usr",
        "local",
        "emhttp",
        "plugins",
        "unraid-plugin-docker-model",
        "unraid-plugin-docker-model.png",
    )
    out_path = sys.argv[1] if len(sys.argv) > 1 else default

    image = rounded_gradient(SIZE, SIZE, RADIUS, TOP, BOTTOM)
    draw = ImageDraw.Draw(image)
    draw_cube(draw, SIZE // 2, SIZE // 2 + 8, 74, 43, 74)

    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    image.save(out_path, format="PNG", optimize=True)
    print("written", out_path)


if __name__ == "__main__":
    main()
