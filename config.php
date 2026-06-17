<?php
/*
 * config.php  ->  central configuration for the photo frame.
 *
 * Read by list.php, img.php and sync.php.
 * Secrets (NAS user/password) do NOT live here but in .env
 * (see .env.example). The .env is not checked in.
 */

/* --- small .env loader (KEY=VALUE per line, # = comment) --- */
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
            // strip surrounding quotes
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

/* --- list from file: one line = one entry, # = comment, blank = ignored --- */
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

    /* ---------- Paths (used by all scripts) ---------- */
    // Folder with the images. __DIR__ = folder of this config.php.
    "photo_dir"   => __DIR__ . "/photo",
    // Cache for downscaled images (must be writable).
    "cache_dir"   => __DIR__ . "/photo/.cache",

    /* ---------- Display / listing (list.php) ---------- */
    "recursive"   => true,                                   // include subfolders?
    "extensions"  => array("jpg","jpeg","png","gif","webp","bmp"),
    // Album whitelist (top-level subfolders). Empty = all.
    // Example: array("Urlaub2023", "Familie")
    "albums"      => array(),

    /* ---------- Image delivery (img.php) ---------- */
    "jpeg_quality"=> 82,                                     // 0..100

    /* ---------- Local cache / rotation (sync.php) ---------- */
    // Maximum size of locally kept images in MB. If the NAS folder is
    // larger, only a slice is kept and swapped out over time.
    "cache_limit_mb"  => 10000,
    // How much is swapped per sync run: delete this many MB of the oldest
    // images and replace them with new random photos.
    "refresh_mb"      => 500,
    // Downscale cache (photo/.cache): delete files not shown for this many
    // days. 0 = never clean up.
    "cache_keep_days" => 7,

    /* ---------- NAS sync (sync.php) -- address/credentials from .env ---------- */
    "nas_base"    => isset($env["NAS_BASE"]) ? $env["NAS_BASE"] : "",        // <-- from .env
    "nas_user"    => isset($env["NAS_USER"]) ? $env["NAS_USER"] : "",        // <-- from .env
    "nas_pass"    => isset($env["NAS_PASS"]) ? $env["NAS_PASS"] : "",        // <-- from .env
    "remote_dir"  => isset($env["REMOTE_DIR"]) ? $env["REMOTE_DIR"] : "/photo", // <-- from .env
    // The list of subfolders to include lives in remote_subdirs.txt
    // (not checked in). One line = one folder, each recursive.
    // File missing/empty = take the whole remote_dir.
    "remote_subdirs" => load_lines(__DIR__ . "/remote_subdirs.txt"),
    "sync_hours"  => 24,                                      // interval for "php sync.php --loop"
    "delete_old"  => true,                                   // delete locally what is gone on the NAS?
);
