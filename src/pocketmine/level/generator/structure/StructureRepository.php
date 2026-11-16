<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

/** Read-only Minecraft 26.2 templates; each generation worker has its own bounded cache. */
final class StructureRepository{
	private static ?array $manifest = null;
	private static ?array $pools = null;
	private static array $cache = [];
	private static int $cacheBytes = 0;
	private const CACHE_LIMIT = 12582912;

	public static function directory() : string{
		return (defined('pocketmine\\PATH') ? \pocketmine\PATH : dirname(__DIR__, 5) . '/') . 'resources/structures/';
	}

	public static function manifest() : array{
		if(self::$manifest === null){
			$path = self::directory() . 'manifest.json';
			if(!is_file($path)){ throw new \RuntimeException('Missing structure resources: ' . $path); }
			self::$manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			if((self::$manifest['format'] ?? 0) !== 1){ throw new \RuntimeException('Unsupported structure manifest format'); }
		}
		return self::$manifest;
	}

	public static function pools() : array{
		if(self::$pools === null){
			$data = json_decode(file_get_contents(self::directory() . 'pools.json'), true, 512, JSON_THROW_ON_ERROR);
			self::$pools = $data['pools'] ?? $data;
		}
		return self::$pools;
	}

	public static function has(string $key) : bool{
		return isset(self::manifest()['templates'][$key]) || in_array($key, LegacyStructures::families(), true);
	}

	public static function template(string $key) : array{
		if(isset(self::$cache[$key])){
			$value = self::$cache[$key];
			unset(self::$cache[$key]); self::$cache[$key] = $value;
			return $value;
		}
		$entry = self::manifest()['templates'][$key] ?? null;
		if($entry === null){
			if(in_array($key, LegacyStructures::families(), true)){ return LegacyStructures::template($key); }
			throw new \InvalidArgumentException('Unknown structure template: ' . $key);
		}
		$file = $entry['file'];
		if(str_contains($file, '..') || str_starts_with($file, '/')){ throw new \RuntimeException('Invalid structure resource path'); }
		$json = gzdecode(file_get_contents(self::directory() . $file));
		if($json === false){ throw new \RuntimeException('Invalid compressed structure: ' . $key); }
		$value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		$value['blockData'] = base64_decode($value['blocks'], true);
		if($value['blockData'] === false || strlen($value['blockData']) % 8 !== 0){ throw new \RuntimeException('Invalid block records: ' . $key); }
		$value['_bytes'] = strlen($json) + strlen($value['blockData']);
		while(self::$cache !== [] && self::$cacheBytes + $value['_bytes'] > self::CACHE_LIMIT){
			$old = array_key_first(self::$cache); self::$cacheBytes -= self::$cache[$old]['_bytes']; unset(self::$cache[$old]);
		}
		self::$cache[$key] = $value; self::$cacheBytes += $value['_bytes'];
		return $value;
	}

	/** Pivot rotation, with negative coordinates retained. */
	public static function rotate(int $x, int $z, int $rotation) : array{
		return match($rotation & 3){ 1 => [-$z, $x], 2 => [-$x, -$z], 3 => [$z, -$x], default => [$x, $z] };
	}

	public static function bounds(array $pieces) : array{
		$bounds = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN];
		foreach($pieces as $piece){
			$size = self::manifest()['templates'][$piece['template']]['size'] ?? self::template($piece['template'])['size'];
			foreach([[0, 0], [$size[0] - 1, 0], [0, $size[2] - 1], [$size[0] - 1, $size[2] - 1]] as [$x, $z]){
				[$x, $z] = self::rotate($x, $z, $piece['rotation']);
				$bounds[0] = min($bounds[0], $piece['x'] + $x); $bounds[2] = min($bounds[2], $piece['z'] + $z);
				$bounds[3] = max($bounds[3], $piece['x'] + $x); $bounds[5] = max($bounds[5], $piece['z'] + $z);
			}
			$bounds[1] = min($bounds[1], $piece['y']); $bounds[4] = max($bounds[4], $piece['y'] + $size[1] - 1);
		}
		return $pieces === [] ? [0, 0, 0, 0, 0, 0] : $bounds;
	}
}
