<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

namespace raklib\server;


class RakLibServer extends \Thread{
    protected $port;
    protected $interface;
    /** @var \ThreadedLogger */
    protected $logger;
    protected $loader;

    /** @var \Volatile */
    public $loadPaths;

    protected $shutdown;

    /** @var \Volatile */
    protected $externalQueue;
    /** @var \Volatile */
    protected $internalQueue;

	/** @var \Volatile frp 直接喂包队列 (主线程 frpc → RakLib 线程 SessionManager) */
	public $frpInQueue;
	/** @var \Volatile frp 回包队列表 (隧道名 => \Volatile 队列), 每条隧道独立, 消除多隧道回包路由歧义 */
	public $frpOutQueues;

	protected $mainPath;

    /** @var bool PROXY protocol 真实 IP 还原开关 */
    public $proxyProtocol = false;
    /** @var bool frp 直接喂包开关 (frpc 经队列喂包, 不走本地 UDP socket) */
    public $frpFeed = false;

	/**
	 * @param \ThreadedLogger $logger
	 * @param \ClassLoader    $loader
	 * @param int             $port
	 * @param string          $interface
	 *
	 * @throws \Throwable
	 */
    public function __construct(\ThreadedLogger $logger, \ClassLoader $loader, $port, $interface = "0.0.0.0", $proxyProtocol = false, $frpFeed = false){
        $this->port = (int) $port;
        if($port < 1 or $port > 65536){
            throw new \Exception("Invalid port range");
        }

        $this->interface = $interface;
        $this->proxyProtocol = $proxyProtocol;
        $this->frpFeed = $frpFeed;
        $this->logger = $logger;
        $this->loader = $loader;
        $loadPaths = [];
        $this->addDependency($loadPaths, new \ReflectionClass($logger));
        $this->addDependency($loadPaths, new \ReflectionClass($loader));
        $this->loadPaths = new \Volatile;
        foreach(array_reverse($loadPaths) as $name => $path){
            $this->loadPaths[$name] = $path;
        }
        $this->shutdown = false;

        $this->externalQueue = new \Volatile;
        $this->internalQueue = new \Volatile;
        $this->frpInQueue = new \Volatile;
        $this->frpOutQueues = new \Volatile;

	    if(\Phar::running(true) !== ""){
		    $this->mainPath = \Phar::running(true);
	    }else{
		    $this->mainPath = \getcwd() . DIRECTORY_SEPARATOR;
	    }
        $this->start();
    }

    /**
     * Real thread entry point (pmmp\thread\Thread::run).
     *
     * pmmpthread 6.x forbids storing non-thread-safe objects (e.g. SessionManager,
     * a plain class) in a thread-safe class property — assigning one throws
     * NonThreadSafeValueError. So we keep $sessionManager as a LOCAL variable
     * inside run() (which executes entirely in the child thread), not a property.
     * The network loop and SessionManager lifetime both live here.
     */
    public function run() : void{
        try{
            // INHERIT_ALL already shares class definitions with the child, but
            // re-registering the injected autoloader is still safe & cheap.
            if($this->loader !== null){
                $this->loader->register(true);
            }
            gc_enable();
            error_reporting(-1);
            ini_set("display_errors", 1);
            ini_set("display_startup_errors", 1);
            set_error_handler([$this, "errorHandler"], E_ALL);
            register_shutdown_function([$this, "shutdownHandler"]);

            $socket = new UDPServerSocket($this->getLogger(), $this->port, $this->interface);
            $sessionManager = new SessionManager($this, $socket);
            $sessionManager->proxyProtocol = $this->proxyProtocol;
            $sessionManager->frpFeed = $this->frpFeed;
            // 强制关闭 MCPE 0.14 RakNet 端口校验: 客户端上报的是它连接的外网端口
            // (如 frp remotePort 40507), 而服务端 getPort() 是内网端口(如 19132),
            // 内外不一致会导致 OPEN_CONNECTION_REQUEST_2 / CLIENT_HANDSHAKE 握手被拒
            // (MOTD 能看到但进不去)。直接关闭校验, 无论是否使用 frp。
            $sessionManager->portChecking = false;

            while(!$this->isShutdown()){
                if($sessionManager !== null){
                    $sessionManager->tickOnce();
                }
                usleep(1000); // ~1ms yield to avoid busy-spinning the core
            }
        }catch(\Throwable $e){
            // NB: STDERR constant is not inherited into the child thread under
            // pmmpthread, so use error_log() (writes to stderr via SAPI) instead.
            error_log("\n=== RAKLIB run() THROW ===");
            error_log(get_class($e).": ".$e->getMessage());
            error_log("File: ".$e->getFile().":".$e->getLine());
            error_log($e->getTraceAsString());
            throw $e;
        }
    }

    protected function addDependency(array &$loadPaths, \ReflectionClass $dep){
        if($dep->getFileName() !== false){
            $loadPaths[$dep->getName()] = $dep->getFileName();
        }

        if($dep->getParentClass() instanceof \ReflectionClass){
            $this->addDependency($loadPaths, $dep->getParentClass());
        }

        foreach($dep->getInterfaces() as $interface){
            $this->addDependency($loadPaths, $interface);
        }
    }

    public function isShutdown(){
        return $this->shutdown === true;
    }

    public function shutdown(){
        $this->shutdown = true;
    }

    public function getPort(){
        return $this->port;
    }

    public function getInterface(){
        return $this->interface;
    }

    /**
     * @return \ThreadedLogger
     */
    public function getLogger(){
        return $this->logger;
    }

    /**
     * @return \Threaded
     */
    public function getExternalQueue(){
        return $this->externalQueue;
    }

    /**
     * @return \Threaded
     */
    public function getInternalQueue(){
        return $this->internalQueue;
    }

    public function pushMainToThreadPacket($str){
        $this->internalQueue[] = $str;
    }

    public function readMainToThreadPacket(){
        return $this->internalQueue->shift();
    }

    public function pushThreadToMainPacket($str){
        $this->externalQueue[] = $str;
    }

    public function readThreadToMainPacket(){
        return $this->externalQueue->shift();
    }

    // ==================== frp 直接喂包队列 ====================
    // 入站帧格式: "\x00" . chr(len(tunnel)) . tunnel . chr(len(ip)) . ip . writeShort(port) . data
    //            (带隧道名, SessionManager 据此记录玩家归属隧道)
    // 回包帧格式: "\x01" . chr(len(ip)) . ip . writeShort(port) . data
    //            每条隧道一个独立队列 frpOutQueues[tunnel], 由对应 Frpc 实例独享

    /** 确保某隧道存在回包队列 */
    public function ensureFrpOutQueue($tunnel){
        if(!isset($this->frpOutQueues[$tunnel])){
            $this->frpOutQueues[$tunnel] = new \Volatile;
        }
    }

    /** 主线程 frpc 调用: 推入一个从 frps 收到的 UDP 包 (真实地址 + 隧道名) */
    public function pushFrpInbound($tunnel, $ip, $port, $data){
        $this->frpInQueue[] = "\x00" . chr(strlen($tunnel)) . $tunnel . chr(strlen($ip)) . $ip . \raklib\Binary::writeShort($port) . $data;
    }

    /** RakLib 线程 SessionManager 调用: 取一个待喂入的包 */
    public function readFrpInbound(){
        return $this->frpInQueue->shift();
    }

    /** RakLib 线程 SessionManager 调用: 推一个回包到指定隧道队列 */
    public function pushFrpOutbound($tunnel, $ip, $port, $data){
        if(!isset($this->frpOutQueues[$tunnel])){
            $this->frpOutQueues[$tunnel] = new \Volatile;
        }
        $this->frpOutQueues[$tunnel][] = "\x01" . chr(strlen($ip)) . $ip . \raklib\Binary::writeShort($port) . $data;
    }

    /** 主线程 frpc 调用: 取某隧道的一个 frp 回包 */
    public function readFrpOutbound($tunnel){
        if(!isset($this->frpOutQueues[$tunnel])){
            return null;
        }
        return $this->frpOutQueues[$tunnel]->shift();
    }

	public function shutdownHandler(){
		if($this->shutdown !== true){
			$this->getLogger()->emergency("RakLib crashed!");
		}
	}

	public function errorHandler($errno, $errstr, $errfile, $errline, $context = null, $trace = null){
		if(error_reporting() === 0){
			return false;
		}
		// DEBUG: 致命错误直接抛出, 暴露真实崩溃点
		if($errno === E_ERROR or $errno === E_USER_ERROR or $errno === E_RECOVERABLE_ERROR){
			throw new \Error("$errstr in $errfile:$errline");
		}
		// PHP 8.5 port: deprecations (E_STRICT removed, implicit casts, etc.) must
		// NOT be treated as fatal errors that trigger a crash dump.
		if($errno === E_DEPRECATED or $errno === E_USER_DEPRECATED){
			return true;
		}
		$errorConversion = [
			E_ERROR => "E_ERROR",
			E_WARNING => "E_WARNING",
			E_PARSE => "E_PARSE",
			E_NOTICE => "E_NOTICE",
			E_CORE_ERROR => "E_CORE_ERROR",
			E_CORE_WARNING => "E_CORE_WARNING",
			E_COMPILE_ERROR => "E_COMPILE_ERROR",
			E_COMPILE_WARNING => "E_COMPILE_WARNING",
			E_USER_ERROR => "E_USER_ERROR",
			E_USER_WARNING => "E_USER_WARNING",
			E_USER_NOTICE => "E_USER_NOTICE",
			E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
			E_DEPRECATED => "E_DEPRECATED",
			E_USER_DEPRECATED => "E_USER_DEPRECATED",
		];
		$errno = isset($errorConversion[$errno]) ? $errorConversion[$errno] : $errno;
		if(($pos = strpos($errstr, "\n")) !== false){
			$errstr = substr($errstr, 0, $pos);
		}
		$oldFile = $errfile;
		$errfile = $this->cleanPath($errfile);

		$this->getLogger()->debug("An $errno error happened: \"$errstr\" in \"$errfile\" at line $errline");

		foreach(($trace = $this->getTrace($trace === null ? 3 : 0, $trace)) as $i => $line){
			$this->getLogger()->debug($line);
		}

		return true;
	}

	public function getTrace($start = 1, $trace = null){
		if($trace === null){
			if(function_exists("xdebug_get_function_stack")){
				$trace = array_reverse(xdebug_get_function_stack());
			}else{
				$e = new \Exception();
				$trace = $e->getTrace();
			}
		}

		$messages = [];
		$j = 0;
		for($i = (int) $start; isset($trace[$i]); ++$i, ++$j){
			$params = "";
			if(isset($trace[$i]["args"]) or isset($trace[$i]["params"])){
				if(isset($trace[$i]["args"])){
					$args = $trace[$i]["args"];
				}else{
					$args = $trace[$i]["params"];
				}
				foreach($args as $name => $value){
					$params .= (is_object($value) ? get_class($value) . " " . (method_exists($value, "__toString") ? $value->__toString() : "object") : gettype($value) . " " . @strval($value)) . ", ";
				}
			}
			$messages[] = "#$j " . (isset($trace[$i]["file"]) ? $this->cleanPath($trace[$i]["file"]) : "") . "(" . (isset($trace[$i]["line"]) ? $trace[$i]["line"] : "") . "): " . (isset($trace[$i]["class"]) ? $trace[$i]["class"] . (($trace[$i]["type"] === "dynamic" or $trace[$i]["type"] === "->") ? "->" : "::") : "") . $trace[$i]["function"] . "(" . substr($params, 0, -2) . ")";
		}

		return $messages;
	}

	public function cleanPath($path){
		return rtrim(str_replace(["\\", ".php", "phar://", rtrim(str_replace(["\\", "phar://"], ["/", ""], $this->mainPath), "/")], ["/", "", "", ""], $path), "/");
	}

}

