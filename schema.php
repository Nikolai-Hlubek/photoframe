<?php
/*
 * schema.php  ->  defines every configuration option once.
 *
 * config.php builds the effective configuration from this schema, and the
 * admin UI renders its form from it. Add a new option here and it shows up
 * in both places automatically.
 *
 * Per field:
 *   key     internal config key (used in code as $cfg["key"])
 *   label   human label for the UI
 *   help    short hint for the UI
 *   group   UI section
 *   type    string | int | float | bool | list | select | text
 *   default default value
 *   store   "settings" (settings.json) or "env" (.env)
 *   env     env variable name (only for store=env)
 *   secret  true -> masked in the UI (passwords)
 *   options for type=select: array of allowed values
 */

return array(

    /* ---------- Source ---------- */
    array("key"=>"source", "label"=>"Quelle", "group"=>"Quelle",
          "help"=>"Woher die Bilder kommen.", "type"=>"select",
          "options"=>array("local","synology"), "default"=>"synology",
          "store"=>"settings"),

    /* ---------- NAS (secrets/address in .env) ---------- */
    array("key"=>"nas_base", "label"=>"NAS-Adresse", "group"=>"NAS",
          "help"=>"IP + Port, z.B. http://192.168.1.10:5000", "type"=>"string",
          "default"=>"", "store"=>"env", "env"=>"NAS_BASE"),
    array("key"=>"nas_user", "label"=>"NAS-Benutzer", "group"=>"NAS",
          "help"=>"Benutzer mit Leserecht auf den Fotoordner.", "type"=>"string",
          "default"=>"", "store"=>"env", "env"=>"NAS_USER"),
    array("key"=>"nas_pass", "label"=>"NAS-Passwort", "group"=>"NAS",
          "help"=>"", "type"=>"string", "default"=>"", "store"=>"env",
          "env"=>"NAS_PASS", "secret"=>true),
    array("key"=>"remote_dir", "label"=>"NAS-Basisordner", "group"=>"NAS",
          "help"=>"Basis auf der NAS, z.B. /photo", "type"=>"string",
          "default"=>"/photo", "store"=>"env", "env"=>"REMOTE_DIR"),

    /* ---------- Display ---------- */
    array("key"=>"weather_lat", "label"=>"Breitengrad", "group"=>"Anzeige",
          "help"=>"Fuer das Wetter (Open-Meteo).", "type"=>"float",
          "default"=>52.52, "store"=>"env", "env"=>"WEATHER_LAT"),
    array("key"=>"weather_lon", "label"=>"Laengengrad", "group"=>"Anzeige",
          "help"=>"Fuer das Wetter (Open-Meteo).", "type"=>"float",
          "default"=>13.41, "store"=>"env", "env"=>"WEATHER_LON"),
    array("key"=>"weather_city", "label"=>"Ortsname (optional)", "group"=>"Anzeige",
          "help"=>"Nur als Beschriftung.", "type"=>"string",
          "default"=>"", "store"=>"env", "env"=>"WEATHER_CITY"),
    array("key"=>"show_clock", "label"=>"Uhr anzeigen", "group"=>"Anzeige",
          "help"=>"", "type"=>"bool", "default"=>true, "store"=>"settings"),
    array("key"=>"show_date", "label"=>"Datum anzeigen", "group"=>"Anzeige",
          "help"=>"", "type"=>"bool", "default"=>true, "store"=>"settings"),
    array("key"=>"show_weather", "label"=>"Wetter anzeigen", "group"=>"Anzeige",
          "help"=>"", "type"=>"bool", "default"=>true, "store"=>"settings"),
    array("key"=>"interval_ms", "label"=>"Anzeigedauer (ms)", "group"=>"Anzeige",
          "help"=>"Millisekunden pro Bild.", "type"=>"int",
          "default"=>20000, "store"=>"settings"),
    array("key"=>"max_width", "label"=>"Max. Bildbreite (px)", "group"=>"Anzeige",
          "help"=>"Serverseitige Verkleinerung. 0 = Original.", "type"=>"int",
          "default"=>1280, "store"=>"settings"),
    array("key"=>"reload_list_min", "label"=>"Liste neu laden (Min)", "group"=>"Anzeige",
          "help"=>"Wie oft das Tablet die Bilderliste neu holt.", "type"=>"int",
          "default"=>60, "store"=>"settings"),
    array("key"=>"ui_scale", "label"=>"Schriftgroesse Overlays (x)", "group"=>"Anzeige",
          "help"=>"Multiplikator fuer Uhr/Datum/Wetter. 1.0 = Standard, 1.5 = groesser.",
          "type"=>"float", "default"=>1.0, "store"=>"settings"),

    /* ---------- Cache / sync ---------- */
    array("key"=>"photo_dir", "label"=>"Bilderordner", "group"=>"Cache/Sync",
          "help"=>"Leer = photo/ neben den Skripten.", "type"=>"string",
          "default"=>"", "store"=>"settings"),
    array("key"=>"recursive", "label"=>"Unterordner einbeziehen", "group"=>"Cache/Sync",
          "help"=>"", "type"=>"bool", "default"=>true, "store"=>"settings"),
    array("key"=>"extensions", "label"=>"Dateiendungen", "group"=>"Cache/Sync",
          "help"=>"Kommagetrennt.", "type"=>"list",
          "default"=>array("jpg","jpeg","png","gif","webp","bmp"), "store"=>"settings"),
    array("key"=>"albums", "label"=>"Album-Whitelist (Anzeige)", "group"=>"Cache/Sync",
          "help"=>"Kommagetrennt. Leer = alle.", "type"=>"list",
          "default"=>array(), "store"=>"settings"),
    array("key"=>"jpeg_quality", "label"=>"JPEG-Qualitaet", "group"=>"Cache/Sync",
          "help"=>"0..100", "type"=>"int", "default"=>82, "store"=>"settings"),
    array("key"=>"cache_limit_mb", "label"=>"Cache-Limit (MB)", "group"=>"Cache/Sync",
          "help"=>"Maximale Groesse der lokalen Bilder.", "type"=>"int",
          "default"=>10000, "store"=>"settings"),
    array("key"=>"refresh_mb", "label"=>"Austausch pro Lauf (MB)", "group"=>"Cache/Sync",
          "help"=>"Aelteste loeschen, neue laden.", "type"=>"int",
          "default"=>500, "store"=>"settings"),
    array("key"=>"cache_keep_days", "label"=>"Verkleinerungs-Cache (Tage)", "group"=>"Cache/Sync",
          "help"=>"Ungenutzte .cache-Dateien loeschen. 0 = nie.", "type"=>"int",
          "default"=>7, "store"=>"settings"),
    array("key"=>"sync_hours", "label"=>"Sync-Intervall (Std)", "group"=>"Cache/Sync",
          "help"=>"Fuer 'php sync.php --loop'.", "type"=>"int",
          "default"=>24, "store"=>"settings"),
    array("key"=>"delete_old", "label"=>"Auf NAS Geloeschtes entfernen", "group"=>"Cache/Sync",
          "help"=>"Nur bei Quelle synology.", "type"=>"bool",
          "default"=>true, "store"=>"settings"),

    /* ---------- Logging ---------- */
    array("key"=>"log_level", "label"=>"Log-Level", "group"=>"Logging",
          "help"=>"", "type"=>"select", "options"=>array("info","error"),
          "default"=>"info", "store"=>"settings"),
    array("key"=>"log_max_kb", "label"=>"Max. Loggroesse (KB)", "group"=>"Logging",
          "help"=>"Darueber wird rotiert.", "type"=>"int",
          "default"=>512, "store"=>"settings"),

    /* ---------- Admin ---------- */
    array("key"=>"admin_pass", "label"=>"Admin-Passwort", "group"=>"Admin",
          "help"=>"Login fuer das Management-Interface (Port 8081).",
          "type"=>"string", "default"=>"", "store"=>"env", "env"=>"ADMIN_PASS",
          "secret"=>true),
);
