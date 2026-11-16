<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\tile\Tile;

/** Main-thread tile creation, with deterministic legacy loot and explicit modern-item aliases. */
final class StructureTiles{
	private static ?array $loot = null;

	public static function apply(Level $level, array $tiles) : int{
		$count = 0;
		foreach($tiles as $tile){
			[$x, $y, $z] = $tile['pos'];
			$pos = new Vector3($x, $y, $z);
			if($level->getTile($pos) !== null){ continue; }
			$chunk = $level->getChunk($x >> 4, $z >> 4, false);
			if($chunk === null){ continue; }
			$id = $chunk->getBlockId($x & 15, $y, $z & 15);
			$type = match($id){ 54,146 => 'Chest', 23 => 'Dispenser', 125 => 'Dropper', 61,62 => 'Furnace', 154 => 'Hopper', 117 => 'BrewingStand', 116 => 'EnchantTable', 140 => 'FlowerPot', 144 => 'Skull', 63,68 => 'Sign', 52 => 'MobSpawner', 118 => 'Cauldron', 199 => 'ItemFrame', default => null };
			if($type === null || in_array(str_replace('minecraft:', '', $tile['name']), ['jigsaw','structure_block'], true)){ continue; }
			$source = $tile['nbt'] ?? [];
			$tag = new CompoundTag('', [new StringTag('id', $type), new IntTag('x', $x), new IntTag('y', $y), new IntTag('z', $z)]);
			$hash = (int) hexdec(substr(hash('sha256', $level->getSeed() . ':' . $x . ':' . $y . ':' . $z), 0, 7));
			if(in_array($type, ['Chest','Dispenser','Dropper','Furnace','Hopper','BrewingStand'], true)){
				$items = $source['Items'] ?? [];
				if($items === [] && isset($source['LootTable'])){
					$lootName=(string)$source['LootTable'];
					$lootName=str_starts_with($lootName,'minecraft:')?$lootName:'minecraft:chests/'.str_replace('loot.','',$lootName);
					$items = self::loot($lootName, $hash);
				}
				$list = new ListTag('Items', []);
				$list->setTagType(NBT::TAG_Compound);
				$slot = 0;
				foreach($items as $entry){
					$item = self::item($entry['id'] ?? $entry['name'] ?? 'air');
					if($item->getId() === 0){ continue; }
					if(isset($entry['Damage'])){$item->setDamage((int)$entry['Damage']);}
					$item->setCount(max(1, min(64, (int) ($entry['Count'] ?? $entry['count'] ?? 1))));
					$targetSlot = (int) ($entry['Slot'] ?? $slot++);
					$capacity = match($type){'Dispenser','Dropper'=>9,'Furnace'=>3,'Hopper'=>5,'BrewingStand'=>4,default=>27};
					if($targetSlot < 0 || $targetSlot >= $capacity){ continue; }
					$list[count($list)] = NBT::putItemHelper($item, $targetSlot);
				}
				$tag->Items = $list;
			}
			if(isset($source['CustomName'])){ $tag->CustomName = new StringTag('CustomName', self::text($source['CustomName'])); }
			if($type === 'Sign'){
				$lines = $source['front_text']['messages'] ?? [$source['Text1']??'', $source['Text2']??'', $source['Text3']??'', $source['Text4']??''];
				foreach(range(1,4) as $line){ $name='Text'.$line; $tag->{$name}=new StringTag($name,self::text($lines[$line-1]??'')); }
			}
			if($type === 'Skull'){
				$name = str_replace(['minecraft:','_wall'], '', $tile['name']);
				$skull = match($name){'wither_skeleton_skull'=>1,'zombie_head'=>2,'player_head'=>3,'creeper_head'=>4,'dragon_head'=>5,default=>0};
				$tag->SkullType = new ByteTag('SkullType', (int)($source['SkullType']??$skull));
				$tag->Rot = new ByteTag('Rot', (((int)($tile['properties']['rotation']??$source['Rot']??0))+4*(int)($tile['rotation']??0))&15);
			}
			if($type === 'FlowerPot'){
				$plant = str_replace(['minecraft:', 'potted_'], '', $tile['name']);
				[$plantId,$meta] = match($plant){'cactus'=>[81,0],'dead_bush'=>[32,0],'fern'=>[31,2],'dandelion'=>[37,0],'brown_mushroom'=>[39,0],'red_mushroom'=>[40,0],'spruce_sapling'=>[6,1],'birch_sapling'=>[6,2],'jungle_sapling'=>[6,3],'acacia_sapling'=>[6,4],'dark_oak_sapling'=>[6,5],'oak_sapling'=>[6,0],'poppy'=>[38,0],'blue_orchid'=>[38,1],'allium'=>[38,2],'azure_bluet'=>[38,3],'red_tulip'=>[38,4],'orange_tulip'=>[38,5],'white_tulip'=>[38,6],'pink_tulip'=>[38,7],'oxeye_daisy'=>[38,8],default=>[0,0]};
				$tag->item = new ShortTag('item',$plant==='flower_pot'?0:$plantId);
				$tag->mData = new IntTag('mData',$meta);
			}
			if($type==='ItemFrame'){
				$sourceItem=$source['Item']??['id'=>0];
				$item=self::item($sourceItem['id']??0);
				if(isset($sourceItem['Damage'])){ $item->setDamage((int)$sourceItem['Damage']); }
				$item->setCount(max(1,min(64,(int)($sourceItem['Count']??$sourceItem['count']??1))));
				$tag->Item=NBT::putItemHelper($item);$tag->Item->setName('Item');
				$tag->ItemRotation=new ByteTag('ItemRotation',(int)($source['ItemRotation']??0));
			}
			if($type === 'MobSpawner'){
				$mob = $source['SpawnData']['entity']['id'] ?? $source['SpawnData']['id'] ?? '';
				$entityId = (int)($source['EntityId'] ?? match(str_replace('minecraft:', '', $mob)){'spider'=>35,'cave_spider'=>40,'skeleton'=>34,'blaze'=>43,'silverfish'=>39,default=>32});
				$tag->EntityId = new IntTag('EntityId',$entityId);
			}
			if(Tile::createTile($type, $chunk, $tag) !== null){ ++$count; $chunk->setChanged(); }
		}
		return $count;
	}

	private static array $itemMappings = [];

	/** Allows callers to disclose substitutions and omitted unsupported loot. */
	public static function itemMappingReport() : array{ return self::$itemMappings; }

	/** Numeric pairs bypass fromString(), whose string bitwise operations fail on PHP 8.4. */
	public static function item(string|int $name) : Item{
		if(is_int($name)){ return Item::get($name >= 0 && $name <= 65535 ? $name : 0); }
		$original=$name; $name=strtolower(trim($name));
		if(str_starts_with($name,'minecraft:')){ $name=substr($name,10); }
		if(preg_match('/^([0-9]+)(?::([0-9]+))?$/D',$name,$m)===1){ return Item::get((int)$m[1]<=65535?(int)$m[1]:0,(int)($m[2]??0)&65535); }
		$aliases=[
			'golden_helmet'=>[314,0],'golden_chestplate'=>[315,0],'golden_leggings'=>[316,0],'golden_boots'=>[317,0],
			'leather_helmet'=>[298,0],'leather_chestplate'=>[299,0],'leather_leggings'=>[300,0],
			'chainmail_helmet'=>[302,0],'chainmail_chestplate'=>[303,0],'chainmail_leggings'=>[304,0],'chainmail_boots'=>[305,0],
			'charcoal'=>[263,1],'lapis_lazuli'=>[351,4],'ink_sac'=>[351,0],'bone_meal'=>[351,15],'cocoa_beans'=>[351,3],
			'cod'=>[349,0],'salmon'=>[460,0],'tropical_fish'=>[461,0],'pufferfish'=>[462,0],'cooked_cod'=>[350,0],'cooked_salmon'=>[463,0],
			'beef'=>[363,0],'chicken'=>[365,0],'porkchop'=>[319,0],'rabbit'=>[411,0],
			'clay'=>[82,0],'clay_ball'=>[337,0],'melon'=>[103,0],'melon_slice'=>[360,0],'pumpkin'=>[86,0],
			'grass_block'=>[2,0],'short_grass'=>[31,1],'fern'=>[31,2],'terracotta'=>[172,0],'coarse_dirt'=>[3,1],
			'granite'=>[1,1],'polished_granite'=>[1,2],'diorite'=>[1,3],'polished_diorite'=>[1,4],'andesite'=>[1,5],'polished_andesite'=>[1,6],
			'cut_sandstone'=>[24,2],'chiseled_sandstone'=>[24,1],'red_sand'=>[12,1],'cut_red_sandstone'=>[179,2],'chiseled_red_sandstone'=>[179,1],
			'stone_bricks'=>[98,0],'mossy_stone_bricks'=>[98,1],'cracked_stone_bricks'=>[98,2],'chiseled_stone_bricks'=>[98,3],
			'chipped_anvil'=>[145,4],'damaged_anvil'=>[145,8],'wet_sponge'=>[19,1],
			'blue_orchid'=>[38,1],'allium'=>[38,2],'azure_bluet'=>[38,3],'red_tulip'=>[38,4],'orange_tulip'=>[38,5],'white_tulip'=>[38,6],'pink_tulip'=>[38,7],'oxeye_daisy'=>[38,8],
			'skeleton_skull'=>[397,0],'wither_skeleton_skull'=>[397,1],'zombie_head'=>[397,2],'player_head'=>[397,3],'creeper_head'=>[397,4],
			'water_bucket'=>[325,8],'lava_bucket'=>[325,10],'milk_bucket'=>[325,1],'experience_bottle'=>[384,0],
			'enchanted_golden_apple'=>[466,0],'glistering_melon_slice'=>[382,0],'slime_ball'=>[341,0],'note_block'=>[25,0],
			'oak_door'=>[324,0],'oak_sign'=>[323,0],'oak_trapdoor'=>[96,0],'oak_button'=>[143,0],'oak_pressure_plate'=>[72,0],
		];
		$pair=$aliases[$name]??null; $reason='exact legacy item';
		if($pair===null){
			$substitutions=[
				'crossbow'=>[261,0],'spectral_arrow'=>[262,0],'tipped_arrow'=>[262,0],'lingering_potion'=>[438,0],
				'raw_iron'=>[265,0],'raw_gold'=>[266,0],'raw_copper'=>[265,0],'copper_ingot'=>[265,0],
				'glow_berries'=>[260,0],'sweet_berries'=>[260,0],'suspicious_stew'=>[282,0],'bamboo'=>[280,0],
				'amethyst_shard'=>[406,0],'prismarine_shard'=>[406,0],'prismarine_crystals'=>[348,0],
				'crying_obsidian'=>[49,0],'blue_ice'=>[174,0],'moss_block'=>[35,13],'moss_carpet'=>[171,13],
				'barrel'=>[54,0],'ender_chest'=>[54,0],'shulker_box'=>[54,0],'blast_furnace'=>[61,0],'smoker'=>[61,0],
				'lantern'=>[50,0],'soul_lantern'=>[50,0],'candle'=>[50,0],'glow_ink_sac'=>[351,0],
				'cartography_table'=>[58,0],'fletching_table'=>[58,0],'smithing_table'=>[58,0],'loom'=>[58,0],
				'lectern'=>[47,0],'composter'=>[118,0],'grindstone'=>[245,0],'stonecutter'=>[245,0],
			];
			if(isset($substitutions[$name])){ $pair=$substitutions[$name]; $reason='explicit legacy material/function substitute'; }
		}
		$woods=['oak'=>0,'spruce'=>1,'birch'=>2,'jungle'=>3,'acacia'=>4,'dark_oak'=>5];
		if($pair===null && preg_match('/^(?:stripped_)?(oak|spruce|birch|jungle|acacia|dark_oak)_(planks|log|wood|leaves|sapling|slab|stairs|fence|fence_gate|door|trapdoor|button|pressure_plate|sign|hanging_sign|shelf)$/D',$name,$m)===1){
			$v=$woods[$m[1]];
			$pair=match($m[2]){
				'planks'=>[5,$v],'log','wood'=>[$v<4?17:162,($v<4?$v:$v-4)|($m[2]==='wood'?12:0)],
				'leaves'=>[$v<4?18:161,$v<4?$v:$v-4],'sapling'=>[6,$v],'slab'=>[158,$v],
				'stairs'=>[[53,134,135,136,163,164][$v],0],'fence'=>[85,$v],
				'fence_gate'=>[[107,183,184,185,187,186][$v],0],'door'=>[[324,427,428,429,430,431][$v],0],
				'trapdoor'=>[96,0],'button'=>[143,0],'pressure_plate'=>[72,0],'sign','hanging_sign'=>[323,0],'shelf'=>[47,0],
			};
			$reason=in_array($m[2],['trapdoor','button','pressure_plate','hanging_sign','shelf'],true)||str_starts_with($name,'stripped_')?'wood species/texture substitute':'exact legacy wood material';
		}
		$colors=['white'=>0,'orange'=>1,'magenta'=>2,'light_blue'=>3,'yellow'=>4,'lime'=>5,'pink'=>6,'gray'=>7,'light_gray'=>8,'cyan'=>9,'purple'=>10,'blue'=>11,'brown'=>12,'green'=>13,'red'=>14,'black'=>15];
		if($pair===null && preg_match('/^(white|orange|magenta|light_blue|yellow|lime|pink|gray|light_gray|cyan|purple|blue|brown|green|red|black)_(wool|carpet|terracotta|glazed_terracotta|concrete|concrete_powder|banner|bed|stained_glass|stained_glass_pane|candle|shulker_box|dye)$/D',$name,$m)===1){
			$v=$colors[$m[1]];
			$pair=match($m[2]){'wool'=>[35,$v],'carpet'=>[171,$v],'terracotta','glazed_terracotta','concrete','concrete_powder'=>[159,$v],'banner'=>[35,$v],'bed'=>[355,0],'stained_glass'=>[20,0],'stained_glass_pane'=>[102,0],'candle'=>[50,0],'shulker_box'=>[54,0],'dye'=>[351,15-$v]};
			$reason=in_array($m[2],['wool','carpet','terracotta','dye'],true)?'exact legacy color metadata':'explicit color/material substitute; unsupported detail omitted';
		}
		if($pair===null && defined(Item::class.'::'.strtoupper($name))){ $pair=[(int)constant(Item::class.'::'.strtoupper($name)),0]; }
		if($pair===null){ $pair=[0,0]; $reason='omitted: unsupported modern item; AIR skipped, no valuable generic fallback'; }
		self::$itemMappings[$original]=['id'=>$pair[0],'meta'=>$pair[1],'reason'=>$reason];
		return Item::get((int)$pair[0],(int)$pair[1]);
	}

	/** Bounded approximation: nested entries/tables, weights, counts; modern conditions/enchantments omitted. */
	private static function loot(string $name, int $seed, int $depth = 0) : array{
		if($depth>4){return [];}
		if(self::$loot===null){$path=StructureRepository::directory().'loot_tables.json';self::$loot=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];}
		$table=self::$loot[$name]??null;if(!is_array($table)){return [];}
		$next=static function(int $bound) use (&$seed):int{$seed=($seed*1103515245+12345)&0x7fffffff;return ($seed>>8)%max(1,$bound);};
		$value=static function($range) use ($next):int{if(is_numeric($range)){return (int)$range;}if(!is_array($range)){return 1;}$min=is_numeric($range['min']??null)?(int)$range['min']:(int)($range['value']??1);$max=is_numeric($range['max']??null)?(int)$range['max']:$min;return $min+$next(max(1,min(1024,$max-$min+1)));};
		$expand=function(array $entry,int $nest=0) use (&$expand,$next,$value,$depth):array{
			if($nest>8){return [];}$type=str_replace('minecraft:','',(string)($entry['type']??''));$out=[];
			if($type==='loot_table'){$key=$entry['value']??$entry['name']??'';$out=is_string($key)?self::loot($key,$next(0x7fffffff),$depth+1):[];}
			elseif($type==='item'){$itemName=(string)($entry['name']??'air');if(self::item($itemName)->getId()!==0){$out=[['id'=>$itemName,'Count'=>1]];}}
			elseif(in_array($type,['alternatives','group','sequence'],true)){
				foreach($entry['children']??[] as $child){if(!is_array($child)){continue;}$part=$expand($child,$nest+1);$out=array_merge($out,$part);if($type==='alternatives' && $part!==[]){break;}if(count($out)>=27){break;}}
			}
			foreach($entry['functions']??[] as $function){if(str_replace('minecraft:','',(string)($function['function']??''))==='set_count'){$count=$value($function['count']??1);foreach($out as &$item){$item['Count']=max(0,min(64,($function['add']??false)?$item['Count']+$count:$count));}unset($item);}}
			return array_slice(array_values(array_filter($out,static fn(array $item):bool=>$item['Count']>0)),0,27);
		};
		$items=[];
		foreach($table['pools']??[] as $pool){
			$entries=$pool['entries']??[];$total=0;foreach($entries as $entry){$total+=max(0,(int)($entry['weight']??1));}
			if($total===0){continue;}
			for($i=0,$rolls=max(0,min(12,$value($pool['rolls']??1)));$i<$rolls && count($items)<27;++$i){
				$choice=$next($total);foreach($entries as $entry){$choice-=max(0,(int)($entry['weight']??1));if($choice<0){$items=array_merge($items,$expand($entry));break;}}
			}
		}
		return array_slice($items,0,27);
	}

	private static function text($value) : string{
		if(is_array($value)){return (string)($value['text']??$value['translate']??'').implode('',array_map([self::class,'text'],$value['extra']??[]));}
		$string=(string)$value;
		$decoded=json_decode($string,true);
		return is_array($decoded)?self::text($decoded):$string;
	}
}
