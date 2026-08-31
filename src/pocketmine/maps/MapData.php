<?php

/*
 *
 *  ____            _        _   __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | | (_| |   <| |  _ <
 * |_|  \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\__,_|_|\_\_|_| \___|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author MPMPESCore
 * @link https://github.com/mpmp666/MPMPESCore
 *
*/

namespace pocketmine\maps;

use pocketmine\level\Level;
use pocketmine\Server;
use pocketmine\utils\MapColor;

/**
 * 地图数据管理器(实现参考 SCAXE 0.14.x 核心)
 * 每张地图存为 128x128 PNG: worlds/<世界名>/data/maps/Map_<id>.dat
 * (地图数据与对应世界放在同一个目录下)
 */
class MapData{

	/** @var MapData[] */
	private static $instances = [];

	/** @var array */
	public $MapData = [];
	private $path = '';
	private $server;

	/**
	 * 取得某个世界的地图数据管理器(按世界目录隔离)
	 *
	 * @param Level $level
	 *
	 * @return MapData
	 */
	public static function get(Level $level) : MapData{
		$folder = $level->getFolderName();
		if(!isset(self::$instances[$folder])){
			self::$instances[$folder] = new self($level->getServer(), $level->getServer()->getDataPath() . "worlds/" . $folder . "/data");
		}
		return self::$instances[$folder];
	}

	public function __construct(Server $server, string $path){
		$this->server = $server;
		$this->path = ($path . "/maps/");
		if(!file_exists($this->path)){
			mkdir($this->path, 0777, true);
		}
	}

	/**
	 * @param int $id
	 *
	 * @return MapColor[][] MapColor[y][x]
	 */
	public function getMapData($id){
		if(isset($this->MapData[$id])){
			return $this->MapData[$id];
		}else{
			return $this->loadMap($id);
		}
	}

	/**
	 * @param int $id
	 *
	 * @return MapColor[][]
	 */
	public function loadMap($id){
		$img = imagecreatefrompng($this->path . "Map_" . $id . ".dat");
		for($y = 0; $y < 128; ++$y){
			for($x = 0; $x < 128; ++$x){
				$rgb = ImageColorAt($img, $x, $y);
				$colors = imagecolorsforindex($img, $rgb);
				$array[$y][$x] = new MapColor($colors['red'], $colors['green'], $colors['blue']);
			}
		}
		$this->MapData[$id] = $array;
		imagedestroy($img);
		return $array;
	}

	/**
	 * @param int            $id
	 * @param MapColor[][]   $data MapColor[y][x]
	 */
	public function saveMapData($id, $data){
		$this->MapData[$id] = $data;
		$img = imagecreatetruecolor(128, 128);
		imagesavealpha($img, true);
		$background = imagecolorallocatealpha($img, 0x00, 0x00, 0x00, 0x00);
		imagefill($img, 0, 0, $background);
		for($y = 0; $y < 128; ++$y){
			for($x = 0; $x < 128; ++$x){
				$color = $data[$y][$x];
				$rgb = imagecolorallocate($img, $color->getR(), $color->getG(), $color->getB());
				imagesetpixel($img, $x, $y, $rgb);
			}
		}
		imagepng($img, $this->path . "Map_" . $id . ".dat");
		imagedestroy($img);
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public function haveMap($id){
		return file_exists($this->path . "Map_" . $id . ".dat");
	}

	/**
	 * 下一个可用的地图 ID(顺序小整数, 能放进物品 damage 里)
	 *
	 * @return int
	 */
	public function nextId(){
		$next = 1;
		foreach(glob($this->path . "Map_*.dat") as $f){
			if(preg_match('/Map_(\d+)\.dat$/', $f, $m) > 0){
				$next = max($next, ((int) $m[1]) + 1);
			}
		}
		return $next;
	}
}
