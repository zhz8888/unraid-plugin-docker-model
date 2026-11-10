#!/usr/bin/env python3
"""Render the plugin icon from icon.svg.

The SVG is the design of the plugin icon and the single source of truth.
Unraid cannot show an SVG, so the plugin ships a PNG that this script
renders from it. Rendering the SVG, instead of drawing the icon a second
time, means the two can never drift apart.

Only the subset of SVG that icon.svg uses is supported: one vertical
linear gradient, one rounded rectangle and straight stroked paths. Every
other construct makes the script fail, so a changed SVG can never
silently produce an icon that does not match it.

Usage:
    python3 scripts/make-icon.py [output.png]
"""

import os
import re
import sys

from PIL import Image, ImageDraw

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOURCE = os.path.join(REPO_ROOT, 'icon.svg')

# The icon is drawn this many times larger and scaled down again, so that the
# edges are anti aliased the way an SVG renderer would draw them.
SUPERSAMPLE = 4


class SvgError(Exception):
    """Raised when the SVG uses a construct this renderer does not support."""


def parse_attributes(text):
    """Return the attributes of an XML tag as a dict."""
    return dict(re.findall(r'([\w:-]+)\s*=\s*"([^"]*)"', text))


def parse_colour(value):
    """Return an RGB tuple for a #rrggbb colour."""
    if not re.fullmatch(r'#([0-9a-fA-F]{6})', value or ''):
        raise SvgError('unsupported colour: %s' % value)
    return tuple(int(value[1 + i:3 + i], 16) for i in (0, 2, 4))


def parse_path(data):
    """Return the points of a path that only uses the M, L and Z commands."""
    commands = re.findall(r'([A-Za-z])', data)
    numbers = [float(n) for n in re.findall(r'-?\d+(?:\.\d+)?', data)]
    points = []
    index = 0
    closed = False

    for command in commands:
        if command == 'M':
            if index + 2 > len(numbers):
                raise SvgError('truncated moveto in path: %s' % data)
            points.append((numbers[index], numbers[index + 1]))
            index += 2
        elif command == 'L':
            if index + 2 > len(numbers):
                raise SvgError('truncated lineto in path: %s' % data)
            points.append((numbers[index], numbers[index + 1]))
            index += 2
        elif command == 'Z':
            closed = True
        else:
            raise SvgError('unsupported path command %s in: %s' % (command, data))

    if index != len(numbers):
        raise SvgError('trailing numbers in path: %s' % data)
    if not points:
        raise SvgError('path without points: %s' % data)

    # Z closes the subpath, so the last segment runs back to the first point.
    if closed:
        points.append(points[0])

    return points


def parse_svg(svg):
    """Return the description of the icon that icon.svg describes."""
    root = re.search(r'<svg\b([^>]*)>', svg)
    if not root:
        raise SvgError('no <svg> element')

    root_attrs = parse_attributes(root.group(1))
    if 'viewBox' not in root_attrs:
        raise SvgError('the <svg> element has no viewBox')
    view_box = [float(v) for v in root_attrs['viewBox'].split()]
    if len(view_box) != 4:
        raise SvgError('unsupported viewBox: %s' % root_attrs['viewBox'])
    size = int(round(view_box[2]))

    gradient = re.search(r'<linearGradient\b([^>]*)>(.*?)</linearGradient>', svg, re.S)
    if not gradient:
        raise SvgError('no linear gradient')
    gradient_attrs = parse_attributes(gradient.group(1))
    if gradient_attrs.get('x1', '0') != '0' or gradient_attrs.get('y1', '0') != '0' \
            or gradient_attrs.get('x2', '0') != '0' or gradient_attrs.get('y2', '1') != '1':
        raise SvgError('only a vertical gradient is supported')
    stops = [parse_colour(parse_attributes(stop)['stop-color'])
             for stop in re.findall(r'<stop\b([^>]*)/?>', gradient.group(2))]
    if len(stops) < 2:
        raise SvgError('the gradient needs at least two stops')

    rect = re.search(r'<rect\b([^>]*)/?>', svg)
    if not rect:
        raise SvgError('no <rect> element')
    rect_attrs = parse_attributes(rect.group(1))
    fill = rect_attrs.get('fill', '')
    if fill != 'url(#%s)' % gradient_attrs.get('id', ''):
        raise SvgError('the rectangle is not filled with the gradient: %s' % fill)
    radius = float(rect_attrs.get('rx', rect_attrs.get('ry', '0')))
    if rect_attrs.get('rx', '') != rect_attrs.get('ry', ''):
        raise SvgError('only a circle rounded rectangle is supported')

    group = re.search(r'<g\b([^>]*)>(.*?)</g>', svg, re.S)
    if not group:
        raise SvgError('no stroked <g> element')
    group_attrs = parse_attributes(group.group(1))
    if group_attrs.get('fill', 'none') != 'none':
        raise SvgError('the stroked group must not be filled')
    stroke = parse_colour(group_attrs.get('stroke', '#000000'))
    width = int(round(float(group_attrs.get('stroke-width', '1'))))
    if group_attrs.get('stroke-linejoin', 'miter') != 'round':
        raise SvgError('only round line joins are supported')
    if group_attrs.get('stroke-linecap', 'butt') != 'round':
        raise SvgError('only round line caps are supported')

    paths = []
    for path in re.findall(r'<path\b([^>]*)/?>', group.group(2)):
        attrs = parse_attributes(path)
        if 'd' not in attrs:
            raise SvgError('a path has no d attribute')
        paths.append(parse_path(attrs['d']))

    if not paths:
        raise SvgError('the stroked group has no path')

    return {
        'size': size,
        'stops': stops,
        'radius': radius,
        'stroke': stroke,
        'width': width,
        'paths': paths,
    }


def gradient_image(size, stops):
    """Return an RGB image with a vertical gradient."""
    image = Image.new('RGB', (size, size))
    pixels = image.load()
    top, bottom = stops[0], stops[-1]

    for y in range(size):
        ratio = y / float(size - 1)
        colour = tuple(int(round(top[i] + (bottom[i] - top[i]) * ratio)) for i in range(3))
        for x in range(size):
            pixels[x, y] = colour

    return image


def draw_path(draw, points, width, colour):
    """Draw a stroked path with round joins and round caps."""
    if len(points) < 2:
        return

    draw.line(points, fill=colour, width=width, joint='curve')

    # Pillow draws butt caps, the SVG asks for round ones.
    radius = width / 2.0
    for x, y in (points[0], points[-1]):
        draw.ellipse([x - radius, y - radius, x + radius, y + radius], fill=colour)


def render_at(icon):
    """Return the icon drawn at the size of the description."""
    size = icon['size']
    image = Image.new('RGBA', (size, size), (0, 0, 0, 0))

    mask = Image.new('L', (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        (0, 0, size - 1, size - 1), radius=int(round(icon['radius'])), fill=255)
    image.paste(gradient_image(size, icon['stops']), (0, 0), mask)

    draw = ImageDraw.Draw(image)
    for points in icon['paths']:
        draw_path(draw, points, icon['width'], icon['stroke'] + (255,))

    return image


def render(icon):
    """Return the rendered icon as an RGBA image.

    Pillow draws hard edges, an SVG renderer anti aliases them. The icon is
    drawn larger than it is and scaled down again, which gives the edges
    the smooth look of the SVG that the browser would produce.
    """
    size = icon['size']
    scale = SUPERSAMPLE

    large = {
        'size': size * scale,
        'radius': icon['radius'] * scale,
        'stops': icon['stops'],
        'stroke': icon['stroke'],
        'width': icon['width'] * scale,
        'paths': [[(x * scale, y * scale) for x, y in points] for points in icon['paths']],
    }

    return render_at(large).resize((size, size), Image.LANCZOS)


def main():
    default = os.path.join(
        REPO_ROOT,
        'src',
        'usr',
        'local',
        'emhttp',
        'plugins',
        'unraid-plugin-docker-model',
        'unraid-plugin-docker-model.png',
    )
    out_path = sys.argv[1] if len(sys.argv) > 1 else default

    with open(SOURCE, encoding='utf-8') as handle:
        svg = handle.read()

    try:
        icon = parse_svg(svg)
    except SvgError as error:
        print('cannot render %s: %s' % (SOURCE, error), file=sys.stderr)
        return 1

    image = render(icon)

    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    image.save(out_path, format='PNG', optimize=True)
    print('written', out_path)

    return 0


if __name__ == '__main__':
    sys.exit(main())
