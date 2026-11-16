<?php
/** Standalone source-derived enchanting regression: real Item/NBT/inventory code, no server/world writes. */
namespace {
	error_reporting(E_ALL & ~E_DEPRECATED);
	define('pocketmine\\PATH', dirname(__DIR__) . '/');
	spl_autoload_register(function($class){ $path = \pocketmine\PATH . 'src/' . str_replace('\\', '/', $class) . '.php'; if(is_file($path)){ require_once $path; } });
	interface_exists('pocketmine\\command\\CommandSender');
}
namespace pocketmine\level {
	class Level{
		public $blocks = []; public $drops = [];
		public function getBlockIdAt(int $x, int $y, int $z) : int{ return $this->blocks["$x:$y:$z"] ?? 0; }
		public function dropItem($position, $item){ $this->drops[] = $item; }
	}
}
namespace pocketmine {
	class Player implements \pocketmine\command\CommandSender{
		public $spawned = true; public $level = 50; public $creative = false; public $cancelExperience = false; public $inventory; public $server; public $packets = []; public $messages = [];
		public function __construct($server){ $this->server = $server; $this->inventory = new \EnchantTestInventory(); }
		public function getInventory(){ return $this->inventory; }
		public function getExpLevel(){ return $this->level; }
		public function setExpLevel(int $level){ if($this->cancelExperience){ return false; } $this->level = $level; return true; }
		public function isCreative(){ return $this->creative; }
		public function isSpectator(){ return false; }
		public function updateExperience(){}
		public function getWindowId($inventory){ return 4; }
		public function dataPacket($packet){ $this->packets[] = $packet; }
		public function removeWindow($inventory){}
		public function getServer(){ return $this->server; }
		public function getName(){ return 'Fixture'; }
		public function sendMessage($message){ $this->messages[] = $message; }
		public function hasPermission($name){ return true; }
		public function isPermissionSet($name){ return true; }
		public function addAttachment(\pocketmine\plugin\Plugin $plugin, $name = null, $value = null){}
		public function removeAttachment(\pocketmine\permission\PermissionAttachment $attachment){}
		public function recalculatePermissions(){}
		public function getEffectivePermissions(){ return []; }
		public function isOp(){ return true; }
		public function setOp($value){}
	}
}
namespace {
	use pocketmine\item\Item;
	use pocketmine\item\Dye;
	use pocketmine\item\enchantment\Enchantment as E;
	use pocketmine\item\enchantment\EnchantmentLevelTable as Table;
	use pocketmine\inventory\AnvilInventory as Anvil;
	use pocketmine\inventory\EnchantInventory;
	use pocketmine\level\Position;
	use pocketmine\nbt\tag\CompoundTag;
	use pocketmine\nbt\tag\IntTag;
	use pocketmine\level\generator\ender\noise\JavaRandom;

	final class EnchantTestInventory{
		public $items = []; public $hand; public $full = false; public $cancelWrites = false;
		public function getItemInHand(){ return copyEnch($this->hand ?? Item::get(0,0,0)); }
		public function setItemInHand($item){ $this->hand = $item; return true; }
		public function getSize(){return $this->full?0:36;}
		public function getHotbarSize(){return 0;}
		public function getMaxStackSize(){return 64;}
		public function getItem($slot){return isset($this->items[$slot])?copyEnch($this->items[$slot]):Item::get(0,0,0);}
		public function setItem($slot,$item){if($this->cancelWrites){return false;}$this->items[$slot]=copyEnch($item);return true;}
		public function canAddItem($item){ return !$this->full; }
		public function addItem(...$items){ if($this->full){ return $items; } foreach($items as $item){ $this->items[] = copyEnch($item); } return []; }
		public function sendContents($target){}
	}
	final class EnchantTestServer{
		public $player;
		public function getPlayer($name){ return $name === 'Fixture' ? $this->player : null; }
		public function getPluginManager(){ return $this; }
		public function getPermissionSubscriptions($name){ return []; }
		public function getLanguage(){ return $this; }
		public function get($key){ return $key; }
	}
	final class EnchantTestTable extends EnchantInventory{
		public $shelves = 15;
		public function __construct(){ $this->size = 2; (new \ReflectionProperty(EnchantInventory::class,'enchantmentSeed'))->setValue($this,42); }
		public function countBookshelf(){ return $this->shelves; }
		public function sendEnchantmentList(){}
		public function sendContents($target){}
		public function sendSlot($index, $target){}
	}
	final class EnchantTestAnvil extends Anvil{
		public $closeOnSlot = null; public $closePlayer = null;
		public function __construct($level = null){ $this->size = 3; if($level!==null){$this->holder=new Position(0,0,0,$level);} }
		public function sendContents($target){}
		public function sendSlot($index, $target){
			if($this->closeOnSlot===$index&&$this->closePlayer!==null){
				$this->closeOnSlot=null;$this->onClose($this->closePlayer);
			}
		}
	}
	$checks = 0;
	function verifyEnch($ok, $message){ global $checks; ++$checks; if(!$ok){ throw new \RuntimeException($message); } }
	function copyEnch(Item $item){ return Item::get($item->getId(), $item->getDamage(), $item->getCount(), $item->getCompoundTag()); }
	function enchantedEnch($id, $enchant, $level){ $item = Item::get($id); $item->addEnchantment(E::getEnchantment($enchant)->setLevel($level)); return $item; }
	function repairEnch(Item $item, $cost){ $tag = $item->getNamedTag() ?? new CompoundTag('', []); $tag->RepairCost = new IntTag('RepairCost', $cost); $item->setNamedTag($tag); return $item; }
	function offerResultEnch(Item $item, $entry){ $result = $item->getId() === Item::BOOK ? Item::get(Item::ENCHANTED_BOOK,0,1,$item->getCompoundTag()) : copyEnch($item); foreach($entry->getEnchantments() as $e){ $result->addEnchantment($e); } return $result; }
	pocketmine\block\Block::init(); Item::init(); E::init(); Table::init(); pocketmine\inventory\InventoryType::init();
	$server = new EnchantTestServer(); $player = new pocketmine\Player($server); $server->player = $player;
	// Source rows: legacy ID -> name,max,weight,minBase,minStep,maxBase,maxStep,anvil.
	$fixture = [
		['protection',4,10,1,11,12,11,1], ['fire_protection',4,5,10,8,18,8,2], ['feather_falling',4,5,5,6,11,6,2], ['blast_protection',4,2,5,8,13,8,4], ['projectile_protection',4,5,3,6,9,6,2], ['thorns',3,1,10,20,60,20,8], ['respiration',3,2,10,10,40,10,4], ['depth_strider',3,2,10,10,25,10,4], ['aqua_affinity',1,2,1,0,41,0,4], ['sharpness',5,10,1,11,21,11,1], ['smite',5,5,5,8,25,8,2], ['bane_of_arthropods',5,5,5,8,25,8,2], ['knockback',2,5,5,20,55,20,2], ['fire_aspect',2,2,10,20,60,20,4], ['looting',3,2,15,9,65,9,4], ['efficiency',5,10,1,10,51,10,1], ['silk_touch',1,1,15,0,65,0,8], ['unbreaking',3,5,5,8,55,8,2], ['fortune',3,2,15,9,65,9,4], ['power',5,10,1,10,16,10,1], ['punch',2,2,12,20,37,20,4], ['flame',1,2,20,0,50,0,4], ['infinity',1,1,20,0,50,0,8], ['luck_of_the_sea',3,2,15,9,65,9,4], ['lure',3,2,15,9,65,9,4]
	];
	foreach($fixture as $id => $row){
		[$name,$max,$weight,$min,$minStep,$cap,$capStep,$anvil] = $row;
		verifyEnch(E::getEnchantment($id)->getId() === $id && E::fromString('minecraft:'.$name)->getId() === $id, 'Pinned ID/name '.$id);
		verifyEnch(E::getEnchantMaxLevel($id) === $max && E::getEnchantWeight($id) === $weight && E::getEnchantment($id)->getAnvilCost() === $anvil, 'Source metadata '.$name);
		for($level=1;$level<=$max;++$level){ verifyEnch(E::getEnchantMinMaxAbility($id,$level) === [$min+($level-1)*$minStep,$cap+($level-1)*$capStep], 'Cost range '.$name.' '.$level); }
		verifyEnch(E::canEnchantItem(Item::get(Item::BOOK),$id) && E::canEnchantItem(Item::get(Item::ENCHANTED_BOOK),$id), 'Books carry '.$name);
	}
	foreach(['junk','sharpness!','foo:sharpness','1e1','-1','25','0.5',[]] as $bad){ verifyEnch(E::fromString($bad)->getId() === -1, 'Malformed selector'); }
	$matrix = [Item::DIAMOND_SWORD=>[9,10,11,12,13,14,17],Item::DIAMOND_AXE=>[9,10,11,15,16,17,18],Item::DIAMOND_PICKAXE=>[15,16,17,18],Item::DIAMOND_HOE=>[15,16,17,18],Item::SHEARS=>[15,17],Item::FLINT_STEEL=>[17],Item::BOW=>[17,19,20,21,22],Item::FISHING_ROD=>[17,23,24],Item::DIAMOND_HELMET=>[0,1,3,4,5,6,8,17],Item::DIAMOND_CHESTPLATE=>[0,1,3,4,5,17],Item::DIAMOND_LEGGINGS=>[0,1,3,4,5,17],Item::DIAMOND_BOOTS=>[0,1,2,3,4,5,7,17],Item::STONE=>[]];
	foreach($matrix as $itemId=>$allowed){ for($id=0;$id<25;++$id){ verifyEnch(E::canEnchantItem(Item::get($itemId),$id) === in_array($id,$allowed,true), 'Item eligibility '.$itemId.'/'.$id); } }
	verifyEnch(!E::canEnchantItem(Item::get(Item::DIAMOND_AXE),9,true) && !E::canEnchantItem(Item::get(Item::DIAMOND_HELMET),5,true), 'Primary table tags');
	for($a=0;$a<25;++$a){ for($b=0;$b<25;++$b){ $expected=$a!==$b; foreach([[0,1,3,4],[9,10,11],[16,18]] as $group){ if(in_array($a,$group,true)&&in_array($b,$group,true)){ $expected=false; } } verifyEnch(E::getEnchantment($a)->isCompatibleWith(E::getEnchantment($b)) === $expected, 'Conflict '.$a.'/'.$b); } }
	verifyEnch(E::getEnchantAbility(Item::get(Item::BOOK))===1 && E::getEnchantAbility(Item::get(Item::BOW))===1 && E::getEnchantAbility(Item::get(Item::SHEARS))===0, 'Enchantable component');
	foreach([0=>[8,13,30],1=>[4,12,30],12345=>[6,20,30],987654321=>[8,12,30]] as $seed=>$expected){ $r=new JavaRandom($seed); $actual=[]; for($slot=0;$slot<3;++$slot){ $actual[]=Table::getEnchantmentCost($r,$slot,15,Item::get(Item::BOOK)); } verifyEnch($actual===$expected,'Java option fixture '.$seed); }
	for($seed=0;$seed<50;++$seed){ foreach($matrix as $itemId=>$allowed){ $item=Item::get($itemId); foreach(Table::generateOffers($item,15,$seed) as $option=>$entry){ $list=$entry->getEnchantments(); verifyEnch($entry->getResourceCost()===$option+1,'Option consumption'); foreach($list as $e){ verifyEnch(in_array($e->getId(),$allowed,true)&&$e->getLevel()>=1&&$e->getLevel()<=E::getEnchantMaxLevel($e->getId()),'Legal offer'); foreach($list as $other){ if($e!==$other){ verifyEnch($e->isCompatibleWith($other),'Offer conflict'); } } } } } }
	// Java java.util.Random reference, transcribed from EnchantmentHelper.selectEnchantment with the four pickaxe candidates.
	$weighted = ['0:8'=>[[15,1],[17,1]],'0:15'=>[[18,1],[17,2]],'0:30'=>[[18,2],[17,3],[15,4]],'1:8'=>[[17,1],[15,1]],'1:15'=>[[15,2],[17,2],[18,1]],'1:30'=>[[15,4],[17,3],[18,2]],'2:8'=>[[15,1],[17,1]],'2:15'=>[[17,2],[15,2]],'2:30'=>[[17,3],[15,4]],'12345:8'=>[[17,1]],'12345:15'=>[[17,2]],'12345:30'=>[[17,3],[15,4],[18,3]]];
	foreach($weighted as $selector=>$expected){ [$seed,$cost]=array_map('intval',explode(':',$selector)); $actual=array_map(function($e){ return [$e->getId(),$e->getLevel()]; },Table::selectEnchantments(Item::get(Item::DIAMOND_PICKAXE),$cost,new JavaRandom($seed))); verifyEnch($actual===$expected,'Independent weighted Java fixture '.$selector); }
	// Real table mutation path: exact output, option consumption, cancellation, stale replay and forge rejection.
	foreach([0,1,2] as $option){
		$table=new EnchantTestTable(); $item=Item::get(Item::DIAMOND_PICKAXE); $table->setItem(0,$item); $table->setItem(1,Item::get(Item::DYE,Dye::BLUE,8)); $entry=$table->getEnchantmentEntries()[$option]; $result=offerResultEnch($item,$entry); $player->level=50; $seed=$table->getEnchantmentSeed();
		verifyEnch($table->onEnchant($player,$item,$result), 'Apply table option '.$option);
		verifyEnch($player->level===50-$option-1 && $table->getItem(1)->getCount()===7-$option, 'Charge table option '.$option);
		verifyEnch($seed!==$table->getEnchantmentSeed() && !$table->onEnchant($player,$item,$result), 'Seed reset/replay');
	}
	$table=new EnchantTestTable(); $item=Item::get(Item::BOOK); $table->setItem(0,$item); $table->setItem(1,Item::get(Item::DYE,Dye::BLUE,3)); $entry=$table->getEnchantmentEntries()[2]; $result=offerResultEnch($item,$entry); $player->level=50;
	$forged=copyEnch($result); $tag=$forged->getNamedTag(); $tag->RepairCost=new IntTag('RepairCost',-999); $forged->setNamedTag($tag);
	verifyEnch(!$table->onEnchant($player,$item,$forged)&&$player->level===50,'Table forged NBT rejected');
	$player->cancelExperience=true; verifyEnch(!$table->onEnchant($player,$item,$result)&&$table->getItem(1)->getCount()===3,'Cancelled experience prevents mutation'); $player->cancelExperience=false;
	verifyEnch($table->onEnchant($player,$item,$result)&&$table->getItem(0)->getId()===Item::ENCHANTED_BOOK,'Book conversion');
	$table=new EnchantTestTable(); $table->setItem(0,$item); $player->creative=true; $player->level=0; $entry=$table->getEnchantmentEntries()[2]; verifyEnch($table->onEnchant($player,$item,offerResultEnch($item,$entry))&&$player->level===0,'Creative table free'); $player->creative=false;
	// Anvil source fixtures: repair material, equal levels, compatibility, tax sum, prior-work escalation, rename cap.
	$air=Item::get(0,0,0); $sword=Item::get(Item::DIAMOND_SWORD,800); $repair=Anvil::calculateResult($sword,Item::get(Item::DIAMOND,0,2));
	verifyEnch($repair!==null&&$repair['cost']===2&&$repair['consumed']===2&&$repair['item']->getDamage()===20,'Quarter durability repair');
	verifyEnch($repair['item']->getNamedTagEntry('RepairCost')->getValue()===1,'First repair cost');
	$left=repairEnch(enchantedEnch(Item::DIAMOND_SWORD,9,2),1); $right=repairEnch(enchantedEnch(Item::ENCHANTED_BOOK,9,2),3); $combine=Anvil::calculateResult($left,$right);
	verifyEnch($combine!==null&&$combine['item']->getEnchantmentLevel(9)===3&&$combine['cost']===7&&$combine['item']->getNamedTagEntry('RepairCost')->getValue()===7,'Anvil merge/tax SUM/work MAX');
	verifyEnch(Anvil::calculateResult(Item::get(Item::STONE),$right)===null,'Book item eligibility');
	verifyEnch(Anvil::calculateResult(enchantedEnch(Item::DIAMOND_SWORD,9,2),enchantedEnch(Item::ENCHANTED_BOOK,10,2))===null,'Clash-only book rejected');
	$capped=Anvil::calculateResult(enchantedEnch(Item::DIAMOND_SWORD,9,5),enchantedEnch(Item::ENCHANTED_BOOK,9,5)); verifyEnch($capped['item']->getEnchantmentLevel(9)===5,'Normal level cap');
	$rename=Anvil::calculateResult(repairEnch(Item::get(Item::DIAMOND_SWORD),63),$air,'Named'); verifyEnch($rename!==null&&$rename['cost']===39&&$rename['item']->getNamedTagEntry('RepairCost')->getValue()===63,'Rename cap39/preserve prior-work');
	verifyEnch(Anvil::calculateResult(repairEnch(Item::get(Item::DIAMOND_SWORD),39),$right)===null,'Too expensive survival');
	$anvil=new EnchantTestAnvil(); $anvil->setItem(0,$left); $anvil->setItem(1,$right); $player->level=50;
	$bad=copyEnch($combine['item']); repairEnch($bad,0); verifyEnch(!$anvil->onAnvil($player,$bad)&&$player->level===50,'Forged anvil RepairCost rejected');
	verifyEnch(!$anvil->setItem(2,$combine['item']),'Unpaid direct output denied');
	verifyEnch($anvil->onAnvil($player,$combine['item'])&&$player->level===43&&$anvil->getItem(0)->getId()===0,'Anvil exact pay');
	verifyEnch(!$anvil->onAnvil($player,$combine['item'])&&$player->level===43,'Anvil replay denied');
	$player->inventory->full=true; verifyEnch(!$anvil->takeResult($player)&&$anvil->getItem(2)->getId()!==0,'Full inventory preserves paid output'); $player->inventory->full=false;
	$before=count($player->inventory->items); $player->inventory->cancelWrites=true;
	verifyEnch(!$anvil->takeResult($player)&&count($player->inventory->items)===$before&&$anvil->getItem(2)->getId()!==Item::AIR,'Cancelled inventory delivery preserves paid output');
	$player->inventory->cancelWrites=false;
	verifyEnch($anvil->takeResult($player)&&count($player->inventory->items)===$before+1&&!$anvil->takeResult($player),'Paid output once');
	$command=new pocketmine\command\defaults\EnchantCommand('enchant'); $player->inventory->hand=Item::get(Item::DIAMOND_SWORD); $command->execute($player,'enchant',['Fixture','minecraft:sharpness','5']); verifyEnch($player->inventory->hand->getEnchantmentLevel(9)===5,'Command modern name');
	foreach([['junk','1'],['smite','1'],['sharpness','0'],['sharpness','6'],['sharpness','2.5']] as $args){ $before=$player->inventory->hand->getCompoundTag(); $command->execute($player,'enchant',array_merge(['Fixture'],$args)); verifyEnch($player->inventory->hand->getCompoundTag()===$before,'Command rejects invalid/incompatible input'); }
	$player->inventory->hand=Item::get(Item::BOOK); $command->execute($player,'enchant',['Fixture','respiration','3']); verifyEnch($player->inventory->hand->getId()===Item::ENCHANTED_BOOK&&$player->inventory->hand->getEnchantmentLevel(6)===3,'Command book conversion');
	$unicode=str_repeat('界',50); verifyEnch(Anvil::calculateResult(Item::get(Item::DIAMOND_SWORD),$air,$unicode)!==null&&Anvil::calculateResult(Item::get(Item::DIAMOND_SWORD),$air,$unicode.'界')===null,'Unicode name length');
	$named=Item::get(Item::DIAMOND_SWORD); $named->setCustomName('Before'); $cleared=Anvil::calculateResult($named,$air,''); verifyEnch($cleared!==null&&!$cleared['item']->hasCustomName()&&$cleared['cost']===1,'Explicit name removal');
	$table=new EnchantTestTable(); $item=Item::get(Item::DIAMOND_PICKAXE); $table->setItem(0,$item); $table->setItem(1,Item::get(Item::DYE,Dye::BLUE,3)); $entry=$table->getEnchantmentEntries()[2]; $result=offerResultEnch($item,$entry); $player->level=29; verifyEnch(!$table->onEnchant($player,$item,$result),'Displayed XP requirement enforced'); $player->level=50; $table->setItem(1,Item::get(Item::DYE,Dye::BLUE,2)); verifyEnch(!$table->onEnchant($player,$item,$result),'Insufficient lapis rejected');
	$bad=copyEnch($result); $bad->setCount(2); verifyEnch(!$table->onEnchant($player,$item,$bad),'Forged table count rejected'); $table->shelves=0; verifyEnch(!$table->onEnchant($player,$item,$result),'Changed bookshelf snapshot rejected');
	$anvil=new EnchantTestAnvil(); $anvil->setItem(0,$left); $anvil->setItem(1,$right); $player->level=50; $player->cancelExperience=true; verifyEnch(!$anvil->onAnvil($player,$combine['item'])&&$anvil->getItem(0)->getId()!==0,'Cancelled anvil payment prevents mutation'); $player->cancelExperience=false;
	// Legacy clients can omit a server-owned RepairCost tag; the result and fee
	// still come exclusively from server inputs, never the packet's fee fields.
	$anvil=new EnchantTestAnvil(); $anvil->setItem(0,$left); $anvil->setItem(1,$right); $player->level=50;
	$legacy=copyEnch($combine['item']); $tag=$legacy->getNamedTag(); unset($tag->RepairCost); $legacy->setNamedTag($tag);
	verifyEnch($anvil->onAnvil($player,$legacy),'Legacy omitted RepairCost accepted');
	verifyEnch($player->level===43&&$anvil->getItem(2)->getNamedTagEntry('RepairCost')->getValue()===7,'Omitted prior-work metadata calculated on server');
	verifyEnch(!$anvil->onAnvil($player,$legacy)&&$player->level===43,'Legacy omitted-tag replay rejected');
	// Synchronous packet callbacks can close the menu during any input/output
	// update. Paid results must be drained once after the operation commits.
	foreach([0,1,2] as $closeSlot){
		$world=new pocketmine\level\Level(); $anvil=new EnchantTestAnvil($world);
		$anvil->setItem(0,$left);$anvil->setItem(1,$right);$player->level=50;
		$anvil->closePlayer=$player;$anvil->closeOnSlot=$closeSlot;
		verifyEnch($anvil->onAnvil($player,$combine['item'])&&$player->level===43,'Close callback completes paid operation');
		verifyEnch(count($world->drops)===1&&$world->drops[0]->getEnchantmentLevel(9)===3,'Close callback returns paid result once');
		verifyEnch($anvil->getItem(0)->getId()===0&&$anvil->getItem(1)->getId()===0&&$anvil->getItem(2)->getId()===0,'Closed operation has no duplicate inputs/output');
		$anvil->onClose($player);verifyEnch(count($world->drops)===1,'Repeated close does not duplicate paid output');
	}
	echo "Enchanting 26.2: $checks checks passed.\n";
}
