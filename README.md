# Fotorahmen

Ein schlanker Foto-Rahmen für ein altes Wandtablet: Ein kleiner PHP-Server
(läuft z. B. in **Termux** auf einem Telefon) hält einen größenbegrenzten
Foto-Cache von einer **Synology-NAS** vor und zeigt die Bilder als Diashow im
Browser des Tablets.

```
Wandtablet (Browser, Vollbild)
   │  http://<server-ip>:8080/
   ▼
PHP-Server (Termux)
   ├─ index.html  → Diashow (Crossfade, Uhr, Datum, Wetter)
   ├─ list.php    → Liste der lokal vorhandenen Bilder (JSON)
   ├─ img.php     → liefert/verkleinert Bilder, mit Cache
   └─ sync.php    → holt Fotos von der NAS, hält das Größenlimit ein
                    (lädt neue, löscht die ältesten)
```

Der Server weiß nicht, was gerade angezeigt wird. Er sorgt nur dafür, dass
lokal höchstens `cache_limit_mb` an Fotos liegen und ab und zu frische
nachkommen.

---

## Szenario: alte Hardware wiederverwenden

Die Idee ist, ausgediente Geräte sinnvoll weiterzubetreiben, statt neue
Hardware zu kaufen. Die Arbeit wird auf drei Rollen verteilt:

- **NAS (Datenspeicher):** hält die große Fotosammlung. Sie muss nichts
  Besonderes können außer die Bilder bereitstellen.
- **Altes Telefon (Mini-Server):** läuft dauerhaft in **Termux** und macht die
  eigentliche Arbeit – Bilder holen/zwischenspeichern, verkleinern und über
  HTTP ausliefern. Ein Telefon eignet sich gut: stromsparend, hat Speicher und
  WLAN, kann rund um die Uhr laufen.
- **Altes Tablet (Kiosk-Display):** zeigt nur noch eine Webseite im Vollbild
  (Kiosk-Browser). Es braucht keine aktuelle Software – nur einen Browser, der
  die Diashow-Seite des Telefons öffnet. Genau deshalb läuft die Logik auf dem
  Telefon und nicht auf dem alten Tablet.

### Quellsysteme

Woher die Bilder kommen, bestimmt die Einstellung `source`:

| Quelle      | Verhalten                                                          |
|-------------|-------------------------------------------------------------------|
| `local`     | Bilder liegen bereits im `photo_dir` (kopiert/gemountet). Kein Sync, **nichts wird gelöscht**. |
| `synology`  | `sync.php` holt per FileStation-API, mit Größenlimit & Rotation.   |

Weitere Quellen (z. B. SMB, Cloud) lassen sich später ergänzen.

### Roadmap (in Arbeit)

- **Management-Interface** auf Port 8081: `.env`, Ordnerliste und alle
  Einstellungen grafisch konfigurieren, NAS-Verbindung testen, Ordner auswählen.
- **Zentrale Konfiguration** per Schema (`config.php`) + `settings.json`.
- **Logging** (`log.php` → `logs/`) für Sync-Läufe, Admin-Änderungen und
  Web-Fehler, inkl. Anzeige im Management-Interface.

---

## 1. Voraussetzungen (in Termux)

```bash
pkg update && pkg upgrade
pkg install php php-gd openssh git
termux-setup-storage        # einmalig: Zugriff auf den Telefon-Speicher
```

Die `gd`-Erweiterung (für das serverseitige Verkleinern) steckt in Termux im
**eigenen Paket `php-gd`** – ohne das fehlt sie. Prüfen:

```bash
php -m | grep -iE 'gd|exif'
```

- `gd` vorhanden → optimal.
- `gd` fehlt → `pkg install php-gd` nachholen. Notfalls läuft es auch ohne:
  `img.php` liefert dann die Originalbilder aus und das Tablet skaliert per CSS
  (auf schwachen Geräten ist `gd` aber schonender).

---

## 2. Zugriff vom PC per SSH / PuTTY

Damit man bequem vom Rechner aus arbeiten kann, startet man in Termux den
SSH-Server:

```bash
passwd            # einmalig ein Passwort für den Termux-Benutzer setzen
whoami            # Benutzername merken (z. B. u0_a123)
sshd              # SSH-Server starten (Port 8022)
```

In **PuTTY** verbinden:

- **Host**: `<telefon-ip>`  (in Termux per `ifconfig` herausfinden)
- **Port**: `8022`
- **Login**: der Name aus `whoami`, dann das gesetzte Passwort

> `sshd` muss nach einem Neustart erneut gestartet werden (oder per Termux:Boot,
> siehe Abschnitt 6).

---

## 3. Projekt von GitHub klonen (per SSH-Key)

### a) SSH-Key auf dem Telefon erzeugen

```bash
ssh-keygen -t ed25519 -C "fotorahmen@telefon"
# Enter für Standardpfad, optional Passphrase
cat ~/.ssh/id_ed25519.pub
```

### b) Key bei GitHub hinterlegen

Den ausgegebenen `ssh-ed25519 …`-Text kopieren und in GitHub eintragen:
**Settings → SSH and GPG keys → New SSH key**.

### c) Klonen

```bash
cd ~
git clone git@github.com:<DEIN-USER>/<DEIN-REPO>.git fotoframe
cd fotoframe
```

> Spätere Updates einfach mit `git pull`. Die nicht eingecheckten Dateien
> (`.env`, `remote_subdirs.txt`, `photo/`) bleiben dabei unangetastet.

---

## 4. Konfiguration

### a) Zugangsdaten – `.env` (wird **nicht** eingecheckt)

```bash
cp .env.example .env
```

`.env` bearbeiten (z. B. mit `nano .env`):

```
NAS_USER=dein_nas_benutzer
NAS_PASS=dein_passwort
NAS_BASE=http://192.168.1.10:5000     # NAS-IP + Port (5000 http / 5001 https)
REMOTE_DIR=/photo                     # Basis-Ordner auf der NAS
```

> Tipp: Auf der NAS einen eigenen Benutzer mit **nur Leserecht** auf den
> Fotoordner anlegen.

### b) Allgemeine Einstellungen – Schema & `settings.json`

Alle nicht-geheimen Optionen sind in **`config.php`** als **Schema** definiert
(Schlüssel, Typ, Default, Hilfetext). Geänderte Werte landen in **`settings.json`**
(gitignored). Am bequemsten stellst du sie im **Management-Interface** ein
(siehe Abschnitt 5) – dort erscheint jede Schema-Option automatisch.

> Faustregel: `config.php` = *welche* Optionen es gibt (Code). `settings.json` =
> *deine* Werte. `.env` = Geheimnisse (NAS-Zugang, Ort, Admin-Passwort).

Wichtige Optionen: `source` (`local`/`synology`), `cache_limit_mb`, `refresh_mb`,
`sync_hours`, `cache_keep_days`, `interval_ms`, `max_width`, `show_clock/date/weather`.

### Quelle wählen: `synology` oder `local`

- **`synology`** (Default): `sync.php` holt die Bilder von der NAS.
- **`local`**: Bilder liegen schon im `photo_dir` (kopiert/gemountet). `sync.php`
  tut dann **nichts** und löscht **nichts** – nur `php -S` läuft.

Umschalten im Management-Interface oder in `settings.json` (`"source": "local"`).

### c) Welche Ordner – `remote_subdirs.txt` (wird **nicht** eingecheckt)

```bash
cp remote_subdirs.txt.example remote_subdirs.txt
```

Ein Ordner pro Zeile (unter `remote_dir`, jeweils rekursiv), `#` = Kommentar:

```
Urlaub2023
Familie
Familie/Familienleben - 2025
```

- **Leer oder nur Kommentare → alle Ordner** unter `remote_dir`.
- Leerzeichen in Ordnernamen sind erlaubt; Schreibweise muss exakt der NAS
  entsprechen.

---

## 5. Fotos holen & Server starten

Fotos einmal testweise holen:

```bash
php sync.php
```

Es loggt jede Aktion (`+ geladen`, `- gelöscht`, Cache-Stand in MB). Der erste
Lauf füllt den Cache bis `cache_limit_mb` – das kann je nach Limit etwas dauern.

Server starten:

```bash
php -S 0.0.0.0:8080
```

- `0.0.0.0` = im ganzen LAN erreichbar (nicht nur lokal).
- Im Browser testen: `http://<server-ip>:8080/list.php` sollte eine JSON-Liste
  zeigen, `http://<server-ip>:8080/` die Diashow.

### Alles zusammen mit den Skripten (empfohlen)

```bash
chmod +x start.sh stop.sh status.sh   # einmalig
./start.sh     # Wake-Lock + Sync-Schleife + Diashow (8080) + Admin (8081)
./status.sh    # zeigt, was läuft
./stop.sh      # alles wieder herunterfahren
```

`start.sh` merkt sich die PIDs in `.run/`, schreibt Ausgaben nach `logs/*.out`
und verhindert einen Doppelstart.

### Oder einzeln im Hintergrund

```bash
termux-wake-lock
php sync.php --loop &             # alle sync_hours automatisch synchronisieren
php -S 0.0.0.0:8080 &             # Diashow
php -S 0.0.0.0:8081 -t admin &    # Management-Interface
```

### Management-Interface (Port 8081)

Zum grafischen Konfigurieren läuft ein zweiter Server mit Dokumentwurzel
`admin/`:

```bash
php -S 0.0.0.0:8081 -t admin
```

Aufruf im Browser (PC oder Handy): `http://<server-ip>:8081/`

- **Login** per Admin-Passwort (`ADMIN_PASS` in der `.env`). Ist keins gesetzt,
  läuft die UI ungeschützt und warnt dich – setze dort zuerst ein Passwort.
- Editierbar: **alle** Schema-Optionen, die `.env`-Felder (NAS-Zugang, Ort) und
  die Ordnerliste (`remote_subdirs.txt`).
- Buttons: **Verbindung testen** und **NAS-Ordner durchsuchen** (Ordner per Klick
  in die Liste übernehmen). Unten werden die letzten **Log-Zeilen** angezeigt.

> Nur im vertrauten LAN betreiben – die UI bearbeitet Zugangsdaten.

---

## 6. Dauerbetrieb / Autostart

Für automatischen Start nach dem Booten die App **Termux:Boot** installieren und
ein Startskript anlegen:

```bash
mkdir -p ~/.termux/boot
cat > ~/.termux/boot/fotorahmen.sh <<'EOF'
#!/data/data/com.termux/files/usr/bin/sh
sshd
~/fotoframe/start.sh
EOF
chmod +x ~/.termux/boot/fotorahmen.sh
```

`start.sh` übernimmt Wake-Lock, Sync-Schleife, Diashow- und Admin-Server. Passe
den Pfad `~/fotoframe` an deinen Projektordner an (z. B. `~/photoframe`).

---

## 7. Tablet einrichten

1. **Fully Kiosk Browser** installieren (von fully-kiosk.com).
2. **Start URL**: `http://<server-ip>:8080/`
3. Aktivieren: *Keep Screen On*, *Fullscreen*, optional *Launch on Boot* und ein
   *Screen On/Off Schedule* (nachts aus).

Zum schnellen Vorabtest tut es auch der eingebaute Browser **Jelly** mit
derselben URL.

---

## 8. Anzeige anpassen

Die Diashow ist **`index.php`** und zieht ihre Werte aus der Konfiguration
(Schema/`settings.json`/`.env`). Einstellen am besten im **Management-Interface**:

| Option          | Bedeutung                                        |
|-----------------|--------------------------------------------------|
| `interval_ms`   | Anzeigedauer pro Bild (Standard 20 s)            |
| `max_width`     | serverseitige Verkleinerungsbreite (px)          |
| `show_clock`    | Uhr ein/aus                                       |
| `show_date`     | Datum ein/aus                                     |
| `show_weather`  | Wetter ein/aus (Open-Meteo, kein API-Key nötig)  |
| `weather_lat` / `weather_lon` / `weather_city` | Ort fürs Wetter (in `.env`) |

Einzelne Werte sind auch per URL überschreibbar, z. B.:
`http://<server-ip>:8080/?interval=30000&album=Urlaub2023`

---

## Was wird (nicht) eingecheckt?

| Datei                         | Im Git? | Inhalt                          |
|-------------------------------|---------|---------------------------------|
| `.env`                        | nein    | NAS-Zugang, Ort, Admin-Passwort |
| `settings.json`               | nein    | per UI geänderte Einstellungen  |
| `remote_subdirs.txt`          | nein    | gewählte Ordner                 |
| `logs/`                       | nein    | Logdateien                      |
| `photo/`                      | nein    | heruntergeladene Bilder + Cache |
| `*.example`                   | ja      | Vorlagen                        |
| `config.php`, `schema.php`, `nas.php`, `log.php`, `store.php`, `index.php`, `admin/` | ja | Code (ohne Geheimnisse) |

---

## Fehlersuche

| Symptom                                   | Ursache / Lösung                                              |
|-------------------------------------------|--------------------------------------------------------------|
| PuTTY verbindet nicht                     | `sshd` läuft nicht, falscher Port (8022) oder falsche IP.    |
| `git clone` fragt nach Passwort           | SSH-Key nicht bei GitHub hinterlegt oder falsche Repo-URL.    |
| Diashow zeigt „NAS nicht erreichbar"      | Server läuft nicht oder falsche IP/Port in der Tablet-URL.    |
| `list.php` liefert `[]`                    | `photo/` leer → erst `php sync.php` laufen lassen.            |
| `sync.php`: „Login fehlgeschlagen"        | `.env`-Daten falsch, oder `nas_base`-Port stimmt nicht.      |
| Keine Bilder aus einem Ordner             | Schreibweise in `remote_subdirs.txt` ≠ NAS (Groß/Klein, Leerzeichen). |
| Bilder werden nicht verkleinert           | `gd` fehlt → `pkg install php-gd` (siehe Schritt 1). Läuft sonst unkomprimiert. |
| `sync.php` sagt „kein Sync nötig"         | `source` steht auf `local`. Für NAS-Abgleich `source=synology` setzen. |
| Admin-UI: keine Anmeldung möglich         | `ADMIN_PASS` in `.env` prüfen; leer = ungeschützt (dann Passwort setzen). |
| Telefon-Display geht aus                  | `termux-wake-lock` und in Fully Kiosk *Keep Screen On*.       |
