<?php

declare(strict_types=1);

namespace pocketmine\level\generator\ender;

use pocketmine\level\generator\ender\noise\JavaRandom;
use pocketmine\level\generator\structure\EndCityLayout;
use pocketmine\level\generator\structure\StructureRepository;

/** Natural End City placement and layout from the supplied Minecraft 26.2 source. */
final class EndCityGenerator{
	public const SPACING = 20;
	public const SEPARATION = 11;
	public const SALT = 10387313;
	/**
	 * Conservative discovery radius only; layout geometry is never restricted.
	 * Ignoring collisions, source offsets and depth eight bound the radius at
	 * 267: H=max(29,14+T), T=max(14,10+B,4+F), F=max(21,15+B),
	 * B=max(134,36+H), with calls advancing depth and the initial roof at -3.
	 */
	public const MAX_HORIZONTAL_REACH = 272;

	/**
	 * A triangular random-spread region has exactly one candidate chunk.
	 * Four nextInt(9) calls run on the placement stream, independently of layout.
	 *
	 * @return array{regionX:int,regionZ:int,chunkX:int,chunkZ:int,x:int,z:int,placement_seed:int}
	 */
	public static function candidate(int $seed, int $regionX, int $regionZ) : array{
		$placementSeed = JavaRandom::addLong(JavaRandom::addLong(JavaRandom::multiplyLong($regionX, 341873128712), JavaRandom::multiplyLong($regionZ, 132897987541)), JavaRandom::addLong($seed, self::SALT));
		$random = new JavaRandom($placementSeed);
		$chunkX = $regionX * self::SPACING + intdiv($random->nextInt(9) + $random->nextInt(9), 2);
		$chunkZ = $regionZ * self::SPACING + intdiv($random->nextInt(9) + $random->nextInt(9), 2);
		return [
			'regionX' => $regionX, 'regionZ' => $regionZ, 'chunkX' => $chunkX, 'chunkZ' => $chunkZ,
			'x' => $chunkX * 16 + 7, 'z' => $chunkZ * 16 + 7, 'placement_seed' => $placementSeed
		];
	}

	/**
	 * surfaceHeight(blockX, blockZ) returns NoiseBasedChunkGenerator's raw
	 * WORLD_SURFACE_WG getBaseHeight: first air above the highest occupied block.
	 * biomeEligible(quartX, quartY, quartZ), when supplied, implements the source
	 * structure biome tag (End highlands and midlands). It runs after height checks.
	 *
	 * Pieces and bounds are relative to the returned world-space x/y/z pivot.
	 * No legacy-client height translation or truncation is applied here.
	 */
	public static function plan(int $seed, int $regionX, int $regionZ, callable $surfaceHeight, ?callable $biomeEligible = null) : ?array{
		$candidate = self::candidate($seed, $regionX, $regionZ);
		// GenerationContext.makeRandom -> WorldgenRandom.setLargeFeatureSeed.
		$random = new JavaRandom($seed);
		$xScale = $random->nextLong();
		$zScale = $random->nextLong();
		$sourceSeed = JavaRandom::multiplyLong($candidate['chunkX'], $xScale) ^ JavaRandom::multiplyLong($candidate['chunkZ'], $zScale) ^ $seed;
		$random->setSeed($sourceSeed);
		$rotation = $random->nextInt(4);
		$dx = $rotation === 1 || $rotation === 2 ? -5 : 5;
		$dz = $rotation === 2 || $rotation === 3 ? -5 : 5;
		$x = $candidate['x'];
		$z = $candidate['z'];
		// Structure.getCornerHeights uses getFirstOccupiedHeight = getBaseHeight-1.
		$y = min(
			(int)$surfaceHeight($x, $z) - 1,
			(int)$surfaceHeight($x, $z + $dz) - 1,
			(int)$surfaceHeight($x + $dx, $z) - 1,
			(int)$surfaceHeight($x + $dx, $z + $dz) - 1
		);
		if($y < 60){ return null; }
		if($biomeEligible !== null && !$biomeEligible($x >> 2, $y >> 2, $z >> 2)){ return null; }
		$pieces = (new EndCityLayout($random))->buildAtRotation($rotation);
		return $candidate + [
			'family' => 'end_city', 'y' => $y, 'rotation' => $rotation, 'source_seed' => $sourceSeed,
			'pieces' => $pieces, 'bounds' => StructureRepository::bounds($pieces)
		];
	}
}
