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
			$loader = Server::getInstance()->getLoader();
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
		if($this->getClassLoader() === null){
			$this->setClassLoader();
		}
	}
}
