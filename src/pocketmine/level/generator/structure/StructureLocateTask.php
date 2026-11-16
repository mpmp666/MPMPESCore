<?php

declare(strict_types=1);

namespace pocketmine\level\generator\structure;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

/** Terrain searches use only scalar task properties and the registered generation worker. */
final class StructureLocateTask extends AsyncTask{
	public $levelId;
	public $family;
	public $x;
	public $z;
	public $playerName;
	// Kept for the existing /structure locate constructor; both commands now prove nearest.
	public $nearest;

	public function __construct(int $levelId, string $family, int $x, int $z, string $playerName = '', bool $nearest = false){
		$this->levelId = $levelId;
		$this->family = $family;
		$this->x = $x; $this->z = $z;
		$this->playerName = $playerName;
		$this->nearest = $nearest;
	}

	public function onRun(){
		try{
			$manager = $this->getFromThreadStore('generation.level'.$this->levelId.'.manager');
			$generator = $this->getFromThreadStore('generation.level'.$this->levelId.'.generator');
			if($manager === null || $generator === null){ $this->setResult(['error'=>'世界生成器未就绪，请稍后重试。']); return; }
			$options = $generator->getSettings();
			$settings = is_array($options['structure-settings'] ?? null) ? $options['structure-settings'] : [];
			$dimension = StructureLocator::dimension($generator->getName());
			$error = StructureLocator::requestError($this->family, $dimension, $settings);
			if($error !== null){ $this->setResult(['error'=>$error]); return; }
			$engine = $generator->structureEngine($manager);
			if($engine === null){ $this->setResult(['error'=>'该世界未启用自然建筑。']); return; }
			$locator = new StructureLocator($engine, (int) $manager->getSeed(), $dimension, $settings);
			$result = $locator->locate($this->family, $this->x, $this->z);
			if($result['plan'] !== null){
				$plan = $result['plan']; $b = $plan['bounds'];
				$result['plan'] = [
					'family'=>$plan['family'], 'variant'=>$plan['variant'] ?? '',
					'x'=>$plan['x'], 'y'=>$plan['y'], 'z'=>$plan['z'], 'pieces'=>count($plan['pieces']),
					'bounds'=>[$plan['x']+$b[0], $plan['y']+$b[1], $plan['z']+$b[2], $plan['x']+$b[3], $plan['y']+$b[4], $plan['z']+$b[5]],
					'height_clipped'=>(bool)($plan['height_clipped']??false)
				];
			}
			$this->setResult($result);
		}catch(\Throwable $e){
			$this->setResult(['error'=>'建筑定位失败：'.$e->getMessage()]);
		}
	}

	/** Also used by CLI regressions to check that partial searches never claim absence. */
	public static function message(array $result) : string{
		if(isset($result['error'])){ return $result['error']; }
		if($result['status'] === 'absent'){
			return '已穷尽全部 '.$result['regionsChecked'].' 个候选区域，没有符合条件的 '.$result['family'].'。';
		}
		$plan = $result['plan'];
		if($plan === null){
			return '搜索未完成：已检查 '.$result['regionsChecked'].' 个候选区域，暂未找到符合条件的 '.$result['family'].'；不能断言不存在，请更换搜索起点。';
		}
		$name = $plan['family'].($plan['variant'] !== '' ? ' ('.$plan['variant'].')' : '');
		$distance = number_format(sqrt($result['distanceSquared']), 2, '.', '');
		$prefix = $result['complete'] ? '最近的 ' : '搜索未完成，当前候选 ';
		$message = $prefix.$name.' 锚点位于 '.$plan['x'].' '.$plan['y'].' '.$plan['z'].'，距起点水平距离 '.$distance.' 格；范围 ['.implode(', ', array_slice($plan['bounds'], 0, 3)).'] 至 ['.implode(', ', array_slice($plan['bounds'], 3, 3)).']。';
		$message .= ' 已检查 '.$result['regionsChecked'].' 个候选区域';
		if($result['complete']){
			$message .= $result['remainingLowerBoundSquared'] === null
				? '，候选区域已穷尽，已证明最近'
				: '，剩余候选距离下界 '.number_format(sqrt($result['remainingLowerBoundSquared']), 2, '.', '').' 格，已证明最近';
		}else{
			$message .= '，尚未证明最近';
		}
		if(($plan['height_clipped']??false)===true){$message.='；原版布局超出此客户端 y=0..127，超界部分无法显示';}
		return $message.'（自然生成预测，建筑在新区块生成时出现）。';
	}

	public function onCompletion(Server $server){
		$sender = $this->playerName !== '' ? $server->getPlayerExact($this->playerName) : null;
		$result = $this->getResult();
		$message = is_array($result) ? self::message($result) : '建筑定位失败：搜索任务没有返回结果。';
		if($sender !== null){ $sender->sendMessage($message); }
		else{ $server->getLogger()->info('[Locate] '.$message); }
	}
}
