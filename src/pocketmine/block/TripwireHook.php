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

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\Player;

class TripwireHook extends Solid {

    protected $id = self::TRIPWIRE_HOOK;

    public function __construct($meta = 0){
        $this->meta = (int) $meta;
    }

    public function getName() :string {
        return "Tripwire Hook";
    }

    public function getHardness() {
        return 0;
    }

    public function getResistance(){
        return 0;
    }

    public function isSolid(){
        return false; // 墙面挂件, 不占满碰撞盒 (对齐 Lever/Transparent 写法, 否则 useItemOn 碰撞检测拦截放置)
    }

    public function place(Item $item, Block $block, Block $target, $face, $fx, $fy, $fz, ?Player $player = null){
        // 绊线钩只能挂在实体方块的侧面(北/南/西/东), 由点击的面决定朝向
        // 原版 MCPE 0.14.3: meta 低2位 = 朝向 (0=南,1=西,2=北,3=东)
        $faces = [
            3 => 0, // SIDE_SOUTH -> 朝南
            2 => 2, // SIDE_NORTH -> 朝北
            4 => 1, // SIDE_WEST  -> 朝西
            5 => 3, // SIDE_EAST  -> 朝东
        ];
        if(!isset($faces[$face])){
            return false; // 不能挂在上下方
        }
        if($target->isTransparent() === true){
            return false; // 目标方块必须是不透明方块才能附着
        }
        $this->meta = $faces[$face];
        $this->getLevel()->setBlock($block, $this, true, true);
        return true;
    }

}
