#!/usr/bin/env python3
"""Render converted, assembled block geometry with Pillow only.

Usage:
  python3 tools/render_structure_previews.py
  python3 tools/render_structure_previews.py --input /tmp/layouts.json --output docs/structure-preview.png

Requires Pillow (python3 -m pip install Pillow). Without --input, invoke
export_structure_preview.php using --php or the provisioned bin/php7/bin/php.
No running game server, client, network access or terrain generation is needed.
"""
from __future__ import annotations

import argparse
import base64
from collections import Counter
import json
from pathlib import Path
import struct
import subprocess
import tempfile

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
FONT_DIRECTORY = Path('/usr/share/fonts/truetype/dejavu')
LABELS = {
    'village': 'Village',
    'pillager_outpost': 'Pillager Outpost',
    'end_city': 'End City',
    'woodland_mansion': 'Woodland Mansion',
    'bastion': 'Bastion',
    'ancient_city': 'Ancient City',
    'trial_chambers': 'Trial Chambers',
    'shipwreck': 'Shipwreck',
}
# Approximate colors of the converted legacy IDs, never their modern source names.
WOODS = [(166, 134, 82), (113, 83, 48), (213, 201, 144), (163, 115, 80), (172, 94, 53), (76, 53, 31)]
DYES = [(221, 222, 215), (222, 125, 48), (176, 75, 175), (91, 151, 196), (226, 193, 49), (111, 169, 48), (210, 137, 159), (67, 72, 75), (146, 148, 140), (43, 119, 135), (118, 65, 151), (52, 62, 137), (112, 76, 48), (74, 96, 40), (153, 49, 43), (34, 36, 40)]
TERRACOTTA = [(204, 171, 151), (164, 91, 48), (151, 89, 110), (115, 111, 144), (188, 137, 42), (105, 121, 56), (163, 79, 79), (68, 55, 51), (143, 112, 102), (78, 100, 104), (121, 75, 89), (73, 62, 94), (78, 53, 39), (75, 82, 42), (146, 64, 47), (43, 30, 25)]
COLORS = {
    1: (129, 131, 132), 2: (102, 137, 70), 3: (129, 94, 61), 4: (119, 123, 122),
    7: (77, 79, 79), 8: (58, 103, 175), 9: (58, 103, 175), 10: (232, 111, 25), 11: (232, 111, 25),
    12: (220, 209, 153), 13: (137, 128, 123), 14: (153, 144, 105), 15: (150, 136, 124),
    16: (97, 100, 100), 18: (68, 108, 50), 20: (162, 201, 203), 21: (94, 111, 145),
    22: (51, 78, 156), 24: (218, 203, 150), 30: (216, 219, 212), 31: (83, 124, 52),
    32: (136, 103, 58), 37: (211, 180, 50), 38: (183, 71, 55), 39: (143, 109, 83),
    40: (181, 57, 49), 41: (234, 197, 62), 42: (205, 208, 204), 45: (153, 99, 79),
    47: (145, 116, 76), 48: (105, 122, 88), 49: (49, 42, 66), 50: (244, 197, 86),
    51: (239, 146, 38), 52: (64, 83, 84), 54: (157, 109, 45), 58: (145, 105, 60),
    59: (155, 154, 50), 60: (118, 88, 56), 61: (112, 116, 116), 62: (140, 126, 91),
    65: (162, 127, 76), 67: (119, 123, 122), 68: (151, 119, 72), 69: (125, 116, 97),
    70: (129, 131, 132), 71: (197, 200, 194), 72: (166, 134, 82), 73: (141, 111, 110),
    76: (204, 81, 46), 78: (227, 234, 233), 79: (144, 184, 217), 80: (228, 235, 234),
    81: (67, 122, 64), 82: (156, 165, 178), 83: (138, 167, 75), 86: (187, 120, 39),
    87: (117, 65, 64), 88: (89, 70, 57), 89: (213, 180, 116), 90: (104, 61, 135),
    91: (206, 145, 47), 96: (166, 134, 82), 98: (127, 130, 126), 99: (156, 133, 105),
    100: (180, 65, 51), 101: (169, 174, 169), 102: (162, 201, 203), 103: (134, 153, 47),
    104: (122, 148, 53), 105: (122, 148, 53), 106: (70, 110, 48), 108: (153, 99, 79),
    109: (127, 130, 126), 110: (120, 103, 113), 111: (64, 108, 46), 112: (66, 42, 44),
    113: (66, 42, 44), 114: (66, 42, 44), 116: (69, 67, 78), 117: (121, 106, 70),
    118: (78, 83, 87), 120: (111, 132, 94), 121: (218, 220, 165), 128: (218, 203, 150),
    130: (52, 76, 71), 133: (61, 174, 106), 137: (163, 125, 93), 139: (119, 123, 122),
    140: (143, 89, 66), 145: (83, 84, 84), 146: (157, 101, 41), 148: (205, 208, 204),
    151: (157, 132, 92), 152: (169, 43, 34), 154: (80, 85, 87), 155: (225, 220, 210),
    156: (225, 220, 210), 160: (146, 177, 181), 165: (113, 164, 76), 167: (197, 200, 194),
    169: (213, 180, 116), 172: (158, 104, 77), 173: (46, 48, 48), 174: (144, 184, 217),
    175: (104, 146, 65), 179: (184, 103, 52), 180: (184, 103, 52), 181: (184, 103, 52),
    182: (184, 103, 52), 198: (153, 129, 80), 199: (180, 133, 74),
}
STAIR_WOODS = {53: 0, 134: 1, 135: 2, 136: 3, 163: 4, 164: 5}


def font(size: int, bold: bool = False):
    name = 'DejaVuSans-Bold.ttf' if bold else 'DejaVuSans.ttf'
    for candidate in (str(FONT_DIRECTORY / name), name):
        try:
            return ImageFont.truetype(candidate, size)
        except OSError:
            pass
    try:
        return ImageFont.load_default(size=size)
    except TypeError:
        return ImageFont.load_default()


def color(block_id: int, metadata: int) -> tuple[int, int, int]:
    if block_id in (5, 125, 126):
        return WOODS[(metadata & 7) % 6]
    if block_id in (17, 162):
        return WOODS[((metadata & 3) + (4 if block_id == 162 else 0)) % 6]
    if block_id in STAIR_WOODS:
        return WOODS[STAIR_WOODS[block_id]]
    if block_id in (85, 107, 64):
        return WOODS[0]
    if block_id in (183, 184, 185, 186, 187):
        return WOODS[{183: 1, 184: 2, 185: 3, 186: 5, 187: 4}[block_id]]
    if block_id in (193, 194, 195, 196, 197):
        return WOODS[block_id - 192]
    if block_id in (35, 171):
        return DYES[metadata & 15]
    if block_id == 159:
        return TERRACOTTA[metadata & 15]
    if block_id == 1 and metadata:
        return {1: (152, 112, 95), 2: (159, 120, 104), 3: (190, 192, 184), 4: (199, 201, 193), 5: (128, 134, 130), 6: (137, 143, 139)}.get(metadata, COLORS[1])
    if block_id in (43, 44):
        return [(155, 156, 150), COLORS[24], WOODS[0], COLORS[4], COLORS[45], COLORS[98], COLORS[155], COLORS[112]][metadata & 7]
    return COLORS.get(block_id, (142, 137, 126))


def shade(rgb: tuple[int, int, int], factor: float) -> tuple[int, int, int]:
    return tuple(max(0, min(255, round(component * factor))) for component in rgb)


def cells_for(view: dict) -> dict[tuple[int, int, int], tuple[int, int]]:
    """Replay every write, including air, in the same order as StructurePlacer."""
    cells = {}
    raw = base64.b64decode(view['blocks'], validate=True)
    if len(raw) != view['write_count'] * 14:
        raise ValueError('Unexpected transformed record length')
    for x, y, z, block_id, metadata in struct.iter_unpack('<iiiBB', raw):
        key = (x, y, z)
        if block_id:
            cells[key] = (block_id, metadata)
        else:
            cells.pop(key, None)
    if not cells:
        raise ValueError('Layout has no visible geometry')
    return cells


def render_geometry(view: dict, width: int, height: int) -> tuple[Image.Image, dict]:
    cells = cells_for(view)
    # x and z grow toward the camera. Render the top and the two facing sides
    # only where their neighbor is air. Projection: (2*x-2*z, x+z-2*y).
    def project(x, y, z):
        return (2 * (x - z), x + z - 2 * y)

    xs, ys = [], []
    for x, y, z in cells:
        for dx, dy, dz in ((0, 0, 0), (1, 1, 0), (0, 1, 1), (1, 0, 1)):
            px, py = project(x + dx, y + dy, z + dz)
            xs.append(px)
            ys.append(py)
    min_x, max_x, min_y, max_y = min(xs), max(xs), min(ys) - 2, max(ys) + 2
    scale = min((width - 34) / max(1, max_x - min_x), (height - 30) / max(1, max_y - min_y))
    supersample = 2
    image = Image.new('RGBA', (width * supersample, height * supersample))
    draw = ImageDraw.Draw(image)
    offset_x = (width - (max_x - min_x) * scale) / 2 - min_x * scale
    offset_y = (height - (max_y - min_y) * scale) / 2 - min_y * scale

    def screen(point):
        px, py = project(*point)
        return (round((px * scale + offset_x) * supersample), round((py * scale + offset_y) * supersample))

    # A farther block always precedes a block on its +x, +z or +y neighbor.
    # Secondary keys explicitly stabilize all ties independent of dict order.
    surfaces = 0
    for x, y, z in sorted(cells, key=lambda p: (p[0] + p[1] + p[2], p[0] + p[2], p[1], p[0], p[2])):
        block_id, metadata = cells[x, y, z]
        rgb = color(block_id, metadata)
        # A small deterministic material variation reveals the actual block grid.
        noise = ((x * 734287 + y * 912931 + z * 438289) & 15) / 300 - 0.025
        rgb = shade(rgb, 1 + noise)
        faces = []
        if (x, y, z + 1) not in cells:
            faces.append(([(x, y, z + 1), (x + 1, y, z + 1), (x + 1, y + 1, z + 1), (x, y + 1, z + 1)], 0.70))
        if (x + 1, y, z) not in cells:
            faces.append(([(x + 1, y, z), (x + 1, y, z + 1), (x + 1, y + 1, z + 1), (x + 1, y + 1, z)], 0.84))
        if (x, y + 1, z) not in cells:
            faces.append(([(x, y + 1, z), (x + 1, y + 1, z), (x + 1, y + 1, z + 1), (x, y + 1, z + 1)], 1.10))
        for points, light in faces:
            draw.polygon([screen(point) for point in points], fill=shade(rgb, light))
            surfaces += 1
    image = image.resize((width, height), Image.Resampling.LANCZOS)
    bounds = [min(p[index] for p in cells) for index in range(3)] + [max(p[index] for p in cells) for index in range(3)]
    stats = {'blocks': len(cells), 'faces': surfaces, 'bounds': bounds, 'ids': Counter(state[0] for state in cells.values())}
    return image, stats


def axes(draw: ImageDraw.ImageDraw, x: int, y: int):
    # The axes match the exact projection used for every panel.
    origin = (x, y)
    for end, label, rgb in (((x + 24, y + 12), '+X', (157, 87, 77)), ((x - 24, y + 12), '+Z', (73, 123, 118)), ((x, y - 24), '+Y', (100, 117, 157))):
        draw.line([origin, end], fill=rgb, width=2)
        draw.text((end[0] - 7, end[1] - (15 if label == '+Y' else -3)), label, font=font(10), fill=rgb)


def contact_sheet(data: dict, output: Path):
    if data.get('format') != 1 or data.get('record_format') != '<iiiBB':
        raise ValueError('Unsupported preview export')
    if [view['family'] for view in data['views']] != list(LABELS):
        raise ValueError('Expected all eight families in contact sheet order')
    image = Image.new('RGB', (1600, 1040), '#eef1f3')
    draw = ImageDraw.Draw(image)
    draw.text((36, 24), 'STRUCTURE GEOMETRY', font=font(30, True), fill='#20313c')
    draw.text((37, 65), 'Assembled converted layouts  /  isometric block models', font=font(16), fill='#566974')
    badge = 'MODEL PREVIEWS'
    draw.rounded_rectangle((1371, 31, 1564, 67), radius=8, fill='#dce5e8')
    draw.text((1390, 40), badge, font=font(15, True), fill='#3d5964')
    axes(draw, 1290, 57)
    for index, view in enumerate(data['views']):
        column, row = index % 4, index // 4
        left, top = 28 + column * 391, 105 + row * 429
        right, bottom = left + 371, top + 410
        draw.rounded_rectangle((left, top + 3, right, bottom + 3), radius=12, fill='#dce2e5')
        draw.rounded_rectangle((left, top, right, bottom), radius=12, fill='#ffffff')
        draw.text((left + 17, top + 15), LABELS[view['family']], font=font(20, True), fill='#253b47')
        geometry, stats = render_geometry(view, 353, 288)
        piece_count = len(view['pieces'])
        draw.text((left + 18, top + 43), f"{piece_count} {'piece' if piece_count == 1 else 'pieces'}  ·  {stats['blocks']:,} blocks", font=font(13), fill='#6b7b83')
        image.paste(geometry, (left + 9, top + 68), geometry)
        draw.line((left + 17, top + 363, right - 17, top + 363), fill='#e8edef')
        b = stats['bounds']
        dimensions = ' × '.join(str(b[i + 3] - b[i] + 1) for i in range(3))
        draw.text((left + 18, top + 373), f'{dimensions} blocks  (X · Y · Z)', font=font(12), fill='#74848b')
        print(f"{view['family']}: {len(view['pieces'])} pieces, {stats['blocks']:,} final blocks, {stats['faces']:,} exposed faces, bounds {stats['bounds']}")
    draw.text((36, 978), f"Offline geometry preview; legacy material substitutions; seed {data['seed']}", font=font(18), fill='#344d59')
    draw.text((37, 1009), 'Model geometry previews, not client screenshots.  Block cells shown as cubes; individual panels fitted to view.', font=font(13), fill='#70828c')
    output.parent.mkdir(parents=True, exist_ok=True)
    image.save(output, format='PNG', optimize=True)
    print(f'Saved {output} ({output.stat().st_size:,} bytes; {image.width} × {image.height})')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', type=Path, help='JSON from export_structure_preview.php; otherwise export automatically')
    parser.add_argument('--output', type=Path, default=ROOT / 'docs/structure-preview.png')
    parser.add_argument('--php', type=Path, default=ROOT / 'bin/php7/bin/php')
    parser.add_argument('--seed', type=int, default=42)
    options = parser.parse_args()
    if options.input:
        data = json.loads(options.input.read_text())
    else:
        with tempfile.TemporaryDirectory(prefix='structure-preview-') as directory:
            export = Path(directory) / 'layouts.json'
            subprocess.run([str(options.php), str(ROOT / 'tools/export_structure_preview.php'), str(export), str(options.seed)], cwd=ROOT, check=True)
            data = json.loads(export.read_text())
    contact_sheet(data, options.output)


if __name__ == '__main__':
    main()
