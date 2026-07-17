<?php

/*
 * Genisys real-multithread: ThreadManager now only tracks live threads
 * for monitoring / shutdown. Real threads run on their own (pmmp\thread),
 * so the old per-tick pump (tickAll) is gone.
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

	/**
	 * No-op kept for backward compatibility. Real threads self-drive via
	 * pmmp\thread; the main loop no longer pumps them.
	 *
	 * @deprecated
	 */
	public function tickAll(){
		// intentionally empty
	}
}
