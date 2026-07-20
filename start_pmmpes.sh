#!/bin/bash
# MPMPESCore 启动脚本（s390x，原生 PHP 8.4.16）
# 用法: ./start_pmmpes.sh   (后台启动) / ./start_pmmpes.sh stop (发 stop 关服)
DIR="$(cd -P "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP="/home/linux1/bin/php7/bin/php"
PIDFILE="/tmp/mpmpes.pid"
LOGFILE="/tmp/mpmpes.log"

case "$1" in
  stop)
    if [ -f "$PIDFILE" ]; then
      PID=$(cat "$PIDFILE")
      echo "stop" > /tmp/mpmpes_cmd.fifo 2>/dev/null
      kill -TERM "$PID" 2>/dev/null
      echo "已发送停止信号给 PID $PID"
    else
      echo "无 PID 文件，服务可能未运行"
    fi
    ;;
  *)
    cd "$DIR"
    # 用 fifo 接控制台命令，便于 stop
    rm -f /tmp/mpmpes_cmd.fifo
    mkfifo /tmp/mpmpes_cmd.fifo
    setsid bash -c "exec $PHP ./src/pocketmine/PocketMine.php < /tmp/mpmpes_cmd.fifo > $LOGFILE 2>&1 & echo \$! > $PIDFILE"
    sleep 1
    echo "已启动，PID=$(cat $PIDFILE 2>/dev/null)，日志: $LOGFILE"
    ;;
esac
