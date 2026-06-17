<?php
/*
 * config.php  ->  zentrale Konfiguration fuer den Fotorahmen.
 *
 * Wird von list.php, img.php und sync.php eingelesen.
 * Geheimnisse (NAS-Benutzer/Passwort) stehen NICHT hier, sondern in .env
 * (siehe .env.example). Die .env wird nicht eingecheckt.
 */

/* --- kleiner .env-Loader (KEY=WERT pro Zeile, # = Kommentar) --- */
if (!function_exists("load_env")) {
    function load_env($path) {
        $env = array();
        if (!is_file($path)) return $env;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === "" || $line[0] === "#") continue;
            $pos = strpos($line, "=");
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // umschliessende Anfuehrungszeichen entfernen
            if (strlen($val) >= 2 &&
                (($val[0] === '"' && substr($val, -1) === '"') ||
                 ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }
            $env[$key] = $val;
        }
        return $env;
    }
}

/* --- Liste aus Datei: eine Zeile = ein Eintrag, # = Kommentar, leer = ignoriert --- */
if (!function_exists("load_lines")) {
    function load_lines($path) {
        $out = array();
        if (!is_file($path)) return $out;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === "" || $line[0] === "#") continue;
            $out[] = $line;
        }
        return $out;
    }
}

$env = load_env(__DIR__ . "/.env");

return array(

    /* ---------- Pfade (gelten fuer alle Skripte) ---------- */
    // Ordner mit den Bildern. __DIR__ = Ordner dieser config.php.
    "photo_dir"   => __DIR__ . "/photo",
    // Zwischenspeicher fuer verkleinerte Bilder (muss beschreibbar sein).
    "cache_dir"   => __DIR__ . "/photo/.cache",

    /* ---------- Anzeige / Auflistung (list.php) ---------- */
    "recursive"   => true,                                   // Unterordner mitnehmen?
    "extensions"  => array("jpg","jpeg","png","gif","webp","bmp"),
    // Album-Whitelist (oberste Unterordner). Leer = alle.
    // Beispiel: array("Urlaub2023", "Familie")
    "albums"      => array(),

    /* ---------- Bildauslieferung (img.php) ---------- */
    "jpeg_quality"=> 82,                                     // 0..100

    /* ---------- Lokaler Cache / Rotation (sync.php) ---------- */
    // Maximalgroesse der lokal gehaltenen Bilder in MB. Ist der NAS-Ordner
    // groesser, wird nur ein Ausschnitt gehalten und nach und nach getauscht.
    "cache_limit_mb"  => 10000,
    // Wie viel pro Sync-Lauf ausgetauscht wird: so viele MB der aeltesten
    // Bilder loeschen und durch neue Zufallsfotos ersetzen.
    "refresh_mb"      => 500,
    // Verkleinerungs-Cache (photo/.cache): Dateien loeschen, die so viele
    // Tage nicht mehr angezeigt wurden. 0 = nie aufraeumen.
    "cache_keep_days" => 7,

    /* ---------- NAS-Sync (sync.php) ---------- */
    "nas_base"    => "http://192.168.178.26:5050",          // NAS-IP + Port (5000 http / 5001 https)
    "nas_user"    => isset($env["NAS_USER"]) ? $env["NAS_USER"] : "",  // <-- aus .env
    "nas_pass"    => isset($env["NAS_PASS"]) ? $env["NAS_PASS"] : "",  // <-- aus .env
    "remote_dir"  => "/photo",                              // Basis-Ordner auf der NAS
    // Liste der zu beruecksichtigenden Unterordner steht in remote_subdirs.txt
    // (nicht eingecheckt). Eine Zeile = ein Ordner, je rekursiv.
    // Datei fehlt/leer = kompletten remote_dir nehmen.
    "remote_subdirs" => load_lines(__DIR__ . "/remote_subdirs.txt"),
    "sync_hours"  => 24,                                      // Intervall fuer "php sync.php --loop"
    "delete_old"  => true,                                   // lokal loeschen, was auf NAS fehlt?
);
