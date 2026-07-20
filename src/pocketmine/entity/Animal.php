<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
*/

namespace pocketmine\entity;


abstract class Animal extends Creature implements Ageable{

	public function initEntity(){
		parent::initEntity();
		if($this->getDataProperty(self::DATA_AGEABLE_FLAGS) === null){
			$this->setDataProperty(self::DATA_AGEABLE_FLAGS, self::DATA_TYPE_BYTE, 0);
		}
	}


	// 原版 0.14.3 AI 状态 (移植 EatBlockGoal/BreedGoal/SitGoal 等用, 普通属性避免引用未定义 DATA_FLAG)
	/** @var bool 是否坐下 (Wolf SitGoal) */
	public $sitting = false;
	/** @var int 繁殖冷却/求爱计时 (BreedGoal/MakeLoveGoal), >0 表示处于 inLove */
	public $inLove = 0;
	/** @var int 吃草计时 (EatBlockGoal) */
	public $eatTimer = 0;
	/** @var bool 正在吃草 */
	public $eating = false;

	public function isSitting() : bool{ return $this->sitting; }
	public function setSitting(bool $v = true){ $this->sitting = $v; }
	public function isInLove() : bool{ return $this->inLove > 0; }
	public function setInLove(int $ticks = 600){ $this->inLove = $ticks; }

	public function isBaby(){
		return $this->getDataFlag(self::DATA_AGEABLE_FLAGS, self::DATA_FLAG_BABY);
	}
}