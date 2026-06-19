<?php
/*
 * log.php  ->  tiny shared logger.
 *
 *   logmsg($m)  CLI use (sync.php): prints to stdout AND appends to the log file.
 *   logweb($m)  web use (list/img/admin): appends to the log file only.
 *
 * Log file: logs/app.log next to this file. Rotated to app.log.1 when it
 * grows past the configured limit (default 512 KB; set via log_set_max()).
 */

if (!function_exists("log_set_max")) {

    $GLOBALS["FR_LOG_DIR"] = __DIR__ . "/logs";
    $GLOBALS["FR_LOG_MAX"] = 512 * 1024;

    function log_set_max($kb) {
        if ((int)$kb > 0) $GLOBALS["FR_LOG_MAX"] = (int)$kb * 1024;
    }

    function log_file() { return $GLOBALS["FR_LOG_DIR"] . "/app.log"; }

    function fr_write($line) {
        $dir = $GLOBALS["FR_LOG_DIR"];
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = log_file();
        if (is_file($file) && filesize($file) > $GLOBALS["FR_LOG_MAX"]) {
            @rename($file, $file . ".1");   // single-backup rotation
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    function fr_stamp($m) { return "[" . date("Y-m-d H:i:s") . "] " . $m; }

    /* CLI: echo + file */
    function logmsg($m) {
        $line = fr_stamp($m);
        echo $line . "\n";
        fr_write($line);
    }

    /* web / silent: file only */
    function logweb($m) {
        fr_write(fr_stamp($m));
    }

    /* last $n lines of the log (for the admin UI) */
    function log_tail($n = 200) {
        $file = log_file();
        if (!is_file($file)) return array();
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return array();
        return array_slice($lines, -$n);
    }
}
