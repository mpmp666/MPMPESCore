<?php

/*
 * Genisys PHP 8.5 port: global pthreads-compatible shims.
 * PHP 8.x has no pthreads extension. These global classes emulate the
 * tiny subset of pthreads API that Genisys/raklib use, driven synchronously
 * by the main server loop (pocketmine\ThreadManager::tickAll()).
 *
 * Real threads are NOT created. Long-running run() loops are pumped one
 * step per server tick via onTick(). For raklib this means the network
 * loop runs on the main thread.
 */

namespace {

	/**
	 * Global \Thread shim. Mirrors the pthreads Thread start/run model but
	 * is single-process: start() flags state and registers with ThreadManager;
	 * the main loop calls onTick() each tick (subclasses move their run() body
	 * there, or keep run() as the one-shot entry).
	 */
	class Thread{

		private $running = false;
		private $joined = false;
		protected $isKilled = false;

		public function start(int $options = 0){
			$tm = \pocketmine\ThreadManager::getInstance();
			if($tm === null){
				\pocketmine\ThreadManager::init();
				$tm = \pocketmine\ThreadManager::getInstance();
			}
			$tm->add($this);
			if(!$this->running and !$this->joined){
				$this->running = true;
				// Do NOT auto-run(): run() bodies are blocking loops. The main
				// server loop pumps onTick() instead (see ThreadManager::tickAll).
				if(method_exists($this, "onStart")){
					$this->onStart();
				}
				return true;
			}
			return false;
		}

		/** Per-tick pump. Override in subclasses that had a run() loop. */
		public function onTick(){ }

		public function isRunning(){
			return $this->running;
		}

		public function isJoined(){
			return $this->joined;
		}

		public function isTerminated(){
			return $this->isKilled;
		}

		public function isKilled(){
			return $this->isKilled;
		}

		public function quit(){
			$this->isKilled = true;
			$this->running = false;
			$this->joined = true;
			\pocketmine\ThreadManager::getInstance()->remove($this);
		}

		/** pthreads compatibility no-ops */
		public function notify(){ }
		public function wait(int $timeout = 0){ }
		public function synchronized(callable $block){
			return $block($this);
		}
		public function stack($work){ }
		public function unstack(){ }
		public function shutdown(){ }

		public function getThreadName(){
			return (new \ReflectionClass($this))->getShortName();
		}


		/** pthreads compatibility: no real threads, main process returns null. */
		public static function getCurrentThread(){
			return null;
		}

		public static function getCurrentThreadId(){
			return 0;
		}


		/** pthreads compatibility no-op (single process). */
		public function join(){
			return true;
		}
	}

	/**
	 * Global \Worker shim. Adds a task queue pumped one-per-tick.
	 */
	class Worker extends Thread{
		/** pthreads compat: collect finished task (no-op in single-process shim). */
		public function collector(\Collectable $task){
			// In the shim, finished tasks are dropped by onTick(); nothing to reap.
			return true;
		}

		private $queue = [];

		public function stack($work){
			$this->queue[] = $work;
		}

		public function unstack(){
			return array_pop($this->queue);
		}

		public function getStacked(){
			return count($this->queue);
		}

		public function onTick(){
			if(!empty($this->queue)){
				$task = array_shift($this->queue);
				try{
					if(method_exists($task, "run")){
						$task->run();
					}
				}catch(\Throwable $e){
					if(method_exists($this, "handleException")){
						$this->handleException($e);
					}else{
						\pocketmine\utils\MainLogger::getLogger()->logException($e);
					}
				}
			}
		}
	}

	/**
	 * Global \Threaded shim: a thread-safe-ish shared container.
	 * We use a plain ArrayObject; shifts/pushes work as in pthreads.
	 */
	class Threaded extends \ArrayObject{
		/** pthreads compat: AsyncTask extends Threaded, needs isRunning()/isTerminated(). */
		public function isRunning(){
			return $this->running ?? false;
		}

		public function isTerminated(){
			return $this->joined ?? false;
		}

		public function shift(){
			if($this->count() === 0){
				return null;
			}
			$vals = array_values($this->getArrayCopy());
			$out = array_shift($vals);
			$this->exchangeArray($vals);
			return $out;
		}

		#[\ReturnTypeWillChange]
		public function count(){
			return count($this->getArrayCopy());
		}
	}

	/** \Volatile is just a Threaded in pthreads 3.x. */
	class Volatile extends Threaded{ }

	/** \Collectable interface (pthreads). */
	interface Collectable{
		public function isGarbage();
	}
}
