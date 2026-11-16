<?php

namespace pocketmine\entity\ai;

use pocketmine\entity\Ageable;
use pocketmine\entity\Animal;
use pocketmine\entity\Arrow;
use pocketmine\entity\Creature;
use pocketmine\entity\Creeper;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\entity\Fireball26;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Sheep;
use pocketmine\entity\Snowball;
use pocketmine\event\entity\EntityCombustEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\Item;
use pocketmine\level\Explosion;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;

/**
 * Shared goal controller for the legacy entities, informed by Java 26.2 sources.
 * Creature owns physics; Navigation26 owns the loaded-only motion motor.
 */
final class VanillaAI26{
	const MAX_STATES = 4096;
	private static $states = [];
	private static $pruneAt = 0;

	public static function supports(Creature $mob) : bool{
		return MobBehavior26::profile($mob) !== null;
	}

	public static function mode(Creature $mob) : string{
		$profile = MobBehavior26::profile($mob);
		return $profile === null ? 'ground' : $profile['mode'];
	}

	public static function configKey(Creature $mob) : string{
		return strtolower(MobBehavior26::name($mob));
	}

	public static function tick(Creature $mob, int $delta = 1) : void{
		$profile = MobBehavior26::profile($mob);
		if($profile === null){
			return;
		}
		if($mob->closed || !$mob->isAlive()){
			self::forget($mob);
			return;
		}
		$server = $mob->getLevel()->getServer();
		if((isset($server->aiEnabled) && !$server->aiEnabled) || (isset($server->aiConfig[self::configKey($mob)]) && !$server->aiConfig[self::configKey($mob)]) || $mob->getDataProperty(Entity::DATA_NO_AI)){
			return;
		}
		$name = MobBehavior26::name($mob);
		if($name === 'Zombie' || $name === 'ZombieVillager'){
			$profile['range'] = max(1, min(64, (float) ($server->aiConfig['zombie-follow-range'] ?? $profile['range'])));
			$profile['speed'] *= max(0.1, min(3, (float) ($server->aiConfig['zombie-speed-multiplier'] ?? 1)));
		}
		$now = (int) $server->getTick();
		$state = self::state($mob, $now);
		if($state->lastTick === $now){
			return;
		}
		$delta = min(20, max(1, $delta, $state->lastTick < 0 ? 1 : $now - $state->lastTick));
		$state->lastTick = $now;
		$state->seen = $now;
		self::prune($now);
		self::timers($mob, $state, $delta);
		self::run($mob, $profile, $state, $now, $delta);
		if(!$mob->closed && ($now % 20 === $mob->getId() % 20)){
			self::persist($mob, $state);
		}
	}

	/** Call after attack() has applied damage and event cancellation is final. */
	public static function onDamage(Creature $mob, EntityDamageEvent $source) : void{
		$profile = MobBehavior26::profile($mob);
		if($source->isCancelled() || $profile === null || $mob->closed || !$mob->isAlive()){
			return;
		}
		$now = (int) $mob->getLevel()->getServer()->getTick();
		$state = self::state($mob, $now);
		$state->hurtUntil = $now + 8; // preserve Living's knockback rather than overwrite it immediately.
		$attacker = $source instanceof EntityDamageByEntityEvent ? $source->getDamager() : null;
		if($profile['panic']){
			$state->panic = mt_rand(40, 100);
			$state->panicFrom = $attacker instanceof Entity ? [$attacker->x, $attacker->y, $attacker->z] : [$mob->x - mt_rand(-2, 2), $mob->y, $mob->z - mt_rand(-2, 2)];
		}
		if($attacker instanceof Living && self::valid($mob, $attacker, 64) && !self::isOwner($state, $attacker)){
			if(in_array($state->name, ['PigZombie', 'Wolf', 'Enderman', 'IronGolem'], true) || $profile['monster']){
				if(!($state->name === 'IronGolem' && self::entityName($attacker) === 'Creeper') && !($state->name === 'IronGolem' && $attacker instanceof Player && self::nbt($mob, 'PlayerCreated', 0))){
					self::anger($mob, $state, $attacker);
					if(in_array($state->name, ['PigZombie', 'Wolf', 'Silverfish'], true)){
						self::alertPack($mob, $state, $attacker, $now);
					}
				}
			}
		}
		if($state->name === 'Enderman' && $source->getCause() === EntityDamageEvent::CAUSE_PROJECTILE && $now >= $state->teleportAt){
			self::teleport($mob, $state, $now);
		}
		self::persist($mob, $state);
	}

	/**
	 * Feed hook after the caller's interaction event. True consumes one item in
	 * survival; this method deliberately does not alter the player's inventory.
	 */
	public static function feed(Creature $mob, Player $player, Item $item) : bool{
		$profile = MobBehavior26::profile($mob);
		if($profile === null || !$profile['breed'] || !$mob->isAlive() || $mob->closed || $player->isSpectator() || $player->getLevel() !== $mob->getLevel() || $mob->distanceSquared($player) > 16 || $item->getCount() <= 0){
			return false;
		}
		$state = self::state($mob, (int) $mob->getLevel()->getServer()->getTick());
		$id = $item->getId();
		if($state->name === 'Wolf' && !$state->tamed && $id === 352 && $state->anger <= 0){
			if(mt_rand(0, 2) === 0){
				$state->tamed = true;
				$state->owner = strtolower($player->getName());
				$mob->setMaxHealth(max(40, $mob->getMaxHealth()));
				$mob->setHealth($mob->getMaxHealth());
				if($mob instanceof Animal){
					$mob->setSitting(true);
				}
			}
			self::persist($mob, $state);
			return true;
		}
		if(!in_array($id, $profile['food'], true)){
			return false;
		}
		if($state->name === 'Wolf'){
			if(!$state->tamed){
				return false;
			}
			if($mob->getHealth() < $mob->getMaxHealth()){
				$amount = in_array($id, [364, 320, 424], true) ? 8 : 4;
				$event = new EntityRegainHealthEvent($mob, $amount, EntityRegainHealthEvent::CAUSE_EATING);
				$mob->heal($amount, $event);
				return !$event->isCancelled();
			}
		}
		if($state->name === 'Ocelot' && !$state->trusting){
			if(mt_rand(0, 2) === 0){
				$state->trusting = true;
			}
			self::persist($mob, $state);
			return true;
		}
		if($state->age < 0){
			$state->age = min(0, $state->age + max(1, (int) ceil(-$state->age * 0.1)));
			self::babyFlag($mob, $state->age < 0);
			self::persist($mob, $state);
			return true;
		}
		if($state->age > 0 || $state->love > 0){
			return false;
		}
		$state->love = 600;
		$state->breedTime = 0;
		if($mob instanceof Animal){
			$mob->setInLove(600);
		}
		self::persist($mob, $state);
		return true;
	}

	/** Flush only existing state before the entity serializes its NBT. */
	public static function save(Creature $mob) : void{
		$s = self::$states[$mob->getId()] ?? null;
		if($s !== null && $s->identity === spl_object_hash($mob)){
			self::persist($mob, $s);
		}
	}

	/** Owner interaction consumes no item; call after the interaction event. */
	public static function interactOwned(Creature $mob, Player $player, Item $hand) : bool{
		if(MobBehavior26::name($mob) !== 'Wolf' || !$mob->isAlive() || $mob->closed || $player->isSpectator() || $mob->getLevel() !== $player->getLevel() || $mob->distanceSquared($player) > 16){
			return false;
		}
		$s = self::state($mob, (int) $mob->getLevel()->getServer()->getTick());
		if(!self::isOwner($s, $player) || MobBehavior26::acceptsFood('Wolf', $hand->getId())){
			return false;
		}
		$mob->setSitting(!$mob->isSitting());
		$s->target = 0;
		$s->anger = 0;
		$s->angerTarget = 0;
		self::persist($mob, $s);
		return true;
	}

	public static function forget(Entity $mob) : void{
		$id = $mob->getId();
		if(isset(self::$states[$id]) && self::$states[$id]->identity === spl_object_hash($mob)){
			if(!$mob->closed && $mob instanceof Creature){
				self::persist($mob, self::$states[$id]);
			}
			unset(self::$states[$id]);
		}
		if(method_exists(Navigation26::class, 'forget')){
			Navigation26::forget((int) $mob->getId());
		}
	}

	/** Read-only diagnostics. No world/entity references are returned. */
	public static function snapshot($id = null) : array{
		if($id !== null){
			return isset(self::$states[$id]) ? self::$states[$id]->snapshot() : [];
		}
		$result = [];
		foreach(self::$states as $key => $state){
			$result[$key] = $state->snapshot();
		}
		return $result;
	}

	private static function state(Creature $mob, int $now) : MobState26{
		$id = $mob->getId();
		$identity = spl_object_hash($mob);
		$level = spl_object_hash($mob->getLevel());
		if(isset(self::$states[$id]) && self::$states[$id]->identity === $identity && self::$states[$id]->level === $level){
			return self::$states[$id];
		}
		if(count(self::$states) >= self::MAX_STATES){
			// Scalar records are bounded even when external code omits forget().
			reset(self::$states);
			unset(self::$states[key(self::$states)]);
		}
		$s = new MobState26();
		$s->identity = $identity;
		$s->level = $level;
		$s->name = MobBehavior26::name($mob);
		$s->seen = $now;
		$s->age = max(-24000, min(6000, (int) self::nbt($mob, 'Age', 0)));
		if($s->age === 0 && (($mob instanceof Ageable && $mob->isBaby()) || self::nbt($mob, 'IsBaby', 0))){
			$s->age = -24000;
		}
		self::babyFlag($mob, $s->age < 0);
		$s->love = max(0, min(600, (int) self::nbt($mob, 'InLove', $mob instanceof Animal ? $mob->inLove : 0)));
		$s->anger = max(0, min(780, (int) self::nbt($mob, 'AngerTime', 0)));
		$s->angryName = strtolower((string) self::nbt($mob, 'AngryAtName26', ''));
		$s->owner = strtolower((string) self::nbt($mob, 'OwnerName26', self::nbt($mob, 'Owner', '')));
		$s->tamed = $s->owner !== '';
		$s->trusting = (bool) self::nbt($mob, 'Trusting26', 0);
		if($mob instanceof Animal && $s->tamed){
			$mob->setSitting((bool) self::nbt($mob, 'Sitting', 0));
		}
		$s->egg = max(1, min(12000, (int) self::nbt($mob, 'EggLayTime', mt_rand(6000, 12000))));
		$s->scanAt = $now;
		$s->wanderAt = $now + ($id % 20);
		self::$states[$id] = $s;
		return $s;
	}

	private static function nbt(Entity $mob, string $key, $default){
		return isset($mob->namedtag->{$key}) ? $mob->namedtag[$key] : $default;
	}

	private static function persist(Creature $mob, MobState26 $s) : void{
		if(!$mob->namedtag instanceof CompoundTag){
			return;
		}
		if(MobBehavior26::profile($mob)['breed']){
			$mob->namedtag->Age = new IntTag('Age', $s->age);
			$mob->namedtag->InLove = new IntTag('InLove', $s->love);
			$mob->namedtag->IsBaby = new ByteTag('IsBaby', $s->age < 0 ? 1 : 0);
		}
		if(in_array($s->name, ['PigZombie', 'Enderman', 'Wolf', 'IronGolem'], true)){
			$mob->namedtag->AngerTime = new IntTag('AngerTime', $s->anger);
			$mob->namedtag->AngryAtName26 = new StringTag('AngryAtName26', $s->anger > 0 ? $s->angryName : '');
		}
		if($s->name === 'Wolf'){
			$mob->namedtag->OwnerName26 = new StringTag('OwnerName26', $s->owner);
			$mob->namedtag->Sitting = new ByteTag('Sitting', $mob instanceof Animal && $mob->isSitting() ? 1 : 0);
		}
		if($s->name === 'Ocelot'){
			$mob->namedtag->Trusting26 = new ByteTag('Trusting26', $s->trusting ? 1 : 0);
		}
		if($s->name === 'Chicken'){
			$mob->namedtag->EggLayTime = new IntTag('EggLayTime', $s->egg);
		}
	}

	private static function prune(int $now) : void{
		if($now < self::$pruneAt){
			return;
		}
		self::$pruneAt = $now + 200;
		foreach(self::$states as $id => $state){
			if($state->seen < $now - 1200){
				unset(self::$states[$id]);
			}
		}
	}

	private static function timers(Creature $mob, MobState26 $s, int $delta) : void{
		foreach(['cooldown', 'panic', 'anger', 'love'] as $timer){
			$s->{$timer} = max(0, $s->{$timer} - $delta);
		}
		if($s->anger === 0){
			$s->angerTarget = 0;
			$s->angryName = '';
		}
		if($s->age !== 0){
			$s->age = $s->age < 0 ? min(0, $s->age + $delta) : max(0, $s->age - $delta);
			self::babyFlag($mob, $s->age < 0);
		}
		if($mob instanceof Animal){
			// Explicit external setInLove() is a supported API, never a proximity trigger.
			if($mob->inLove > $s->love + $delta && $s->age === 0){
				$s->love = min(600, $mob->inLove);
			}
			$mob->inLove = $s->love;
		}
	}

	private static function babyFlag(Creature $mob, bool $baby) : void{
		if($mob instanceof Ageable){
			$wasBaby = $mob->isBaby();
			$mob->setDataFlag(Ageable::DATA_AGEABLE_FLAGS, Ageable::DATA_FLAG_BABY, $baby);
			if($wasBaby !== $baby && method_exists($mob, 'refreshDimensions26')){
				$mob->refreshDimensions26($baby);
			}
		}
	}

	private static function run(Creature $mob, array $p, MobState26 $s, int $now, int $delta) : void{
		$level = $mob->getLevel();
		if(!Environment26::loadedBox($level, $mob->x - 1, $mob->z - 1, $mob->x + 1, $mob->z + 1)){
			$s->goal = 'unloaded_boundary';
			self::stop($mob, $p['mode']);
			return;
		}
		if($p['sunlight'] && $now >= $s->sunAt){
			$s->sunAt = $now + 20;
			if(self::day($mob) && !$mob->isInsideOfWater() && !self::helmet($mob) && Environment26::canSeeSky($mob)){
				$ev = new EntityCombustEvent($mob, 8);
				$level->getServer()->getPluginManager()->callEvent($ev);
				if(!$ev->isCancelled()){
					$mob->setOnFire($ev->getDuration());
				}
			}
		}
		if($s->name === 'Chicken' && $s->age >= 0){
			$s->egg -= $delta;
			if($s->egg <= 0){
				$level->dropItem($mob->add(0, 0.2, 0), Item::get(344, 0, 1));
				$s->egg = mt_rand(6000, 12000);
			}
		}
		if($s->name === 'Enderman' && $now >= $s->teleportAt){
			if($mob->isInsideOfWater()){
				self::teleport($mob, $s, $now);
				$ev = new EntityDamageEvent($mob, EntityDamageEvent::CAUSE_DROWNING, 1);
				$mob->attack($ev->getFinalDamage(), $ev);
			}elseif(self::day($mob) && Environment26::canSeeSky($mob) && mt_rand(0, 29) === 0){
				$s->target = 0;
				self::teleport($mob, $s, $now);
			}
		}
		if($s->name === 'Bat' && method_exists($mob, 'isResting') && $mob->isResting()){
			$above = Environment26::block($level, (int) floor($mob->x), (int) floor($mob->y + $mob->height + 0.1), (int) floor($mob->z));
			$near = self::nearestPlayer($mob, 4, false);
			if($above === null || $above === 0 || $near !== null || $s->panic > 0){
				$mob->setResting(false);
				$mob->setDataProperty(16, Entity::DATA_TYPE_BYTE, 0);
			}else{
				$s->goal = 'rest';
				self::stop($mob, 'flying');
				return;
			}
		}
		if($now < $s->hurtUntil){
			$s->goal = 'hurt';
			return;
		}
		// MOVE goals are exclusive; look may run alongside an idle or stationary goal.
		if($p['mode'] !== 'swimming' && $mob->isInsideOfWater()){
			$s->goal = 'float';
			$mob->setMotion(new Vector3($mob->motionX * 0.8, max(0.12, $mob->motionY), $mob->motionZ * 0.8));
			return;
		}
		if($s->panic > 0 && $p['panic']){
			$s->goal = 'panic';
			$from = $s->panicFrom === null ? [$mob->x - 1, $mob->y, $mob->z] : $s->panicFrom;
			self::flee($mob, new Vector3($from[0], $from[1], $from[2]), $p['speed'] * 1.5, $p['mode']);
			return;
		}
		if($s->name === 'Wolf' && $mob instanceof Animal && $mob->isSitting()){
			$s->goal = 'sit';
			self::stop($mob, $p['mode']);
			return;
		}
		$target = $s->target ? $level->getEntity($s->target) : null;
		if(!$target instanceof Living || !self::allowed($mob, $target, $p, $s)){
			$target = null;
			$s->target = 0;
			$s->targetReason = '';
		}
		if($now >= $s->scanAt){
			$s->scanAt = $now + MobBehavior26::SCAN_INTERVAL;
			if($target === null){
				$target = self::acquire($mob, $p, $s, $now);
				$s->target = $target === null ? 0 : $target->getId();
			}
		}
		// Cats and wolves outrank approach goals; an already swollen creeper keeps its fuse priority.
		if($s->fuse === 0 && in_array($s->name, ['Creeper', 'Skeleton'], true)){
			$avoid = self::avoid($mob, $s, false);
			if($avoid !== null){
				$s->goal = 'avoid';
				self::flee($mob, $avoid, $p['speed'] * 1.2, $p['mode']);
				return;
			}
		}
		if($target !== null){
			$s->goal = 'combat';
			self::combat($mob, $target, $p, $s, $now, $delta);
			return;
		}
		if($s->fuse > 0 && self::swell($mob, null, $s, $delta)){
			return;
		}
		if($p['breed'] && $s->love > 0 && $s->age === 0 && self::breed($mob, $p, $s, $now, $delta)){
			return;
		}
		$s->breedTime = 0;
		$s->mate = 0;
		$tempt = self::tempt($mob, $p, $s);
		if($tempt !== null){
			$s->goal = 'tempt';
			self::look($mob, $tempt);
			if($mob->distanceSquared($tempt) > 6.25){
				Navigation26::steer($mob, $tempt, $p['speed'] * 1.1, $p['mode']);
			}else{
				self::stop($mob, $p['mode']);
			}
			return;
		}
		if($s->name === 'Wolf' && $s->tamed){
			$owner = self::owner($mob, $s);
			if($owner !== null && $mob->distanceSquared($owner) > 16){
				$s->goal = 'follow_owner';
				Navigation26::steer($mob, $owner, $p['speed'], 'ground');
				return;
			}
		}
		if($p['breed'] && $s->age < 0){
			foreach(Environment26::nearby($mob, 8) as $parent){
				if($parent instanceof Creature && MobBehavior26::name($parent) === $s->name && !self::isBaby($parent) && abs($parent->y - $mob->y) <= 4 && $mob->distanceSquared($parent) >= 9){
					$s->goal = 'follow_parent';
					Navigation26::steer($mob, $parent, $p['speed'] * 1.1, $p['mode']);
					return;
				}
			}
		}
		$avoid = self::avoid($mob, $s, true);
		if($avoid !== null){
			$s->goal = 'avoid';
			self::flee($mob, $avoid, $p['speed'] * 1.4, $p['mode']);
			return;
		}
		if($s->name === 'Sheep' && self::graze($mob, $s, $delta)){
			$s->goal = 'graze';
			self::stop($mob, 'ground');
			return;
		}
		self::idle($mob, $p, $s, $now);
	}

	private static function entityName(Entity $entity) : string{
		return $entity instanceof Creature ? MobBehavior26::name($entity) : '';
	}

	private static function valid(Creature $mob, Entity $target, float $range) : bool{
		if($target === $mob || $target->closed || !$target->isAlive() || $mob->getLevel() !== $target->getLevel() || $mob->distanceSquared($target) > min(64, $range) ** 2){
			return false;
		}
		if($target instanceof Player && (!$target->isOnline() || $target->isCreative() || $target->isSpectator())){
			return false;
		}
		return Environment26::chunk($target->getLevel(), ((int) floor($target->x)) >> 4, ((int) floor($target->z)) >> 4) !== null;
	}

	private static function allowed(Creature $mob, Living $target, array $p, MobState26 $s) : bool{
		if(!self::valid($mob, $target, $p['range']) || self::isOwner($s, $target) || ($p['monster'] && (int) $mob->getLevel()->getServer()->getDifficulty() === 0)){
			return false;
		}
		if(in_array($p['target'], ['retaliate', 'stare'], true) && ($s->anger <= 0 || $s->angerTarget !== $target->getId())){
			return false;
		}
		if($s->name === 'IronGolem' && self::entityName($target) === 'Creeper'){
			return false;
		}
		if($s->name === 'Wolf' && $target instanceof Creature){
			$other = self::$states[$target->getId()] ?? null;
			if($other !== null && $other->tamed && $s->owner !== '' && $other->owner === $s->owner){
				return false;
			}
		}
		return true;
	}

	private static function acquire(Creature $mob, array $p, MobState26 $s, int $now){
		if($p['monster'] && (int) $mob->getLevel()->getServer()->getDifficulty() === 0){
			return null;
		}
		if($s->anger > 0 && !$s->angerTarget && $s->angryName !== ''){
			foreach($mob->getLevel()->getPlayers() as $player){
				if(strtolower($player->getName()) === $s->angryName){ $s->angerTarget = $player->getId(); break; }
			}
		}
		if($s->anger > 0 && $s->angerTarget){
			$angry = $mob->getLevel()->getEntity($s->angerTarget);
			if($angry instanceof Living && self::allowed($mob, $angry, $p, $s) && Navigation26::canSee($mob, $angry, $p['range'])){
				$s->targetReason = 'retaliation';
				return $angry;
			}
		}
		if($p['target'] === 'none' || $p['target'] === 'retaliate'){
			return null;
		}
		$best = null;
		$bestDistance = $p['range'] ** 2 + 1;
		foreach(Environment26::nearby($mob, $p['range']) as $target){
			if(!$target instanceof Living || !self::valid($mob, $target, $p['range'])){
				continue;
			}
			$name = self::entityName($target);
			$chosen = false;
			switch($p['target']){
				case 'player': $chosen = $target instanceof Player || ($name === 'IronGolem' && $s->name !== 'Ghast'); break;
				case 'zombie': $chosen = $target instanceof Player || in_array($name, ['Villager', 'IronGolem'], true); break;
				case 'spider': $chosen = (!self::day($mob) || !Environment26::canSeeSky($mob)) && ($target instanceof Player || $name === 'IronGolem'); break;
				case 'golem': $chosen = $target instanceof Creature && MobBehavior26::isMonster($target) && !in_array($name, ['Creeper', 'PigZombie'], true); break;
				case 'snow_golem': $chosen = $target instanceof Creature && MobBehavior26::isMonster($target); break;
				case 'chicken': $chosen = $name === 'Chicken' && $s->age >= 0; break;
				case 'stare': $chosen = $target instanceof Player && self::staring($mob, $target); break;
			}
			if(!$chosen || !Navigation26::canSee($mob, $target, $p['range'])){
				continue;
			}
			$distance = $mob->distanceSquared($target);
			if($distance < $bestDistance){
				$bestDistance = $distance;
				$best = $target;
			}
		}
		if($p['target'] === 'stare'){
			if($best === null){
				$s->stare = 0;
				$s->stareTarget = 0;
				return null;
			}
			if($s->stareTarget !== $best->getId()){
				$s->stareTarget = $best->getId();
				$s->stare = $now;
				return null;
			}
			if($now - $s->stare < 5){
				return null;
			}
			self::anger($mob, $s, $best);
			$s->targetReason = 'stare';
		}else{
			$s->targetReason = $best === null ? '' : $p['target'];
		}
		return $best;
	}

	private static function anger(Creature $mob, MobState26 $s, Living $attacker) : void{
		$s->anger = mt_rand(400, 780);
		$s->angerTarget = $attacker->getId();
		$s->angryName = $attacker instanceof Player ? strtolower($attacker->getName()) : '';
		$s->target = $attacker->getId();
		$s->targetReason = 'retaliation';
		$s->scanAt = 0;
	}

	private static function alertPack(Creature $mob, MobState26 $state, Living $attacker, int $now) : void{
		foreach(Environment26::nearby($mob, 16) as $friend){
			if(!$friend instanceof Creature || MobBehavior26::name($friend) !== $state->name || !self::valid($friend, $attacker, 32)){
				continue;
			}
			$s = self::state($friend, $now);
			if($state->name === 'Wolf' && ($s->owner !== $state->owner || self::isOwner($s, $attacker))){
				continue;
			}
			self::anger($friend, $s, $attacker);
			self::persist($friend, $s);
		}
	}

	private static function combat(Creature $mob, Living $target, array $p, MobState26 $s, int $now, int $delta) : void{
		self::look($mob, $target);
		$visible = Navigation26::canSee($mob, $target, $p['range']);
		$distance = $mob->distanceSquared($target);
		if($p['attack'] === 'swell'){
			if(self::swell($mob, $target, $s, $delta)){
				return;
			}
		}elseif($p['attack'] === 'arrow' || $p['attack'] === 'snowball'){
			if($visible && $distance <= $p['reach'] ** 2){
				self::stop($mob, $p['mode']);
				if($s->cooldown === 0){
					$s->cooldown = $p['attack'] === 'arrow' && $mob->getLevel()->getServer()->getDifficulty() >= 3 ? 20 : $p['cooldown'];
					self::shoot($mob, $target, $p['attack'], $s);
				}
				return;
			}
		}elseif($p['attack'] === 'blaze' && $visible && $distance > 4 && $distance < $p['reach'] ** 2){
			self::stop($mob, 'flying');
			if($s->cooldown === 0){
				++$s->shotStep;
				if($s->shotStep === 1){
					$s->cooldown = 60;
				}elseif($s->shotStep <= 4){
					$s->cooldown = 6;
					self::shoot($mob, $target, 'small_fireball', $s);
				}else{
					$s->cooldown = 100;
					$s->shotStep = 0;
				}
			}
			return;
		}elseif($p['attack'] === 'ghast'){
			if($visible && $distance <= $p['reach'] ** 2){
				$s->charge = $s->cooldown > 0 ? 0 : $s->charge + $delta;
				if($s->charge >= 20 && $s->cooldown === 0){
					self::shoot($mob, $target, 'large_fireball', $s);
					$s->cooldown = 40;
					$s->charge = 0;
				}
			}else{
				$s->charge = max(0, $s->charge - $delta);
			}
			if($distance > 256){
				Navigation26::steer($mob, $target->add(0, 4, 0), $p['speed'], 'flying');
			}else{
				self::stop($mob, 'flying');
			}
			return;
		}else{
			$reach = $p['attack'] === 'blaze' ? 2.0 : $p['reach'];
			if($visible && $distance <= $reach ** 2 && $s->cooldown === 0){
				$s->cooldown = $p['cooldown'];
				self::melee($mob, $target, $p, $s);
			}
			if($s->name === 'Enderman' && $target instanceof Player && self::staring($mob, $target) && $visible){
				self::stop($mob, 'ground');
				return;
			}
		}
		if($visible || $s->anger > 0){
			Navigation26::steer($mob, $target, $p['speed'], $p['mode']);
			if(in_array($s->name, ['Slime', 'LavaSlime', 'Rabbit'], true) && $mob->onGround){
				$mob->setMotion(new Vector3($mob->motionX, $s->name === 'LavaSlime' ? 0.48 : 0.38, $mob->motionZ));
			}
			if(in_array($s->name, ['Spider', 'CaveSpider'], true) && !empty($mob->isCollidedHorizontally)){
				$mob->setMotion(new Vector3($mob->motionX, max(0.2, $mob->motionY), $mob->motionZ));
			}
		}else{
			self::stop($mob, $p['mode']);
			$s->target = 0;
		}
	}

	private static function melee(Creature $mob, Living $target, array $p, MobState26 $s) : void{
		$damage = $p['damage'];
		if($s->name === 'Slime' || $s->name === 'LavaSlime'){
			$size = max(1, min(127, (int) self::nbt($mob, 'Size', 0) + 1));
			if($s->name === 'Slime' && $size === 1){ return; }
			$damage = $size + ($s->name === 'LavaSlime' ? 2 : 0);
		}
		if($s->name === 'IronGolem'){
			$damage = mt_rand(7, 21);
		}elseif($p['monster']){
			$difficulty = (int) $mob->getLevel()->getServer()->getDifficulty();
			if($difficulty === 0){
				return;
			}
			$damage = $difficulty === 1 ? max(1, (int) floor($damage / 2) + 1) : ($difficulty >= 3 ? (int) floor($damage * 1.5) : $damage);
		}
		$ev = new EntityDamageByEntityEvent($mob, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
		++$s->attackSerial;
		$target->attack($ev->getFinalDamage(), $ev);
		$s->lastAttackCancelled = $ev->isCancelled();
		if($ev->isCancelled()){
			return;
		}
		$ev->useArmors();
		if($s->name === 'CaveSpider' && $mob->getLevel()->getServer()->getDifficulty() >= 2){
			$effect = Effect::getEffect(Effect::POISON);
			$effect->setDuration($mob->getLevel()->getServer()->getDifficulty() >= 3 ? 300 : 140);
			$target->addEffect($effect);
		}
		if($s->name === 'IronGolem'){
			$target->setMotion(new Vector3($target->motionX, $target->motionY + 0.4, $target->motionZ));
		}
	}

	private static function swell(Creature $mob, $target, MobState26 $s, int $delta) : bool{
		$old = $s->fuse;
		$visible = $target instanceof Living && self::valid($mob, $target, 16) && Navigation26::canSee($mob, $target, 16);
		// Source SwellGoal: start inside 3, retain until 7 or loss of sight.
		$swell = $s->cooldown === 0 && $visible && $mob->distanceSquared($target) < ($s->fuse > 0 ? 49 : 9);
		$s->fuse = $swell ? min(30, $s->fuse + $delta) : max(0, $s->fuse - $delta);
		$mob->setDataProperty(Creeper::DATA_SWELL_DIRECTION, Entity::DATA_TYPE_INT, $swell ? 1 : -1);
		$mob->setDataProperty(Creeper::DATA_SWELL_OLD, Entity::DATA_TYPE_INT, $old);
		$mob->setDataProperty(Creeper::DATA_SWELL, Entity::DATA_TYPE_INT, $s->fuse);
		if($s->fuse > 0){
			$s->goal = 'swell';
			self::stop($mob, 'ground');
		}
		if($s->fuse >= 30){
			$size = method_exists($mob, 'isPowered') && $mob->isPowered() ? 6.0 : 3.0;
			// Legacy Explosion reads neighbors synchronously; require its entire blast area loaded.
			$radius = $size * 3 + 3;
			if(!Environment26::explosionLoaded($mob, $size)){
				$s->fuse = 0;
				return true;
			}
			$explosion = new Explosion(new Position($mob->x, $mob->y, $mob->z, $mob->getLevel()), $size, $mob);
			if(!empty($mob->getLevel()->getServer()->aiConfig['creeperexplode'])){
				$explosion->explodeA();
			}
			$exploded = $explosion->explodeB(); // EntityExplodeEvent includes the real creeper and level.
			$s->fuse = 0;
			if($exploded){
				self::forget($mob);
				$mob->close();
			}else{
				$s->cooldown = 20;
			}
			return true;
		}
		return $s->fuse > 0;
	}

	private static function shoot(Creature $mob, Living $target, string $type, MobState26 $s) : void{
		$eye = $mob->add(0, max(0.3, $mob->getEyeHeight() - 0.1), 0);
		$direction = $target->add(0, $target->getEyeHeight() * 0.7, 0)->subtract($eye);
		$horizontal = sqrt($direction->x ** 2 + $direction->z ** 2);
		if($type === 'arrow' || $type === 'snowball'){
			$direction->y += $horizontal * ($type === 'arrow' ? 0.09 : 0.12);
		}
		$direction = $direction->normalize();
		$eye = $eye->add($direction->multiply(max(0.6, $mob->width / 2 + 0.3)));
		if(!Environment26::loadedPath($mob, $eye, 3)){
			return;
		}
		$chunk = Environment26::chunk($mob->getLevel(), ((int) floor($eye->x)) >> 4, ((int) floor($eye->z)) >> 4);
		if($chunk === null){
			return;
		}
		$motion = $direction->multiply($type === 'arrow' ? 1.6 : ($type === 'snowball' ? 1.2 : 0.7));
		$nbt = self::entityNBT($eye, $motion, $mob->yaw, $mob->pitch);
		if($type === 'arrow'){
			$projectile = new Arrow($chunk, $nbt, $mob, false);
			if(method_exists($projectile, 'setPickupAllowed')){ $projectile->setPickupAllowed(false); }
			$ev = new EntityShootBowEvent($mob, Item::get(261), $projectile, 1.0);
			$mob->getLevel()->getServer()->getPluginManager()->callEvent($ev);
			if($ev->isCancelled()){
				$projectile->close();
				return;
			}
			$projectile = $ev->getProjectile();
			$projectile->setMotion($motion->multiply(max(0, min(3, $ev->getForce()))));
		}elseif($type === 'snowball'){
			$projectile = new Snowball($chunk, $nbt, $mob);
		}else{
			$nbt->Large26 = new ByteTag('Large26', $type === 'large_fireball' ? 1 : 0);
			$projectile = new Fireball26($chunk, $nbt, $mob);
		}
		if($projectile instanceof \pocketmine\entity\Projectile){
			$ev = new ProjectileLaunchEvent($projectile);
			$mob->getLevel()->getServer()->getPluginManager()->callEvent($ev);
			if($ev->isCancelled()){
				$projectile->close();
				return;
			}
		}
		++$s->attackSerial;
		$projectile->spawnToAll();
	}

	public static function entityNBT(Vector3 $position, Vector3 $motion, float $yaw = 0, float $pitch = 0) : CompoundTag{
		return new CompoundTag('', [
			new ListTag('Pos', [new DoubleTag('', $position->x), new DoubleTag('', $position->y), new DoubleTag('', $position->z)]),
			new ListTag('Motion', [new DoubleTag('', $motion->x), new DoubleTag('', $motion->y), new DoubleTag('', $motion->z)]),
			new ListTag('Rotation', [new FloatTag('', $yaw), new FloatTag('', $pitch)])
		]);
	}

	private static function breed(Creature $mob, array $p, MobState26 $s, int $now, int $delta) : bool{
		$mate = $s->mate ? $mob->getLevel()->getEntity($s->mate) : null;
		if(!$mate instanceof Creature || MobBehavior26::name($mate) !== $s->name || !self::valid($mob, $mate, 8)){
			$mate = null;
			$best = 65;
			foreach(Environment26::nearby($mob, 8) as $candidate){
				if(!$candidate instanceof Creature || MobBehavior26::name($candidate) !== $s->name){
					continue;
				}
				$other = self::state($candidate, $now);
				if($other->love > 0 && $other->age === 0 && $other->panic === 0 && ($s->name !== 'Wolf' || ($s->tamed && $other->tamed)) && $mob->distanceSquared($candidate) < $best){
					$best = $mob->distanceSquared($candidate);
					$mate = $candidate;
				}
			}
			$s->mate = $mate === null ? 0 : $mate->getId();
			$s->breedTime = 0;
		}
		if($mate === null){
			return false;
		}
		$other = self::state($mate, $now);
		if($other->love <= 0 || $other->age !== 0 || $other->panic > 0){
			$s->mate = 0;
			return false;
		}
		$s->goal = 'breed';
		self::look($mob, $mate);
		Navigation26::steer($mob, $mate, $p['speed'], $p['mode']);
		$s->breedTime += $delta;
		if($s->breedTime >= 60 && $mob->distanceSquared($mate) < 9 && Navigation26::canSee($mob, $mate, 8)){
			$position = $mob->add(($mate->x - $mob->x) / 2, 0, ($mate->z - $mob->z) / 2);
			$chunk = Environment26::chunk($mob->getLevel(), ((int) floor($position->x)) >> 4, ((int) floor($position->z)) >> 4);
			if($chunk !== null && Environment26::canStand($mob, $position)){
				$nbt = self::entityNBT($position, new Vector3());
				$nbt->Age = new IntTag('Age', -24000);
				$nbt->IsBaby = new ByteTag('IsBaby', 1);
				if($s->name === 'Sheep'){
					$nbt->Color = new ByteTag('Color', $mob->getColor());
				}
				if($s->name === 'Wolf'){
					$nbt->OwnerName26 = new StringTag('OwnerName26', $s->owner);
				}
				$child = Entity::createEntity($s->name, $chunk, $nbt);
				if($child instanceof Creature){
					self::babyFlag($child, true);
					$child->spawnToAll();
					$s->love = $other->love = 0;
					$s->age = $other->age = 6000;
					$s->breedTime = $other->breedTime = 0;
					if($mob instanceof Animal){ $mob->inLove = 0; }
					if($mate instanceof Animal){ $mate->inLove = 0; }
					self::persist($mob, $s);
					self::persist($mate, $other);
				}
			}
		}
		return true;
	}

	private static function tempt(Creature $mob, array $p, MobState26 $s){
		if(empty($p['tempt'])){
			return null;
		}
		$best = null;
		$distance = 100;
		foreach(Environment26::nearby($mob, 10) as $player){
			if(!$player instanceof Player || !$player->isOnline() || $player->isSpectator() || ($s->name === 'Wolf' && $s->anger > 0)){
				continue;
			}
			$item = $player->getInventory()->getItemInHand();
			if(in_array($item->getId(), $p['tempt'], true) && $mob->distanceSquared($player) < $distance && Navigation26::canSee($mob, $player, 10)){
				$distance = $mob->distanceSquared($player);
				$best = $player;
			}
		}
		return $best;
	}

	private static function avoid(Creature $mob, MobState26 $s, bool $passive){
		$range = $s->name === 'Rabbit' ? 10 : 8;
		if(!in_array($s->name, $passive ? ['Villager', 'Rabbit', 'Ocelot'] : ['Creeper', 'Skeleton'], true)){
			return null;
		}
		$best = null;
		$distance = $range ** 2 + 1;
		foreach(Environment26::nearby($mob, $range) as $entity){
			if(!$entity instanceof Living || !self::valid($mob, $entity, $range)){
				continue;
			}
			$name = self::entityName($entity);
			$flee = ($s->name === 'Creeper' && $name === 'Ocelot') || ($s->name === 'Skeleton' && $name === 'Wolf')
				|| ($s->name === 'Villager' && $entity instanceof Creature && MobBehavior26::isMonster($entity) && !in_array($name, ['Enderman', 'PigZombie'], true))
				|| ($s->name === 'Rabbit' && ($entity instanceof Player || $name === 'Wolf' || ($entity instanceof Creature && MobBehavior26::isMonster($entity))))
				|| ($s->name === 'Ocelot' && !$s->trusting && $entity instanceof Player);
			if($flee && $mob->distanceSquared($entity) < $distance && Navigation26::canSee($mob, $entity, $range)){
				$distance = $mob->distanceSquared($entity);
				$best = $entity;
			}
		}
		return $best;
	}

	private static function graze(Creature $mob, MobState26 $s, int $delta) : bool{
		if(!$mob instanceof Sheep){
			return false;
		}
		$x = (int) floor($mob->x); $y = (int) floor($mob->y); $z = (int) floor($mob->z);
		$plant = Environment26::block($mob->getLevel(), $x, $y, $z);
		$grass = Environment26::block($mob->getLevel(), $x, $y - 1, $z);
		$edible = $plant === 31 || $grass === 2;
		if($s->eat === 0 && $edible && mt_rand(1, self::isBaby($mob) ? 50 : 1000) === 1){
			$s->eat = 40;
		}
		if($s->eat <= 0){
			return false;
		}
		$before = $s->eat;
		$s->eat = max(0, $s->eat - $delta);
		$mob->eatTimer = $s->eat;
		$mob->eating = $s->eat > 0;
		if($before > 4 && $s->eat <= 4 && $edible){
			$mob->sheared = false;
			$mob->namedtag->Sheared = new ByteTag('Sheared', 0);
			$mob->setDataProperty(Sheep::DATA_COLOR_INFO, Entity::DATA_TYPE_BYTE, $mob->getColor() & 15);
			if($s->age < 0){ $s->age = min(0, $s->age + 1200); }
			// Legacy server has no mobGriefing rule; configurable terrain mutation is opt-in.
			if(!empty($mob->getLevel()->getServer()->aiConfig['mobgriefing'])){
				$mob->getLevel()->setBlock(new Vector3($x, $plant === 31 ? $y : $y - 1, $z), \pocketmine\block\Block::get($plant === 31 ? 0 : 3));
			}
		}
		return true;
	}

	private static function idle(Creature $mob, array $p, MobState26 $s, int $now) : void{
		if($now >= $s->wanderAt){
			$s->wanderAt = $now + mt_rand(60, 140);
			$s->wanderUntil = $now + mt_rand(35, 80);
			$s->wander = [$mob->x + mt_rand(-8, 8), $mob->y + ($p['mode'] === 'ground' ? 0 : mt_rand(-4, 4)), $mob->z + mt_rand(-8, 8)];
		}
		if($s->wander !== null && $now < $s->wanderUntil){
			$s->goal = 'wander';
			$point = new Vector3($s->wander[0], max(1, min(124, $s->wander[1])), $s->wander[2]);
			if(!Navigation26::steer($mob, $point, $p['speed'] * 0.75, $p['mode'])){
				$s->wanderUntil = $now;
			}
			if($s->name === 'Rabbit' && $mob->onGround){
				$mob->setMotion(new Vector3($mob->motionX, 0.32, $mob->motionZ));
			}
		}else{
			$s->goal = 'look';
			self::stop($mob, $p['mode']);
			$player = self::nearestPlayer($mob, 8, false);
			if($player !== null && Navigation26::canSee($mob, $player, 8)){
				self::look($mob, $player);
			}
		}
	}

	private static function nearestPlayer(Creature $mob, float $range, bool $attackable){
		$best = null; $distance = $range ** 2 + 1;
		foreach(Environment26::nearby($mob, $range) as $player){
			if($player instanceof Player && $player->isOnline() && !$player->isSpectator() && (!$attackable || !$player->isCreative()) && $mob->distanceSquared($player) < $distance){
				$distance = $mob->distanceSquared($player);
				$best = $player;
			}
		}
		return $best;
	}

	private static function isBaby(Creature $mob) : bool{
		return ($mob instanceof Ageable && $mob->isBaby()) || (isset(self::$states[$mob->getId()]) && self::$states[$mob->getId()]->age < 0) || self::nbt($mob, 'IsBaby', 0);
	}

	private static function owner(Creature $mob, MobState26 $s){
		foreach($mob->getLevel()->getPlayers() as $player){
			if(self::isOwner($s, $player) && $player->isOnline() && !$player->isSpectator() && $mob->distanceSquared($player) <= 64 ** 2){
				return $player;
			}
		}
		return null;
	}

	private static function isOwner(MobState26 $s, Entity $target) : bool{
		return $s->tamed && $target instanceof Player && strtolower($target->getName()) === $s->owner;
	}

	private static function staring(Creature $mob, Player $player) : bool{
		if(method_exists($player->getInventory(), 'getHelmet') && $player->getInventory()->getHelmet()->getId() === 86){
			return false;
		}
		$to = $mob->add(0, $mob->getEyeHeight(), 0)->subtract($player->add(0, $player->getEyeHeight(), 0));
		$distance = $to->length();
		if($distance < 0.01){
			return false;
		}
		$view = $player->getDirectionVector();
		$dot = ($view->x * $to->x + $view->y * $to->y + $view->z * $to->z) / $distance;
		return $dot > 1.0 - 0.025 / $distance && Navigation26::canSee($player, $mob, 64);
	}

	private static function helmet(Creature $mob) : bool{
		if(method_exists($mob, 'getInventory')){
			$inventory = $mob->getInventory();
			if($inventory !== null && method_exists($inventory, 'getHelmet') && $inventory->getHelmet()->getId() !== 0){
				return true;
			}
		}
		$armor = self::nbt($mob, 'Armor', self::nbt($mob, 'ArmorItems', null));
		if($armor instanceof ListTag && isset($armor[3]) && $armor[3] instanceof CompoundTag && (int) $armor[3]['id'] !== 0){
			return true;
		}
		return (int) self::nbt($mob, 'Helmet26', 0) !== 0;
	}

	private static function day(Creature $mob) : bool{
		$level = $mob->getLevel();
		if(method_exists($level, 'getDimension') && $level->getDimension() !== 0){
			return false;
		}
		$time = ((int) $level->getTime() % 24000 + 24000) % 24000;
		return $time < 12500 || $time > 23500;
	}

	private static function look(Creature $mob, Entity $target) : void{
		$dx = $target->x - $mob->x; $dz = $target->z - $mob->z;
		$dy = $target->y + $target->getEyeHeight() - $mob->y - $mob->getEyeHeight();
		$yaw = -rad2deg(atan2($dx, $dz));
		$pitch = -rad2deg(atan2($dy, max(0.01, sqrt($dx * $dx + $dz * $dz))));
		$change = fmod($yaw - $mob->yaw + 540, 360) - 180;
		$mob->setRotation($mob->yaw + max(-30, min(30, $change)), max(-60, min(60, $pitch)));
	}

	private static function stop(Creature $mob, string $mode) : void{
		$mob->setMotion(new Vector3(0, $mode === 'ground' ? $mob->motionY : 0, 0));
	}

	private static function flee(Creature $mob, Vector3 $from, float $speed, string $mode) : void{
		$dx = $mob->x - $from->x; $dz = $mob->z - $from->z;
		$length = sqrt($dx * $dx + $dz * $dz);
		if($length < 0.1){ $dx = 1; $dz = mt_rand(-10, 10) / 10; $length = sqrt(1 + $dz * $dz); }
		$target = $mob->add($dx / $length * 8, $mode === 'ground' ? 0 : 1, $dz / $length * 8);
		Navigation26::steer($mob, $target, min(0.4, $speed), $mode);
	}

	private static function teleport(Creature $mob, MobState26 $s, int $now) : bool{
		$s->teleportAt = $now + 20;
		$level = $mob->getLevel();
		for($attempt = 0; $attempt < 16; ++$attempt){
			$x = $mob->x + mt_rand(-24, 24); $z = $mob->z + mt_rand(-24, 24);
			$y = min(123, max(2, (int) floor($mob->y) + mt_rand(-8, 8)));
			if(Environment26::chunk($level, ((int) floor($x)) >> 4, ((int) floor($z)) >> 4) === null){
				continue;
			}
			for($drop = 0; $drop < 16 && $y > 1; ++$drop, --$y){
				$position = new Vector3($x, $y, $z);
				if(!Environment26::loadedPath($mob, $position) || !Environment26::canStand($mob, $position)){
					continue;
				}
				$event = new EntityTeleportEvent($mob, new Position($mob->x, $mob->y, $mob->z, $level), new Position($x, $y, $z, $level));
				$level->getServer()->getPluginManager()->callEvent($event);
				$to = $event->getTo();
				if($event->isCancelled() || $to->getLevel() !== $level || !Environment26::loadedPath($mob, $to) || !Environment26::canStand($mob, $to)){
					return false;
				}
				// Teleport event was dispatched and revalidated above; do not dispatch a second mutable destination.
				$mob->setMotion(new Vector3());
				$result = $mob->setPositionAndRotation($to, $mob->yaw, $mob->pitch);
				if($result !== false){
					$mob->resetFallDistance();
					return true;
				}
			}
		}
		return false;
	}
}
