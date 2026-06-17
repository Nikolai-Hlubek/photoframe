<?php
/*
 * sync.php  ->  haelt lokal einen groessenbegrenzten Foto-Cache vom NAS.
 *
 * Reines PHP ueber die Synology FileStation-API (HTTP/JSON).
 * Pro Lauf:
 *   1. Bilder loeschen, die auf der NAS nicht mehr existieren (optional)
 *   2. ist der Cache voll: die aeltesten ~refresh_mb loeschen (Platz schaffen)
 *   3. neue Zufallsfotos laden, bis cache_limit_mb erreicht ist
 *
 * Der Server weiss NICHT, was gerade angezeigt wird -- er sorgt nur dafuer,
 * dass lokal hoechstens cache_limit_mb liegen und ab und zu frische dazukommen.
 *
 * Aufruf:
 *   php sync.php            -> einmal synchronisieren
 *   php sync.php --loop     -> Endlosschleife, alle sync_hours Stunden
 */

/* ---- Konfiguration zentral aus config.php ---- */
$cfg = require __DIR__ . "/config.php";

$NAS_BASE    = $cfg["nas_base"];
$NAS_USER    = $cfg["nas_user"];
$NAS_PASS    = $cfg["nas_pass"];
$REMOTE_DIR  = $cfg["remote_dir"];
$REMOTE_SUBS = $cfg["remote_subdirs"];
$LOCAL_DIR   = $cfg["photo_dir"];
$SYNC_HOURS  = $cfg["sync_hours"];
$EXT         = $cfg["extensions"];
$DELETE_OLD  = $cfg["delete_old"];
$CACHE_LIMIT = $cfg["cache_limit_mb"] * 1024 * 1024;   // -> Bytes
$REFRESH     = $cfg["refresh_mb"]     * 1024 * 1024;   // -> Bytes
$CACHE_DIR   = $cfg["cache_dir"];
$KEEP_DAYS   = $cfg["cache_keep_days"];

date_default_timezone_set("Europe/Berlin");
$LOOP = in_array("--loop", $argv);

do {
    run_sync();
    if ($LOOP) {
        logmsg("Naechster Lauf in {$SYNC_HOURS} h.");
        sleep($SYNC_HOURS * 3600);
    }
} while ($LOOP);


/* ---------------------- Ablauf ---------------------- */
function run_sync() {
    global $NAS_BASE, $REMOTE_DIR, $REMOTE_SUBS, $LOCAL_DIR, $DELETE_OLD, $CACHE_LIMIT, $REFRESH;

    logmsg("Login an " . $NAS_BASE . " ...");
    $sid = nas_login();
    if (!$sid) { logmsg("FEHLER: Login fehlgeschlagen."); return; }

    if (!is_dir($LOCAL_DIR)) @mkdir($LOCAL_DIR, 0777, true);

    /* NAS-Katalog: relPfad => Groesse. Entweder ganzer remote_dir oder nur
       die in remote_subdirs gelisteten Unterordner (je rekursiv). */
    $remote = array();
    if (empty($REMOTE_SUBS)) {
        nas_walk($sid, $REMOTE_DIR, "", $remote);
    } else {
        foreach ($REMOTE_SUBS as $sub) {
            $sub = trim($sub, "/");
            if ($sub === "") continue;
            nas_walk($sid, $REMOTE_DIR . "/" . $sub, $sub, $remote);
        }
    }
    logmsg(count($remote) . " Bilder auf der NAS (in " .
           (empty($REMOTE_SUBS) ? "allen Ordnern" : count($REMOTE_SUBS) . " Ordner(n)") . ").");

    /* Lokaler Bestand: relPfad => array(mtime, size) */
    $local = local_images($LOCAL_DIR);

    /* 1) Lokal loeschen, was auf der NAS nicht mehr existiert */
    if ($DELETE_OLD) {
        foreach ($local as $rel => $meta) {
            if (!isset($remote[$rel])) {
                @unlink($LOCAL_DIR . "/" . $rel);
                unset($local[$rel]);
                logmsg("  - (NAS weg) " . $rel);
            }
        }
    }

    $total = 0;
    foreach ($local as $m) $total += $m["size"];

    /* Kandidaten = NAS-Bilder, die noch nicht lokal liegen (zufaellig) */
    $new = array();
    foreach ($remote as $rel => $size) if (!isset($local[$rel])) $new[] = $rel;
    shuffle($new);

    /* 2) Ist der Cache voll und gibt es Nachschub: aelteste ~REFRESH loeschen */
    if (!empty($new) && $total >= $CACHE_LIMIT) {
        $freed = evict_oldest($local, $LOCAL_DIR, $REFRESH);
        $total -= $freed;
        logmsg("  Platz geschafft: " . mb($freed) . " (aelteste Bilder).");
    }

    /* 3) Neue Zufallsfotos laden, bis das Limit erreicht ist */
    $dl = 0; $dlbytes = 0;
    foreach ($new as $rel) {
        if ($total >= $CACHE_LIMIT) break;
        $size  = $remote[$rel];
        $localFile = $LOCAL_DIR . "/" . $rel;
        $dir = dirname($localFile);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        if (nas_download($sid, $REMOTE_DIR . "/" . $rel, $localFile)) {
            $total += $size; $dlbytes += $size; $dl++;
            logmsg("  + " . $rel);
        }
    }
    logmsg($dl . " neue Bild(er) geladen (" . mb($dlbytes) . ").");
    logmsg("Cache jetzt: " . mb($total) . " / " . mb($CACHE_LIMIT) . ".");

    /* 4) Verkleinerungs-Cache aufraeumen (lange nicht gezeigte Dateien) */
    prune_cache();

    nas_logout($sid);
    logmsg("Sync fertig.");
}

/* loescht .cache-Dateien, die seit KEEP_DAYS nicht mehr angezeigt wurden */
function prune_cache() {
    global $CACHE_DIR, $KEEP_DAYS;
    if ($KEEP_DAYS <= 0 || !is_dir($CACHE_DIR)) return;
    $cutoff = time() - $KEEP_DAYS * 86400;
    $del = 0; $bytes = 0;
    $dh = opendir($CACHE_DIR);
    if (!$dh) return;
    while (($f = readdir($dh)) !== false) {
        if ($f === "." || $f === "..") continue;
        $p = $CACHE_DIR . "/" . $f;
        if (is_file($p) && filemtime($p) < $cutoff) {
            $bytes += filesize($p);
            @unlink($p);
            $del++;
        }
    }
    closedir($dh);
    if ($del) logmsg("  Cache aufgeraeumt: " . $del . " Datei(en), " . mb($bytes) . ".");
}

/* loescht die aeltesten lokalen Bilder, bis $bytes frei sind; gibt befreite Bytes zurueck */
function evict_oldest(&$local, $base, $bytes) {
    /* nach mtime aufsteigend sortieren (aelteste = zuerst geladen) */
    uasort($local, function ($a, $b) { return $a["mtime"] - $b["mtime"]; });
    $freed = 0;
    foreach ($local as $rel => $meta) {
        if ($freed >= $bytes) break;
        @unlink($base . "/" . $rel);
        $freed += $meta["size"];
        unset($local[$rel]);
    }
    return $freed;
}


/* ---------------------- FileStation-API ---------------------- */
function nas_login() {
    global $NAS_BASE, $NAS_USER, $NAS_PASS;
    $url = $NAS_BASE . "/webapi/auth.cgi?api=SYNO.API.Auth&version=3&method=login"
         . "&account=" . rawurlencode($NAS_USER)
         . "&passwd="  . rawurlencode($NAS_PASS)
         . "&session=FileStation&format=sid";
    $r = json_decode(http_get($url), true);
    return ($r && !empty($r["success"])) ? $r["data"]["sid"] : null;
}

function nas_logout($sid) {
    global $NAS_BASE;
    http_get($NAS_BASE . "/webapi/auth.cgi?api=SYNO.API.Auth&version=1&method=logout"
           . "&session=FileStation&_sid=" . rawurlencode($sid));
}

/* listet $path und steigt rekursiv in Unterordner ab; fuellt $out[relPfad]=size */
function nas_walk($sid, $path, $prefix, &$out) {
    global $NAS_BASE, $EXT;
    $url = $NAS_BASE . "/webapi/entry.cgi?api=SYNO.FileStation.List&version=2&method=list"
         . "&folder_path=" . rawurlencode($path)
         . "&additional=" . rawurlencode('["size"]')
         . "&_sid=" . rawurlencode($sid);
    $r = json_decode(http_get($url), true);
    if (!$r || empty($r["success"])) return;

    foreach ($r["data"]["files"] as $f) {
        $name = $f["name"];
        $rel  = $prefix === "" ? $name : $prefix . "/" . $name;
        if (!empty($f["isdir"])) {
            nas_walk($sid, $path . "/" . $name, $rel, $out);     // Unterordner
        } else if (ext_ok($name, $EXT)) {
            $out[$rel] = isset($f["additional"]["size"]) ? (int)$f["additional"]["size"] : 0;
        }
    }
}

function nas_download($sid, $remotePath, $localFile) {
    global $NAS_BASE;
    $url = $NAS_BASE . "/webapi/entry.cgi?api=SYNO.FileStation.Download&version=2&method=download"
         . "&path=" . rawurlencode($remotePath)
         . "&mode=download&_sid=" . rawurlencode($sid);
    return http_download($url, $localFile);
}


/* ---------------------- Helfer ---------------------- */
function ext_ok($name, $exts) {
    $dot = strrpos($name, ".");
    if ($dot === false) return false;
    return in_array(strtolower(substr($name, $dot + 1)), $exts, true);
}

/* alle lokalen Bilder: relPfad => array(mtime, size); ohne .cache */
function local_images($base) {
    global $EXT;
    $out = array();
    if (!is_dir($base)) return $out;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace("\\", "/", substr($file->getPathname(), strlen($base) + 1));
        if (strpos($rel, ".cache/") === 0) continue;
        if (ext_ok($file->getFilename(), $EXT)) {
            $out[$rel] = array("mtime" => $file->getMTime(), "size" => $file->getSize());
        }
    }
    return $out;
}

function mb($bytes) { return round($bytes / 1048576, 1) . " MB"; }

function http_get($url) {
    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $body = curl_exec($ch);
        curl_close($ch);
        return $body;
    }
    return @file_get_contents($url);
}

function http_download($url, $localFile) {
    if (function_exists("curl_init")) {
        $fp = fopen($localFile, "wb");
        if (!$fp) return false;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok) @unlink($localFile);
        return (bool)$ok;
    }
    $data = @file_get_contents($url);
    if ($data === false) return false;
    return file_put_contents($localFile, $data) !== false;
}

function logmsg($m) { echo "[" . date("H:i:s") . "] " . $m . "\n"; }
