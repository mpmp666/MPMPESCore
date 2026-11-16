# Enchantments aligned with Minecraft 26.2

The existing legacy numeric enchantment IDs **0–24 remain unchanged**. Modern names select those existing IDs. Enchantments introduced after this client's known catalog are not assigned speculative wire IDs.

Reference: the supplied `MCP-Reborn-26.2.zip`, Java sources `EnchantmentHelper`, `Enchantments`, `EnchantmentMenu`, `AnvilMenu`, `Items`, `ToolMaterial`, and `ArmorMaterials`; the embedded `projects/mcp/build/mcp/downloadClient/client.jar` contains the authoritative `data/minecraft/enchantment/*.json`, enchantable item tags, and enchantment exclusive-set tags. The embedded client JAR SHA-256 observed during extraction was `40896ee9f1e2bec3c934daac7e93d41e9e3d9c2f8ae0ca366d52ffbfd1afa290`.

## Legacy catalog

The ranges below are inclusive. `L` is the acquired enchantment level, starting at 1. Table selection chooses the highest level whose range contains the modified enchantability. The final column is the base anvil multiplier; a sacrificed enchanted book halves it, with a minimum of 1.

| ID | Modern name | Max | Weight | Min cost | Max cost | Anvil |
|---:|---|---:|---:|---|---|---:|
| 0 | protection | 4 | 10 | 1+11(L−1) | 12+11(L−1) | 1 |
| 1 | fire_protection | 4 | 5 | 10+8(L−1) | 18+8(L−1) | 2 |
| 2 | feather_falling | 4 | 5 | 5+6(L−1) | 11+6(L−1) | 2 |
| 3 | blast_protection | 4 | 2 | 5+8(L−1) | 13+8(L−1) | 4 |
| 4 | projectile_protection | 4 | 5 | 3+6(L−1) | 9+6(L−1) | 2 |
| 5 | thorns | 3 | 1 | 10+20(L−1) | 60+20(L−1) | 8 |
| 6 | respiration | 3 | 2 | 10+10(L−1) | 40+10(L−1) | 4 |
| 7 | depth_strider | 3 | 2 | 10+10(L−1) | 25+10(L−1) | 4 |
| 8 | aqua_affinity | 1 | 2 | 1 | 41 | 4 |
| 9 | sharpness | 5 | 10 | 1+11(L−1) | 21+11(L−1) | 1 |
| 10 | smite | 5 | 5 | 5+8(L−1) | 25+8(L−1) | 2 |
| 11 | bane_of_arthropods | 5 | 5 | 5+8(L−1) | 25+8(L−1) | 2 |
| 12 | knockback | 2 | 5 | 5+20(L−1) | 55+20(L−1) | 2 |
| 13 | fire_aspect | 2 | 2 | 10+20(L−1) | 60+20(L−1) | 4 |
| 14 | looting | 3 | 2 | 15+9(L−1) | 65+9(L−1) | 4 |
| 15 | efficiency | 5 | 10 | 1+10(L−1) | 51+10(L−1) | 1 |
| 16 | silk_touch | 1 | 1 | 15 | 65 | 8 |
| 17 | unbreaking | 3 | 5 | 5+8(L−1) | 55+8(L−1) | 2 |
| 18 | fortune | 3 | 2 | 15+9(L−1) | 65+9(L−1) | 4 |
| 19 | power | 5 | 10 | 1+10(L−1) | 16+10(L−1) | 1 |
| 20 | punch | 2 | 2 | 12+20(L−1) | 37+20(L−1) | 4 |
| 21 | flame | 1 | 2 | 20 | 50 | 4 |
| 22 | infinity | 1 | 1 | 20 | 50 | 8 |
| 23 | luck_of_the_sea | 3 | 2 | 15+9(L−1) | 65+9(L−1) | 4 |
| 24 | lure | 3 | 2 | 15+9(L−1) | 65+9(L−1) | 4 |

Thorns supports armor, and only chestplates receive it directly from the table. Respiration and Aqua Affinity require helmets; Feather Falling and Depth Strider require boots. Sharpness, Smite, and Bane of Arthropods support swords and axes, but their table primary tag is swords. Knockback, Fire Aspect, and Looting support swords among the available legacy items. Efficiency supports axes, pickaxes, shovels, hoes, and shears. Silk Touch and Fortune exclude shears. Shears and flint-and-steel lack the 26.2 ENCHANTABLE component, so eligible books can be applied through an anvil or command instead of the table. Unbreaking supports the available durability-bearing item families, including bows and fishing rods. Books can store any of these 25 enchants and become enchanted books when an enchantment is applied.

The exclusive groups in this catalog are Protection/Fire Protection/Blast Protection/Projectile Protection, Sharpness/Smite/Bane of Arthropods, and Silk Touch/Fortune. Feather Falling can coexist with the protection group. Distinct compatible sword enchantments can coexist. Duplicate IDs are not independent compatible enchants.

## Public APIs

Existing `getEnchantment`, `getEffectByName`, `getEnchantMaxLevel`, `getEnchantWeight`, `getEnchantAbility`, `getPossibleEnchantments`, `getLevel`, `setLevel`, `getSlot`, and related entry/list APIs remain available. `setLevel()` intentionally remains unrestricted for existing plugin/admin NBT use; table, command, and anvil acquisition enforce their normal limits.

- `Enchantment::fromString($name)` returns an Enchantment, including TYPE_INVALID on failure. It accepts a modern bare or `minecraft:` name, an exact numeric ID, or the legacy TYPE_ suffix. Malformed text is never cast to ID zero.
- `Enchantment::canEnchantItem(Item $item, $enchantment, bool $primary = false)` accepts an Enchantment or legacy numeric ID. The default checks supported item tags for commands/anvils; `true` checks primary table tags. It does not check the current item's other enchantments.
- `$enchantment->isCompatibleWith(Enchantment $other)` checks exclusive sets and rejects duplicate IDs.
- `Enchantment::getEnchantMinMaxAbility(int $id, int $level = 1)` returns `[min, max]`, or `[0, 0]` for an invalid ID/level.
- `EnchantmentLevelTable::selectEnchantments(Item $item, int $cost, ?JavaRandom $random = null)` and `generateOffers(Item $item, int $bookshelves, int $seed)` support deterministic tests. Results use the 25 available IDs, so book distributions intentionally differ from a complete modern catalog.

`/enchant <player> <id|name|minecraft:name> [level]` requires a decimal integer from 1 through that enchantment's max. It rejects air, unsupported items, malformed selectors/levels, and incompatible enchantments. Plain books are converted to enchanted books while retaining their existing NBT.

## Table and anvil protocol integration

The table's display requirement is separate from the resources consumed: options 0, 1, and 2 consume 1, 2, and 3 levels/lapis. Survival must satisfy both values. Creative bypasses XP/lapis payment. Table generation follows the Java bounded random draws, float32 modified-cost calculation, weights, compatible extra rolls, book removal step, primary item tags, and actual bookshelf geometry/air gaps. A player's seed persists across menu closes while connected and resets after a successful enchant. Slots carry a one-item input, a server snapshot, and exact NBT/result validation. Rejected or stale operations synchronize the authoritative container and experience.

`EnchantInventory::onEnchant(Player $who, Item $before, Item $requested)` returns a boolean. The Player slot-0 handler intercepts a newly enchanted result and ends the packet path without ordinary result transactions. Input/output removal afterward uses the normal item-conserving transaction path.

`AnvilInventory::calculateResult(Item $input, Item $addition, ?string $name = null, bool $creative = false)` is a pure calculation returning `['item', 'cost', 'consumed', 'rename_only']` or null. It repairs quarter durability per valid material, combines two same damageable items with the 12% bonus, merges equal levels by one with normal caps, rejects unsupported/conflicting books, charges per-level anvil costs, and preserves source names/NBT. The charge includes the sum of both RepairCost values; the result uses `max(old penalties) * 2 + 1`, giving 1, 3, 7, 15, 31, etc. A rename adds 1 and does not escalate prior work. Rename-only operations cap the charge at 39; other survival operations costing 40 or more are rejected. Names support up to 50 Unicode characters.

`onAnvil(Player $who, Item $requested)` derives only the requested name from the packet, calculates the result independently, checks exact item ID, damage, count, enchantments, and NBT (including RepairCost), then charges and consumes inputs before storing a paid result. It rejects direct writes to slot 2. `takeResult(Player $who)` delivers the paid item once. The Player handler intercepts **all** anvil slot-2 updates, calls onAnvil for non-air and takeResult for air, clears stale transactions, and exits that packet path. A paid result left on close returns as a drop. Destination writes honor inventory-change cancellation; a rejected transfer retains the paid result. Synchronous close callbacks during XP or slot changes are deferred until the operation commits, so the result is returned exactly once.

The client must submit the calculated item/enchantments and other NBT. A missing server-owned RepairCost is normalized from the server calculation for legacy-client compatibility; an explicitly incorrect RepairCost is rejected. The charged fee and paid output always use the server calculation. The standalone helper and isolated server tests prove the server calculation/mutation paths; the old-client rename/output packet sequence and UI presentation still require a real client playtest. This implementation does not emulate modern recipe/UI packets, modern-only item/enchantment IDs, or anvil block wear.

## Verification

Run `bin/php7/bin/php tools/test_enchanting26.php` from the repository root. The helper initializes real Block, Item, Enchantment, and inventory types; it uses only in-memory Player/Level doubles. It checks all 25 IDs, names, max levels, weights, costs, compatibility pairs and item eligibility, independent Java random cost/weighted-choice fixtures, generated offer legality, exact table payment/book conversion/creative/replay/forged output rejection, anvil repair/tax/prior-work/caps, Unicode names, canceled payment, output duplication resistance, and command parsing. No world or server files are modified.
