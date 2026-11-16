"""Explicit modern state to this repository's registered MCPE block mapping.

There is deliberately no catch-all mapping. Unknown names, properties and values
raise ConversionError. The PHP registry is read as text; no target code executes.
"""
from __future__ import annotations

from dataclasses import dataclass
import re


class ConversionError(ValueError):
    pass


HORIZONTAL = ("north", "east", "south", "west")
DIRECTIONS = ("down", "up", "north", "south", "west", "east")
FACE_META = {name: index for index, name in enumerate(DIRECTIONS)}
COLORS = {name: index for index, name in enumerate((
    "white", "orange", "magenta", "light_blue", "yellow", "lime", "pink", "gray",
    "light_gray", "cyan", "purple", "blue", "brown", "green", "red", "black",
))}
WOODS = {name: index for index, name in enumerate(("oak", "spruce", "birch", "jungle", "acacia", "dark_oak"))}
WOOD_STAIRS = dict(zip(WOODS, (53, 134, 135, 136, 163, 164)))
WOOD_DOORS = dict(zip(WOODS, (64, 193, 194, 195, 196, 197)))
WOOD_GATES = dict(zip(WOODS, (107, 183, 184, 185, 187, 186)))
STAIR_META = {"east": 0, "west": 1, "south": 2, "north": 3}
FOUR_META = {"south": 0, "west": 1, "north": 2, "east": 3}
DOOR_META = {"east": 0, "south": 1, "west": 2, "north": 3}
TRAPDOOR_META = {"south": 0, "north": 1, "east": 2, "west": 3}
GATE_META = {"west": 0, "north": 1, "east": 2, "south": 3}
CHEST_META = {"south": 4, "north": 5, "east": 3, "west": 2}
DISPENSER_META = {"down": 0, "up": 1, "north": 2, "south": 3, "west": 4, "east": 5}
PUMPKIN_META = {"east": 0, "south": 1, "west": 2, "north": 3}
TORCH_META = {"east": 1, "west": 2, "south": 3, "north": 4, "up": 0}
AXIS_META = {"y": 0, "x": 4, "z": 8}
RAIL_META = {"north_south": 0, "east_west": 1, "ascending_east": 2,
             "ascending_west": 3, "ascending_north": 4, "ascending_south": 5,
             "south_east": 6, "south_west": 7, "north_west": 8, "north_east": 9}

# Exact names only. Each number is checked against Block.php's actual registration.
# (id, metadata, material substitution explanation or None)
SIMPLE = {
    "air": (0, 0, None), "cave_air": (0, 0, "Air subtype collapsed to legacy air"),
    "stone": (1, 0, None), "smooth_stone": (43, 0, "Smooth stone uses legacy double smooth stone slab"),
    "granite": (1, 1, None), "polished_granite": (1, 2, None),
    "diorite": (1, 3, None), "polished_diorite": (1, 4, None),
    "andesite": (1, 5, None), "polished_andesite": (1, 6, None),
    "dirt": (3, 0, None), "coarse_dirt": (3, 1, None), "cobblestone": (4, 0, None),
    "bedrock": (7, 0, None), "sand": (12, 0, None), "red_sand": (12, 1, None),
    "gravel": (13, 0, None), "gold_ore": (14, 0, None), "iron_ore": (15, 0, None),
    "coal_ore": (16, 0, None), "glass": (20, 0, None), "lapis_ore": (21, 0, None),
    "lapis_block": (22, 0, None), "sandstone": (24, 0, None),
    "chiseled_sandstone": (24, 1, None), "cut_sandstone": (24, 2, None),
    "smooth_sandstone": (24, 2, "Smooth sandstone uses legacy smooth/cut sandstone"),
    "cobweb": (30, 0, None), "dead_bush": (32, 0, None),
    "dandelion": (37, 0, None), "poppy": (38, 0, None), "blue_orchid": (38, 1, None),
    "allium": (38, 2, None), "azure_bluet": (38, 3, None),
    "red_tulip": (38, 4, None), "orange_tulip": (38, 5, None),
    "white_tulip": (38, 6, None), "pink_tulip": (38, 7, None), "oxeye_daisy": (38, 8, None),
    "brown_mushroom": (39, 0, None), "red_mushroom": (40, 0, None),
    "gold_block": (41, 0, None), "iron_block": (42, 0, None), "bricks": (45, 0, None),
    "bookshelf": (47, 0, None), "mossy_cobblestone": (48, 0, None), "obsidian": (49, 0, None),
    "diamond_ore": (56, 0, None), "diamond_block": (57, 0, None), "crafting_table": (58, 0, None),
    "ice": (79, 0, None), "snow_block": (80, 0, None), "clay": (82, 0, None),
    "netherrack": (87, 0, None), "soul_sand": (88, 0, None), "glowstone": (89, 0, None),
    "stone_bricks": (98, 0, None), "mossy_stone_bricks": (98, 1, None),
    "cracked_stone_bricks": (98, 2, None), "chiseled_stone_bricks": (98, 3, None),
    "melon": (103, 0, None), "mycelium": (110, 0, None), "lily_pad": (111, 0, None),
    "nether_bricks": (112, 0, None), "enchanting_table": (116, 0, None),
    "end_stone": (121, 0, None), "emerald_ore": (129, 0, None), "emerald_block": (133, 0, None),
    "flower_pot": (140, 0, None), "redstone_block": (152, 0, None),
    "nether_quartz_ore": (153, 0, None), "quartz_block": (155, 0, None),
    "smooth_quartz": (155, 0, "Smooth quartz uses legacy quartz block"),
    "terracotta": (172, 0, None), "coal_block": (173, 0, None), "packed_ice": (174, 0, None),
    "red_sandstone": (179, 0, None), "chiseled_red_sandstone": (179, 1, None),
    "cut_red_sandstone": (179, 2, None), "dirt_path": (198, 0, None),
    "infested_stone": (1, 0, "Infested block rendered as its host stone; infestation unavailable"),
    "infested_cobblestone": (4, 0, "Infested block rendered as its host cobblestone; infestation unavailable"),
    "infested_stone_bricks": (98, 0, "Infested block rendered as its host bricks; infestation unavailable"),
    "infested_mossy_stone_bricks": (98, 1, "Infested block rendered as its host bricks; infestation unavailable"),
    "infested_cracked_stone_bricks": (98, 2, "Infested block rendered as its host bricks; infestation unavailable"),
    "infested_chiseled_stone_bricks": (98, 3, "Infested block rendered as its host bricks; infestation unavailable"),
    "blackstone": (1, 0, "Blackstone replaced by gray stone"),
    "cobbled_deepslate": (4, 0, "Cobbled deepslate replaced by cobblestone"),
    "chiseled_deepslate": (98, 3, "Chiseled deepslate replaced by chiseled stone bricks"),
    "deepslate_bricks": (98, 0, "Deepslate bricks replaced by stone bricks"),
    "cracked_deepslate_bricks": (98, 2, "Cracked deepslate bricks replaced by cracked stone bricks"),
    "deepslate_tiles": (98, 0, "Deepslate tiles replaced by stone bricks"),
    "cracked_deepslate_tiles": (98, 2, "Cracked deepslate tiles replaced by cracked stone bricks"),
    "polished_deepslate": (1, 0, "Polished deepslate replaced by stone"),
    "reinforced_deepslate": (49, 0, "Reinforced deepslate replaced by dark blast-resistant obsidian"),
    "polished_blackstone_bricks": (98, 0, "Blackstone bricks replaced by stone bricks"),
    "cracked_polished_blackstone_bricks": (98, 2, "Cracked blackstone bricks replaced by cracked stone bricks"),
    "chiseled_polished_blackstone": (98, 3, "Chiseled blackstone replaced by chiseled stone bricks"),
    "gilded_blackstone": (16, 0, "Gold-flecked blackstone replaced by coal ore for dark flecked texture"),
    "smooth_basalt": (1, 0, "Smooth basalt replaced by stone"),
    "tuff": (1, 5, "Tuff replaced by andesite"),
    "polished_tuff": (1, 6, "Polished tuff replaced by polished andesite"),
    "tuff_bricks": (98, 0, "Tuff bricks replaced by stone bricks"),
    "chiseled_tuff": (98, 3, "Chiseled tuff replaced by chiseled stone bricks"),
    "chiseled_tuff_bricks": (98, 3, "Chiseled tuff bricks replaced by chiseled stone bricks"),
    "mud": (159, 12, "Mud replaced by brown terracotta"),
    "packed_mud": (159, 12, "Packed mud replaced by brown terracotta"),
    "mud_bricks": (45, 0, "Mud bricks replaced by legacy fired bricks"),
    "moss_block": (35, 13, "Moss block replaced by green wool"),
    "moss_carpet": (171, 13, "Moss carpet replaced by green carpet"),
    "blue_ice": (174, 0, "Blue ice replaced by packed ice"),
    "powder_snow": (80, 0, "Powder snow replaced by solid snow"),
    "prismarine": (159, 9, "Prismarine replaced by cyan terracotta"),
    "sea_lantern": (89, 0, "Sea lantern replaced by glowstone to retain light"),
    "end_stone_bricks": (121, 0, "End stone brick texture replaced by legacy end stone"),
    "purpur_block": (159, 10, "Purpur block replaced by purple terracotta"),
    "magma_block": (87, 0, "Magma block replaced by netherrack; heat effect unavailable"),
    "sulfur": (159, 4, "26.2 sulfur replaced by yellow terracotta"),
    "cinnabar": (159, 14, "26.2 cinnabar replaced by red terracotta"),
}

STAIRS = {
    "cobblestone": (67, None), "brick": (108, None), "stone_brick": (109, None),
    "nether_brick": (114, None), "sandstone": (128, None), "quartz": (156, None),
    "red_sandstone": (180, None),
    "blackstone": (67, "Blackstone stairs use cobblestone stairs"),
    "cobbled_deepslate": (67, "Deepslate stairs use cobblestone stairs"),
    "deepslate_brick": (109, "Deepslate brick stairs use stone brick stairs"),
    "deepslate_tile": (109, "Deepslate tile stairs use stone brick stairs"),
    "polished_deepslate": (67, "Polished deepslate stairs use cobblestone stairs"),
    "polished_blackstone_brick": (109, "Blackstone brick stairs use stone brick stairs"),
    "mossy_cobblestone": (67, "Mossy texture unavailable on legacy cobblestone stairs"),
    "mossy_stone_brick": (109, "Mossy texture unavailable on legacy stone brick stairs"),
    "diorite": (156, "Diorite stairs use light quartz stairs"),
    "granite": (108, "Granite stairs use reddish brick stairs"),
    "smooth_sandstone": (128, "Smooth texture unavailable on legacy sandstone stairs"),
    "smooth_quartz": (156, "Smooth texture unavailable on legacy quartz stairs"),
    "purpur": (156, "Purpur stairs use pale quartz stairs; purple stair material unavailable"),
    "mud_brick": (108, "Mud brick stairs use brick stairs"),
    "waxed_cut_copper": (108, "Unoxidized copper stairs use orange-red brick stairs"),
    "waxed_oxidized_cut_copper": (109, "Oxidized copper stairs use stone brick stairs; cyan stair material unavailable"),
}
SLABS = {
    "stone": (44, 43, 0, "Modern natural stone slab uses legacy smooth stone slab"),
    "smooth_stone": (44, 43, 0, None), "sandstone": (44, 43, 1, None),
    "cobblestone": (44, 43, 3, None), "brick": (44, 43, 4, None),
    "stone_brick": (44, 43, 5, None), "quartz": (44, 43, 6, None),
    "nether_brick": (44, 43, 7, None), "red_sandstone": (182, 181, 0, None),
    "blackstone": (44, 43, 3, "Blackstone slab uses cobblestone slab"),
    "cobbled_deepslate": (44, 43, 3, "Cobbled deepslate slab uses cobblestone slab"),
    "deepslate_brick": (44, 43, 5, "Deepslate brick slab uses stone brick slab"),
    "deepslate_tile": (44, 43, 5, "Deepslate tile slab uses stone brick slab"),
    "polished_deepslate": (44, 43, 0, "Polished deepslate slab uses smooth stone slab"),
    "diorite": (44, 43, 6, "Diorite slab uses pale quartz slab"),
    "mossy_cobblestone": (44, 43, 3, "Mossy texture unavailable on legacy cobblestone slab"),
    "mud_brick": (44, 43, 4, "Mud brick slab uses brick slab"),
    "polished_tuff": (44, 43, 0, "Polished tuff slab uses smooth stone slab"),
    "smooth_quartz": (44, 43, 6, "Smooth quartz slab uses quartz slab"),
    "smooth_sandstone": (44, 43, 1, "Smooth sandstone slab uses sandstone slab"),
    "purpur": (44, 43, 6, "Purpur slab uses pale quartz slab; purple slab material unavailable"),
    "waxed_cut_copper": (44, 43, 4, "Unoxidized copper slab uses orange-red brick slab"),
    "waxed_oxidized_cut_copper": (44, 43, 5, "Oxidized copper slab uses stone brick slab; cyan slab material unavailable"),
}
WALLS = {
    "cobblestone": (0, None), "mossy_cobblestone": (1, None),
    "blackstone": (0, "Blackstone wall uses cobblestone wall"),
    "brick": (0, "Brick wall uses cobblestone wall; legacy has only cobblestone wall materials"),
    "cobbled_deepslate": (0, "Deepslate wall uses cobblestone wall"),
    "deepslate_brick": (0, "Deepslate brick wall uses cobblestone wall"),
    "deepslate_tile": (0, "Deepslate tile wall uses cobblestone wall"),
    "polished_deepslate": (0, "Polished deepslate wall uses cobblestone wall"),
    "diorite": (0, "Diorite wall uses cobblestone wall"),
    "granite": (0, "Granite wall uses cobblestone wall"),
    "mud_brick": (0, "Mud brick wall uses cobblestone wall"),
    "sandstone": (0, "Sandstone wall uses cobblestone wall"),
    "stone_brick": (0, "Stone brick wall uses cobblestone wall"),
}


@dataclass(frozen=True)
class Result:
    block_id: int
    meta: int
    notes: tuple[str, ...] = ()


class Properties:
    def __init__(self, name, props):
        self.name = name
        self.props = dict(props)
        self.used = set()
        self.notes = set()

    def get(self, key, default, allowed):
        self.used.add(key)
        value = self.props.get(key, default)
        if value not in allowed:
            raise ConversionError(f"Unsupported {self.name}.{key}={value!r}; expected {allowed}")
        return value

    def boolean(self, key, default="false"):
        return self.get(key, default, ("false", "true")) == "true"

    def integer(self, key, default, low, high):
        raw = self.get(key, str(default), tuple(str(i) for i in range(low, high + 1)))
        return int(raw)

    def ignore(self, key, default, allowed, reason):
        self.get(key, default, allowed)
        if key in self.props:
            self.notes.add(reason)

    def finish(self, block_id, meta=0, note=None):
        unknown = set(self.props) - self.used
        if unknown:
            raise ConversionError(f"Unsupported properties on {self.name}: {sorted(unknown)}")
        if not (0 <= block_id <= 255 and 0 <= meta <= 15):
            raise ConversionError(f"Invalid legacy state {block_id}:{meta} from {self.name}")
        if note:
            self.notes.add(note)
        return Result(block_id, meta, tuple(sorted(self.notes)))


def rotate_direction(direction: str, turns: int) -> str:
    return HORIZONTAL[(HORIZONTAL.index(direction) + turns) % 4] if direction in HORIZONTAL else direction


def rotate_properties(props: dict, turns: int) -> dict:
    """Rotate modern states clockwise; never rotate packed legacy metadata."""
    result = {}
    for key, value in props.items():
        key = rotate_direction(key, turns)
        if key in ("facing", "vertical_direction"):
            value = rotate_direction(value, turns)
        elif key == "orientation":
            value = "_".join(rotate_direction(d, turns) for d in value.split("_"))
        elif key == "axis" and turns % 2:
            value = {"x": "z", "z": "x"}.get(value, value)
        elif key == "rotation":
            value = str((int(value) + 4 * turns) % 16)
        elif key == "shape":
            if value in ("north_south", "east_west"):
                if turns % 2:
                    value = {"north_south": "east_west", "east_west": "north_south"}[value]
            elif value.startswith("ascending_"):
                value = "ascending_" + rotate_direction(value[10:], turns)
            elif value in ("south_east", "south_west", "north_west", "north_east"):
                rotated = {rotate_direction(d, turns) for d in value.split("_")}
                value = next(s for s in ("south_east", "south_west", "north_west", "north_east") if set(s.split("_")) == rotated)
        result[key] = value
    return result


def parse_state(text: str) -> dict:
    """Parse a final_state string. Malformed source is explicitly handled by caller."""
    match = re.fullmatch(r"([a-z0-9_.-]+:[a-z0-9_./-]+)(?:\[([^\[\]]*)\])?", text)
    if not match:
        raise ConversionError(f"Malformed jigsaw final_state: {text!r}")
    props = {}
    if match.group(2):
        for item in match.group(2).split(","):
            pair = item.split("=")
            if len(pair) != 2 or not all(pair) or pair[0] in props:
                raise ConversionError(f"Malformed jigsaw final_state: {text!r}")
            props[pair[0]] = pair[1]
    return {"Name": match.group(1), "Properties": props}


def _connections(p):
    for side in HORIZONTAL:
        p.ignore(side, "false", ("false", "true"), "Connections recomputed by legacy neighboring blocks")


def map_state(state: dict) -> Result:
    raw_name = state.get("Name", "")
    if not raw_name.startswith("minecraft:"):
        raise ConversionError(f"Unsupported block namespace: {raw_name!r}")
    name = raw_name[10:]
    p = Properties(name, state.get("Properties", {}))
    if "waterlogged" in p.props and p.boolean("waterlogged"):
        p.notes.add("Waterlogging unavailable; solid block retained without embedded water")
    if name in SIMPLE:
        block_id, meta, note = SIMPLE[name]
        return p.finish(block_id, meta, note)
    if name in ("structure_void", "structure_block", "jigsaw"):
        if name == "structure_block":
            p.get("mode", "data", ("data", "save", "load", "corner"))
        elif name == "jigsaw":
            p.get("orientation", "north_up", tuple(a + "_" + b for a in DIRECTIONS for b in DIRECTIONS if a != b))
        return p.finish(0, 0, "Special structure control block; importer handles skip/marker/final_state per position")
    if name in ("grass_block", "podzol"):
        p.ignore("snowy", "false", ("false", "true"), "Snowy top appearance recomputed from neighboring snow")
        return p.finish(2 if name == "grass_block" else 243)
    if name in ("water", "lava"):
        level = p.integer("level", 0, 0, 15)
        return p.finish((9 if name == "water" else 11) if level == 0 else (8 if name == "water" else 10), level)
    if name in ("short_grass", "fern"):
        return p.finish(31, 1 if name == "short_grass" else 2)
    if name in ("tall_grass", "large_fern"):
        upper = p.get("half", "lower", ("lower", "upper")) == "upper"
        return p.finish(175, (8 if upper else 0) | (2 if name == "tall_grass" else 3))
    if name in ("deepslate", "basalt", "polished_basalt", "bone_block", "purpur_pillar", "muddy_mangrove_roots"):
        p.ignore("axis", "y", ("x", "y", "z"), "Axis texture unavailable on replacement full block")
        values = {"deepslate": (1, 0), "basalt": (1, 0), "polished_basalt": (1, 0),
                  "bone_block": (155, 0), "purpur_pillar": (159, 10), "muddy_mangrove_roots": (159, 12)}
        return p.finish(*values[name], f"{name} replaced by legacy material/color full block")
    if name == "mangrove_roots":
        return p.finish(85, 0, "Mangrove roots replaced by oak fence lattice")
    wood_name = name.removeprefix("stripped_")
    for suffix in ("_log", "_wood"):
        if wood_name.endswith(suffix):
            wood = wood_name[:-len(suffix)]
            if wood not in WOODS and wood != "mangrove":
                break
            variant = WOODS.get(wood, 3)
            axis = AXIS_META[p.get("axis", "y", tuple(AXIS_META))]
            if suffix == "_wood":
                axis = 12
                p.notes.add("All-bark wood uses legacy all-bark log variant; axis texture has no distinct form")
            if name.startswith("stripped_"):
                p.notes.add("Stripped bark texture unavailable; original wood species retained")
            if wood == "mangrove":
                p.notes.add("Mangrove wood replaced by red-brown jungle logs")
            return p.finish(17 if variant < 4 else 162, (variant if variant < 4 else variant - 4) | axis)
    if name.endswith("_leaves"):
        wood = name[:-7]
        if wood not in WOODS and wood != "mangrove":
            raise ConversionError(f"Unsupported leaves material: {name}")
        variant = WOODS.get(wood, 0)
        persistent = p.boolean("persistent")
        p.ignore("distance", "7", tuple(str(i) for i in range(1, 8)), "Leaf distance recalculated by legacy leaf decay")
        return p.finish(18 if variant < 4 else 161, (variant if variant < 4 else variant - 4) | (4 if persistent else 0),
                        "Mangrove leaves use oak leaves" if wood == "mangrove" else None)
    if name.endswith("_sapling") and name[:-8] in WOODS:
        return p.finish(6, WOODS[name[:-8]] | (8 if p.get("stage", "0", ("0", "1")) == "1" else 0))
    if name.endswith("_planks") and name[:-7] in WOODS:
        return p.finish(5, WOODS[name[:-7]])
    if name.endswith("_stairs"):
        material = name[:-7]
        if material in WOOD_STAIRS:
            block_id, note = WOOD_STAIRS[material], None
        elif material in STAIRS:
            block_id, note = STAIRS[material]
        else:
            raise ConversionError(f"Unsupported stair material: {name}")
        facing = p.get("facing", "north", HORIZONTAL)
        upper = p.get("half", "bottom", ("bottom", "top")) == "top"
        shape = p.get("shape", "straight", ("straight", "inner_left", "inner_right", "outer_left", "outer_right"))
        if shape != "straight":
            p.notes.add("Stair corner shape derived by client neighbors; legacy metadata preserves facing and half")
        return p.finish(block_id, STAIR_META[facing] | (4 if upper else 0), note)
    if name.endswith("_slab"):
        material = name[:-5]
        if material in WOODS:
            lower_id, double_id, meta, note = 158, 157, WOODS[material], None
        elif material in SLABS:
            lower_id, double_id, meta, note = SLABS[material]
        else:
            raise ConversionError(f"Unsupported slab material: {name}")
        slab_type = p.get("type", "bottom", ("bottom", "top", "double"))
        return p.finish(double_id if slab_type == "double" else lower_id, meta | (8 if slab_type == "top" else 0), note)
    if name.endswith("_fence"):
        material = name[:-6]
        _connections(p)
        if material == "nether_brick":
            return p.finish(113)
        if material in WOODS:
            return p.finish(85, WOODS[material])
        raise ConversionError(f"Unsupported fence material: {name}")
    if name.endswith("_fence_gate"):
        material = name[:-11]
        if material not in WOOD_GATES:
            raise ConversionError(f"Unsupported gate material: {name}")
        facing = p.get("facing", "north", HORIZONTAL)
        opened = p.boolean("open")
        p.ignore("powered", "false", ("false", "true"), "Gate powered flag has no independent legacy metadata")
        if p.boolean("in_wall"):
            p.notes.add("Gate in-wall height adjustment unavailable in legacy metadata")
        p.notes.add("Gate metadata follows legacy placement mapping; north/east/south/west = 1/2/3/0")
        return p.finish(WOOD_GATES[material], GATE_META[facing] | (4 if opened else 0))
    if name.endswith("_trapdoor"):
        material = name[:-9]
        copper = material in ("oxidized_copper", "waxed_oxidized_copper", "waxed_copper")
        if material not in WOODS and material != "iron" and not copper:
            raise ConversionError(f"Unsupported trapdoor material: {name}")
        meta = TRAPDOOR_META[p.get("facing", "north", HORIZONTAL)]
        if p.get("half", "bottom", ("bottom", "top")) == "top": meta |= 4
        if p.boolean("open"): meta |= 8
        p.ignore("powered", "false", ("false", "true"), "Trapdoor powered flag has no independent legacy metadata")
        note = "Copper trapdoor replaced by iron trapdoor" if copper else ("Wood trapdoor species collapsed to legacy oak trapdoor" if material not in ("oak", "iron") else None)
        p.notes.add("MCPE trapdoor metadata used: direction bits0-1, top bit2, open bit3; PHP bounding-box code has historical bit inconsistency")
        return p.finish(167 if material == "iron" or copper else 96, meta, note)
    if name.endswith("_door"):
        material = name[:-5]
        copper = material in ("waxed_copper", "waxed_oxidized_copper")
        if material not in WOOD_DOORS and material != "iron" and not copper:
            raise ConversionError(f"Unsupported door material: {name}")
        facing = p.get("facing", "north", HORIZONTAL)
        upper = p.get("half", "lower", ("lower", "upper")) == "upper"
        right = p.get("hinge", "left", ("left", "right")) == "right"
        opened, powered = p.boolean("open"), p.boolean("powered")
        meta = (8 | int(right) | (2 if powered else 0)) if upper else (DOOR_META[facing] | (4 if opened else 0))
        return p.finish(71 if material == "iron" or copper else WOOD_DOORS[material], meta,
                        "Copper door replaced by iron door" if copper else None)
    if name.endswith("_wall") and name[:-5] in WALLS:
        meta, note = WALLS[name[:-5]]
        for side in HORIZONTAL:
            p.ignore(side, "none", ("none", "low", "tall"), "Wall connections/height recomputed by legacy neighbor shapes")
        p.ignore("up", "true", ("false", "true"), "Wall central post shape recomputed by legacy neighbors")
        return p.finish(139, meta, note)
    if name in ("iron_bars", "glass_pane"):
        _connections(p)
        return p.finish(101 if name == "iron_bars" else 102)
    for suffix in ("_wool", "_carpet", "_terracotta", "_glazed_terracotta", "_concrete", "_stained_glass", "_stained_glass_pane", "_bed", "_wall_banner", "_banner", "_candle"):
        if not name.endswith(suffix): continue
        color = name[:-len(suffix)]
        if color not in COLORS: continue
        value = COLORS[color]
        if suffix in ("_wool", "_carpet", "_terracotta"):
            return p.finish({"_wool": 35, "_carpet": 171, "_terracotta": 159}[suffix], value)
        if suffix == "_glazed_terracotta":
            p.ignore("facing", "north", HORIZONTAL, "Glazed pattern and its orientation unavailable on plain terracotta")
            return p.finish(159, value, "Glazed terracotta replaced by same-color plain terracotta")
        if suffix == "_concrete":
            return p.finish(159, value, "Concrete replaced by same-color terracotta")
        if suffix in ("_stained_glass", "_stained_glass_pane"):
            if suffix.endswith("pane"): _connections(p)
            return p.finish(102 if suffix.endswith("pane") else 20, 0, "Colored glass unavailable; transparency preserved with clear legacy glass")
        if suffix == "_bed":
            facing = p.get("facing", "north", HORIZONTAL)
            head = p.get("part", "foot", ("foot", "head")) == "head"
            occupied = p.boolean("occupied")
            return p.finish(26, FOUR_META[facing] | (8 if head else 0) | (4 if occupied else 0), "Legacy bed supports only red color" if color != "red" else None)
        if suffix in ("_wall_banner", "_banner"):
            if suffix == "_wall_banner":
                p.ignore("facing", "north", HORIZONTAL, "Banner attachment/orientation unavailable on wool replacement")
            else:
                p.ignore("rotation", "0", tuple(str(i) for i in range(16)), "Banner rotation unavailable on wool replacement")
            return p.finish(35, value, "Banner replaced by same-color wool; original patterns retained in tile data")
        # Colored candle handled by same explicit candle branch below.
        break
    if name.startswith("potted_"):
        plant = name[7:]
        supported = {"allium", "azure_bluet", "birch_sapling", "blue_orchid", "cactus", "dandelion", "dead_bush", "oxeye_daisy", "poppy", "red_tulip", "spruce_sapling", "white_tulip"}
        if plant not in supported: raise ConversionError(f"Unsupported potted plant: {name}")
        return p.finish(140, 0, "Potted plant uses legacy flower pot; importer retains plant identity in synthetic tile data")
    if name in ("chest", "trapped_chest", "ender_chest", "barrel"):
        facing = p.get("facing", "north", DIRECTIONS)
        if name in ("chest", "trapped_chest"):
            p.ignore("type", "single", ("single", "left", "right"), "Double chest pairing derived by legacy adjacent chests")
        if name == "barrel": p.ignore("open", "false", ("false", "true"), "Barrel opening animation unavailable on chest replacement")
        note = None
        if name in ("barrel", "ender_chest"):
            note = f"{name} replaced by chest; original tile data retained"
        if facing in ("up", "down"):
            p.notes.add("Vertical barrel facing unavailable on legacy chest; using north")
            facing = "north"
        meta = CHEST_META.get(facing)
        if meta is None:
            p.notes.add("Vertical container facing unavailable on legacy chest; using south")
            meta = CHEST_META["south"]
        return p.finish(146 if name == "trapped_chest" else 54, meta, note)
    if name in ("furnace", "blast_furnace", "smoker"):
        lit = p.boolean("lit")
        facing = p.get("facing", "north", HORIZONTAL)
        return p.finish(62 if lit else 61, FACE_META[facing], None if name == "furnace" else f"{name} replaced by ordinary furnace")
    if name in ("dispenser", "dropper", "hopper"):
        facing = p.get("facing", "down" if name == "hopper" else "north", DIRECTIONS)
        if name == "hopper":
            disabled = not p.boolean("enabled", "true")
            return p.finish(154, FACE_META[facing] | (8 if disabled else 0))
        triggered = p.boolean("triggered")
        return p.finish(23 if name == "dispenser" else 125, DISPENSER_META[facing] | (8 if triggered else 0))
    if name in ("oak_wall_sign", "spruce_wall_sign"):
        return p.finish(68, FACE_META[p.get("facing", "north", HORIZONTAL)], "Sign wood species collapsed to legacy oak sign" if name.startswith("spruce") else None)
    if name == "ladder":
        return p.finish(65, FACE_META[p.get("facing", "north", HORIZONTAL)])
    if name in ("torch", "wall_torch", "redstone_torch", "redstone_wall_torch"):
        facing = p.get("facing", "up", HORIZONTAL + ("up",)) if "wall" in name else "up"
        block_id = 50
        if name.startswith("redstone"):
            block_id = 76 if p.boolean("lit", "true") else 75
        return p.finish(block_id, TORCH_META[facing])
    if name in ("rail", "powered_rail", "detector_rail", "activator_rail"):
        shape = p.get("shape", "north_south", tuple(RAIL_META))
        powered = p.boolean("powered") if name != "rail" else False
        if name != "rail" and RAIL_META[shape] > 5: raise ConversionError(f"Curved powered rail {name}")
        return p.finish({"rail": 66, "powered_rail": 27, "detector_rail": 28, "activator_rail": 126}[name], RAIL_META[shape] | (8 if powered else 0))
    if name in ("wheat", "carrots", "potatoes", "beetroots", "nether_wart", "cactus", "sugar_cane", "pumpkin_stem", "melon_stem"):
        block_id = {"wheat": 59, "carrots": 141, "potatoes": 142, "beetroots": 244, "nether_wart": 115, "cactus": 81, "sugar_cane": 83, "pumpkin_stem": 104, "melon_stem": 105}[name]
        high = 15 if name in ("cactus", "sugar_cane") else 3 if name in ("nether_wart", "beetroots") else 7
        return p.finish(block_id, p.integer("age", 0, 0, high))
    if name in ("attached_pumpkin_stem", "attached_melon_stem"):
        p.ignore("facing", "north", HORIZONTAL, "Attached stem facing recomputed by legacy adjacent fruit")
        return p.finish(104 if name == "attached_pumpkin_stem" else 105, 7)
    if name == "farmland": return p.finish(60, p.integer("moisture", 0, 0, 7))
    if name == "snow": return p.finish(78, p.integer("layers", 1, 1, 8) - 1)
    if name == "hay_block": return p.finish(170, AXIS_META[p.get("axis", "y", tuple(AXIS_META))])
    if name in ("pumpkin", "carved_pumpkin", "jack_o_lantern"):
        facing = p.get("facing", "south", HORIZONTAL)
        return p.finish(91 if name == "jack_o_lantern" else 86, PUMPKIN_META[facing], "Uncarved pumpkin uses legacy carved pumpkin texture" if name == "pumpkin" else None)
    if name == "vine":
        meta = 0
        for side, bit in (("south", 1), ("west", 2), ("north", 4), ("east", 8)):
            if p.boolean(side): meta |= bit
        p.ignore("up", "false", ("false", "true"), "Vine ceiling attachment recalculated from neighbors")
        return p.finish(106, meta)
    if name in ("fire", "soul_fire"):
        age = p.integer("age", 0, 0, 15)
        for side in HORIZONTAL + ("up",):
            p.ignore(side, "false", ("false", "true"), "Fire attachments recomputed from legacy neighbors")
        return p.finish(51, age, "Soul fire uses ordinary fire" if name == "soul_fire" else None)
    if name in ("cauldron", "water_cauldron"):
        return p.finish(118, p.integer("level", 0, 0, 3) * 2 if name == "water_cauldron" else 0)
    if name == "brewing_stand":
        meta = sum((1 << i) if p.boolean(f"has_bottle_{i}") else 0 for i in range(3))
        return p.finish(117, meta)
    if name in ("spawner", "trial_spawner"):
        if name == "trial_spawner":
            p.ignore("ominous", "false", ("false", "true"), "Trial spawner mechanics unavailable on legacy monster spawner")
            p.ignore("trial_spawner_state", "inactive", ("inactive", "waiting_for_players", "active", "waiting_for_reward_ejection", "ejecting_reward", "cooldown"), "Trial spawner state retained only in original tile data")
        return p.finish(52, 0, "Trial spawner replaced by legacy monster spawner" if name == "trial_spawner" else None)
    if name == "vault":
        facing = p.get("facing", "north", HORIZONTAL)
        p.ignore("ominous", "false", ("false", "true"), "Vault mechanics unavailable on legacy chest")
        p.ignore("vault_state", "inactive", ("inactive", "active", "unlocking", "ejecting"), "Vault state retained only in original tile data")
        return p.finish(54, FACE_META[facing], "Vault replaced by chest")
    if name in ("cartography_table", "fletching_table", "smithing_table", "loom", "lectern", "composter", "grindstone", "stonecutter"):
        if name in ("loom", "lectern", "grindstone", "stonecutter"):
            p.ignore("facing", "north", HORIZONTAL, "Modern workstation orientation unavailable on legacy replacement")
        if name == "lectern":
            p.ignore("has_book", "false", ("false", "true"), "Lectern book availability retained in tile data")
            p.ignore("powered", "false", ("false", "true"), "Lectern redstone behavior unavailable")
        if name == "grindstone":
            p.ignore("face", "floor", ("floor", "wall", "ceiling"), "Grindstone attachment unavailable on legacy stonecutter")
        if name == "composter":
            p.ignore("level", "0", tuple(str(i) for i in range(9)), "Composter contents unavailable on legacy cauldron")
        value = {"cartography_table": 58, "fletching_table": 58, "smithing_table": 58, "loom": 58,
                 "lectern": 47, "composter": 118, "grindstone": 245, "stonecutter": 245}[name]
        return p.finish(value, 0, None if name == "stonecutter" else f"{name} replaced by legacy workstation of similar shape/material")
    if name in ("anvil", "chipped_anvil", "damaged_anvil"):
        facing = p.get("facing", "north", HORIZONTAL)
        damage = {"anvil": 0, "chipped_anvil": 1, "damaged_anvil": 2}[name]
        return p.finish(145, (1 if facing in ("east", "west") else 0) | damage << 2)
    if name == "redstone_wire":
        power = p.integer("power", 0, 0, 15)
        for side in HORIZONTAL:
            p.ignore(side, "none", ("none", "side", "up"), "Redstone connections recomputed by legacy neighbors")
        return p.finish(55, power)
    if name == "redstone_lamp": return p.finish(124 if p.boolean("lit") else 123)
    if name in ("repeater", "comparator"):
        facing = p.get("facing", "north", HORIZONTAL)
        powered = p.boolean("powered")
        # Repeater/comparator face metadata follows north=0/east=1/south=2/west=3.
        meta = HORIZONTAL.index(facing)
        if name == "repeater":
            meta |= (p.integer("delay", 1, 1, 4) - 1) << 2
            p.ignore("locked", "false", ("false", "true"), "Repeater locking recalculated by neighboring legacy redstone")
        else:
            p.ignore("mode", "compare", ("compare", "subtract"), "Comparator arithmetic unavailable on repeater replacement")
        return p.finish(94 if powered else 93, meta, "Comparator replaced by legacy repeater; arithmetic unavailable" if name == "comparator" else None)
    if name == "lever" or name.endswith("_button"):
        if name != "lever" and name not in ("stone_button", "oak_button", "jungle_button"):
            raise ConversionError(f"Unsupported button: {name}")
        face = p.get("face", "wall", ("wall", "floor", "ceiling"))
        facing = p.get("facing", "north", HORIZONTAL)
        powered = p.boolean("powered")
        if face == "wall": meta = TORCH_META[facing]
        elif face == "floor": meta = (5 if facing in ("north", "south") else 6) if name == "lever" else 5
        else: meta = (7 if facing in ("north", "south") else 0) if name == "lever" else 0
        return p.finish(69 if name == "lever" else 77 if name == "stone_button" else 143, meta | (8 if powered else 0),
                        "Wood button species collapsed to legacy oak button" if name == "jungle_button" else None)
    if name.endswith("_pressure_plate"):
        if name not in ("stone_pressure_plate", "oak_pressure_plate", "spruce_pressure_plate", "acacia_pressure_plate", "light_weighted_pressure_plate", "heavy_weighted_pressure_plate"):
            raise ConversionError(f"Unsupported pressure plate: {name}")
        if name.startswith(("light_", "heavy_")):
            return p.finish(147 if name.startswith("light_") else 148, p.integer("power", 0, 0, 15))
        return p.finish(70 if name == "stone_pressure_plate" else 72, int(p.boolean("powered")),
                        "Wood pressure plate species collapsed to legacy oak plate" if name in ("spruce_pressure_plate", "acacia_pressure_plate") else None)
    if name in ("tripwire", "tripwire_hook"):
        powered, attached = p.boolean("powered"), p.boolean("attached")
        if name == "tripwire_hook":
            return p.finish(131, CHEST_META[p.get("facing", "south", HORIZONTAL)] & 3 | (4 if attached else 0) | (8 if powered else 0))
        disarmed = p.boolean("disarmed")
        _connections(p)
        return p.finish(132, int(powered) | (4 if attached else 0) | (8 if disarmed else 0))
    if name == "tnt":
        p.ignore("unstable", "false", ("false", "true"), "TNT instability not represented in legacy metadata")
        return p.finish(46)
    if name == "note_block":
        p.ignore("instrument", "harp", ("harp", "chime", "basedrum", "snare", "hat", "bass", "flute", "bell", "guitar", "xylophone", "iron_xylophone", "cow_bell", "didgeridoo", "bit", "banjo", "pling"), "Note block instrument derived by legacy supporting material")
        p.ignore("note", "0", tuple(str(i) for i in range(25)), "Note retained in synthetic legacy tile data")
        p.ignore("powered", "false", ("false", "true"), "Note block powered flag recomputed by legacy redstone")
        return p.finish(25)
    if name in ("skeleton_skull", "dragon_wall_head"):
        p.ignore("powered", "false", ("false", "true"), "Skull powered animation unavailable")
        if name == "skeleton_skull":
            p.ignore("rotation", "0", tuple(str(i) for i in range(16)), "Skull rotation retained as synthetic Rot tile property")
            return p.finish(144, 1)
        return p.finish(144, FACE_META[p.get("facing", "north", HORIZONTAL)], "Dragon head replaced by legacy skull; original head kind retained in tile data")
    if name in ("lantern", "soul_lantern", "end_rod", "candle", "white_candle", "red_candle", "sea_pickle", "campfire", "bell", "iron_chain", "pointed_dripstone", "sulfur_spike", "potent_sulfur", "decorated_pot", "target", "sculk_sensor", "sticky_piston", "piston_head"):
        if name in ("lantern", "soul_lantern"):
            p.ignore("hanging", "false", ("false", "true"), "Lantern hanging shape unavailable on torch replacement")
            return p.finish(50, 5, f"{name} replaced by standing torch to retain lighting")
        if name == "end_rod":
            facing = p.get("facing", "up", DIRECTIONS)
            return p.finish(50, TORCH_META.get(facing, 5), "End rod replaced by torch to retain light; downward rod uses upright torch")
        if name in ("candle", "white_candle", "red_candle", "sea_pickle"):
            count_key = "pickles" if name == "sea_pickle" else "candles"
            p.ignore(count_key, "1", ("1", "2", "3", "4"), "Cluster count unavailable on single legacy torch")
            lit = p.boolean("lit") if name != "sea_pickle" else False
            return p.finish(50 if lit else 75, 0, f"{name} replaced by {'lit' if lit else 'unlit redstone'} torch; small upright shape preserved")
        if name == "campfire":
            lit = p.boolean("lit", "true")
            p.ignore("facing", "north", HORIZONTAL, "Campfire facing unavailable on replacement")
            p.ignore("signal_fire", "false", ("false", "true"), "Campfire smoke behavior unavailable")
            return p.finish(51 if lit else 158, 0, "Lit campfire replaced by fire; unlit campfire by bottom oak slab")
        if name == "bell":
            p.ignore("attachment", "floor", ("floor", "ceiling", "single_wall", "double_wall"), "Bell attachment unavailable on gold block replacement")
            p.ignore("facing", "north", HORIZONTAL, "Bell orientation unavailable on gold block replacement")
            p.ignore("powered", "false", ("false", "true"), "Bell ringing behavior unavailable")
            return p.finish(41, 0, "Bell replaced by gold block")
        if name == "iron_chain":
            p.ignore("axis", "y", ("x", "y", "z"), "Chain axis unavailable on iron bars replacement")
            return p.finish(101, 0, "Iron chain replaced by iron bars")
        if name in ("pointed_dripstone", "sulfur_spike"):
            p.ignore("thickness", "tip", ("tip_merge", "tip", "frustum", "middle", "base"), "Pointed spike profile unavailable on legacy fence")
            p.ignore("vertical_direction", "up", ("up", "down"), "Spike vertical direction unavailable on legacy fence")
            return p.finish(85, 0, f"{name} replaced by narrow oak fence post")
        if name == "potent_sulfur":
            p.ignore("potent_sulfur_state", "dormant", ("dormant",), "26.2 sulfur state retained as source property only")
            return p.finish(159, 4, "26.2 potent sulfur replaced by yellow terracotta")
        if name == "decorated_pot":
            p.ignore("facing", "north", HORIZONTAL, "Decorated pot orientation/pattern retained only in source tile data")
            p.ignore("cracked", "false", ("false", "true"), "Decorated pot cracked state unavailable")
            return p.finish(140, 0, "Decorated pot replaced by small legacy flower pot")
        if name == "target":
            p.ignore("power", "0", tuple(str(i) for i in range(16)), "Target redstone function unavailable")
            return p.finish(170, 0, "Target replaced by hay bale for similar straw material")
        if name == "sculk_sensor":
            p.ignore("sculk_sensor_phase", "inactive", ("inactive", "active", "cooldown"), "Sculk vibration sensing unavailable")
            p.ignore("power", "0", tuple(str(i) for i in range(16)), "Sculk power state unavailable")
            return p.finish(159, 9, "Sculk sensor replaced by dark cyan terracotta; source tile data retained")
        if name in ("sticky_piston", "piston_head"):
            p.ignore("facing", "north", DIRECTIONS, "Piston orientation/motion unavailable because no piston is registered")
            if name == "sticky_piston": p.ignore("extended", "false", ("false", "true"), "Piston extension unavailable")
            else:
                p.ignore("short", "false", ("false", "true"), "Piston arm shape unavailable")
                p.ignore("type", "normal", ("normal", "sticky"), "Piston sticky mechanics unavailable")
            return p.finish(98 if name == "sticky_piston" else 5, 0, "Unregistered piston body uses stone bricks; piston head uses oak planks")
    copper_colors = {
        "copper_block": 1, "waxed_copper_block": 1, "waxed_chiseled_copper": 1,
        "waxed_cut_copper": 1, "oxidized_cut_copper": 9, "waxed_oxidized_copper": 9,
        "waxed_oxidized_cut_copper": 9, "waxed_oxidized_chiseled_copper": 9,
    }
    if name in copper_colors:
        return p.finish(159, copper_colors[name], "Copper replaced by orange/cyan terracotta matching oxidation color")
    if name in ("waxed_copper_grate", "waxed_oxidized_copper_grate"):
        return p.finish(101, 0, "Copper grate replaced by transparent iron bars; metal color unavailable")
    if name == "waxed_copper_bulb":
        lit = p.boolean("lit")
        p.ignore("powered", "false", ("false", "true"), "Copper bulb latch behavior unavailable on redstone lamp")
        return p.finish(124 if lit else 123, 0, "Copper bulb replaced by legacy redstone lamp preserving lit state")
    raise ConversionError(f"Unmapped modern block: {raw_name}")


def load_registry(path):
    """Resolve registered IDs from source text rather than assuming Java IDs."""
    text = path.read_text(encoding="utf-8")
    constants = {name: int(value) for name, value in re.findall(r"\bconst\s+(\w+)\s*=\s*(\d+)\s*;", text)}
    classes = re.findall(r"self::\$list\[self::(\w+)\]\s*=\s*(\w+)::class", text)
    registered = {}
    for name, cls in classes:
        if name not in constants: raise ConversionError(f"Unknown registry constant: {name}")
        block_id = constants[name]
        if not 0 <= block_id <= 255: raise ConversionError(f"Registered ID outside byte range: {name}")
        registered[block_id] = {"constant": name, "class": cls}
    if registered.get(158, {}).get("class") != "WoodSlab":
        raise ConversionError("Registry must use MCPE wooden slab ID158")
    return registered
