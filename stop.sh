#!/data/data/com.termux/files/usr/bin/sh
#
# stop.sh  ->  shuts down everything that start.sh started.

cd "$(dirname "$0")" || exit 1

PIDDIR=".run"

for n in sync web admin; do
  f="$PIDDIR/$n.pid"
  if [ -f "$f" ]; then
    pid="$(cat "$f")"
    if kill "$pid" 2>/dev/null; then
      echo "$n gestoppt (PID $pid)"
    else
      echo "$n war nicht (mehr) aktiv"
    fi
    rm -f "$f"
  else
    echo "$n: keine PID-Datei"
  fi
done

# clean up any orphaned php servers (e.g. after a crash):
pkill -f "php -S 0.0.0.0:8080" 2>/dev/null
pkill -f "php -S 0.0.0.0:8081" 2>/dev/null
pkill -f "php sync.php --loop" 2>/dev/null

command -v termux-wake-unlock >/dev/null 2>&1 && termux-wake-unlock

echo "Alles gestoppt."
