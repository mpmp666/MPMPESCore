<?php

/**
 * Geometry and placement regression for the supplied Minecraft 26.2 sources.
 *
 * Generate comparison records with:
 * python3 tools/end_city_reference.py /path/to/MCP-Reborn-26.2.zip --scan 1024 > /tmp/end-city-reference.json
 * bin/php7/bin/php tools/test_end_city.php /tmp/end-city-reference.json
 */
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
if(!defined('pocketmine\\PATH')){ define('pocketmine\\PATH', dirname(__DIR__) . '/'); }
spl_autoload_register(static function(string $class) : void{
	$path = \pocketmine\PATH . 'src/' . str_replace('\\', '/', $class) . '.php';
	if(is_file($path)){ require_once $path; }
});

use pocketmine\level\generator\ender\EndCityGenerator;
use pocketmine\level\generator\ender\noise\JavaRandom;
use pocketmine\level\generator\structure\EndCityLayout;
use pocketmine\level\generator\structure\StructureAssembler;
use pocketmine\level\generator\structure\StructureRepository;

function cityCheck(bool $ok, string $message) : void{
	if(!$ok){ throw new RuntimeException($message); }
}
function cityDigest(array $pieces) : string{
	$text = '';
	foreach($pieces as $p){
		$text .= implode('|', [$p['template'], $p['x'], $p['y'], $p['z'], $p['rotation'], (int)$p['ignore_air'], $p['generation_depth']]) . "\n";
	}
	return hash('sha256', $text);
}

if(method_exists(StructureRepository::class, 'setPack')){ StructureRepository::setPack('base'); }
$start = microtime(true);
$templates = [];
foreach(StructureRepository::manifest()['templates'] as $name => $entry){
	if(str_starts_with($name, 'end_city/')){ $templates[$name] = $entry; }
}
cityCheck(count($templates) === 20, 'Minecraft 26.2 must contain all twenty End City templates');
foreach($templates as $name => $entry){
	$template = StructureRepository::template($name);
	cityCheck($template['size'] === $entry['size'], 'Template dimension mismatch: ' . $name);
	cityCheck(strlen($template['blockData']) > 0, 'Empty template: ' . $name);
}

$maxPieces = 0;
$maxHeight = 0;
$maxReach = 0;
$shipCount = 0;
$rotations = [];
$comparisonCount = 0;
$fixturePath = $argv[1] ?? null;
// Recorded by the source-derived Java runner; source SHA-256:
// 73114dbb3737a2fd8e5bbc204d8c64f3a458212cef343e94bb8cc40a41059987
$reference = $fixturePath !== null ? json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR) : [
	'sizes' => [],
	'cases' => json_decode(<<<'JSON'
[
{"kind":"manual","seed":-9223372036854775808,"rotation":2,"count":19,"bounds":[-43,0,-12,3,37,3],"sha256":"8f2f38695e5f705a689c94e2655d908f1b8aea9d2c1bcdfbf65de34a16aed8d2","next_int":1580443894},
{"kind":"manual","seed":-1,"rotation":1,"count":53,"bounds":[-12,0,-30,76,94,26],"sha256":"534867f8eee9fc803df59a396c621664ba012f8cd63fa283c0bc8d94e2841897","next_int":945944107},
{"kind":"manual","seed":42,"rotation":2,"count":68,"bounds":[-45,0,-38,39,98,104],"sha256":"9f78a63977037fb1665326712d058b2b5d5d4c73f88b3ef1ceb09e699a58a81b","next_int":1312452476},
{"kind":"manual","seed":244,"rotation":2,"count":104,"bounds":[-85,0,-41,4,130,86],"sha256":"87a410851df36aca94c229b80efcc4b7ccf60c9cbd586f4853f413c3edc73708","next_int":-1636864132},
{"kind":"manual","seed":800,"rotation":2,"count":99,"bounds":[-12,0,-145,43,94,24],"sha256":"781f80ad135260981aa79ab835fbda3fd3bb47eea672f8d601e6f7d7f7024b20","next_int":1566768703},
{"kind":"manual","seed":838,"rotation":2,"count":176,"bounds":[-64,0,-82,66,101,21],"sha256":"5aa4292e431af823e5f12c9ec09a5170c95220b4c02881d4bf4c934f4a6f721a","next_int":1438573234},
{"kind":"natural","seed":0,"regionX":0,"regionZ":0,"chunkX":3,"chunkZ":4,"placement_seed":10387313,"source_seed":-4094562639895711408,"rotation":0,"count":72,"bounds":[-26,0,-51,12,96,89],"sha256":"4cdf28763015fa9436dd92eefb2b31ac5a6dba13160c69ea4726497a5514a628","next_int":1812905303},
{"kind":"natural","seed":1,"regionX":93750,"regionZ":-93750,"chunkX":1875002,"chunkZ":-1874994,"placement_seed":19591419495168564,"source_seed":-340393580008873587,"rotation":1,"count":76,"bounds":[-12,0,-27,38,112,57],"sha256":"d6a3cc33191220eac8e67653478bc94a0b782009990b6cd031360c69ae58f64f","next_int":-1401599521},
{"kind":"natural","seed":0,"regionX":100,"regionZ":-100,"chunkX":2008,"chunkZ":-1999,"placement_seed":20897524504413,"source_seed":-2132196307250599138,"rotation":2,"count":73,"bounds":[-12,0,-43,40,90,117],"sha256":"314b84c0fd1e7ce9a66be47764d2ddea809fba3dd91f35c7e8beade4c0f5fa3f","next_int":-1216128991},
{"kind":"natural","seed":0,"regionX":-5,"regionZ":12,"chunkX":-94,"chunkZ":247,"placement_seed":-114579405755,"source_seed":2334156099824904738,"rotation":3,"count":10,"bounds":[-3,0,-12,12,36,3],"sha256":"28ecbaf1dca188dbf347c0ee6cd6b53dcd74cf4be8b6ca67adf827268067d7f6","next_int":-427988695}
]
JSON, true, 512, JSON_THROW_ON_ERROR)
];
	foreach($reference['sizes'] as $name => $size){
		cityCheck($templates['end_city/' . $name]['size'] === $size, 'Source bounding dimensions differ: ' . $name);
	}
	foreach($reference['cases'] as $case){
		$seed = $case['seed'];
		if($case['kind'] === 'manual'){
			$random = new JavaRandom($seed);
			$rotation = $random->nextInt(4);
			$pieces = (new EndCityLayout($random))->buildAtRotation($rotation);
			cityCheck($random->nextInt() === $case['next_int'], 'Manual random consumption differs at seed ' . $seed);
			cityCheck($pieces === (new EndCityLayout($seed))->build(), 'Manual rotation consumption differs');
		}else{
			$candidate = EndCityGenerator::candidate($seed, $case['regionX'], $case['regionZ']);
			foreach(['chunkX', 'chunkZ', 'placement_seed'] as $key){
				cityCheck($candidate[$key] === $case[$key], 'Candidate ' . $key . ' differs at ' . json_encode($case));
			}
			$samples = [];
			$biomes = [];
			$plan = EndCityGenerator::plan($seed, $case['regionX'], $case['regionZ'], static function(int $x, int $z) use (&$samples) : int{
				$samples[] = [$x, $z]; return 81;
			}, static function(int $x, int $y, int $z) use (&$biomes) : bool{
				$biomes[] = [$x, $y, $z]; return true;
			});
			cityCheck($plan !== null && $plan['y'] === 80, 'First-air height was not converted to occupied height');
			$rotation = $plan['rotation'];
			$pieces = $plan['pieces'];
			cityCheck($plan['source_seed'] === $case['source_seed'], 'Large feature seed differs');
			$x = $candidate['chunkX'] * 16 + 7;
			$z = $candidate['chunkZ'] * 16 + 7;
			cityCheck($plan['x'] === $x && $plan['z'] === $z, 'Source root must use chunk offset seven');
			$dx = $rotation === 1 || $rotation === 2 ? -5 : 5;
			$dz = $rotation === 2 || $rotation === 3 ? -5 : 5;
			cityCheck($samples === [[$x,$z],[$x,$z+$dz],[$x+$dx,$z],[$x+$dx,$z+$dz]], 'Source height samples differ');
			cityCheck($biomes === [[$x >> 2,20,$z >> 2]], 'Biome predicate must receive quart coordinates after height checks');
			$random = new JavaRandom($case['source_seed']);
			$random->nextInt(4);
			$direct = (new EndCityLayout($random))->buildAtRotation($rotation);
			cityCheck($direct === $pieces && $random->nextInt() === $case['next_int'], 'Natural random consumption differs');
		}
		cityCheck($rotation === $case['rotation'], 'Source rotation differs');
		cityCheck(count($pieces) === $case['count'], 'Source piece count differs at ' . json_encode($case));
		$bounds = StructureRepository::bounds($pieces);
		cityCheck($bounds === $case['bounds'], 'Source inclusive bounds differ');
		cityCheck(cityDigest($pieces) === $case['sha256'], 'Source piece order/offset/flags/tags differ at ' . json_encode($case));
		foreach($pieces as $piece){ cityCheck(!isset($piece['pack']), 'Natural layout must be pack-free'); }
		$maxPieces = max($maxPieces, count($pieces));
		$maxHeight = max($maxHeight, $bounds[4] - $bounds[1] + 1);
		$reach = max(abs($bounds[0]), abs($bounds[2]), abs($bounds[3]), abs($bounds[5]));
		cityCheck($reach <= EndCityGenerator::MAX_HORIZONTAL_REACH, 'Layout exceeds conservative source discovery radius');
		$maxReach = max($maxReach, $reach);
		$shipCount += count(array_filter($pieces, static fn(array $p) : bool => $p['template'] === 'end_city/ship'));
		$rotations[$rotation] = true;
		++$comparisonCount;
	}
	cityCheck($maxPieces > 80, 'Fixture must exercise source layouts above the old 80-piece cap');
	cityCheck($maxHeight > 68, 'Fixture must exercise source layouts above the old 68-block cap');
	cityCheck($maxReach > 112, 'Fixture must exercise source layouts beyond the old span');
	cityCheck($shipCount > 0, 'Fixture must exercise ships');
	cityCheck(count($rotations) === 4, 'Fixture must exercise all four rotations');

$lowBiomeCalls = 0;
cityCheck(EndCityGenerator::plan(42, -3, 7, static fn(int $x, int $z) : int => 60, static function() use (&$lowBiomeCalls) : bool{ ++$lowBiomeCalls; return true; }) === null, 'Occupied y=59 must reject');
cityCheck($lowBiomeCalls === 0, 'Rejected height must not call biome predicate');
$minimum = EndCityGenerator::plan(42, -3, 7, static fn(int $x, int $z) : int => 61);
cityCheck($minimum !== null && $minimum['y'] === 60, 'Occupied y=60 must accept');
cityCheck(EndCityGenerator::plan(42, -3, 7, static fn(int $x, int $z) : int => 81, static fn() : bool => false) === null, 'Biome tag rejection must reject');
$heights = [91, 81, 70, 92];
$sampleIndex = 0;
$minimum = EndCityGenerator::plan(42, -3, 7, static function() use (&$sampleIndex, $heights) : int{ return $heights[$sampleIndex++]; });
cityCheck($minimum['y'] === 69, 'Source must choose minimum of all four occupied heights');

// Interleaving builds models workers and catches global ship/random state leaks.
$first = (new EndCityLayout(42))->build();
(new EndCityLayout(123456))->build();
cityCheck((new EndCityLayout(42))->build() === $first, 'Interleaved builds changed layout');
cityCheck((new EndCityLayout(42))->build('ship') === $first, 'Variant must not alter source random draws');
$manual = StructureAssembler::build('end_city', 42);
cityCheck($manual === $first, 'Existing StructureAssembler manual API differs');
cityCheck($first[1]['ignore_air'] && $first[2]['ignore_air'] && !$first[0]['ignore_air'] && !$first[3]['ignore_air'], 'Initial source overwrite processors differ');

echo 'PASS End City: twenty source templates; ' . $comparisonCount . ' Java source comparisons; max pieces=' . $maxPieces . ', height=' . $maxHeight . ', reach=' . $maxReach . ', ships=' . $shipCount . '; ' . round(microtime(true) - $start, 3) . "s\n";
