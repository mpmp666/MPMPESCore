<?php

/*
 * Genisys real-multithread bridge for PHP 8.x + pmmpthread 6.x.
 *
 * Replaces the single-process shim with genuine pthreads v3 semantics
 * (pmmp\thread\*). The prebuilt pocketmine PHP binary ships with
 * `--enable-zts --enable-pmmpthread` (pmmpthread 6.3.0), so real threads
 * work out of the box. We only need to bridge Genisys' old pthreads-API
 * usage onto the modern classes.
 *
 *   old global name      ->  real class
 *   ------------------------------------------------
 *   \Thread              ->  bridge Thread (extending pmmp\thread\Thread)
 *   \Worker              ->  bridge Worker (extending pmmp\thread\Worker)
 *   \Threaded            ->  pmmp\thread\ThreadSafe   (object, NOT array-access)
 *   \Volatile            ->  pmmp\thread\ThreadSafeArray (array-access container)
 *   \Collectable         ->  interface (kept for AsyncTask compat)
 *
 * IMPORTANT pthreads v3 semantics:
 *   - pmmp\thread\ThreadSafe CANNOT be used as an array ([] / shift / count).
 *     Anything that was `new \Threaded` AND used as a queue/array MUST become
 *     `new \Volatile` (== ThreadSafeArray) instead.
 *   - Only pmmp\thread\ThreadSafeArray supports [] / shift() / count() / foreach.
 *
 * Why INHERIT_ALL (not INHERIT_NONE):
 *   pmmpthread 6.x requires Thread::start(1 arg). With INHERIT_NONE the child
 *   thread does NOT inherit the parent's user function / class definitions, so
 *   any method call inside run() jumps to a NULL address -> SIGSEGV. Using
 *   INHERIT_ALL makes the child inherit all runtime definitions, so user code
 *   (onStart/onRun, loggers, class loaders) works directly. No manual
 *   bootstrap re-registration is needed.
 */

namespace {

	use pmmp\thread\Thread as NativeThread;
	use pmmp\thread\Worker as NativeWorker;
	use pmmp\thread\ThreadSafe;
	use pmmp\thread\ThreadSafeArray;

	/**
	 * Bridge for Genisys' \Thread. Real threads are created by pmmp\thread\Thread.
	 * Subclasses implement onStart() (class loader / init, runs in child thread)
	 * and onRun() (the actual thread body). A blocking loop belongs in onRun().
	 */
	abstract class Thread extends NativeThread{

		/**
		 * Start a real OS thread. Registers it with ThreadManager and launches it
		 * with INHERIT_ALL so the child inherits all parent runtime definitions
		 * (functions, classes, autoloader) and can run user code directly.
		 */
		public function start(int $options = NativeThread::INHERIT_ALL) : bool{
			if(\pocketmine\ThreadManager::getInstance() === null){
				\pocketmine\ThreadManager::init();
			}
			\pocketmine\ThreadManager::getInstance()->add($this);
			return parent::start($options);
		}

		/**
		 * Entry point executed inside the OS thread by pmmp\thread.
		 * Mirrors the old start()->onStart() + per-tick onTick() model by
		 * funnelling both into onStart()/onRun().
		 */
		public function run() : void{
			if(method_exists($this, "onStart")){
				$this->onStart();
			}
			if(method_exists($this, "onRun")){
				$this->onRun();
			}
		}

		/** Genisys calls $thread->quit() to stop; map to join() for a real Thread (idempotent). */
		public function quit(){
			if(!$this->isJoined()){
				$this->join();
			}
		}
	}

	/**
	 * Bridge for Genisys' \Worker. Same onStart()/onRun() funnel.
	 * Tasks are submitted via stack() (pmmp\thread\Worker::stack).
	 */
	abstract class Worker extends NativeWorker{

		public function start(int $options = NativeWorker::INHERIT_ALL) : bool{
			if(\pocketmine\ThreadManager::getInstance() === null){
				\pocketmine\ThreadManager::init();
			}
			\pocketmine\ThreadManager::getInstance()->add($this);
			return parent::start($options);
		}

		public function run() : void{
			if(method_exists($this, "onStart")){
				$this->onStart();
			}
			if(method_exists($this, "onRun")){
				$this->onRun();
			}
		}

		/** Genisys calls $worker->quit() to stop; map to shutdown() for a real Worker (idempotent).
		 *  On s390x + PHP 8.4 ZTS, Worker::shutdown() does not reliably wake a worker
		 *  that is blocked in sleep(), so calling parent join/wait here would deadlock
		 *  the main thread in "Stopping other threads" until ServerKiller force-kills.
		 *  We only signal shutdown() and return; PHP reclaims the thread at exit(0). */
		public function quit(){
			if(!$this->isShutdown()){
				$this->shutdown();
			}
		}
	}

	/**
	 * Global \Threaded now maps to pmmp\thread\ThreadSafe.
	 * WARNING: ThreadSafe is NOT array-accessible. Code that used
	 * `new \Threaded` as a queue MUST be changed to `new \Volatile`.
	 * We keep the name for instanceof/extends compatibility, but any
	 * array-style usage will fatal at runtime (by design, to surface bugs).
	 */
	class Threaded extends ThreadSafe{
		/** pthreads compat no-op kept from old shim */
		public function isRunning(){
			return $this->running ?? false;
		}

		public function isTerminated(){
			return $this->joined ?? false;
		}
	}

	/**
	 * \Volatile is the array-capable container (ThreadSafeArray).
	 * pmmp\thread\ThreadSafeArray is final, so alias directly instead of extending.
	 * Use this for every queue / shared array that used to be `new \Threaded`.
	 */
	if(!class_exists('Volatile', false)){
		class_alias(\pmmp\thread\ThreadSafeArray::class, 'Volatile');
	}

	/**
	 * \Runnable maps to pmmp\thread\Runnable (abstract class, requires run(): void).
	 * Worker::stack() only accepts pmmp\thread\Runnable instances, so AsyncTask
	 * must extend this to be stackable onto a real Worker.
	 */
	if(!class_exists('Runnable', false)){
		class_alias(\pmmp\thread\Runnable::class, 'Runnable');
	}

	/** \Collectable interface (pthreads). AsyncTask implements this. */
	if(!interface_exists('Collectable', false)){
		interface Collectable{
			public function isGarbage();
		}
	}
}
