<?php

namespace pocketmine\entity\ai;

/** Small scalar-only record; never retains mobs, players, levels or chunks. */
final class MobState26{
	public $identity;
	public $level;
	public $name;
	public $lastTick = -1;
	public $seen = 0;
	public $goal = 'idle';
	public $target = 0;
	public $targetReason = '';
	public $anger = 0;
	public $angerTarget = 0;
	public $angryName = '';
	public $panic = 0;
	public $panicFrom = null;
	public $hurtUntil = 0;
	public $cooldown = 0;
	public $fuse = 0;
	public $scanAt = 0;
	public $wanderAt = 0;
	public $wanderUntil = 0;
	public $wander = null;
	public $love = 0;
	public $age = 0;
	public $breedTime = 0;
	public $mate = 0;
	public $egg = 0;
	public $eat = 0;
	public $owner = '';
	public $tamed = false;
	public $trusting = false;
	public $stare = 0;
	public $stareTarget = 0;
	public $shotStep = 0;
	public $charge = 0;
	public $teleportAt = 0;
	public $sunAt = 0;
	public $attackSerial = 0;
	public $lastAttackCancelled = false;

	public function snapshot() : array{
		return get_object_vars($this);
	}
}
