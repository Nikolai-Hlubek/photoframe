#!/data/data/com.termux/files/usr/bin/sh
#
# start.sh  ->  faehrt alles zusammen hoch:
#   - termux-wake-lock (Bildschirm/Prozess wachhalten)
#   - sync.php --loop   (NAS-Abgleich, nur bei Quelle synology aktiv)
#   - Diashow-Server    auf Port 8080
#   - Admin-Server      auf Port 8081
#
# PIDs liegen in .run/, Ausgaben in logs/. Stoppen mit ./stop.sh

cd "$(dirname "$0")" || exit 1

PIDDIR=".run"
mkdir -p "$PIDDIR" logs

is_running() { [ -f "$1" ] && kill -0 "$(cat "$1")" 2>/dev/null; }

if is_running "$PIDDIR/web.pid"; then
  echo "Laeuft anscheinend schon. Erst ./stop.sh ausfuehren."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "FEHLER: php nicht gefunden. 'pkg install php php-gd' ausfuehren."
  exit 1
fi

# Bildschirm/Prozess wachhalten (falls in Termux verfuegbar)
command -v termux-wake-lock >/dev/null 2>&1 && termux-wake-lock

start() {                       # name  command...
  name="$1"; shift
  "$@" >> "logs/$name.out" 2>&1 &
  echo $! > "$PIDDIR/$name.pid"
  echo "  $name gestartet (PID $!)"
}

echo "Starte ..."
start sync  php sync.php --loop
start web   php -S 0.0.0.0:8080 -t public
start admin php -S 0.0.0.0:8081 -t admin

# LAN-IP best effort ermitteln
IP="$(ifconfig 2>/dev/null | grep -Eo 'inet (addr:)?([0-9]+\.){3}[0-9]+' | grep -v '127.0.0.1' | head -1 | grep -Eo '([0-9]+\.){3}[0-9]+')"
[ -z "$IP" ] && IP="<phone-ip>"

echo
echo "Fertig:"
echo "  Diashow : http://$IP:8080/"
echo "  Admin   : http://$IP:8081/"
echo "Logs:    logs/*.out   |   Stoppen: ./stop.sh"
