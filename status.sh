#!/data/data/com.termux/files/usr/bin/sh
#
# status.sh  ->  shows which processes are running.

cd "$(dirname "$0")" || exit 1

PIDDIR=".run"

check() {                       # name  label
  f="$PIDDIR/$1.pid"
  if [ -f "$f" ] && kill -0 "$(cat "$f")" 2>/dev/null; then
    echo "  [an]  $2 (PID $(cat "$f"))"
  else
    echo "  [aus] $2"
  fi
}

echo "Status:"
check sync  "Sync-Schleife"
check web   "Diashow-Server   (8080)"
check admin "Admin-Server     (8081)"
