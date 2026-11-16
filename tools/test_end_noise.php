<?php

declare(strict_types=1);

/** Standalone PHP 8.4 parity test; regenerate oracle with end_reference/build_reference.py. */
use pocketmine\level\generator\ender\noise\EndTerrainNoise;
use pocketmine\level\generator\ender\noise\ImprovedNoise;
use pocketmine\level\generator\ender\noise\JavaRandom;
use pocketmine\level\generator\ender\noise\SimplexNoise;

spl_autoload_register(static function(string $class) : void{
	$prefix = 'pocketmine\\level\\generator\\ender\\noise\\';
	if(str_starts_with($class, $prefix)){
		require_once dirname(__DIR__) . '/src/pocketmine/level/generator/ender/noise/' . substr($class, strlen($prefix)) . '.php';
	}
});

$checks = 0;
$failures = [];
function check(bool $success, string $message) : void{
	global $checks, $failures;
	++$checks;
	if(!$success){
		$failures[] = $message;
	}
}
function sameFloat(float $actual, float|string $expected, string $context) : void{
	if($expected === 'NaN'){
		check(is_nan($actual), $context . ': expected NaN, got ' . $actual);
		return;
	}
	$expected = (float) $expected;
	check(pack('E', $actual) === pack('E', $expected), $context . ': expected ' . sprintf('%.17g', $expected) . ', got ' . sprintf('%.17g', $actual));
}

if(PHP_INT_SIZE !== 8){
	fwrite(STDERR, "End terrain requires a 64-bit PHP build.\n");
	exit(1);
}
$fixturePath = __DIR__ . '/end_reference/end_noise_fixture.json';
if(!is_file($fixturePath)){
	fwrite(STDERR, "Missing Java reference fixture; run python3 tools/end_reference/build_reference.py --archive /path/to/MCP-Reborn-26.2.zip first.\n");
	exit(1);
}
$start = microtime(true);
$fixture = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
foreach(['harness_sha256' => 'EndNoiseReference.java', 'builder_sha256' => 'build_reference.py'] as $key => $file){
	check(hash_file('sha256', __DIR__ . '/end_reference/' . $file) === $fixture['evidence'][$key], 'fixture provenance for ' . $file);
}
foreach($fixture['random'] as $case){
	$seed = (int) $case['seed'];
	$random = new JavaRandom($seed);
	foreach($case['draws'] as $i => $draw){
		$context = 'random seed=' . $seed . ' draw=' . $i . ' ' . $draw[0];
		switch($draw[0]){
			case 'int': check($random->nextInt() === $draw[1], $context); break;
			case 'bounded': check($random->nextInt($draw[1]) === $draw[2], $context); break;
			case 'long': check($random->nextLong() === (int) $draw[1], $context); break;
			case 'double': sameFloat($random->nextDouble(), $draw[1], $context); break;
			case 'float': sameFloat($random->nextFloat(), $draw[1], $context); break;
			case 'boolean': check($random->nextBoolean() === $draw[1], $context); break;
			default: throw new RuntimeException('Unknown reference random draw');
		}
	}
	$random->setSeed($seed);
	$random->consumeCount(17292);
	check($random->nextInt() === $case['consumed'][0], 'consumed int seed=' . $seed);
	sameFloat($random->nextDouble(), $case['consumed'][1], 'consumed double seed=' . $seed);
}
foreach($fixture['long_arithmetic'] as [$a, $b, $sum, $product]){
	check(JavaRandom::addLong((int) $a, (int) $b) === (int) $sum, 'long add ' . $a . ',' . $b);
	check(JavaRandom::multiplyLong((int) $a, (int) $b) === (int) $product, 'long multiply ' . $a . ',' . $b);
}
foreach($fixture['worldgen'] as $case){
	$seed = (int) $case['seed'];
	$x = $case['x'];
	$z = $case['z'];
	$r = new JavaRandom($seed);
	$context = 'worldgen seed=' . $seed . ' at=' . $x . ',' . $z;
	$r->setLargeFeatureSeed($seed, $x, $z);
	check($r->nextInt(10387313) === $case['large'][0] && $r->nextLong() === (int) $case['large'][1], $context . ' large');
	$r->setLargeFeatureWithSalt($seed, $x, $z, 10387313);
	check($r->nextInt(10387313) === $case['salt'][0] && $r->nextLong() === (int) $case['salt'][1], $context . ' salt');
	$decoration = $r->setDecorationSeed($seed, $x, $z);
	check($decoration === (int) $case['decoration'][0] && $r->nextInt() === $case['decoration'][1], $context . ' decoration');
	$r->setFeatureSeed($decoration, 17, 4);
	check($r->nextInt() === $case['feature'][0] && $r->nextLong() === (int) $case['feature'][1], $context . ' feature');
}
foreach($fixture['feature_seed_edges'] as [$seed, $index, $step, $nextInt, $nextLong]){
	$r = new JavaRandom((int) $seed);
	$r->setFeatureSeed((int) $seed, $index, $step);
	check($r->nextInt() === $nextInt && $r->nextLong() === (int) $nextLong, 'feature seed int/long promotion edges');
}
$chunkCount = 0;
$solidCount = 0;
foreach($fixture['noise'] as $case){
	$seed = (int) $case['seed'];
	$random = new JavaRandom($seed);
	$random->consumeCount(17292);
	$simplex = new SimplexNoise($random);
	$improved = new ImprovedNoise(new JavaRandom($seed));
	$terrain = new EndTerrainNoise($seed);
	foreach($case['simplex'] as [$x, $z, $expected]){
		sameFloat($simplex->getValue($x, $z), $expected, 'simplex seed=' . $seed . ' at=' . $x . ',' . $z);
	}
	foreach($case['improved'] as [$x, $y, $z, $smear, $fudge, $expected]){
		sameFloat($improved->noise($x, $y, $z, $smear, $fudge), $expected, 'improved seed=' . $seed . ' at=' . $x . ',' . $y . ',' . $z);
	}
	foreach($case['points'] as [$x, $y, $z, $height, $blended, $raw]){
		$context = 'seed=' . $seed . ' at=' . $x . ',' . $y . ',' . $z;
		sameFloat($terrain->islandHeight($x, $z), $height, 'island ' . $context);
		sameFloat($terrain->blendedNoise($x, $y, $z), $blended, 'blended ' . $context);
		sameFloat($terrain->density($x, $y, $z), $raw, 'raw ' . $context);
	}
	foreach($case['biomes'] as [$x, $z, $expected]){
		check($terrain->biome($x, $z) === $expected, 'noise biome seed=' . $seed . ' at=' . $x . ',' . $z);
	}
	foreach($case['chunks'] as $chunk){
		++$chunkCount;
		$cx = $chunk['x'];
		$cz = $chunk['z'];
		$context = 'seed=' . $seed . ' chunk=' . $cx . ',' . $cz;
		$grid = $terrain->chunkGrid($cx, $cz);
		$latticeBytes = '';
		foreach($grid->latticeValues() as $value){
			$latticeBytes .= pack('E', $value);
		}
		check(hash('sha256', $latticeBytes) === $chunk['lattice_sha256'], '297-node exact lattice hash ' . $context);
		$mask = '';
		$heights = [];
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$surface = 0;
				for($y = 0; $y < 128; ++$y){
					$solid = $grid->solid($x, $y, $z);
					$mask .= $solid ? "\1" : "\0";
					if($solid){
						$surface = $y + 1;
						++$solidCount;
					}
				}
				$heights[] = $surface;
				check($grid->surfaceHeight($x, $z) === $surface, 'surface callback ' . $context . ' local=' . $x . ',' . $z);
			}
		}
		check(hash('sha256', $mask) === $chunk['solid_sha256'], 'all 32768 block signs ' . $context);
		check($heights === $chunk['heights'], 'all 256 WORLD_SURFACE_WG heights ' . $context);
		$emitted = str_repeat("\0", 32768);
		$grid->forEachSolid(static function(int $x, int $y, int $z) use (&$emitted) : void{
			$emitted[($x * 16 + $z) * 128 + $y] = "\1";
		});
		check($emitted === $mask, 'optimized generation callback ' . $context);
		foreach($chunk['samples'] as [$x, $y, $z, $raw, $density]){
			sameFloat($grid->rawDensity($x, $y, $z), $raw, 'interpolated raw ' . $context . ' local=' . $x . ',' . $y . ',' . $z);
			sameFloat($grid->density($x, $y, $z), $density, 'squeezed ' . $context . ' local=' . $x . ',' . $y . ',' . $z);
			sameFloat($terrain->interpolatedDensity($cx * 16 + $x, $y, $cz * 16 + $z), $density, 'world block helper ' . $context);
		}
	}
}
if($failures !== []){
	fwrite(STDERR, count($failures) . ' / ' . $checks . " End noise checks failed:\n" . implode("\n", array_slice($failures, 0, 80)) . "\n");
	exit(1);
}
printf("PASS %d checks; %d chunks, %d block signs, %d solids; %.3fs; peak %.1f MiB\n", $checks, $chunkCount, $chunkCount * 32768, $solidCount, microtime(true) - $start, memory_get_peak_usage(true) / 1048576);
echo 'Java fixture SHA-256: ' . hash_file('sha256', $fixturePath) . "\n";
echo 'Source archive SHA-256: ' . $fixture['evidence']['archive_sha256'] . "\n";
