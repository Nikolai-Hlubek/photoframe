<?php
/*
 * img.php?p=<relativer Pfad>&w=<maxBreite>
 *
 * Liefert ein Bild aus PHOTO_DIR aus. Optional serverseitig verkleinert
 * (schont RAM/CPU des alten Tablets). Verkleinerte Bilder werden in
 * CACHE_DIR zwischengespeichert.
 *
 * Konfiguration steht zentral in config.php.
 */

$cfg = require __DIR__ . "/config.php";

$PHOTO_DIR = $cfg["photo_dir"];
$CACHE_DIR = $cfg["cache_dir"];
$JPEG_Q    = $cfg["jpeg_quality"];

$base = realpath($PHOTO_DIR);
$rel  = isset($_GET["p"]) ? $_GET["p"] : "";
$w    = isset($_GET["w"]) ? intval($_GET["w"]) : 0;

/* ---- Pfad absichern (kein Verzeichnis-Ausbruch) ---- */
if ($base === false || $rel === "") { http_response_code(400); exit; }
$rel  = str_replace("\\", "/", $rel);
$full = realpath($base . "/" . $rel);
if ($full === false || strpos($full, $base) !== 0 || !is_file($full)) {
    http_response_code(404); exit;
}

$info = @getimagesize($full);
$mime = $info ? $info["mime"] : "application/octet-stream";

/* ---- Ohne Verkleinerung oder ohne GD: Original durchreichen ---- */
$canResize = function_exists("imagecreatetruecolor") && $info &&
             ($info[0] > $w) && $w > 0 &&
             in_array($mime, array("image/jpeg", "image/png"), true);

if (!$canResize) {
    header("Content-Type: " . $mime);
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . filesize($full));
    readfile($full);
    exit;
}

/* ---- Cache pruefen ---- */
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0777, true);
$key   = md5($full . "|" . filemtime($full) . "|" . $w) . ".jpg";
$cache = $CACHE_DIR . "/" . $key;

if (is_file($cache)) {
    @touch($cache);                 // "zuletzt benutzt" -> schuetzt vor Aufraeumen
    header("Content-Type: image/jpeg");
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . filesize($cache));
    readfile($cache);
    exit;
}

/* ---- Verkleinern ---- */
$src = ($mime === "image/png") ? @imagecreatefrompng($full) : @imagecreatefromjpeg($full);
if (!$src) {
    header("Content-Type: " . $mime);
    readfile($full);
    exit;
}
$ow = imagesx($src); $oh = imagesy($src);
$nw = $w; $nh = (int) round($oh * ($w / $ow));
$dst = imagecreatetruecolor($nw, $nh);
imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);

/* EXIF-Drehung bei JPEG anwenden */
if ($mime === "image/jpeg" && function_exists("exif_read_data")) {
    $exif = @exif_read_data($full);
    if (!empty($exif["Orientation"])) {
        $o = $exif["Orientation"];
        if ($o == 3) $dst = imagerotate($dst, 180, 0);
        elseif ($o == 6) $dst = imagerotate($dst, -90, 0);
        elseif ($o == 8) $dst = imagerotate($dst, 90, 0);
    }
}

imagejpeg($dst, $cache, $JPEG_Q);   // in Cache schreiben
imagedestroy($src); imagedestroy($dst);

header("Content-Type: image/jpeg");
header("Cache-Control: public, max-age=86400");
header("Content-Length: " . filesize($cache));
readfile($cache);
