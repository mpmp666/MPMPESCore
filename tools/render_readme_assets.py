#!/usr/bin/env python3
"""Build the project's original vector artwork and its README PNG exports.

Run from any directory: python3 tools/render_readme_assets.py
Requires CairoSVG and Pillow; SVGs contain no external fonts, images or scripts.
"""
from __future__ import annotations

from html import escape
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "docs" / "assets"
INK = "#edf7f5"
MUTED = "#a1b8bb"
MINT = "#82efbf"
CYAN = "#6ecce8"
PANEL = "#122a30"
LINE = "#29464c"
FONT = "DejaVu Sans, sans-serif"


def text(x, y, value, size=24, color=INK, weight=400, anchor="start", spacing=0):
    return (
        f'<text x="{x}" y="{y}" font-family="{FONT}" font-size="{size}" '
        f'font-weight="{weight}" fill="{color}" text-anchor="{anchor}" '
        f'letter-spacing="{spacing}">{escape(str(value))}</text>'
    )


def rect(x, y, w, h, fill=PANEL, radius=16, stroke=LINE):
    return (f'<rect x="{x}" y="{y}" width="{w}" height="{h}" '
            f'rx="{radius}" fill="{fill}" stroke="{stroke}"/>')


def path(d, stroke=LINE, width=2, fill="none", extra=""):
    return (f'<path d="{d}" fill="{fill}" stroke="{stroke}" '
            f'stroke-width="{width}" stroke-linecap="round" '
            f'stroke-linejoin="round" {extra}/>')


def svg(width, height, title, description, content):
    return f'''<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}" role="img" aria-labelledby="title desc">
<title id="title">{escape(title)}</title>
<desc id="desc">{escape(description)}</desc>
<defs>
  <linearGradient id="background" x2="1" y2="1">
    <stop stop-color="#102b31"/><stop offset="1" stop-color="#09171e"/>
  </linearGradient>
  <linearGradient id="face" x2="1" y2="1">
    <stop stop-color="#9af5c6"/><stop offset="1" stop-color="#4dc5b5"/>
  </linearGradient>
  <radialGradient id="halo">
    <stop stop-color="#225a55" stop-opacity=".65"/>
    <stop offset="1" stop-color="#225a55" stop-opacity="0"/>
  </radialGradient>
</defs>
{content}
</svg>
'''


def mark(x=0, y=0, scale=1):
    """A block-cut M, extruded toward the top right; six linked client nodes."""
    content = []
    for node_x in (64, 448):
        for node_y in (146, 256, 366):
            joint_x = 112 if node_x == 64 else 400
            content.append(path(f"M{node_x} {node_y} H{joint_x}", "#30565b", 3))
            content.append(rect(node_x - 5, node_y - 5, 10, 10, MINT, 2, "none"))
    front = "M116 366 V158 H170 L256 258 L342 158 H396 V366 H340 V248 L256 342 L172 248 V366 Z"
    # Exposed extrusion surfaces follow the same outline as the front face.
    content.append(path("M116 158 L144 134 H198 L170 158 Z", fill="#d5ffe6", stroke="none"))
    content.append(path("M170 158 L198 134 L284 234 L256 258 Z", fill="#a1f0cd", stroke="none"))
    content.append(path("M256 258 L284 234 L370 134 L342 158 Z", fill="#36a899", stroke="none"))
    content.append(path("M342 158 L370 134 H424 L396 158 Z", fill="#d5ffe6", stroke="none"))
    content.append(path("M396 158 L424 134 V342 L396 366 Z", fill="#2896a4", stroke="none"))
    content.append(path("M172 366 L200 342 V280 L172 248 Z", fill="#2896a4", stroke="none"))
    content.append(path(front, fill="url(#face)", stroke="none"))
    content.append(path("M116 158 H170 L256 258 L342 158 H396", "#c4ffe2", 2))
    return f'<g transform="translate({x} {y}) scale({scale})">{"".join(content)}</g>'


def cube(x, y, size=30, colors=(MINT, "#359f87", "#247484")):
    top, left, right = colors
    return "".join([
        path(f"M{x} {y} l{size} {-size/2} l{size} {size/2} l{-size} {size/2} Z", fill=top, stroke="none"),
        path(f"M{x} {y} l{size} {size/2} v{size} l{-size} {-size/2} Z", fill=left, stroke="none"),
        path(f"M{x+size} {y+size/2} l{size} {-size/2} v{size} l{-size} {size/2} Z", fill=right, stroke="none"),
    ])


def logo():
    return svg(512, 512, "MPMPESCore icon", "An extruded mint M linked to six client nodes on a dark square.",
               rect(1, 1, 510, 510, "url(#background)", 108, "#26494f")
               + '<circle cx="270" cy="246" r="228" fill="url(#halo)"/>'
               + mark())


def banner():
    parts = [rect(1, 1, 1598, 618, "url(#background)", 28, "#26494f")]
    parts.append('<ellipse cx="1250" cy="300" rx="370" ry="300" fill="url(#halo)"/>')
    # Sparse grid suggests a world made of blocks, while keeping the copy clear.
    for x in range(950, 1600, 64):
        parts.append(path(f"M{x} 40 V580", "#1a363d", 1))
    for y in range(76, 590, 64):
        parts.append(path(f"M960 {y} H1560", "#1a363d", 1))
    parts.append(rect(88, 72, 54, 4, MINT, 2, "none"))
    parts.append(text(88, 124, "LEGACY CLIENTS / SHARED WORLDS", 18, MINT, 600, spacing=3))
    parts.append(text(82, 239, "MPMPESCore", 83, weight=700, spacing=-3))
    parts.append(text(88, 303, "One server. Six versions. Same world.", 30, MUTED))
    parts.append(text(88, 378, "MINECRAFT: POCKET EDITION", 16, MUTED, 500, spacing=2))
    for index, version in enumerate(("0.11", "0.12", "0.13", "0.14", "0.15", "0.16")):
        x = 88 + index * 130
        parts.append(rect(x, 402, 112, 53, "#163239", 10, "#32585d"))
        parts.append(text(x + 56, 437, version, 25, MINT if version == "0.14" else INK, 600, "middle"))
    parts.append(path("M88 505 H866", "#29464c", 1))
    parts.append(text(88, 552, "MPAPI 1.1    /    BUILT-IN FRP    /    MULTI-DIMENSION", 16, MUTED, spacing=1))
    # A voxel plinth under the mark; this is brand artwork, not a game screenshot.
    parts.append(path("M1002 412 L1248 290 L1494 412 L1248 537 Z", "#355759", 2, "#153237"))
    parts.append(path("M1002 412 V434 L1248 559 L1494 434 V412 M1248 537 V559", "#355759", 2))
    parts.append(mark(980, 23, 1.04))
    parts.append(cube(1018, 174, 22))
    parts.append(cube(1434, 286, 17, (CYAN, "#348f9d", "#226677")))
    parts.append(cube(1350, 100, 12))
    parts.append(text(1248, 588, "BUILT ON GENISYS", 14, MUTED, 500, "middle", 3))
    return svg(1600, 620, "MPMPESCore — one server, six versions, same world",
               "Brand illustration: six MCPE versions, 0.11 through 0.16, and a mint block-cut M. Not a client screenshot.", "".join(parts))


def compatibility():
    parts = [rect(1, 1, 1598, 718, "url(#background)", 24, "#26494f")]
    parts.append(text(56, 66, "SIX CLIENT VERSIONS. ONE SHARED WORLD.", 30, weight=700))
    parts.append(text(56, 101, "Protocol translation overview", 20, MUTED))
    parts.append(text(56, 150, "MCPE CLIENTS", 14, MINT, 600, spacing=2))
    rows = [
        ("0.11.x", "21–33 / family 5"),
        ("0.12.x", "34 / family 4"),
        ("0.13.x", "37–39 / family 2"),
        ("0.14.x", "45 / 46 / 60 / 70 · native"),
        ("0.15.x", "81–83 / family 1"),
        ("0.16.x", "90 / 91 / family 3"),
    ]
    for index, (version, protocol) in enumerate(rows):
        y = 172 + index * 71
        color = MINT if index == 3 else CYAN
        parts.append(path(f"M400 {y+29} H{448+index*10} V385 H550", "#315c66", 2))
        parts.append(rect(56, y, 344, 58, "#17353b" if index == 3 else PANEL, 10, "#3d776c" if index == 3 else LINE))
        parts.append(rect(56, y+13, 3, 32, color, 1, "none"))
        parts.append(text(74, y+37, version, 23, INK, 700))
        parts.append(text(174, y+36, protocol, 14, MUTED))
        parts.append(f'<circle cx="400" cy="{y+29}" r="4" fill="{color}"/>')
    parts.append(rect(550, 251, 418, 270, "#17373c", 20, "#458575"))
    parts.append(text(583, 295, "ONE UDP PORT", 14, MINT, 600, spacing=2))
    parts.append(text(583, 346, "MultiProtocol", 34, weight=700))
    parts.append(text(583, 387, "Packet IDs · fields · batches", 20, MUTED))
    parts.append(path("M583 411 H933", "#35565b", 1))
    parts.append(text(583, 446, "Native baseline: protocol 70", 19, INK))
    parts.append(text(583, 482, "Default port: 19132 / UDP", 17, MUTED))
    parts.append(path("M968 385 H1072", MINT, 3))
    parts.append(path("M1062 379 L1072 385 L1062 391", MINT, 3))
    parts.append(rect(1088, 251, 456, 270, PANEL, 20, "#35565b"))
    parts.append(mark(1110, 271, .24))
    parts.append(text(1254, 329, "MPMPESCore", 28, weight=700))
    parts.append(text(1254, 364, "Shared world state", 20, MINT))
    parts.append(path("M1120 411 H1510", LINE, 1))
    parts.append(text(1120, 449, "Players · chunks · entities", 21, INK))
    parts.append(text(1120, 485, "Same map, across client versions", 18, MUTED))
    parts.append(rect(56, 624, 1488, 55, "#302e24", 10, "#57503a"))
    parts.append(text(78, 659, "Compatibility varies by feature. See README for 0.13.0, 0.16 and map-item limitations.", 21, "#e3cd9b"))
    return svg(1600, 720, "MPMPESCore multi-protocol architecture",
               "Six MCPE client families connect through one UDP port and the MultiProtocol layer to shared players, chunks and entities. Protocol 70 is the native baseline. Feature limitations are documented in README.", "".join(parts))


def main():
    try:
        import cairosvg
        from PIL import Image
    except ImportError as error:
        raise SystemExit("Install artwork dependencies: python3 -m pip install cairosvg pillow") from error

    OUTPUT.mkdir(parents=True, exist_ok=True)
    for name, source in (("logo", logo()), ("banner", banner()), ("compatibility", compatibility())):
        target = OUTPUT / f"{name}.svg"
        target.write_text(source, encoding="utf-8")
        png = OUTPUT / f"{name}.png"
        cairosvg.svg2png(bytestring=source.encode("utf-8"), write_to=str(png))
        # Keep repository assets compact and strip transient renderer metadata.
        with Image.open(png) as image:
            image.save(png, optimize=True)
            width, height = image.size
        print(f"{target.relative_to(ROOT)} + {png.relative_to(ROOT)} ({width} × {height})")


if __name__ == "__main__":
    main()
