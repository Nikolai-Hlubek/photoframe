<?php
/*
 * store.php  ->  write helpers for the admin UI.
 *
 * Writes back the three editable stores:
 *   .env             (secrets/address; managed keys merged, unknown kept)
 *   settings.json    (non-secret options)
 *   remote_subdirs.txt
 *
 * Relies on load_env()/load_settings() from config.php (required first).
 */

if (!function_exists("store_write_env")) {

    function store_write_env($path, $pairs) {
        $existing = function_exists("load_env") ? load_env($path) : array();
        $merged = array_merge($existing, $pairs);   // new values win, unknown keys kept
        $out = "# NAS access/address, location and admin password -- not committed\n";
        foreach ($merged as $k => $v) {
            $out .= $k . "=" . str_replace(array("\r", "\n"), "", (string)$v) . "\n";
        }
        return file_put_contents($path, $out, LOCK_EX) !== false;
    }

    function store_write_settings($path, $arr) {
        $json = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
    }

    function store_write_lines($path, $text) {
        $text = str_replace("\r\n", "\n", $text);
        return file_put_contents($path, $text, LOCK_EX) !== false;
    }
}
