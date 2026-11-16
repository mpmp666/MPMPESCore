<?php
/** Read-only regression of the real /tp command with in-memory world/player doubles. */
namespace {
	error_reporting(E_ALL & ~E_DEPRECATED);
	define('pocketmine\\PATH', dirname(__DIR__) . '/');
	spl_autoload_register(function($class){
		$path = \pocketmine\PATH . 'src/' . str_replace('\\', '/', $class) . '.php';
		if(is_file($path)){ require_once $path; }
	});
	// Load the real CommandSender/permission contracts, Command, VanillaCommand and Position.
	interface_exists('pocketmine\\command\\CommandSender');
	trait TeleportTestSenderMethods{
		public $messages = [];
		public $allowed = true;
		public $testServer;
		public function getServer(){ return $this->testServer; }
		public function sendMessage($message){ $this->messages[] = $message; }
		public function hasPermission($name){ return $this->allowed && $name === 'pocketmine.command.teleport'; }
		public function isPermissionSet($name){ return true; }
		public function addAttachment(\pocketmine\plugin\Plugin $plugin, $name = null, $value = null){}
		public function removeAttachment(\pocketmine\permission\PermissionAttachment $attachment){}
		public function recalculatePermissions(){}
		public function getEffectivePermissions(){ return []; }
		public function isOp(){ return true; }
		public function setOp($value){}
	}
}
namespace pocketmine\level {
	class Level{
		public $name;
		public $spawn;
		public $safeSpawnCalls = 0;
		public function __construct($name){ $this->name = $name; $this->spawn = new \pocketmine\math\Vector3(100.5, 70, -20.5); }
		public function getFolderName(){ return $this->name; }
		public function getSafeSpawn(){ ++$this->safeSpawnCalls; return $this->spawn; }
	}
}
namespace pocketmine {
	class Player extends \pocketmine\level\Location implements \pocketmine\command\CommandSender{
		use \TeleportTestSenderMethods;
		public $name;
		public $calls = [];
		public $teleportResult = true;
		public function __construct($server, $name, $level){ parent::__construct(10, 64, -30, 45, 12, $level); $this->testServer = $server; $this->name = $name; }
		public function getName(){ return $this->name; }
		public function teleport(\pocketmine\math\Vector3 $to, $yaw = null, $pitch = null){ $this->calls[] = [$to, $yaw, $pitch]; return $this->teleportResult; }
	}
}
namespace {
	use pocketmine\command\CommandSender;
	use pocketmine\command\defaults\TeleportCommand;
	use pocketmine\event\TranslationContainer;
	use pocketmine\level\Level;
	use pocketmine\level\Position;
	use pocketmine\Player;
	final class TeleportTestConsole implements CommandSender{
		use TeleportTestSenderMethods;
		public function __construct($server){ $this->testServer = $server; }
		public function getName(){ return 'CONSOLE'; }
	}
	final class TeleportTestServer extends \pocketmine\Server{
		public $online = [];
		public $loaded = [];
		public $saved = [];
		public $loads = [];
		public $checks = [];
		public $failLoad = false;
		public $throwLoad = false;
		public function __construct(){}
		public function getOnlinePlayers(){ return $this->online; }
		public function getLevels(){ return $this->loaded; }
		public function isLevelGenerated($name){ $this->checks[] = $name; return isset($this->saved[$name]); }
		public function loadLevel($name){
			$this->loads[] = $name;
			if($this->throwLoad){ throw new \RuntimeException('test load error'); }
			if($this->failLoad){ return false; }
			$this->loaded[$name] = $this->saved[$name]; return true;
		}
		public function generateLevel($name, $seed = null, $generator = null, $options = []){ throw new \RuntimeException('Teleport must never generate a world'); }
		public function getPluginManager(){ return $this; }
		public function getPermissionSubscriptions($permission){ return []; }
		public function getLanguage(){ return $this; }
		public function get($key){ return $key; }
	}
	$assertions = 0;
	function verifyTp($condition, $message){ global $assertions; ++$assertions; if(!$condition){ throw new \RuntimeException($message); } }
	function fixtureTp(){
		$server = new TeleportTestServer();
		$server->loaded = ['overworld' => new Level('overworld'), 'end' => new Level('end'), 'Bob' => new Level('Bob'), 'Bobby' => new Level('Bobby'), '123' => new Level('123')];
		$server->saved = ['nether' => new Level('nether')];
		$alice = new Player($server, 'Alice', $server->loaded['overworld']);
		$bob = new Player($server, 'Bob', $server->loaded['end']);
		$bob->yaw = 91.5; $bob->pitch = -12.5;
		$server->online = [$alice, $bob];
		return [$server, $alice, $bob, new TeleportTestConsole($server)];
	}
	$command = new TeleportCommand('tp');
	verifyTp($command->getPermission() === 'pocketmine.command.teleport', 'Permission changed');
	// Expected destinations are independently enumerated; all parsing runs in the real command.
	$cases = [
		['Bob', false, 'Alice', 'player'], ['Al Bob', false, 'Alice', 'player'], ['Alice Bob', true, 'Alice', 'player'],
		['1 2 3', false, 'Alice', 'overworld', [1,2,3], [45,12]],
		['Bob 1 2 3', false, 'Bob', 'end', [1,2,3], [91.5,-12.5]],
		['1 2 3 90.25 -20.5', false, 'Alice', 'overworld', [1,2,3], [90.25,-20.5]],
		['Bob 1 2 3 90.25 -20.5', true, 'Bob', 'end', [1,2,3], [90.25,-20.5]],
		['~ ~ ~', false, 'Alice', 'overworld', [10,64,-30], [45,12]],
		['~1.5 ~-2 ~+3 ~15.25 ~-2.5', false, 'Alice', 'overworld', [11.5,62,-27], [60.25,9.5]],
		['Bob ~1 ~-1 ~2 ~ ~', true, 'Bob', 'end', [11,63,-28], [91.5,-12.5]],
		['+1e2 .5 -2.5e1', false, 'Alice', 'overworld', [100,.5,-25], [45,12]],
		['-30000000 0 30000000', false, 'Alice', 'overworld', [-30000000,0,30000000], [45,12]],
		['0 127 0', false, 'Alice', 'overworld', [0,127,0], [45,12]],
		['world end', false, 'Alice', 'end', [100.5,70,-20.5], [45,12]],
		['Alice world end', true, 'Alice', 'end', [100.5,70,-20.5], [45,12]],
		['world end 1 2 3', false, 'Alice', 'end', [1,2,3], [45,12]],
		['world end 1 2 3 35.5 -4', false, 'Alice', 'end', [1,2,3], [35.5,-4]],
		['Bob world overworld ~2 ~ ~-3 ~1 ~-1', true, 'Bob', 'overworld', [12,64,-33], [92.5,-13.5]],
		['end', false, 'Alice', 'end', [100.5,70,-20.5], [45,12]],
		['Alice end', true, 'Alice', 'end', [100.5,70,-20.5], [45,12]],
		['end 1 2 3', false, 'Alice', 'end', [1,2,3], [45,12]],
		['end 1 2 3 5 6', false, 'Alice', 'end', [1,2,3], [5,6]],
		['Alice end 1 2 3', true, 'Alice', 'end', [1,2,3], [45,12]],
		['Alice end 1 2 3 5 6', true, 'Alice', 'end', [1,2,3], [5,6]],
		['1 2 3 end', false, 'Alice', 'end', [1,2,3], [45,12]],
		['Alice 1 2 3 end', true, 'Alice', 'end', [1,2,3], [45,12]],
		['world Bob', false, 'Alice', 'Bob', [100.5,70,-20.5], [45,12]],
		['world 123 1 2 3', false, 'Alice', '123', [1,2,3], [45,12]],
		['world nether', false, 'Alice', 'nether', [100.5,70,-20.5], [45,12]],
		['Alice nether 1 2 3', true, 'Alice', 'nether', [1,2,3], [45,12]],
		['Bo', false, 'Alice', 'player'], ['Alice Bo', true, 'Alice', 'player'],
		['Bob 1 2 3', false, 'Bob', 'end', [1,2,3], [91.5,-12.5]]
	];
	foreach($cases as $case){
		list($server,$alice,$bob,$console) = fixtureTp();
		$sender = $case[1] ? $console : $alice;
		verifyTp($command->execute($sender, 'tp', explode(' ', $case[0])) === true, 'Command result '.$case[0]);
		$target = $case[2] === 'Alice' ? $alice : $bob;
		verifyTp(count($target->calls) === 1, 'No teleport for '.$case[0].': '.json_encode($sender->messages));
		list($to,$yaw,$pitch) = $target->calls[0];
		if($case[3] === 'player'){
			verifyTp($to === $bob && $yaw === null && $pitch === null, 'Player destination/orientation lost '.$case[0]);
		}else{
			verifyTp($to instanceof Position && $to->getLevel()->getFolderName() === $case[3], 'Destination level lost '.$case[0]);
			verifyTp([$to->x,$to->y,$to->z] == $case[4], 'Wrong coordinates '.$case[0]);
			verifyTp([$yaw,$pitch] == $case[5], 'Wrong orientation '.$case[0]);
			verifyTp($to->getLevel()->safeSpawnCalls === (strpos($case[0], 'nether') !== false && count(explode(' ', $case[0])) === 2 ? 1 : ($case[4] === [100.5,70,-20.5] ? 1 : 0)), 'Safe spawn call differs '.$case[0]);
		}
		verifyTp($server->loads === (strpos($case[0], 'nether') !== false ? ['nether'] : []), 'World loading differs '.$case[0]);
	}
	$invalid = [
		'', '1 2', 'world', 'world end 1', 'world end 1 2', 'world end 1 2 3 90',
		'Alice world end 1 2 3 90', 'Missing Bob', 'Missing world end', 'Missing 1 2 3',
		'nonexistent', 'Alice nonexistent', 'world nonexistent', 'world nonexistent 1 2 3',
		'1 2 3 nonexistent', 'Alice 1 2 3 nonexistent', 'nonexistent 1 2 3',
		'abc 64 0', '~bad 64 0', '1 64nope 0', '1 64 0garbage', '1 64 0 ~oops 0',
		'1 64 0 0 bad', '1 64 0 1e309 0', '1 64 0 INF 0', '1 64 0 NAN 0',
		'1e309 64 0', '1 -1 0', '1 128 0', '30000001 64 0', '-30000001 64 0',
		'0 64 30000001', '~30000000 64 0', '0 ~64 0', '0 64 ~-30000000',
		'world end bad 64 0', 'Alice world end 1 64 bad', 'world nether 0 200 0',
		'Alice 1 2 3 90', '1 2 3 90 0 end', 'Alice world end 1 2 3 4 5 extra'
	];
	foreach($invalid as $text){
		list($server,$alice,$bob,$console) = fixtureTp();
		$command->execute($alice, 'tp', $text === '' ? [] : explode(' ', $text));
		verifyTp(!$alice->calls && !$bob->calls, 'Invalid command teleported: '.$text);
		verifyTp(count($alice->messages) > 0, 'Invalid command gave no error: '.$text);
		verifyTp(!$server->loads, 'Invalid command loaded world: '.$text);
	}
	foreach(['Bob','1 2 3','1 2 3 4 5','world end','end','end 1 2 3'] as $text){
		list($server,$alice,$bob,$console) = fixtureTp();
		$command->execute($console, 'tp', explode(' ', $text));
		verifyTp(!$alice->calls && !$bob->calls && count($console->messages) > 0, 'Console self form accepted '.$text);
	}
	foreach(['failLoad','throwLoad'] as $flag){
		list($server,$alice,$bob,$console) = fixtureTp(); $server->$flag = true;
		$command->execute($alice, 'tp', ['world','nether']);
		verifyTp(!$alice->calls && $server->loads === ['nether'], 'Load failure teleported');
		verifyTp(strpos((string) end($alice->messages), 'Could not load world') !== false, 'Load failure unclear');
	}
	foreach([false, new \pocketmine\math\Vector3(0,128,0), new \pocketmine\math\Vector3(INF,64,0)] as $badSpawn){
		list($server,$alice,$bob,$console) = fixtureTp(); $server->loaded['end']->spawn = $badSpawn;
		$command->execute($alice, 'tp', ['world','end']);
		verifyTp(!$alice->calls, 'Invalid safe spawn teleported');
	}
	list($server,$alice,$bob,$console) = fixtureTp(); $alice->allowed = false;
	$command->execute($alice, 'tp', ['world','nether']);
	verifyTp(!$alice->calls && !$server->loads && count($alice->messages) === 1, 'Permission denial has effects');
	foreach([['world','end'], ['Bob']] as $args){
		list($server,$alice,$bob,$console) = fixtureTp(); $alice->teleportResult = false;
		$command->execute($alice, 'tp', $args);
		verifyTp(count($alice->messages) === 1 && strpos((string) $alice->messages[0], 'Teleport failed') !== false, 'Failed teleport announced success');
	}
	// Server's actual getPlayer() prefix resolution wins over identically named worlds.
	list($server,$alice,$bob,$console) = fixtureTp(); $server->loaded['Bo'] = new Level('Bo');
	$command->execute($alice, 'tp', ['Bo']);
	verifyTp($alice->calls[0][0] === $bob && !$server->checks, 'Prefix player/world conflict lost');
	echo 'Teleport command: PASS ('.count($cases).' valid forms, '.count($invalid).' invalid forms, '.$assertions.' assertions)' . PHP_EOL;
}
