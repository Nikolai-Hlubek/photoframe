<?php
/*
 * config.php  ->  builds the effective configuration.
 *
 * Sources, in increasing priority:
 *   1. defaults from schema.php
 *   2. settings.json   (non-secret values, edited via the admin UI)
 *   3. .env            (secrets: NAS access, admin password, location)
 *
 * Read by list.php, img.php, index.php, sync.php and admin/.
 * Returns a plain array, so existing code keeps using $cfg["key"].
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
            $key  = trim(substr($line, 0, $pos));
            $val  = substr($line, $pos + 1);
            $lead = ltrim($val);
            if ($lead !== "" && ($lead[0] === '"' || $lead[0] === "'")) {
                // quoted value: take between first and last quote (ignores trailing comment)
                $q = $lead[0];
                $t = trim($val);
                $end = strrpos($t, $q);
                $val = ($end > 0) ? substr($t, 1, $end - 1) : trim($t, $q);
            } else {
                // unquoted: strip an inline comment ("<space>#...") then trim
                $val = preg_replace('/\s+#.*$/', '', $val);
                $val = trim($val);
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

/* --- settings.json -> array --- */
if (!function_exists("load_settings")) {
    function load_settings($path) {
        if (!is_file($path)) return array();
        $j = json_decode(file_get_contents($path), true);
        return is_array($j) ? $j : array();
    }
}

/* --- coerce a stored value to the schema type --- */
if (!function_exists("cfg_coerce")) {
    function cfg_coerce($val, $type) {
        switch ($type) {
            case "int":   return (int)$val;
            case "float": return (float)$val;
            case "bool":
                if (is_bool($val)) return $val;
                return in_array(strtolower((string)$val), array("1","true","yes","on","ja"), true);
            case "list":
                if (is_array($val)) {
                    return array_values(array_filter(array_map("trim", $val), "strlen"));
                }
                $parts = preg_split('/[,\r\n]+/', (string)$val);
                return array_values(array_filter(array_map("trim", $parts), "strlen"));
            default:      return (string)$val;
        }
    }
}

$schema   = require __DIR__ . "/schema.php";
$env      = load_env(__DIR__ . "/.env");
$settings = load_settings(__DIR__ . "/settings.json");

$cfg = array();
foreach ($schema as $f) {
    $key = $f["key"];
    $val = $f["default"];
    if ($f["store"] === "env") {
        $ek = $f["env"];
        if (isset($env[$ek]) && $env[$ek] !== "") $val = $env[$ek];
    } else {
        if (array_key_exists($key, $settings) && $settings[$key] !== "") $val = $settings[$key];
    }
    $cfg[$key] = cfg_coerce($val, $f["type"]);
}

/* derived / file-based values */
if (empty($cfg["photo_dir"])) $cfg["photo_dir"] = __DIR__ . "/photo";
$cfg["cache_dir"]      = $cfg["photo_dir"] . "/.cache";
$cfg["remote_subdirs"] = load_lines(__DIR__ . "/remote_subdirs.txt");

return $cfg;
