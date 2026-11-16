# Minecraft Java 26.2 End terrain reference

The standalone PHP sampler ports the supplied MCP source and server data for new End chunks. It preserves the legacy 48-bit random stream, exact octave construction order, the 17,292-draw island seed skip, float32 End island arithmetic, Java integer overflow, density slides, and 8×4×8 interpolation.

## Runtime API

All classes live in `pocketmine\level\generator\ender\noise` and use the normal project autoloader. PHP 8.4 on a 64-bit build is required; no PHP extension or external process is used at runtime.

```php
$terrain = new EndTerrainNoise($seed);
$grid = $terrain->chunkGrid($chunkX, $chunkZ);
$grid->forEachSolid(static function(int $localX, int $y, int $localZ) use ($chunk) : void{
    $chunk->setBlockId($localX, $y, $localZ, Block::END_STONE);
});
```

`chunkGrid()` computes 297 lattice nodes (3×33×3). The callback interpolates cheaply and emits only solid blocks, including disconnected island overhangs. Filling columns up to a single surface height would lose that geometry.

- `EndTerrainNoise::__construct(int $seed)` selects the world seed.
- `density(int $worldX, int $y, int $worldZ): float` returns the scaled density entering the interpolation marker, before interpolation and squeeze. Use this for lattice references, not direct per-block filling.
- `interpolatedDensity(int $worldX, int $y, int $worldZ): float` returns the final squeezed density at a block. Positive is solid, otherwise air.
- `surfaceHeight(int $worldX, int $worldZ): int` returns `WORLD_SURFACE_WG` / the first air above the highest solid base terrain block, or zero in the void. This is the End City structure callback. Highest occupied is this result minus one.
- `islandHeight(int $worldX, int $worldZ): float` returns the raw float32 island height, normally −100 through 80; `islandDensity()` returns `(height−8)/128`.
- `biome(int $worldX, int $worldZ): string` returns the unzoomed `TheEndBiomeSource` noise biome (`the_end`, `end_highlands`, `end_midlands`, `small_end_islands`, `end_barrens`). It includes the chunk-radius rule and chunk-center sampling.
- `blendedNoise(int $worldX, int $y, int $worldZ): float` exposes the unshifted legacy blended noise for reference tests.
- `EndChunkGrid::density(localX,y,localZ)`, `rawDensity()`, and `solid()` sample blocks. Local X/Z must be 0–15 and Y 0–127.
- `EndChunkGrid::column(localX,localZ)` returns 128 final densities indexed by Y.
- `EndChunkGrid::surfaceHeight(localX,localZ)` and `surfaces()[localX][localZ]` return first-air heights.
- `EndChunkGrid::latticeValues()` exposes nodes in `(gridX*3+gridZ)*33+gridY` order.

The caches have fixed bounds: eight chunk grids, 4,096 lattice values, 8,192 island height values, 512 accepted-candidate neighborhoods, and 32,768 candidate classifications. Adjacent chunks share sampled lattice and island data. Runtime generation never runs all noise octaves per voxel.

`JavaRandom` is also shared with structure/feature generation: `setSeed`, `next(bits)`, `nextInt(?bound)`, `nextLong`, `nextBoolean`, `nextFloat`, `nextDouble`, `consumeCount`; exact signed-long `addLong` and `multiplyLong`; and source-equivalent `setLargeFeatureSeed`, `setLargeFeatureWithSalt`, `setDecorationSeed`, `setFeatureSeed`. `setDecorationSeed` takes block coordinates and returns the signed decoration seed.

## Reproduce the reference

Run from the repository root:

```sh
python3 tools/end_reference/build_reference.py --archive /path/to/MCP-Reborn-26.2.zip
bin/php7/bin/php tools/test_end_noise.php
```

The builder reads the original zip without changing it, extracts selected source algorithm bodies into a temporary directory, compiles a small dependency-free Java reference, and writes `end_noise_fixture.json`. JDK 17+ and Python 3.11+ are sufficient. `--archive /path/to/MCP-Reborn-26.2.zip` selects your source archive and is required. `--output /path/to/fixture.json` overrides the output location. The checked-in fixture can be tested with PHP alone, without the archive or JDK.

The generated constructor shells implement only the needed legacy Perlin octave profile (all amplitudes one, octaves −15..0 and −7..0), and the density expression comes from the server's embedded End JSON. Actual noise method bodies, island height, math functions, random draws, and interpolation updates come from the supplied Java source. The fixture records SHA-256 identities for the archive, each source, embedded JSON, builder, and test shell.

One decompiler error is deliberately corrected: the source prints `combined * 1.110223E-16F` in `BitRandomSource.nextDouble()`. The original server class contains `l2d`, `ldc2_w 1.1102230246251565E-16d`, `dmul`. The builder verifies those bytecode instructions with `javap` and restores the double literal. Its evidence includes the original class digest and disassembly. Following the decompiled float expression produces different octave offsets.

The fixture covers seven seeds, signed long arithmetic and seeding edges, random rejection bounds, independently sampled Simplex/Improved/Blended noise, negative and far coordinates, and 42 full chunks. Tests require exact double bytes and lattice hashes, all 32,768 solid decisions in every chunk, all 256 first-air heights, and the optimized callback mask to match. This establishes the tested base-terrain boundary, not full-game equivalence.

## Scope

The base sampler covers new, unblended chunks in the vanilla End's 0–127 generation height. Blocks are End stone for positive final density and air otherwise. It adds no bedrock floor. Structures, spikes/cages, podium, spawn platform, scattered feature islands, chorus plants, entities, and gameplay belong to the integration/feature layer. Biome zoom/fiddling and old-chunk blending are outside this sampler.

Java's original End island radius expression squares/adds signed int32 coordinates before converting to float. At certain far distances it overflows negative and produces NaN (the vanilla distant End rings). The port preserves that behavior; `density > 0` treats NaN as air. World coordinates within Java's ±30,000,000 block border are the supported coordinate domain.
