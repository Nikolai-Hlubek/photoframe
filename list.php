<?php
/*
 * list.php  ->  liefert eine JSON-Liste aller Bilder als relative Pfade.
 *
 * Konfiguration steht zentral in config.php.
 */

$cfg = require __DIR__ . "/config.php";

$PHOTO_DIR = $cfg["photo_dir"];
$RECURSIVE = $cfg["recursive"];
$EXT       = $cfg["extensions"];
$ALBUMS    = $cfg["albums"];

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache");

$base = realpath($PHOTO_DIR);
if ($base === false || !is_dir($base)) {
    echo "[]";
    exit;
}

$result = array();

/* ?album= aus index.html hat Vorrang vor der Whitelist */
$override = isset($_GET["album"]) ? trim($_GET["album"]) : "";
$override = str_replace(array("/", "\\", ".."), "", $override); // absichern
if ($override !== "") {
    $allowed = array($override);
} else {
    $allowed = $ALBUMS;
}

function ext_ok($name, $exts) {
    $dot = strrpos($name, ".");
    if ($dot === false) return false;
    $e = strtolower(substr($name, $dot + 1));
    return in_array($e, $exts, true);
}

/* prueft, ob der relative Pfad im erlaubten Album liegt */
function album_ok($rel, $allowed) {
    if (empty($allowed)) return true;          // kein Filter -> alles
    $top = explode("/", $rel);
    return in_array($top[0], $allowed, true);
}

if ($RECURSIVE) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && ext_ok($file->getFilename(), $EXT)) {
            $rel = str_replace("\\", "/", substr($file->getPathname(), strlen($base) + 1));
            if (strpos($rel, ".cache/") === 0) continue;   // Cache-Ordner auslassen
            if (album_ok($rel, $allowed)) $result[] = $rel;
        }
    }
} else {
    $dh = opendir($base);
    if ($dh) {
        while (($f = readdir($dh)) !== false) {
            if ($f === "." || $f === "..") continue;
            if (is_file($base . "/" . $f) && ext_ok($f, $EXT)) {
                $result[] = $f;
            }
        }
        closedir($dh);
    }
}

echo json_encode($result);
