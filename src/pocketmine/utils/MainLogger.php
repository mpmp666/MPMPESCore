<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\utils;

use LogLevel;
use pocketmine\Thread;
use pocketmine\Worker;

class MainLogger extends \AttachableThreadedLogger{
	protected $logFile;
	protected $logStream;
	protected $shutdown;
	protected $logDebug;
	private $logResource;
	/** @var MainLogger */
	public static $logger = null;
	
	private $consoleCallback;

	/** Extra Settings */
	protected $write = true;

	public $shouldSendMsg = "";
	public $shouldRecordMsg = false;
	private $lastGet = 0;

	public function setSendMsg($b){
		$this->shouldRecordMsg = $b;
		$this->lastGet = time();
	}

	public function getMessages(){
		$msg = $this->shouldSendMsg;
		$this->shouldSendMsg = "";
		$this->lastGet = time();
		return $msg;
	}

	/**
	 * @param string $logFile
	 * @param bool   $logDebug
	 *
	 * @throws \RuntimeException
	 */
	public function __construct($logFile, $logDebug = false){
		if(static::$logger instanceof MainLogger){
			throw new \RuntimeException("MainLogger has been already created");
		}
		static::$logger = $this;
		touch($logFile);
		$this->logFile = $logFile;
		$this->logDebug = (bool) $logDebug;
		$this->logStream = new \Volatile;
		$this->start();
	}

	/**
	 * @return MainLogger
	 */
	public static function getLogger(){
		return static::$logger;
	}

	public function emergency($message, $name = "EMERGENCY"){
		$this->send($message, \LogLevel::EMERGENCY, $name, TextFormat::RED);
	}

	public function alert($message, $name = "ALERT"){
		$this->send($message, \LogLevel::ALERT, $name, TextFormat::RED);
	}

	public function critical($message, $name = "CRITICAL"){
		$this->send($message, \LogLevel::CRITICAL, $name, TextFormat::RED);
	}

	public function error($message, $name = "ERROR"){
		$this->send($message, \LogLevel::ERROR, $name, TextFormat::DARK_RED);
	}

	public function warning($message, $name = "WARNING"){
		$this->send($message, \LogLevel::WARNING, $name, TextFormat::YELLOW);
	}

	public function notice($message, $name = "NOTICE"){
		$this->send($message, \LogLevel::NOTICE, $name, TextFormat::AQUA);
	}

	public function info($message, $name = "INFO"){
		$this->send($message, \LogLevel::INFO, $name, TextFormat::WHITE);
	}

	public function debug($message, $name = "DEBUG"){
		if($this->logDebug === false){
			return;
		}
		$this->send($message, \LogLevel::DEBUG, $name, TextFormat::GRAY);
	}

	/**
	 * @param bool $logDebug
	 */
	public function setLogDebug($logDebug){
		$this->logDebug = (bool) $logDebug;
	}

	public function logException(\Throwable $e, $trace = null){
		if($trace === null){
			$trace = $e->getTrace();
		}
		$errstr = $e->getMessage();
		$errfile = $e->getFile();
		$errno = $e->getCode();
		$errline = $e->getLine();

		$errorConversion = [
			0 => "EXCEPTION",
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
			E_STRICT => "E_STRICT",
			E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
			E_DEPRECATED => "E_DEPRECATED",
			E_USER_DEPRECATED => "E_USER_DEPRECATED",
		];
		if($errno === 0){
			$type = LogLevel::CRITICAL;
		}else{
			$type = ($errno === E_ERROR or $errno === E_USER_ERROR) ? LogLevel::ERROR : (($errno === E_USER_WARNING or $errno === E_WARNING) ? LogLevel::WARNING : LogLevel::NOTICE);
		}
		$errno = isset($errorConversion[$errno]) ? $errorConversion[$errno] : $errno;
		if(($pos = strpos($errstr, "\n")) !== false){
			$errstr = substr($errstr, 0, $pos);
		}
		$errfile = \pocketmine\cleanPath($errfile);
		$this->log($type, get_class($e) . ": \"$errstr\" ($errno) in \"$errfile\" at line $errline");
		foreach(@\pocketmine\getTrace(1, $trace) as $i => $line){
			$this->debug($line);
		}
	}

	public function log($level, $message){
		switch($level){
			case LogLevel::EMERGENCY:
				$this->emergency($message);
				break;
			case LogLevel::ALERT:
				$this->alert($message);
				break;
			case LogLevel::CRITICAL:
				$this->critical($message);
				break;
			case LogLevel::ERROR:
				$this->error($message);
				break;
			case LogLevel::WARNING:
				$this->warning($message);
				break;
			case LogLevel::NOTICE:
				$this->notice($message);
				break;
			case LogLevel::INFO:
				$this->info($message);
				break;
			case LogLevel::DEBUG:
				$this->debug($message);
				break;
		}
	}

	public function shutdown(){
		$this->shutdown = true;
	}

	protected function send($message, $level, $prefix, $color){
		$now = time();

		$thread = \Thread::getCurrentThread();
		if($thread === null){
			$threadName = "Server thread";
		}elseif($thread instanceof Thread or $thread instanceof Worker){
			$threadName = $thread->getThreadName() . " thread";
		}else{
			$threadName = (new \ReflectionClass($thread))->getShortName() . " thread";
		}

		if($this->shouldRecordMsg){
			if((time() - $this->lastGet) >= 10) $this->shouldRecordMsg = false; // 10 secs timeout
			else{
				if(strlen($this->shouldSendMsg) >= 10000) $this->shouldSendMsg = "";
				$this->shouldSendMsg .= $color . "|" . $prefix . "|" . trim($message, "\r\n") . "\n";
			}
		}

		//Perf: build the plain-text line from the RAW message (single clean() pass),
		//and only run toANSI() when the terminal actually renders colors.
		//Old code always ran toANSI() AND clean()ed the longer ANSI-expanded string.
		$time = date("H:i:s", $now);
		$cleanMessage = "[" . $time . "] [" . $threadName . "/" . $prefix . "]: " . TextFormat::clean((string) $message);

		if(Terminal::hasFormattingCodes()){
			$message = TextFormat::toANSI(TextFormat::AQUA . "[" . $time . "] " . TextFormat::RESET . $color . "[" . $threadName . "/" . $prefix . "]:" . " " . $message . TextFormat::RESET);
			echo $message . PHP_EOL;
		}else{
			$message = $cleanMessage;
			echo $message . PHP_EOL;
		}

		if(isset($this->consoleCallback)){
			call_user_func($this->consoleCallback);
		}

		if($this->attachment instanceof \ThreadedLoggerAttachment){
			$this->attachment->call($level, $message);
		}

		$this->logStream[] = date("Y-m-d", $now) . " " . $cleanMessage . "\n";
		if($this->logStream->count() === 1){
			$this->synchronized(function(){
				$this->notify();
			});
		}
	}

	/*public function run(){
		$this->shutdown = false;
		if($this->write){
			$this->logResource = fopen($this->logFile, "a+b");
			if(!is_resource($this->logResource)){
				throw new \RuntimeException("Couldn't open log file");
			}

			while($this->shutdown === false){
				if(!$this->write) {
					fclose($this->logResource);
					break;
				}
				$this->synchronized(function(){
					while($this->logStream->count() > 0){
						$chunk = $this->logStream->shift();
						fwrite($this->logResource, $chunk);
					}

					$this->wait(25000);
				});
			}

			if($this->logStream->count() > 0){
				while($this->logStream->count() > 0){
					$chunk = $this->logStream->shift();
					fwrite($this->logResource, $chunk);
				}
			}

			fclose($this->logResource);
		}
	}*/

	/** Real thread body (pmmp\thread\Thread::run -> onRun). Drains logStream to disk.
	 *
	 * Perf: keeps the log file open for the whole thread lifetime and writes
	 * through a 64KB stdio buffer instead of doing open()+write()+close()
	 * (file_put_contents with FILE_APPEND) for every single log line.
	 * The queue is always drained, even when writing is disabled, so the
	 * shared logStream can never grow without bound (memory leak fix).
	 */
	public function onRun(){
		$this->logResource = null;
		$buf = "";
		$flushCounter = 0;

		while(!$this->isTerminated() and !$this->shutdown){
			while($this->logStream->count() > 0){
				$buf .= $this->logStream->shift();
			}

			if($this->write){
				if($this->logResource === null){
					$res = @fopen($this->logFile, "ab");
					if(is_resource($res)){
						stream_set_write_buffer($res, 65536);
						$this->logResource = $res;
					}
				}

				if($buf !== "" and $this->logResource !== null){
					@fwrite($this->logResource, $buf);
					if(++$flushCounter >= 25){ //~50ms at 2ms loop interval
						$flushCounter = 0;
						@fflush($this->logResource);
					}
				}
			}elseif($this->logResource !== null){ //Writing disabled: close the handle, keep draining
				@fclose($this->logResource);
				$this->logResource = null;
			}
			$buf = "";

			usleep(2000);
		}

		//Final drain on shutdown so the tail of the log is not lost
		while($this->logStream->count() > 0){
			$buf .= $this->logStream->shift();
		}
		if($this->write and $buf !== ""){
			if($this->logResource === null){
				$this->logResource = @fopen($this->logFile, "ab");
			}
			if(is_resource($this->logResource)){
				@fwrite($this->logResource, $buf);
				@fflush($this->logResource);
			}
		}
		if(is_resource($this->logResource)){
			@fclose($this->logResource);
		}
		$this->logResource = null;
	}

	public function setWrite($write){
		$this->write = $write;
	}
	
	public function setConsoleCallback($callback){
		$this->consoleCallback = $callback;
	}
}
