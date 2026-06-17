<?php
/*
 * list.php  ->  liefert eine JSON-Liste aller Bilder als relative Pfade.
 *
 * Konfiguration: PHOTO_DIR auf den Ordner setzen, in dem deine Fotos liegen.
 * Der Pfad darf irgendwo auf der NAS liegen (muss NICHT unter /web sein),
 * weil die Bilder ueber img.php ausgeliefert werden.
 */

$PHOTO_DIR = "photo";   // <-- ANPASSEN
$RECURSIVE = true;                            // Unterordner mitnehmen?
$EXT       = array("jpg", "jpeg", "png", "gif", "webp", "bmp");

/*
 * Album-/Ordner-Filter (Whitelist der obersten Unterordner unter PHOTO_DIR).
 * Leer = alle Bilder. Beispiel: array("Urlaub2023", "Familie")
 * Zusaetzlich kann index.html per ?album=Name EIN Album erzwingen.
 */
$ALBUMS = array();

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
