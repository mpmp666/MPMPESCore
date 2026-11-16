#!/usr/bin/env python3
"""Import vanilla structure resources from an MCP-Reborn ZIP using stdlib only.

Usage:
    python3 tools/import_vanilla_structures.py /path/to/MCP-Reborn-26.2.zip
    python3 tools/import_vanilla_structures.py /path/to/archive.zip --check

No Java, PHP, extracted scripts, or target code is executed. Archive contents
are read directly in memory. Outputs are limited to resources/structures.
"""
from __future__ import annotations

import argparse
import base64
from collections import Counter, defaultdict
import gzip
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import struct
import sys
import zipfile

from structure_import.blocks import (
    ConversionError, DIRECTIONS, load_registry, map_state, parse_state,
    rotate_properties,
)
from structure_import.nbt import loads

ROOT = Path(__file__).resolve().parents[1]
JAR_MEMBER = "projects/mcp/build/mcp/extractServer/output.jar"
STRUCTURE_PREFIX = "data/minecraft/structure/"
# A typo actually present in the supplied vanilla source. Java's failed block
# state parse yields air. Do not silently accept arbitrary future malformed data.
KNOWN_MALFORMED_FINAL_STATE = "minecraft:acacia_fence[east=false,north=false,south=false,waterlogged=false,west=false]]"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def file_digest(path):
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def json_bytes(value, pretty=False):
    return (json.dumps(value, ensure_ascii=True, sort_keys=True, allow_nan=False,
                       indent=2 if pretty else None,
                       separators=None if pretty else (",", ":")) + "\n").encode("utf-8")


def compressed_json(value):
    # GzipFile fixes the OS header to 255 as well as omitting filename/time.
    out = io.BytesIO()
    with gzip.GzipFile(fileobj=out, mode="wb", filename="", mtime=0, compresslevel=9) as handle:
        handle.write(json_bytes(value))
    return out.getvalue()


def safe_key(path, prefix, suffix):
    key = path[len(prefix):-len(suffix)]
    parts = PurePosixPath(key).parts
    if not key or key.startswith("/") or any(p in (".", "..") for p in parts) or "\\" in key:
        raise ConversionError(f"Unsafe resource path {path!r}")
    return key


def state_key(state):
    return state["Name"] + ("[" + ",".join(f"{k}={v}" for k, v in sorted(state.get("Properties", {}).items())) + "]" if state.get("Properties") else "")


class Importer:
    def __init__(self, archive, output, check=False):
        self.archive = archive
        self.output = output
        self.check = check
        self.registry_path = ROOT / "src/pocketmine/block/Block.php"
        self.registry = load_registry(self.registry_path)
        self.states = {}
        self.names = defaultdict(lambda: {"states": set(), "ids": set(), "notes": set(), "palette_occurrences": 0})
        self.counts = Counter()
        self.tile_kinds = Counter()
        self.entity_kinds = Counter()
        self.data_versions = Counter()
        self.variants = []
        self.malformed = []
        self.ground_levels = []
        self.source_roots = Counter()
        self.payload_digests = {}
        self.decoded_bytes = 0
        self.compressed_bytes = 0

    def convert_state(self, state, palette_occurrence=False):
        key = state_key(state)
        if key not in self.states:
            results = [map_state({"Name": state["Name"], "Properties": rotate_properties(state.get("Properties", {}), turn)}) for turn in range(4)]
            for result in results:
                if result.block_id not in self.registry:
                    raise ConversionError(f"{key} maps to unregistered block ID {result.block_id}")
            self.states[key] = {
                "source": state,
                "rotations": [[r.block_id, r.meta] for r in results],
                "notes": sorted(set(note for r in results for note in r.notes)),
            }
        entry = self.states[key]
        name = state["Name"].removeprefix("minecraft:")
        report = self.names[name]
        report["states"].add(key)
        report["ids"].update(tuple(pair) for pair in entry["rotations"])
        report["notes"].update(entry["notes"])
        if palette_occurrence:
            report["palette_occurrences"] += 1
        return entry["rotations"]

    def write(self, relative, data):
        path = self.output / relative
        if self.check:
            if not path.is_file() or path.read_bytes() != data:
                raise ConversionError(f"Resource differs from deterministic conversion: {path}")
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        self.payload_digests[relative] = digest(data)
        return len(data)

    def final_state(self, nbt, key, pos):
        final = nbt.get("final_state", "minecraft:air")
        try:
            return parse_state(final)
        except ConversionError:
            if final != KNOWN_MALFORMED_FINAL_STATE:
                raise
            self.malformed.append({"template": key, "pos": pos, "final_state": final,
                                   "action": "minecraft:air", "reason": "Vanilla block state parse failure returns air"})
            return {"Name": "minecraft:air"}

    def template(self, key, raw):
        root = loads(raw)
        self.source_roots.update(root.keys())
        self.data_versions[str(root.get("DataVersion", "missing"))] += 1
        size = root["size"]
        if len(size) != 3 or any(type(n) is not int or not 0 < n < 65536 for n in size):
            raise ConversionError(f"Invalid size for {key}: {size}")
        palettes = root.get("palettes", [root.get("palette", [])])
        if not palettes or not palettes[0]:
            raise ConversionError(f"Empty palette in {key}")
        if any(len(pal) != len(palettes[0]) for pal in palettes):
            raise ConversionError(f"Palette alternatives have unequal lengths: {key}")
        for palette in palettes:
            for state in palette:
                self.convert_state(state, palette_occurrence=True)
        if "palettes" in root:
            self.variants.append({"template": key, "count": len(palettes), "selected": 0,
                                  "palette_sha256": [digest(json_bytes(pal)) for pal in palettes],
                                  "alternatives": palettes})
        selected = palettes[0]
        output_palette = [self.convert_state(state) for state in selected]
        palette_by_state = {state_key(s): i for i, s in enumerate(selected)}
        packed = bytearray()
        connectors, markers, tiles = [], [], []
        positions = set()
        source_blocks = root.get("blocks", [])
        for block in sorted(source_blocks, key=lambda b: tuple(b["pos"])):
            pos, index = block["pos"], block["state"]
            if len(pos) != 3 or any(type(n) is not int or not 0 <= n < size[i] for i, n in enumerate(pos)):
                raise ConversionError(f"Block outside template dimensions: {key} {pos}")
            if tuple(pos) in positions:
                raise ConversionError(f"Duplicate block coordinate: {key} {pos}")
            positions.add(tuple(pos))
            if type(index) is not int or not 0 <= index < len(selected):
                raise ConversionError(f"Invalid palette index: {key} {index}")
            state = selected[index]
            name = state["Name"].removeprefix("minecraft:")
            nbt = block.get("nbt")
            if nbt is not None:
                if not isinstance(nbt, dict): raise ConversionError(f"Noncompound tile NBT in {key}")
                self.tile_kinds[nbt.get("id", "missing")] += 1
                # Preserve every original block entity including structure controls;
                # runtime may select usable classes while connectors/markers are explicit.
                tile = {"pos": pos, "name": name, "nbt": nbt}
                if state.get("Properties"):
                    tile["properties"] = state["Properties"]
                tiles.append(tile)
            elif name.startswith("potted_") or name in ("skeleton_skull", "dragon_wall_head", "note_block"):
                # These modern block entities can store all visible information in
                # the palette and omit NBT entirely. Keep that source information
                # without pretending an original compound existed.
                tiles.append({"pos": pos, "name": name, "nbt": {},
                              "properties": state.get("Properties", {}), "synthetic": True})
                self.counts["synthetic_tiles"] += 1
            if name == "structure_block":
                mode = (nbt or {}).get("mode", state.get("Properties", {}).get("mode", "")).lower()
                if mode == "data":
                    markers.append({"pos": pos, "metadata": (nbt or {}).get("metadata", "")})
                    self.counts["markers"] += 1
                self.counts["structure_blocks_skipped"] += 1
                continue
            if name == "jigsaw":
                nbt = nbt or {}
                orientation = state.get("Properties", {}).get("orientation", "north_up").split("_")
                if len(orientation) != 2 or any(d not in DIRECTIONS for d in orientation):
                    raise ConversionError(f"Invalid jigsaw orientation in {key}")
                connector = {"pos": pos, "front": orientation[0], "top": orientation[1],
                             "name": nbt.get("name", "minecraft:empty"),
                             "target": nbt.get("target", "minecraft:empty"),
                             "pool": nbt.get("pool", "minecraft:empty"),
                             "joint": nbt.get("joint", "rollable" if orientation[0] in ("up", "down") else "aligned"),
                             "selection_priority": int(nbt.get("selection_priority", 0)),
                             "placement_priority": int(nbt.get("placement_priority", 0))}
                connectors.append(connector)
                self.counts["connectors"] += 1
                state = self.final_state(nbt, key, pos)
                name = state["Name"].removeprefix("minecraft:")
                mapped = self.convert_state(state)
                final_key = state_key(state)
                if final_key not in palette_by_state:
                    palette_by_state[final_key] = len(output_palette)
                    output_palette.append(mapped)
                index = palette_by_state[final_key]
                self.counts["jigsaw_final_states"] += 1
            if name == "structure_void":
                self.counts["structure_void_skipped"] += 1
                continue
            if name == "structure_block":
                self.counts["structure_blocks_skipped"] += 1
                continue
            if len(output_palette) > 65536:
                raise ConversionError(f"Palette exceeds unsigned16 index: {key}")
            packed.extend(struct.pack("<4H", *pos, index))
            self.counts["blocks"] += 1
            if name in ("air", "cave_air", "void_air"):
                self.counts["explicit_air_blocks"] += 1
        entities = root.get("entities", [])
        for entity in entities:
            self.entity_kinds[entity.get("nbt", {}).get("id", "missing")] += 1
        self.counts["entities"] += len(entities)
        self.counts["tiles"] += len(tiles)
        self.counts["source_blocks"] += len(source_blocks)
        result = {"format": 1, "size": size, "palette": output_palette,
                  "blocks": base64.b64encode(packed).decode("ascii"),
                  "connectors": connectors, "tiles": tiles, "entities": entities,
                  "markers": markers, "source_sha256": digest(raw)}
        self.decoded_bytes += len(json_bytes(result))
        relative = key + ".json.gz"
        self.compressed_bytes += self.write(relative, compressed_json(result))
        ground = root.get("ground_level", root.get("ground_level_delta", 1))
        if "ground_level" in root or "ground_level_delta" in root:
            self.ground_levels.append(key)
        meta = {"size": size, "family": key.split("/", 1)[0], "file": relative, "ground_level": ground}
        if connectors: meta["connectors"] = connectors
        return meta

    def run(self):
        archive_sha256 = file_digest(self.archive)
        with zipfile.ZipFile(self.archive) as outer:
            members = [info for info in outer.infolist() if info.filename == JAR_MEMBER]
            if len(members) != 1:
                raise ConversionError("Archive must contain exactly one extractServer/output.jar")
            jar_bytes = outer.read(members[0])
        source = {"archive": self.archive.name, "archive_sha256": archive_sha256,
                  "archive_bytes": self.archive.stat().st_size,
                  "jar_member": JAR_MEMBER, "jar_sha256": digest(jar_bytes), "jar_bytes": len(jar_bytes),
                  "legacy_registry": "src/pocketmine/block/Block.php",
                  "legacy_registry_sha256": file_digest(self.registry_path),
                  "rotation": "Clockwise quarter turns about +Y: north->east, (x,z)->(length-1-z,x)",
                  "palette_variants": "First source palette selected deterministically; all alternative states validated and reported",
                  "ground_level": "Template NBT has no ground-level metadata; bundled StructurePoolElement.getGroundLevelDelta() returns 1",
                  "ground_level_source": "src/main/java/net/minecraft/world/level/levelgen/structure/pools/StructurePoolElement.java"}
        templates, families = {}, defaultdict(list)
        pools, loot = {}, {}
        worldgen = {"structures": {}, "structure_sets": {}, "processor_lists": {}}
        with zipfile.ZipFile(io.BytesIO(jar_bytes)) as jar:
            paths = jar.namelist()
            if len(paths) != len(set(paths)):
                raise ConversionError("Nested jar contains duplicate member paths")
            structure_paths = sorted(n for n in paths if n.startswith(STRUCTURE_PREFIX) and n.endswith(".nbt"))
            if not structure_paths: raise ConversionError("No vanilla structure templates found")
            for index, path in enumerate(structure_paths, 1):
                key = safe_key(path, STRUCTURE_PREFIX, ".nbt")
                templates[key] = self.template(key, jar.read(path))
                families[templates[key]["family"]].append(key)
                if index % 100 == 0:
                    print(f"{'Checking' if self.check else 'Importing'} templates {index}/{len(structure_paths)}", flush=True)
            for path in sorted(paths):
                if not path.endswith(".json"): continue
                destinations = [
                    ("data/minecraft/worldgen/template_pool/", pools),
                    ("data/minecraft/loot_table/", loot),
                    ("data/minecraft/worldgen/structure/", worldgen["structures"]),
                    ("data/minecraft/worldgen/structure_set/", worldgen["structure_sets"]),
                    ("data/minecraft/worldgen/processor_list/", worldgen["processor_lists"]),
                ]
                for prefix, destination in destinations:
                    if path.startswith(prefix):
                        key = "minecraft:" + safe_key(path, prefix, ".json")
                        destination[key] = json.loads(jar.read(path))
                        break
        for name, obj in (("pools.json", pools), ("worldgen.json", worldgen), ("loot_tables.json", loot)):
            self.write(name, json_bytes(obj))
        self.write("manifest.json", json_bytes({"format": 1, "templates": templates,
                                                 "families": dict(families), "source": source}))
        names = {}
        for name, info in sorted(self.names.items()):
            names[name] = {"state_count": len(info["states"]), "palette_occurrences": info["palette_occurrences"],
                           "legacy_states": [list(pair) for pair in sorted(info["ids"])],
                           "legacy_ids": sorted(set(pair[0] for pair in info["ids"])),
                           "substitutions_and_losses": sorted(info["notes"])}
        mapping = {"format": 1, "unconvertible": 0, "unmapped_names": [], "names": names,
                   "states": self.states, "registry": self.registry,
                   "palette_variants": self.variants, "malformed_final_states": self.malformed,
                   "tile_policy": "All original decoded block entity compounds retained unchanged, including control blocks; runtime must filter by supported tile class",
                   "source": source}
        self.write("mapping_report.json", json_bytes(mapping, pretty=True))
        inventory = {"format": 1, "source": source, "templates": len(templates),
                     "families": {name: len(keys) for name, keys in sorted(families.items())},
                     "modern_block_names": len(names), "modern_block_states": len(self.states),
                     "unconvertible": 0, "unmapped_names": [],
                     "max_dimensions": [max(t["size"][i] for t in templates.values()) for i in range(3)],
                     "counts": dict(sorted(self.counts.items())), "tile_kinds": dict(sorted(self.tile_kinds.items())),
                     "entity_kinds": dict(sorted(self.entity_kinds.items())),
                     "template_pools": len(pools), "loot_tables": len(loot),
                     "worldgen": {key: len(value) for key, value in worldgen.items()},
                     "palette_variant_templates": len(self.variants),
                     "palette_variant_policy": "Selected source palette 0; all alternatives retained in mapping_report.json and fully validated",
                     "data_versions": dict(sorted(self.data_versions.items())),
                     "source_root_fields": dict(sorted(self.source_roots.items())),
                     "templates_with_ground_metadata": self.ground_levels,
                     "malformed_final_states": self.malformed,
                     "substituted_block_names": {name: info for name, info in names.items() if info["substitutions_and_losses"]},
                     "template_json_bytes": self.decoded_bytes, "template_gzip_bytes": self.compressed_bytes,
                     "resource_bytes_before_inventory": sum((self.output / path).stat().st_size for path in self.payload_digests),
                     "resource_sha256": dict(sorted(self.payload_digests.items()))}
        self.write("inventory.json", json_bytes(inventory, pretty=True))
        summary = {"templates": len(templates), "names": len(names), "states": len(self.states),
                   "unconvertible": 0, "template_gzip_bytes": self.compressed_bytes,
                   "resource_bytes": sum((self.output / path).stat().st_size for path in self.payload_digests),
                   "resource_files": len(self.payload_digests), "pools": len(pools), "loot_tables": len(loot)}
        print(json.dumps(summary, sort_keys=True), flush=True)
        return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive", type=Path)
    parser.add_argument("--check", action="store_true", help="Reconvert and require byte-identical committed outputs")
    args = parser.parse_args()
    if not args.archive.is_file(): parser.error("Source archive is not a regular file")
    try:
        Importer(args.archive, ROOT / "resources/structures", args.check).run()
    except (ConversionError, ValueError, KeyError, IndexError, zipfile.BadZipFile) as exc:
        print(f"Conversion failed closed: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
