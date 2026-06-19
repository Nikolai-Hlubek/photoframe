<?php
/*
 * sync.php  ->  keeps a size-limited photo cache from the source locally.
 *
 * Only runs for source = "synology" (FileStation API). For source = "local"
 * it does nothing (the photos are managed by the user; never deleted).
 *
 * Per run (synology):
 *   1. delete images that no longer exist on the NAS (optional)
 *   2. if the cache is full: delete the oldest ~refresh_mb (make room)
 *   3. download new random photos until cache_limit_mb is reached
 *   4. clean up the downscale cache
 *
 * Usage:
 *   php sync.php            -> sync once
 *   php sync.php --loop     -> endless loop, every sync_hours hours
 */

$cfg = require __DIR__ . "/config.php";
require __DIR__ . "/log.php";
require __DIR__ . "/nas.php";

log_set_max($cfg["log_max_kb"]);

$SOURCE      = $cfg["source"];
$NAS_BASE    = $cfg["nas_base"];
$NAS_USER    = $cfg["nas_user"];
$NAS_PASS    = $cfg["nas_pass"];
$REMOTE_DIR  = $cfg["remote_dir"];
$REMOTE_SUBS = $cfg["remote_subdirs"];
$LOCAL_DIR   = $cfg["photo_dir"];
$SYNC_HOURS  = $cfg["sync_hours"];
$EXT         = $cfg["extensions"];
$DELETE_OLD  = $cfg["delete_old"];
$CACHE_LIMIT = $cfg["cache_limit_mb"] * 1024 * 1024;   // -> bytes
$REFRESH     = $cfg["refresh_mb"]     * 1024 * 1024;   // -> bytes
$CACHE_DIR   = $cfg["cache_dir"];
$KEEP_DAYS   = $cfg["cache_keep_days"];

date_default_timezone_set("Europe/Berlin");
$LOOP = in_array("--loop", $argv);

/* source guard: nothing to do for local sources */
if ($SOURCE !== "synology") {
    logmsg("Quelle '" . $SOURCE . "' -> kein Sync noetig (Bilder werden lokal verwaltet).");
    exit(0);
}

do {
    run_sync();
    if ($LOOP) {
        logmsg("Naechster Lauf in {$SYNC_HOURS} h.");
        sleep($SYNC_HOURS * 3600);
    }
} while ($LOOP);


/* ---------------------- main flow ---------------------- */
function run_sync() {
    global $NAS_BASE, $NAS_USER, $NAS_PASS, $REMOTE_DIR, $REMOTE_SUBS,
           $LOCAL_DIR, $DELETE_OLD, $CACHE_LIMIT, $REFRESH, $EXT;

    logmsg("Login an " . $NAS_BASE . " ...");
    $sid = nas_login($NAS_BASE, $NAS_USER, $NAS_PASS);
    if (!$sid) { logmsg("FEHLER: Login fehlgeschlagen. " . $GLOBALS["NAS_ERR"]); return; }

    if (!is_dir($LOCAL_DIR)) @mkdir($LOCAL_DIR, 0777, true);

    /* NAS catalog: relPath => size. Whole remote_dir or only listed subfolders. */
    $remote = array();
    if (empty($REMOTE_SUBS)) {
        nas_walk($NAS_BASE, $sid, $REMOTE_DIR, "", $remote, $EXT);
    } else {
        foreach ($REMOTE_SUBS as $sub) {
            $sub = trim($sub, "/");
            if ($sub === "") continue;
            nas_walk($NAS_BASE, $sid, $REMOTE_DIR . "/" . $sub, $sub, $remote, $EXT);
        }
    }
    logmsg(count($remote) . " Bilder auf der NAS (in " .
           (empty($REMOTE_SUBS) ? "allen Ordnern" : count($REMOTE_SUBS) . " Ordner(n)") . ").");

    /* local stock: relPath => array(mtime, size) */
    $local = local_images($LOCAL_DIR);

    /* 1) delete locally what no longer exists on the NAS */
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

    /* candidates = NAS images not yet local (random order) */
    $new = array();
    foreach ($remote as $rel => $size) if (!isset($local[$rel])) $new[] = $rel;
    shuffle($new);

    /* 2) if the cache is full and there is supply: delete the oldest ~REFRESH */
    if (!empty($new) && $total >= $CACHE_LIMIT) {
        $freed = evict_oldest($local, $LOCAL_DIR, $REFRESH);
        $total -= $freed;
        logmsg("  Platz geschafft: " . mb($freed) . " (aelteste Bilder).");
    }

    /* 3) download new random photos until the limit is reached */
    $dl = 0; $dlbytes = 0;
    foreach ($new as $rel) {
        if ($total >= $CACHE_LIMIT) break;
        $size  = $remote[$rel];
        $localFile = $LOCAL_DIR . "/" . $rel;
        $dir = dirname($localFile);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        if (nas_download($NAS_BASE, $sid, $REMOTE_DIR . "/" . $rel, $localFile)) {
            $total += $size; $dlbytes += $size; $dl++;
            logmsg("  + " . $rel);
        }
    }
    logmsg($dl . " neue Bild(er) geladen (" . mb($dlbytes) . ").");
    logmsg("Cache jetzt: " . mb($total) . " / " . mb($CACHE_LIMIT) . ".");

    /* 4) clean up the downscale cache (files not shown for a long time) */
    prune_cache();

    nas_logout($NAS_BASE, $sid);
    logmsg("Sync fertig.");
}

/* deletes .cache files not displayed since KEEP_DAYS */
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

/* deletes the oldest local images until $bytes are free; returns freed bytes */
function evict_oldest(&$local, $base, $bytes) {
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

/* all local images: relPath => array(mtime, size); excluding .cache */
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
        if (nas_ext_ok($file->getFilename(), $EXT)) {
            $out[$rel] = array("mtime" => $file->getMTime(), "size" => $file->getSize());
        }
    }
    return $out;
}

function mb($bytes) { return round($bytes / 1048576, 1) . " MB"; }
