<?php
/** Export seed layouts as ordered, transformed legacy block writes for offline previews. */
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
define('pocketmine\\PATH', dirname(__DIR__) . '/');
spl_autoload_register(static function(string $class) : void{
	$path = \pocketmine\PATH . 'src/' . str_replace('\\', '/', $class) . '.php';
	if(is_file($path)){ require_once $path; }
});

use pocketmine\level\generator\structure\StructureRepository as Repository;
use pocketmine\level\generator\structure\StructureService as Service;

try{
	$destination = $argv[1] ?? '-';
	$seed = isset($argv[2]) ? (int)$argv[2] : 42;
	$families = ['village', 'pillager_outpost', 'end_city', 'woodland_mansion', 'bastion', 'ancient_city', 'trial_chambers', 'shipwreck'];
	$views = [];
	foreach($families as $family){
		$pieces = Service::pieces($family, $seed, '');
		if($pieces === []){ throw new RuntimeException('Empty layout: ' . $family); }
		$records = '';
		$count = 0;
		foreach($pieces as $piece){
			$template = Repository::template($piece['template']);
			$rotation = $piece['rotation'] & 3;
			$data = $template['blockData'];
			for($offset = 0, $length = strlen($data); $offset < $length; $offset += 8){
				$cell = unpack('vx/vy/vz/vp', $data, $offset);
				[$id, $meta] = $template['palette'][$cell['p']][$rotation];
				if($id < 0 || ($id === 0 && ($piece['ignore_air'] ?? false))){ continue; }
				[$x, $z] = Repository::rotate($cell['x'], $cell['z'], $rotation);
				// V stores two's-complement little-endian coordinates; the reader uses <iiiBB.
				$records .= pack('V3CC', $piece['x'] + $x, $piece['y'] + $cell['y'], $piece['z'] + $z, $id, $meta);
				++$count;
			}
		}
		$views[] = ['family' => $family, 'pieces' => $pieces, 'bounds' => Repository::bounds($pieces), 'write_count' => $count, 'blocks' => base64_encode($records)];
		fwrite(STDERR, $family . ': ' . count($pieces) . ' pieces, ' . $count . " transformed writes\n");
	}
	$result = ['format' => 1, 'seed' => $seed, 'record_format' => '<iiiBB', 'placement' => 'ordered writes; air clears unless piece ignore_air; structure void skipped; no terrain or height clipping', 'views' => $views];
	$json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	if($destination === '-'){
		fwrite(STDOUT, $json);
	}elseif(file_put_contents($destination, $json) === false){
		throw new RuntimeException('Cannot write ' . $destination);
	}
}catch(Throwable $error){
	fwrite(STDERR, 'Structure preview export failed: ' . $error->getMessage() . "\n");
	exit(1);
}
