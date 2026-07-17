<?php

/*
 * Genisys PHP 8.5 port: pocketmine\Thread extends the global \Thread shim.
 * Keeps the classLoader plumbing that Server/CommandReader rely on.
 */

namespace pocketmine;

/**
 * This class must be extended by all custom threading classes.
 * The actual threading is emulated by the global \Thread shim
 * (see pthreads_shim.php); this subclass adds class loader plumbing.
 */
abstract class Thread extends \Thread{

	/** @var \ClassLoader */
	protected $classLoader;

	public function getClassLoader(){
		return $this->classLoader;
	}

	public function setClassLoader(?\ClassLoader $loader = null){
		if($loader === null){
			// Server may not exist yet when this thread is started from inside
			// the Server constructor (RakLibServer/CommandReader). Defer: leave
			// classLoader null and let the subclass use its own injected loader.
			$server = Server::getInstance();
			if($server === null){
				return;
			}
			$loader = $server->getLoader();
		}
		$this->classLoader = $loader;
	}

	public function registerClassLoader(){
		if(!interface_exists("ClassLoader", false)){
			require(\pocketmine\PATH . "src/spl/ClassLoader.php");
			require(\pocketmine\PATH . "src/spl/BaseClassLoader.php");
			require(\pocketmine\PATH . "src/pocketmine/CompatibleClassLoader.php");
		}
		if($this->classLoader !== null){
			$this->classLoader->register(true);
		}
	}

	public function onStart(){
		// Only auto-resolve a class loader if the server is up; otherwise the
		// subclass already holds the loader it needs (or doesn't need one).
		if($this->getClassLoader() === null and Server::getInstance() !== null){
			$this->setClassLoader();
		}
	}
}
