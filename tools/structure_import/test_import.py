"""Focused source-free regression checks for converter invariants."""
from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from structure_import.blocks import ConversionError, load_registry, map_state, rotate_properties

ROOT = Path(__file__).resolve().parents[2]
REGISTRY = load_registry(ROOT / "src/pocketmine/block/Block.php")


def converted(name, props=None, rotation=0):
    result = map_state({"Name": "minecraft:" + name,
                        "Properties": rotate_properties(props or {}, rotation)})
    assert result.block_id in REGISTRY
    assert 0 <= result.meta <= 15
    return result.block_id, result.meta


def run():
    for turn in range(4):
        assert converted("oak_stairs", {"facing": "north", "half": "top"}, turn) == (53, [7, 4, 6, 5][turn])
        assert converted("oak_log", {"axis": "x"}, turn) == (17, 4 if turn % 2 == 0 else 8)
        assert converted("oak_slab", {"type": "top"}, turn) == (158, 8)
        assert converted("spruce_fence", {}, turn) == (85, 1)
    assert converted("oak_door", {"facing": "north", "half": "lower", "open": "true"}) == (64, 7)
    assert converted("oak_door", {"facing": "north", "half": "upper", "hinge": "right"}) == (64, 9)
    assert converted("oak_trapdoor", {"facing": "north", "half": "top", "open": "true"}) == (96, 13)
    assert converted("oak_fence_gate", {"facing": "north"}) == (107, 1)
    assert converted("oak_fence_gate", {"facing": "east"}) == (107, 2)
    assert converted("oak_fence_gate", {"facing": "south"}) == (107, 3)
    assert converted("oak_fence_gate", {"facing": "west"}) == (107, 0)
    assert converted("stone", {}, 0) == (1, 0)
    assert converted("stone", {}, 1) == (1, 0)
    assert converted("tripwire_hook", {"facing": "east"}) == (131, 3)
    assert converted("chest", {"facing": "north"}) == (54, 5)
    assert converted("dispenser", {"facing": "west"}) == (23, 4)
    assert converted("wall_torch", {"facing": "north"}) == (50, 4)
    assert converted("torch") == (50, 0)
    assert converted("waxed_oxidized_cut_copper_stairs", {"facing": "north", "half": "bottom", "shape": "straight"}, 0)[0] == 109
    for state in (
        {"Name": "minecraft:not_registered"},
        {"Name": "minecraft:stone", "Properties": {"unexpected": "true"}},
        {"Name": "minecraft:oak_stairs", "Properties": {"facing": "diagonal"}},
    ):
        try:
            map_state(state)
        except ConversionError:
            continue
        raise AssertionError(f"Unsupported state failed open: {state}")
    print("legacy ID/meta, four-rotation and unknown-state checks passed")


if __name__ == "__main__":
    run()
