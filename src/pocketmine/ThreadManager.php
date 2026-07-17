<?php

/*
 * Genisys PHP 8.5 port: ThreadManager shim. No pthreads \Volatile.
 * Holds registered pseudo-threads and pumps them each server tick.
 */

namespace pocketmine;

class ThreadManager{

	/** @var ThreadManager */
	private static $instance = null;

	/** @var Thread[]|Worker[] */
	private $threads = [];

	public static function init(){
		self::$instance = new ThreadManager();
	}

	/**
	 * @return ThreadManager
	 */
	public static function getInstance(){
		return self::$instance;
	}

	/**
	 * @param Worker|Thread $thread
	 */
	public function add($thread){
		// PHP 8.5 shim: check against the GLOBAL \Thread/\Worker base classes.
		// RakLibServer/MainLogger extend \Thread/\Worker directly (not the
		// pocketmine\Thread subclass), so checking pocketmine\Thread here would
		// wrongly reject them and they'd never be pumped by tickAll().
		if($thread instanceof \Thread or $thread instanceof \Worker){
			$this->threads[spl_object_hash($thread)] = $thread;
		}
	}

	/**
	 * @param Worker|Thread $thread
	 */
	public function remove($thread){
		unset($this->threads[spl_object_hash($thread)]);
	}

	/**
	 * @return Worker[]|Thread[]
	 */
	public function getAll(){
		return array_values($this->threads);
	}

	/** Pump every registered pseudo-thread once (called each server tick). */
	public function tickAll(){
		foreach($this->threads as $thread){
			if($thread->isRunning() and !$thread->isKilled()){
				$thread->onTick();
			}
		}
	}
}
