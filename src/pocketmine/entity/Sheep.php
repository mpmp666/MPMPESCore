<?php

/**
 * OpenGenisys Project
 *
 * @author PeratX
 */

namespace pocketmine\entity;

use pocketmine\block\Wool;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;
use pocketmine\item\Item as ItemItem;
use pocketmine\level\format\FullChunk;
use pocketmine\nbt\tag\CompoundTag;

class Sheep extends Animal implements Colorable{
	const DATA_FLAG_SHEARED = 12; // MCPE 0.14.3 羊剪毛视觉标记 (Entity 未定义, 此处补)

	const NETWORK_ID = 13;

	const DATA_COLOR_INFO = 16;

	public $width = 0.625;
	public $length = 1.4375;
	public $height = 1.8;
	
	public function getName() : string{
		return "Sheep";
	}

	public function __construct(FullChunk $chunk, CompoundTag $nbt){
		if(!isset($nbt->Color)){
			$nbt->Color = new ByteTag("Color", self::getRandomColor());
		}
		parent::__construct($chunk, $nbt);

		if(!isset($nbt->Sheared)){
			$nbt->Sheared = new ByteTag("Sheared", 0);
		}
		$this->sheared = (bool) $nbt["Sheared"];

		$this->setDataProperty(self::DATA_COLOR_INFO, self::DATA_TYPE_BYTE, $this->getColor());
	}

	public static function getRandomColor() : int{
		$rand = "";
		$rand .= str_repeat(Wool::WHITE . " ", 20);
		$rand .= str_repeat(Wool::ORANGE . " ", 5);
		$rand .= str_repeat(Wool::MAGENTA . " ", 5);
		$rand .= str_repeat(Wool::LIGHT_BLUE . " ", 5);
		$rand .= str_repeat(Wool::YELLOW . " ", 5);
		$rand .= str_repeat(Wool::GRAY . " ", 10);
		$rand .= str_repeat(Wool::LIGHT_GRAY . " ", 10);
		$rand .= str_repeat(Wool::CYAN . " ", 5);
		$rand .= str_repeat(Wool::PURPLE . " ", 5);
		$rand .= str_repeat(Wool::BLUE . " ", 5);
		$rand .= str_repeat(Wool::BROWN . " ", 5);
		$rand .= str_repeat(Wool::GREEN . " ", 5);
		$rand .= str_repeat(Wool::RED . " ", 5);
		$rand .= str_repeat(Wool::BLACK . " ", 10);
		$arr = explode(" ", $rand);
		return $arr[mt_rand(0, count($arr) - 1)];
	}

	public function getColor() : int{
		return (int) $this->namedtag["Color"];
	}

	public function setColor(int $color){
		$this->namedtag->Color = new ByteTag("Color", $color);
	}
	
	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->eid = $this->getId();
		$pk->type = Sheep::NETWORK_ID;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = $this->motionX;
		$pk->speedY = $this->motionY;
		$pk->speedZ = $this->motionZ;
		$pk->yaw = $this->yaw;
		$pk->pitch = $this->pitch;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);

		parent::spawnTo($player);
	}
	
	/** @var bool 是否已被剪毛 (剪毛后不掉毛, 重生羊毛需吃草恢复) */
	public $sheared = false;

	/**
	 * 剪毛: 玩家手持剪刀右键调用
	 * 原版: 掉落 1-3 有色羊毛, 剪刀耐久-1, 羊进入已剪状态
	 * (对应 MCPE 0.14.3 剪刀交互; 核心原无此功能, 此处补全)
	 */
	public function shear(Player $player) : bool{
		if($this->sheared) return false; // 已剪过
		$item = $player->getInventory()->getItemInHand();
		if($item->getId() !== ItemItem::SHEARS) return false;
		$this->sheared = true;
		$this->setDataFlag(self::DATA_FLAGS, self::DATA_FLAG_SHEARED, true);
		$this->sendData($this->getViewers()); // 同步客户端: 羊变秃
		$this->namedtag->Sheared = new ByteTag("Sheared", 1);
		// 掉落 1-3 有色羊毛
		$count = mt_rand(1, 3);
		$wool = ItemItem::get(ItemItem::WOOL, $this->getColor(), $count);
		$this->getLevel()->dropItem($this->add(0, $this->getEyeHeight(), 0), $wool);
		// 剪刀耐久 -1
		$item->setDamage($item->getDamage() + 1);
		if($item->getDamage() >= $item->getMaxDurability()){
			$player->getInventory()->setItemInHand(ItemItem::get(ItemItem::AIR));
		}else{
			$player->getInventory()->setItemInHand($item);
		}
		return true;
	}

	public function getDrops(){
		$drops = [];
		if(!$this->sheared){ // 已剪毛的羊死亡不掉羊毛
			$drops[] = ItemItem::get(ItemItem::WOOL, $this->getColor(), 1);
		}
		return $drops;
	}
}