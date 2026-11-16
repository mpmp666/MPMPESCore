<?php
/** Read-only CLI regression for the real natural-start engine and /locate command. */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
define('pocketmine\\PATH', dirname(__DIR__).'/');
define('pocketmine\\DEBUG', 0);
define('ENDIANNESS', 1);
define('INT32_MASK', 0xffffffff);
spl_autoload_register(static function(string $class) : void{
	$path = \pocketmine\PATH.'src/'.str_replace('\\', '/', $class).'.php';
	if(is_file($path)){ require_once $path; }
});
require_once \pocketmine\PATH.'src/pocketmine/pthreads_shim.php';

use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\level\SimpleChunkManager;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\normal\Normal;
use pocketmine\level\generator\ender\Ender;
use pocketmine\level\generator\ender\EndCityGenerator;
use pocketmine\level\generator\structure\StructureLocator;
use pocketmine\level\generator\structure\StructureLocateTask;
use pocketmine\utils\Random;

function checkLocate(bool $ok, string $message) : void{
	if(!$ok){ throw new RuntimeException($message); }
}
function locateEngine(int $seed, array $settings = [], string $class = Normal::class) : array{
	$manager = new SimpleChunkManager($seed);
	$generator = new $class(['structure-settings'=>$settings]);
	$generator->init($manager, new Random($seed));
	return [$generator->structureEngine($manager), $manager, $generator];
}
function locateSummary(array $result) : array{
	$plan = $result['plan'];
	$b = $plan['bounds'];
	return [
		'origin'=>$result['origin'], 'status'=>$result['status'], 'variant'=>$plan['variant'] ?? '',
		'anchor'=>[$plan['x'], $plan['y'], $plan['z']], 'region'=>$result['region'],
		'bounds'=>[$plan['x']+$b[0], $plan['y']+$b[1], $plan['z']+$b[2], $plan['x']+$b[3], $plan['y']+$b[4], $plan['z']+$b[5]],
		'distanceSquared'=>$result['distanceSquared'], 'horizontalDistance'=>sqrt($result['distanceSquared']),
		'regionsChecked'=>$result['regionsChecked'], 'regionsEnumerated'=>$result['regionsEnumerated'],
		'regionsRemaining'=>$result['regionsRemaining'], 'remainingLowerBoundSquared'=>$result['remainingLowerBoundSquared'],
		'outsideLowerBoundSquared'=>$result['outsideLowerBoundSquared'], 'enumeratedExhausted'=>$result['enumeratedExhausted'], 'proof'=>$result['proof']
	];
}
Block::init(); Biome::init(); Item::init();
$started = microtime(true);
// The public regression needs no saved world. An optional --world check
// observes an existing level.dat without changing the stable reference cases.
$options = getopt('', ['world:']);
$worldPath = $options['world'] ?? null;
$worldBefore = null;
if($worldPath !== null){
	checkLocate(is_string($worldPath) && is_file($worldPath), 'Missing --world level.dat');
	$worldBefore = hash_file('sha256', $worldPath);
	$nbt = new \pocketmine\nbt\NBT(\pocketmine\nbt\NBT::BIG_ENDIAN);
	$nbt->readCompressed(file_get_contents($worldPath));
	$data = $nbt->getData()['Data'];
	$savedSeed = (int) $data['RandomSeed'];
	$savedSpawn = [(int) $data['SpawnX'], (int) $data['SpawnY'], (int) $data['SpawnZ']];
	[$savedEngine] = locateEngine($savedSeed);
	$savedResult = (new StructureLocator($savedEngine, $savedSeed, 'overworld'))->locate('village', $savedSpawn[0], $savedSpawn[2]);
	echo 'Observed world: '.json_encode(['seed'=>$savedSeed, 'spawn'=>$savedSpawn, 'status'=>$savedResult['status'], 'complete'=>$savedResult['complete']])."\n";
	if($savedResult['plan'] !== null){ echo 'Observed village: '.json_encode(locateSummary($savedResult))."\n"; }
}
$seed = 1794737014;
$spawn = [128, 70, 128];
echo 'Reference world: '.json_encode(['seed'=>$seed, 'spawn'=>$spawn])."\n";
[$engine, $manager, $generator] = locateEngine($seed);
$locator = new StructureLocator($engine, $seed, 'overworld');
foreach([[$spawn[0], $spawn[2]], [0, 0]] as [$x, $z]){
	$result = $locator->locate('village', $x, $z);
	checkLocate($result['status'] === 'found' && $result['complete'], 'Current-world village search was not proven');
	echo 'Actual village: '.json_encode(locateSummary($result), JSON_UNESCAPED_SLASHES)."\n";
}
// Independent exhaustive scan of region anchors; terrain is necessary only inside the
// returned distance. More distant anchors cannot beat the result, regardless of terrain.
function referenceLocate($engine, int $seed, string $name, int $x, int $z, int $spacing, int $separation, int $distanceSquared) : array{
	$request = StructureLocator::normalize($name);
	$centerX = (int) floor($x / (16 * $spacing));
	$centerZ = (int) floor($z / (16 * $spacing));
	$best = null; $bestKey = null; $tested = 0; $enumerated = 0;
	for($rx = $centerX - 8; $rx <= $centerX + 8; ++$rx){
		for($rz = $centerZ - 8; $rz <= $centerZ + 8; ++$rz){
			++$enumerated;
			$hash = hexdec(substr(hash('sha256', $seed.':'.$request['family'].':'.$rx.':'.$rz), 0, 7));
			$ax = ($rx * $spacing + $hash % ($spacing - $separation)) * 16 + 8;
			$az = ($rz * $spacing + intdiv((int) $hash, 97) % ($spacing - $separation)) * 16 + 8;
			$d = ($ax - $x) * ($ax - $x) + ($az - $z) * ($az - $z);
			checkLocate(StructureLocator::regionLowerBoundSquared($rx, $rz, $x, $z, $spacing, $separation) <= $d, 'Region bound overestimated negative-coordinate anchor');
			if($d > $distanceSquared){ continue; }
			++$tested;
			$plan = $engine->start($request['family'], $rx, $rz);
			if($plan === null || ($request['variant'] !== '' && $plan['variant'] !== $request['variant'])){ continue; }
			$key = [$d, $rx, $rz];
			if($bestKey === null || $key < $bestKey){ $best = $plan; $bestKey = $key; }
		}
	}
	// At least seven full region widths separate the origin and the scanned exterior.
	checkLocate((7 * $spacing * 16) ** 2 > $distanceSquared, 'Reference brute-force window too small');
	return ['plan'=>$best, 'key'=>$bestKey, 'enumerated'=>$enumerated, 'tested'=>$tested];
}
foreach([
	[$seed, 128, 128, [], 'village'],
	[$seed, 0, 0, [], 'minecraft:village'],
	[123456, -1, -449, [], 'village'],
	[-987654321, -897, 31, [], 'village'],
	[$seed, -449, -897, ['families'=>['village'=>['spacing'=>12]]], 'village']
] as [$caseSeed, $x, $z, $settings, $name]){
	[$caseEngine, $caseManager] = locateEngine($caseSeed, $settings);
	$caseLocator = new StructureLocator($caseEngine, $caseSeed, 'normal', $settings);
	$result = $caseLocator->locate($name, $x, $z);
	checkLocate($result['status'] === 'found', 'Reference case did not prove nearest');
	$def = StructureLocator::definition('village', 'overworld', $settings);
	$reference = referenceLocate($caseEngine, $caseSeed, $name, $x, $z, $def[0], $def[1], $result['distanceSquared']);
	checkLocate($result['plan'] === $reference['plan'], 'Best-first result differs from reference brute force');
	checkLocate($result['region'] === array_slice($reference['key'], 1), 'Region tie order differs from brute force');
	checkLocate($caseManager->getChunk(0, 0) === null, 'Locate generated terrain in the caller manager');
	checkLocate($result['remainingLowerBoundSquared'] >= $result['distanceSquared'], 'Invalid nearest proof');
	echo 'Brute force: '.json_encode(['seed'=>$caseSeed, 'origin'=>[$x,$z], 'spacing'=>$def[0], 'anchor'=>[$result['plan']['x'],$result['plan']['y'],$result['plan']['z']], 'regionsChecked'=>$result['regionsChecked'], 'referenceEnumerated'=>$reference['enumerated'], 'referenceTerrainChecked'=>$reference['tested']])."\n";
}
foreach([
	'minecraft:VILLAGE'=>['village',''], 'mansion'=>['woodland_mansion',''],
	'outpost'=>['pillager_outpost',''], 'bastion_remnant'=>['bastion',''], 'endcity'=>['end_city',''],
	'ocean_ruins'=>['underwater_ruin',''], 'ocean_ruin'=>['underwater_ruin',''],
	'village_plains'=>['village','plains'], 'desert_village'=>['village','desert'],
	'minecraft:village/savanna'=>['village','savanna'], 'village_snowy'=>['village','snowy'], 'village_taiga'=>['village','taiga']
] as $name => $expected){
	checkLocate(array_values(StructureLocator::normalize($name)) === $expected, 'Wrong alias '.$name);
}
$variant = $locator->locate('minecraft:village_savanna', 128, 128);
checkLocate($variant['status'] === 'found' && $variant['plan']['variant'] === 'savanna', 'Village variant was not respected');
$plains = $locator->locate('minecraft:village_plains', 128, 128);
checkLocate($plains['status'] === 'found' && $plains['plan']['variant'] === 'plains' && $plains['regionsChecked'] > 1, 'Variant search returned nearer wrong subtype');
$plainsReference = referenceLocate($engine, $seed, 'village_plains', 128, 128, 28, 10, $plains['distanceSquared']);
checkLocate($plains['plan'] === $plainsReference['plan'], 'Village variant differs from exhaustive candidates');
checkLocate(StructureLocator::requestError('village', 'nether') !== null, 'Wrong dimension accepted');
checkLocate(StructureLocator::requestError('village', 'flat') !== null, 'Unsupported generator accepted');
checkLocate(StructureLocator::requestError('nonsense', 'overworld') !== null, 'Unknown family accepted');
checkLocate(StructureLocator::requestError('village', 'overworld', ['families'=>['village'=>['enabled'=>false]]]) !== null, 'Disabled family accepted');
checkLocate(StructureLocator::definition('village', 'overworld', ['families'=>['village'=>['spacing'=>1]]])[0] === 12, 'Minimum spacing clamp differs from engine');
checkLocate(StructureLocator::definition('village', 'overworld', ['families'=>['village'=>['spacing'=>999]]])[0] === 128, 'Maximum spacing clamp differs from engine');
$partial = $locator->locate('village_desert', 128, 128, 1);
checkLocate($partial['status'] === 'incomplete' && $partial['plan'] === null && $partial['proof'] === 'budget', 'Budget exhaustion claimed absence');
$boundary = $locator->locate('village', 0, 0, 256, 0);
checkLocate($boundary['status'] === 'incomplete' && $boundary['plan'] !== null && $boundary['proof'] === 'boundary', 'Exterior bound ignored');
$endOverride = ['families'=>['end_city'=>['spacing'=>128]]];
$endDefinition = StructureLocator::definition('end_city', 'end', $endOverride);
checkLocate(array_slice($endDefinition, 0, 2) === [20, 11], 'End City source placement allowed spacing override');
foreach([$seed, 123456, -987654321] as $endSeed){
	for($rx = -4; $rx <= 4; ++$rx){
		for($rz = -4; $rz <= 4; ++$rz){
			$candidate = EndCityGenerator::candidate($endSeed, $rx, $rz);
			$anchor = StructureLocator::anchor($endSeed, 'end_city', $rx, $rz, 20, 11);
			checkLocate($anchor === [$candidate['x'], $candidate['z']], 'End City exact candidate anchor mismatch');
			checkLocate(($anchor[0] - 7) % 16 === 0 && ($anchor[1] - 7) % 16 === 0, 'End City source +7 anchor lost');
			$bound = StructureLocator::regionLowerBoundSquared($rx, $rz, -319, 327, 20, 11, 7);
			checkLocate($bound <= ($anchor[0] + 319) ** 2 + ($anchor[1] - 327) ** 2, 'End City region bound overestimated exact anchor');
		}
	}
}
$outsideBound = new ReflectionMethod(StructureLocator::class, 'outsideLowerBoundSquared');
$outsideBound->setAccessible(true);
checkLocate($outsideBound->invoke(null, 0, 0, 0, 0, 170, 170, 20, 11, 7) === 157 ** 2, 'End City exterior bound used +8 instead of +7');
[$endEngine] = locateEngine($seed, $endOverride, Ender::class);
$endLocator = new StructureLocator($endEngine, $seed, 'end', $endOverride);
$city = $endLocator->locate('endcity', 1200, 1200);
checkLocate($city['status'] === 'found' && $city['complete'], 'End City nearest search failed after source placement integration');
$candidate = EndCityGenerator::candidate($seed, $city['region'][0], $city['region'][1]);
checkLocate([$city['plan']['x'], $city['plan']['z']] === [$candidate['x'], $candidate['z']], 'End City search did not use source placement anchor');
$referenceCity = null; $referenceCityKey = null; $referenceCityChecked = 0;
for($rx = -5; $rx <= 11; ++$rx){
	for($rz = -5; $rz <= 11; ++$rz){
		$candidate = EndCityGenerator::candidate($seed, $rx, $rz);
		$d = ($candidate['x'] - 1200) ** 2 + ($candidate['z'] - 1200) ** 2;
		if($d > $city['distanceSquared']){ continue; }
		++$referenceCityChecked;
		$plan = $endEngine->start('end_city', $rx, $rz);
		if($plan !== null && ($referenceCityKey === null || [$d,$rx,$rz] < $referenceCityKey)){
			$referenceCity = $plan; $referenceCityKey = [$d,$rx,$rz];
		}
	}
}
checkLocate((7 * 320) ** 2 > $city['distanceSquared'], 'End City reference window too small');
checkLocate($city['plan'] === $referenceCity && $city['region'] === array_slice($referenceCityKey, 1), 'End City nearest differs from source candidate brute force');
echo 'Source End City: '.json_encode(locateSummary($city))."\n";
$finite = $endLocator->locate('end_exit_portal', -10000, -10000);
checkLocate($finite['status'] === 'found' && $finite['complete'] && $finite['regionsEnumerated'] === 1 && $finite['enumeratedExhausted'], 'Finite End centre search was not exhaustive');
checkLocate([$finite['plan']['x'], $finite['plan']['z']] === [-4, -4], 'Fountain root anchor was not centred at zero');
checkLocate(StructureLocator::requestError('end_gateway', 'end') !== null, 'End gateway decorations accepted as region structures');
final class LocateEmptyEnd extends Normal{
	// A finite End start cannot be built without an End terrain provider.
	public function getName() : string{ return 'Ender'; }
}
[$emptyEnd] = locateEngine($seed, [], LocateEmptyEnd::class);
$absent = (new StructureLocator($emptyEnd, $seed, 'end'))->locate('end_exit_portal', 0, 0);
checkLocate($absent['status'] === 'absent' && $absent['complete'] && $absent['proof'] === 'exhausted', 'Finite exhausted search not distinguished from incomplete');
echo 'Aliases, dimensions, spacing clamps, budget and exterior proof: PASS'."\n";

// Exercise the real command with an in-memory scheduler; never boot or restart a server.
final class LocateTestServer{
	public $level;
	public array $settings = [];
	public array $tasks = [];
	public function getDefaultLevel(){ return $this->level; }
	public function getAdvancedProperty($key, $default = null){ return $this->settings; }
	public function getScheduler(){ return $this; }
	public function scheduleAsyncTask($task){ $this->tasks[] = $task; }
}
final class LocateTestLevel{
	public string $generator = 'normal';
	public function getId(){ return 42; }
	public function getProvider(){ return $this; }
	public function getGenerator(){ return $this->generator; }
	public function getSpawnLocation(){ return new \pocketmine\math\Vector3(128, 70, 128); }
}
final class LocateTestConsole implements \pocketmine\command\CommandSender{
	public $server;
	public array $messages = [];
	public bool $allowed = true;
	public function __construct($server){ $this->server = $server; }
	public function getServer(){ return $this->server; }
	public function getName(){ return 'CONSOLE'; }
	public function sendMessage($message){ $this->messages[] = (string) $message; }
	public function isPermissionSet($name){ return true; }
	public function hasPermission($name){ return $this->allowed; }
	public function addAttachment(\pocketmine\plugin\Plugin $plugin, $name = null, $value = null){}
	public function removeAttachment(\pocketmine\permission\PermissionAttachment $attachment){}
	public function recalculatePermissions(){}
	public function getEffectivePermissions(){ return []; }
	public function isOp(){ return true; }
	public function setOp($value){}
}
final class LocateTestPlayer extends \pocketmine\Player{
	public $testServer;
	public $testLevel;
	public array $testMessages = [];
	public function __construct($server, $level){ $this->testServer = $server; $this->testLevel = $level; $this->x = -17.5; $this->z = 61.9; }
	public function __destruct(){} // The fixture has no network connection to close.
	public function getServer(){ return $this->testServer; }
	public function getLevel(){ return $this->testLevel; }
	public function hasPermission($name) : bool{ return true; }
	public function sendMessage($message){ $this->testMessages[] = (string) $message; }
	public function getName(){ return 'LocateTestPlayer'; }
}
$testServer = new LocateTestServer();
$testServer->level = new LocateTestLevel();
$console = new LocateTestConsole($testServer);
$command = new \pocketmine\command\defaults\LocateCommand('locate');
checkLocate($command->getAliases() === [], 'Pointless locatebiome alias remains');
checkLocate($command->getPermission() === 'pocketmine.command.locate', 'Wrong standalone permission');
checkLocate($command->execute($console, 'locate', ['village']) === true, 'Short command rejected');
$task = array_pop($testServer->tasks);
checkLocate($task->x === 128 && $task->z === 128, 'Console origin was not actual level spawn');
checkLocate($command->execute($console, 'locate', ['structure','minecraft:village','-1.2','-449.9']) === true, 'Modern coordinate command rejected');
$task = array_pop($testServer->tasks);
checkLocate($task->family === 'village' && $task->x === -2 && $task->z === -450, 'Explicit negative coordinate origin incorrect');
checkLocate($command->execute($console, 'locate', ['village_savanna']) === true, 'Village variant command rejected');
checkLocate(array_pop($testServer->tasks)->family === 'village_savanna', 'Village variant task lost filter');
$player = new LocateTestPlayer($testServer, $testServer->level);
checkLocate($command->execute($player, 'locate', ['village']) === true, 'Player command rejected');
$task = array_pop($testServer->tasks);
checkLocate($task->x === -18 && $task->z === 61 && $task->playerName === 'LocateTestPlayer', 'Player origin incorrect');
checkLocate($command->execute($player, 'locate', ['structure','village','0','-32']) === true, 'Player coordinate override rejected');
$task = array_pop($testServer->tasks);
checkLocate($task->x === 0 && $task->z === -32, 'Player coordinate override ignored');
foreach([[], ['structure'], ['biome','plains'], ['village','10'], ['village','0','0','extra'], ['village','x','2'], ['village','NaN','0'], ['village','1e5','0'], ['village','30000001','0'], ['village','~-2','0'], ['nonsense']] as $bad){
	$count = count($console->messages);
	checkLocate($command->execute($console, 'locate', $bad) === false, 'Invalid command accepted: '.json_encode($bad));
	checkLocate($testServer->tasks === [] && count($console->messages) > $count, 'Invalid command queued task or lacked error');
}
$testServer->level->generator = 'nether';
checkLocate($command->execute($console, 'locate', ['village']) === false && $testServer->tasks === [], 'Wrong-dimension command queued task');
$testServer->level->generator = 'normal';
$testServer->settings = ['enabled'=>false];
checkLocate($command->execute($console, 'locate', ['village']) === false && $testServer->tasks === [], 'Disabled structures queued task');
$testServer->settings = [];
$testServer->level = null;
checkLocate($command->execute($console, 'locate', ['village']) === false && $testServer->tasks === [], 'Missing default level accepted');
$testServer->level = new LocateTestLevel();
$console->allowed = false;
$command->execute($console, 'locate', ['village']);
checkLocate($testServer->tasks === [], 'Permission denial queued task');

// Execute both the new and legacy constructor forms through the real worker body.
$store = ['generation.level42.manager'=>$manager, 'generation.level42.generator'=>$generator];
foreach([new StructureLocateTask(42, 'minecraft:village', 128, 128, '', true), new StructureLocateTask(42, 'village', 128, 128)] as $task){
	$task->onRun();
	$taskResult = $task->getResult();
	checkLocate($taskResult['status'] === 'found' && $taskResult['plan']['x'] === 216, 'Worker/legacy constructor did not find proven nearest');
	checkLocate($taskResult['plan']['bounds'] === [170,62,3,280,75,111], 'Worker world bounds incorrect');
	checkLocate(strpos(StructureLocateTask::message($taskResult), '113.70') !== false, 'Worker message omitted horizontal distance');
	$task->cleanObject();
}
checkLocate(strpos(StructureLocateTask::message($partial), '不能断言不存在') !== false, 'Partial message implies absence');
final class LocateTestThread extends \pmmp\thread\Thread{
	public $task;
	public function __construct($task){ $this->task = $task; }
	public function run() : void{
		global $store;
		Block::init(); Biome::init(); Item::init();
		[$workerEngine, $workerManager, $workerGenerator] = locateEngine(1794737014, ['families'=>['village'=>['spacing'=>12]]]);
		$store = ['generation.level42.manager'=>$workerManager, 'generation.level42.generator'=>$workerGenerator];
		$this->task->run();
	}
}
$threadTask = new StructureLocateTask(42, 'village', -449, -897, '', true);
$thread = new LocateTestThread($threadTask);
$thread->start(\pmmp\thread\Thread::INHERIT_ALL);
$thread->join();
$threadResult = $threadTask->getResult();
checkLocate($threadTask->isFinished() && !$threadTask->isCrashed(), 'Locate task failed inside real OS thread');
checkLocate($threadResult['status'] === 'found' && $threadResult['plan']['x'] === -360 && $threadResult['plan']['z'] === -760, 'Worker lost configured spacing');
$threadTask->cleanObject();
echo 'Command origins, syntax/errors, permission, scalar worker cleanup, legacy task and real thread: PASS'."\n";
if($worldPath !== null){checkLocate(hash_file('sha256', $worldPath) === $worldBefore, 'World metadata changed');}
echo 'PASS '.round(microtime(true)-$started, 3).'s, peak '.round(memory_get_peak_usage(true)/1048576, 1)."MiB\n";
