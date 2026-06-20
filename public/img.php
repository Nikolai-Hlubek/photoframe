<?php
/*
 * img.php?p=<relative path>&w=<maxWidth>
 *
 * Serves an image from PHOTO_DIR. Optionally downscaled server-side and
 * rotated according to its EXIF orientation. Processed images are cached
 * in CACHE_DIR. Falls back to the untouched original when neither
 * downscaling nor rotation is needed (or GD is unavailable).
 *
 * Configuration lives centrally in config.php.
 */

$cfg = require __DIR__ . "/../config.php";
require __DIR__ . "/../log.php";
log_set_max($cfg["log_max_kb"]);

$PHOTO_DIR = $cfg["photo_dir"];
$CACHE_DIR = $cfg["cache_dir"];
$JPEG_Q    = $cfg["jpeg_quality"];
$CACHE_VER = "v2";                       // bump to invalidate all cached files

$base = realpath($PHOTO_DIR);
$rel  = isset($_GET["p"]) ? $_GET["p"] : "";
$w    = isset($_GET["w"]) ? intval($_GET["w"]) : 0;

/* ---- sanitize path (no directory traversal) ---- */
if ($base === false || $rel === "") { http_response_code(400); exit; }
$rel  = str_replace("\\", "/", $rel);
$full = realpath($base . "/" . $rel);
if ($full === false || strpos($full, $base) !== 0 || !is_file($full)) {
    http_response_code(404);
    logweb("img 404: " . $rel);
    exit;
}

$info = @getimagesize($full);
$mime = $info ? $info["mime"] : "application/octet-stream";

/* ---- read EXIF orientation (JPEG only; needs the exif extension) ---- */
$orient = 1;
if ($mime === "image/jpeg" && function_exists("exif_read_data")) {
    $e = @exif_read_data($full);
    if (!empty($e["Orientation"])) $orient = (int)$e["Orientation"];
}

$gd         = function_exists("imagecreatetruecolor")
              && in_array($mime, array("image/jpeg", "image/png"), true);
$needResize = ($info && $w > 0 && $info[0] > $w);
$needRotate = ($orient > 1);

/* ---- nothing to do (or no GD): pass the original through ---- */
if (!$gd || (!$needResize && !$needRotate)) {
    header("Content-Type: " . $mime);
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . filesize($full));
    readfile($full);
    exit;
}

/* ---- cache (key includes width + orientation + version) ---- */
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0777, true);
$key   = md5($full . "|" . filemtime($full) . "|" . $w . "|" . $orient . "|" . $CACHE_VER) . ".jpg";
$cache = $CACHE_DIR . "/" . $key;

if (is_file($cache)) {
    @touch($cache);                 // mark "last used" -> protects from cleanup
    serve_jpeg($cache);
}

/* ---- decode ---- */
$src = ($mime === "image/png") ? @imagecreatefrompng($full) : @imagecreatefromjpeg($full);
if (!$src) {                        // decode failed -> serve original untouched
    header("Content-Type: " . $mime);
    readfile($full);
    exit;
}

/* ---- optional downscale ---- */
if ($needResize) {
    $ow = imagesx($src); $oh = imagesy($src);
    $nw = $w; $nh = (int) round($oh * ($w / $ow));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    imagedestroy($src);
} else {
    $dst = $src;
}

/* ---- apply EXIF orientation ---- */
if ($needRotate) $dst = apply_orientation($dst, $orient);

imagejpeg($dst, $cache, $JPEG_Q);
imagedestroy($dst);
serve_jpeg($cache);


function apply_orientation($img, $o) {
    switch ($o) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 3: $img = imagerotate($img, 180, 0); break;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
        case 5: $img = imagerotate($img, -90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 6: $img = imagerotate($img, -90, 0); break;
        case 7: $img = imagerotate($img,  90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 8: $img = imagerotate($img,  90, 0); break;
    }
    return $img;
}

function serve_jpeg($file) {
    header("Content-Type: image/jpeg");
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . filesize($file));
    readfile($file);
    exit;
}
