<?php

declare(strict_types=1);

/**
 * Standalone source-derived Java 26.2 feature fixtures.
 * Run: bin/php7/bin/php tools/test_end_features.php
 * Geometry digests were recorded by the Java oracle transcribed from
 * EndSpikeFeature.placeSpike/SpikeCacheLoader, Util.toShuffledList,
 * EndPodiumFeature.place, EndPlatformFeature.createEndPlatform at natural
 * placed_feature/end_platform origin[100,49,0] (floor48, clear49..51). Random fixtures
 * use RandomSupport, Xoroshiro128PlusPlus and WorldgenRandom/BitRandomSource.
 * nextDouble uses the shipped l2d/dmul bytecode correction already verified by
 * tools/end_reference/build_reference.py (the decompiler printed a float literal).
 * Outer fixtures use EndIslandFeature and ChorusFlowerBlock.generatePlant over
 * the existing exact-source terrain oracle, plus EndGatewayFeature geometry.
 * Geometry fixtures were recorded from independent Java harnesses during
 * development. Those temporary harnesses are not included; the checked-in
 * cases provide regression coverage. The terrain oracle is reproducible via
 * tools/end_reference/build_reference.py. Original source SHA256:
 * EndSpikeFeature: 1e829842e55a0cd1726b30040f0dbd597f78ddca4caba4409f8f7a2b793c149e
 * EndPodiumFeature: a47c8d217db8866da40f77565c24abd2e3bfc259fdf131ec77182743bc9b4fcc
 * EndGatewayFeature: 2cdd5e376b04ee8787f5bc1f8f94eb6b287e1d92d50cd2fc72a9130a5f6f065e
 * EndPlatformFeature: 66b13e04d8a0f5870aa2143d270731e964ce85434265d75cb2ad90d5cdc84b65
 * EndIslandFeature: b40929ff6687fa493c07027070426c32b64ab1e9f279299e9cd9f5d3c2cd8fec
 * ChorusFlowerBlock: 9bda35178bac689fed632df3d6f84c79d9089adf6fc500b5420e63399a6fa43b
 */
error_reporting(E_ALL & ~E_DEPRECATED);
spl_autoload_register(static function(string $class) : void{
	$path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $class) . '.php';
	if(is_file($path)){
		require_once $path;
	}
});

use pocketmine\block\Block;
use pocketmine\level\format\mcregion\Chunk;
use pocketmine\level\generator\ender\EndFeatures;
use pocketmine\level\generator\ender\noise\EndTerrainNoise;
use pocketmine\level\generator\ender\noise\XoroshiroRandom;

$checks = 0;
function featureCheck(bool $ok, string $message) : void{
	global $checks;
	++$checks;
	if(!$ok){
		throw new RuntimeException($message);
	}
}

/** Record every final write; throw on any escaped chunk or Y coordinate. */
final class FeatureTestChunk extends Chunk{
	public array $writes = [];
	public function __construct(int $cx, int $cz){
		$this->x = $cx;
		$this->z = $cz;
	}
	public function setBlock($x, $y, $z, $blockId = null, $meta = null){
		if($x < 0 || $x > 15 || $z < 0 || $z > 15 || $y < 0 || $y > 127){
			throw new RuntimeException('Feature escaped the supplied chunk');
		}
		$this->writes[($this->x * 16 + $x) . ':' . $y . ':' . ($this->z * 16 + $z)] = $blockId . ':' . $meta;
	}
	public function getBlockId($x, $y, $z){
		return (int) explode(':', $this->writes[($this->x * 16 + $x) . ':' . $y . ':' . ($this->z * 16 + $z)] ?? '1:0')[0];
	}
}

function featureDigest(array $writes) : string{
	ksort($writes, SORT_STRING);
	$text = '';
	foreach($writes as $key => $value){
		$text .= $key . '=' . $value . "\n";
	}
	return hash('sha256', $text);
}

$randomCases = json_decode(<<<'JSON'
[
{"seed":"0","raw":"3038984756725240190","long":"3038984751730664151","bounded":539439708,"float":0.11712485551834106,"double":0.09971248740970695,"decoration":"7367130305509955952","featureLong":"-7533502599867499501"},
{"seed":"1","raw":"-1033667707219518978","long":"-1033667707451936253","bounded":103619724,"float":0.4388219118118286,"double":0.15067259793363064,"decoration":"605238139794829937","featureLong":"494224510536435569"},
{"seed":"-1","raw":"-8676505878415342125","long":"-8676505881460725316","bounded":40785815,"float":0.0003120899200439453,"double":0.8143975075328227,"decoration":"2681924360405902255","featureLong":"498488245865162741"},
{"seed":"42","raw":"-4695948378737616609","long":"-4695948378802814517","bounded":569141641,"float":0.45643019676208496,"double":0.6480715192680061,"decoration":"5148643179961733882","featureLong":"-5182335916502387814"},
{"seed":"1794737015","raw":"8673951634126501222","long":"8673951631111610652","bounded":69156410,"float":0.20460158586502075,"double":0.07677563254066389,"decoration":"-5768608574501132697","featureLong":"-6257994036070755208"},
{"seed":"-9223372036854775808","raw":"5559461712809880615","long":"5559461709638992951","bounded":14335675,"float":0.41497015953063965,"double":0.13670008194174788,"decoration":"-1165609575640837040","featureLong":"-2241932908438730156"},
{"seed":"9223372036854775807","raw":"-4337892644778522163","long":"-4337892647980267059","bounded":752515406,"float":0.5892642736434937,"double":0.7773223853487583,"decoration":"-5208980857674627569","featureLong":"-1437872930928971656"}
]
JSON, true, 512, JSON_THROW_ON_ERROR);
foreach($randomCases as $case){
	$seed = (int) $case['seed'];
	$random = new XoroshiroRandom($seed);
	featureCheck($random->nextRawLong() === (int) $case['raw'], 'raw xoroshiro128++ seed ' . $seed);
	$random->setSeed($seed);
	featureCheck($random->nextLong() === (int) $case['long'], 'WorldgenRandom two-output nextLong seed ' . $seed);
	featureCheck($random->nextInt(1073741825) === $case['bounded'], 'bounded rejection seed ' . $seed);
	featureCheck($random->nextFloat() === $case['float'], 'nextFloat seed ' . $seed);
	featureCheck($random->nextDouble() === $case['double'], 'shipped bytecode nextDouble seed ' . $seed);
	$decoration = $random->setDecorationSeed($seed, -123456 * 16, 76543 * 16);
	featureCheck($decoration === (int) $case['decoration'], 'decoration block-coordinate seed ' . $seed);
	$random->setFeatureSeed($decoration, 0, 4);
	featureCheck($random->nextLong() === (int) $case['featureLong'], 'step 4 feature seed ' . $seed);
}

$coreCases = json_decode(<<<'JSON'
[
{"seed":"0","key":20792,"sizes":[6,5,1,7,3,9,0,2,4,8],"sha256":"4a0ccce0215af080c4d02a305a88be3f2131eb73be5010123ce1b574b3d35636"},
{"seed":"1","key":40152,"sizes":[3,6,0,2,8,5,9,4,7,1],"sha256":"5161c326b71e22c9a57d345413cbfafcedfe898e21a9ae31e7e8ec3a343f8828"},
{"seed":"-1","key":8899,"sizes":[9,1,6,2,3,8,5,7,0,4],"sha256":"9e75752c3919bcaf567f3a910d5f4e9bacc99e6c4bd911bca267f6ea14bec664"},
{"seed":"42","key":35575,"sizes":[8,9,3,2,1,4,5,6,7,0],"sha256":"27649193333bb72117682c111c592220e0936112de2a879752264f15ef05cad9"},
{"seed":"1794737015","key":30887,"sizes":[9,1,0,6,5,7,3,4,2,8],"sha256":"7635a3213211660841b846f174139af8311417a067216ff92aa0b6a91c2e9b39"},
{"seed":"-9223372036854775808","key":20792,"sizes":[6,5,1,7,3,9,0,2,4,8],"sha256":"4a0ccce0215af080c4d02a305a88be3f2131eb73be5010123ce1b574b3d35636"},
{"seed":"9223372036854775807","key":8899,"sizes":[9,1,6,2,3,8,5,7,0,4],"sha256":"9e75752c3919bcaf567f3a910d5f4e9bacc99e6c4bd911bca267f6ea14bec664"}
]
JSON, true, 512, JSON_THROW_ON_ERROR);
$centers = [[42,0],[33,24],[12,39],[-13,39],[-34,24],[-42,-1],[-34,-25],[-13,-40],[12,-40],[33,-25]];
$started = microtime(true);
foreach($coreCases as $case){
	$seed = (int) $case['seed'];
	$terrain = new EndTerrainNoise($seed);
	$features = new EndFeatures($seed, $terrain);
	featureCheck($features->getSpikeKey() === $case['key'], 'spike layout cache key ' . $seed);
	featureCheck(array_column($features->getSpikes(), 'size') === $case['sizes'], 'source descending shuffle ' . $seed);
	featureCheck(array_map(static fn(array $s) : array => [$s['centerX'], $s['centerZ']], $features->getSpikes()) === $centers, 'source floor/cos/sin centers ' . $seed);
	featureCheck(count(array_filter($features->getSpikes(), static fn(array $s) : bool => $s['guarded'])) === 2, 'exactly two guarded spikes');
	featureCheck($features->getPodiumY() === max(1, $terrain->surfaceHeight(0,0)-1), 'podium uses highest occupied terrain');
	// Compare fixed-origin feature geometry independently of terrain fixtures.
	(new ReflectionProperty(EndFeatures::class, 'podiumY'))->setValue($features, 62);
	$coordinates = [];
	foreach($features->getSpikes() as $s){
		for($cx = ($s['centerX']-$s['radius'])>>4; $cx <= ($s['centerX']+$s['radius'])>>4; ++$cx){
			for($cz = ($s['centerZ']-$s['radius'])>>4; $cz <= ($s['centerZ']+$s['radius'])>>4; ++$cz){
				$coordinates[$cx . ':' . $cz] = [$cx,$cz];
			}
		}
	}
	foreach([[-1,-1],[-1,0],[0,-1],[0,0],[6,-1],[6,0]] as [$cx,$cz]){
		$coordinates[$cx . ':' . $cz] = [$cx,$cz];
	}
	$all = [];
	$reverse = [];
	foreach([$coordinates, array_reverse($coordinates)] as $orderIndex => $order){
		$out = [];
		foreach($order as [$cx,$cz]){
			$chunk = new FeatureTestChunk($cx,$cz);
			$features->populate($chunk);
			$out += $chunk->writes;
		}
		if($orderIndex === 0){ $all = $out; }else{ $reverse = $out; }
	}
	featureCheck(count($all) === 51554, 'all source write positions ' . $seed);
	featureCheck(featureDigest($all) === $case['sha256'], 'all geometry/state cells equal Java oracle ' . $seed);
	featureCheck(featureDigest($reverse) === featureDigest($all), 'chunk generation order independence ' . $seed);
	featureCheck(count(array_filter($all, static fn(string $state) : bool => $state === '101:0')) === 146, 'two 73-block iron-bar cages');
	featureCheck(($all['0:62:0'] ?? null) === '7:0' && ($all['1:62:0'] ?? null) === '0:0', 'inactive fountain center pillar and air');
	featureCheck(!isset($all['0:66:0']), 'spherical distance must not clear full 32-high cylinder');
	featureCheck(EndFeatures::PLATFORM_ORIGIN_Y === 49, 'natural configured platform origin Y49');
	featureCheck(($all['100:48:0'] ?? null) === '49:0' && ($all['100:49:0'] ?? null) === '0:0' && ($all['100:51:0'] ?? null) === '0:0' && !isset($all['100:52:0']), 'natural platform floor48 clear49..51');
}

// Feature-index ordering is FeatureSorter DFS + reverse, not registration order.
$terrain = new EndTerrainNoise(0);
$features = new EndFeatures(0, $terrain);
$plan = $features->plans(0,0);
featureCheck($plan['featureSeeds'] === ['end_island_decorated'=>0,'end_gateway_return'=>40000,'end_spike'=>40001,'chorus_plant'=>90000,'end_platform'=>100000], 'source global feature index/step mapping');
featureCheck($features->gateways(0,0) === [] && $plan['islands'] === [] && $plan['chorus'] === [], 'central biome excludes outer decorations');
// Exact source methods over the Java terrain oracle: no city intersections in
// these cases. Draft states retain logical IDs; populate maps legacy materials.
$decorations = json_decode(<<<'JSON'
[
{"kind":"island","chunkX":65,"chunkZ":-65,"decorationSeed":"1649227541098564320","origins":[[1049,68,-1026,6]],"blocks":535,"sha256":"3ef6e0ad6ee8647ed0b040887c5d59fee93c3b3e19a15d46fe518063a6e857c8"},
{"kind":"island","chunkX":65,"chunkZ":-60,"decorationSeed":"3414490750957670320","origins":[[1050,59,-951,6]],"blocks":453,"sha256":"8e1607597085146cc224854917f7e7732ab4aac64d622119a4f538d79cab4e6b"},
{"kind":"chorus","chunkX":65,"chunkZ":-48,"decorationSeed":"7651122454619524720","origins":[[1050,58,-760],[1053,58,-759],[1046,57,-763]],"blocks":42,"sha256":"ed5128daf97fa1bdea30a091adbb994c49f8bea0cdb94e4d81e11bb6ecfd0bb4"},
{"kind":"chorus","chunkX":65,"chunkZ":-47,"decorationSeed":"8004175096591345920","origins":[[1055,58,-751],[1047,57,-752],[1042,43,-752],[1050,61,-743]],"blocks":71,"sha256":"8b82af94a7b10453044a25a3d8e930215fb8579745e7a69a04aa66f25d04df26"},
{"kind":"gateway","chunkX":68,"chunkZ":-5,"decorationSeed":"2682957479093751024","origins":[[1089,67,-78]],"blocks":45,"sha256":"5bcc72e51277c35c40108cb178dccf7025c129eaf697f0b75e2271de3ca818bd"},
{"kind":"gateway","chunkX":106,"chunkZ":43,"decorationSeed":"4211061901320259216","origins":[[1698,64,698]],"blocks":45,"sha256":"0c0e4af5f55bfd4e8ce7dffa9ab03fd9c8d03eee853cf64e6c53c01c6f90d0e5"}
]
JSON, true, 512, JSON_THROW_ON_ERROR);
foreach($decorations as $case){
	$cx = $case['chunkX'];
	$cz = $case['chunkZ'];
	$plan = $features->plans($cx,$cz);
	$kind = $case['kind'];
	$names = ['island'=>['islands','islandBlocks'],'chorus'=>['chorus','chorusBlocks'],'gateway'=>['gateways','gatewayBlocks']];
	[$originKey,$blockKey] = $names[$kind];
	$actualOrigins = array_map(static fn(array $o) : array => $kind === 'island' ? [$o['x'],$o['y'],$o['z'],$o['size']] : [$o['x'],$o['y'],$o['z']], $plan[$originKey]);
	featureCheck($plan['decorationSeed'] === (int)$case['decorationSeed'], 'outer decoration seed ' . $cx . ':' . $cz);
	featureCheck($actualOrigins === $case['origins'], 'Java ' . $kind . ' origins ' . $cx . ':' . $cz);
	$states = [];
	foreach($plan[$blockKey] as $key => $block){ $states[$key] = $block[3] . ':' . $block[4]; }
	featureCheck(count($states) === $case['blocks'], 'Java ' . $kind . ' cells ' . $cx . ':' . $cz);
	featureCheck(featureDigest($states) === $case['sha256'], 'Java ' . $kind . ' block hash ' . $cx . ':' . $cz);
	$order = [];
	for($dx=-1;$dx<=1;++$dx){ for($dz=-1;$dz<=1;++$dz){ $order[]=[$cx+$dx,$cz+$dz]; } }
	$first = [];
	$second = [];
	foreach([$order,array_reverse($order)] as $index => $chunks){
		$fresh = new EndFeatures(0, $terrain);
		$out = [];
		foreach($chunks as [$targetX,$targetZ]){
			$chunk = new FeatureTestChunk($targetX,$targetZ);
			$fresh->populate($chunk);
			$out += $chunk->writes;
		}
		if($index === 0){ $first=$out; }else{ $second=$out; }
	}
	featureCheck(featureDigest($first) === featureDigest($second), 'outer neighbour generation order ' . $cx . ':' . $cz);
	foreach($first as $state){
		$id = (int)explode(':',$state)[0];
		featureCheck(!in_array($id,[200,209,240],true), 'unsupported state leaked to chunk');
	}
	foreach($plan[$blockKey] as [$x,$y,$z,$id,$data]){
		if($id === 240){ featureCheck(($first[$x . ':' . $y . ':' . $z] ?? null) === '159:10', 'visible chorus stem substitution'); }
		if($id === 200){ featureCheck(($first[$x . ':' . $y . ':' . $z] ?? null) === '159:2', 'visible chorus flower substitution'); }
		if($id === 209){ featureCheck(($first[$x . ':' . $y . ':' . $z] ?? null) === '89:0', 'visible return-gateway core substitution'); }
	}
}
foreach([[1055,58,-751,'end_highlands'],[1056,58,-751,'end_highlands'],[1042,43,-752,'end_highlands'],[1698,64,698,'end_highlands'],[-1024,64,0,'end_midlands'],[0,64,1024,'the_end'],[1050,59,-951,'small_end_islands']] as [$x,$y,$z,$expected]){
	featureCheck($features->biomeAt($x,$y,$z) === $expected, 'Java BiomeManager zoom ' . $x . ':' . $y . ':' . $z);
}
printf("PASS: %d End feature checks, seven core Java fixtures (360878 final cells), six outer-decoration Java fixtures, %.3fs\n", $checks, microtime(true)-$started);
