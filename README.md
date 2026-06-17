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

### b) Allgemeine Einstellungen – `config.php`

Die wichtigsten Regler:

> NAS-Adresse, -Ordner und -Zugang stehen in der `.env` (siehe oben), nicht hier.

| Schlüssel          | Bedeutung                                                        |
|--------------------|-----------------------------------------------------------------|
| `photo_dir`        | lokaler Bilderordner (Standard: `photo/` neben den Skripten)     |
| `cache_limit_mb`   | maximale Größe des lokalen Caches (z. B. `10000` = 10 GB)        |
| `refresh_mb`       | wie viel pro Sync-Lauf ausgetauscht wird (älteste raus, neue rein)|
| `sync_hours`       | Intervall für `php sync.php --loop`                              |
| `cache_keep_days`  | verwaiste Verkleinerungen im `.cache` nach X Tagen löschen        |

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

### Beides zusammen (im Hintergrund, mit Bildschirm-Wachhalten)

```bash
termux-wake-lock
php sync.php --loop &        # alle sync_hours automatisch synchronisieren
php -S 0.0.0.0:8080 &
```

---

## 6. Dauerbetrieb / Autostart

Für automatischen Start nach dem Booten die App **Termux:Boot** installieren und
ein Startskript anlegen:

```bash
mkdir -p ~/.termux/boot
cat > ~/.termux/boot/fotorahmen.sh <<'EOF'
#!/data/data/com.termux/files/usr/bin/sh
termux-wake-lock
sshd
cd ~/fotoframe
php sync.php --loop &
php -S 0.0.0.0:8080 &
EOF
chmod +x ~/.termux/boot/fotorahmen.sh
```

Das startet nach jedem Neustart: SSH-Server, NAS-Sync-Schleife und Webserver.

---

## 7. Tablet einrichten

1. **Fully Kiosk Browser** installieren (von fully-kiosk.com).
2. **Start URL**: `http://<server-ip>:8080/`
3. Aktivieren: *Keep Screen On*, *Fullscreen*, optional *Launch on Boot* und ein
   *Screen On/Off Schedule* (nachts aus).

Zum schnellen Vorabtest tut es auch der eingebaute Browser **Jelly** mit
derselben URL.

---

## 8. Anzeige anpassen (`index.html`, oben im `<script>`)

| Variable        | Bedeutung                                        |
|-----------------|--------------------------------------------------|
| `INTERVAL_MS`   | Anzeigedauer pro Bild (Standard 20 s)            |
| `MAX_WIDTH`     | serverseitige Verkleinerungsbreite (px)          |
| `SHOW_CLOCK`    | Uhr ein/aus                                       |
| `SHOW_DATE`     | Datum ein/aus                                     |
| `SHOW_WEATHER`  | Wetter ein/aus (Open-Meteo, kein API-Key nötig)  |
| `WEATHER_LAT` / `WEATHER_LON` | Koordinaten fürs Wetter             |

Per URL überschreibbar, z. B.:
`http://<server-ip>:8080/?interval=30000&album=Urlaub2023`

---

## Was wird (nicht) eingecheckt?

| Datei                         | Im Git? | Inhalt                          |
|-------------------------------|---------|---------------------------------|
| `.env`                        | nein    | NAS-Zugangsdaten                |
| `remote_subdirs.txt`          | nein    | gewählte Ordner                 |
| `photo/`                      | nein    | heruntergeladene Bilder + Cache |
| `*.example`                   | ja      | Vorlagen                        |
| `config.php`, `*.php`, `index.html` | ja | Code (ohne Geheimnisse)         |

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
| Telefon-Display geht aus                  | `termux-wake-lock` und in Fully Kiosk *Keep Screen On*.       |
