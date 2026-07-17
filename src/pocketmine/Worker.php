<?php

/*
 * Genisys PHP 8.5 port: pocketmine\Worker extends the global \Worker shim.
 */

namespace pocketmine;

/**
 * This class must be extended by all custom worker classes.
 * The actual threading is emulated by the global \Worker shim
 * (see pthreads_shim.php); this subclass keeps the class loader plumbing.
 */
abstract class Worker extends \Worker{

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
		$this->registerClassLoader();
	}
}
