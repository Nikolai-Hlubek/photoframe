<?php
/*
 * nas.php  ->  Synology FileStation API helpers (HTTP/JSON).
 *
 * Shared by sync.php (download) and admin/ (connection test, folder picker).
 * All functions take the base URL / sid explicitly. The last transport error
 * is exposed in $GLOBALS["NAS_ERR"].
 */

if (!function_exists("nas_login")) {

    $GLOBALS["NAS_ERR"] = "";

    function nas_login($base, $user, $pass) {
        $url = $base . "/webapi/auth.cgi?api=SYNO.API.Auth&version=3&method=login"
             . "&account=" . rawurlencode($user)
             . "&passwd="  . rawurlencode($pass)
             . "&session=FileStation&format=sid";
        $raw = nas_http_get($url);
        if ($raw === false || $raw === null || $raw === "") {
            $GLOBALS["NAS_ERR"] = "Keine Antwort. " . $GLOBALS["NAS_ERR"];
            return null;
        }
        $r = json_decode($raw, true);
        if (!is_array($r)) {
            $GLOBALS["NAS_ERR"] = "Antwort nicht als JSON lesbar: " . substr(trim($raw), 0, 160);
            return null;
        }
        if (empty($r["success"])) {
            $code = isset($r["error"]["code"]) ? $r["error"]["code"] : "?";
            $GLOBALS["NAS_ERR"] = "API-Fehlercode " . $code . " (" . nas_auth_err($code) . ")";
            return null;
        }
        return $r["data"]["sid"];
    }

    function nas_logout($base, $sid) {
        nas_http_get($base . "/webapi/auth.cgi?api=SYNO.API.Auth&version=1&method=logout"
                   . "&session=FileStation&_sid=" . rawurlencode($sid));
    }

    /* lists $path and recurses into subfolders; fills $out[relPath]=size */
    function nas_walk($base, $sid, $path, $prefix, &$out, $exts) {
        $url = $base . "/webapi/entry.cgi?api=SYNO.FileStation.List&version=2&method=list"
             . "&folder_path=" . rawurlencode($path)
             . "&additional=" . rawurlencode('["size"]')
             . "&_sid=" . rawurlencode($sid);
        $r = json_decode(nas_http_get($url), true);
        if (!$r || empty($r["success"])) return;
        foreach ($r["data"]["files"] as $f) {
            $name = $f["name"];
            $rel  = $prefix === "" ? $name : $prefix . "/" . $name;
            if (!empty($f["isdir"])) {
                nas_walk($base, $sid, $path . "/" . $name, $rel, $out, $exts);
            } else if (nas_ext_ok($name, $exts)) {
                $out[$rel] = isset($f["additional"]["size"]) ? (int)$f["additional"]["size"] : 0;
            }
        }
    }

    /* immediate subfolders of $path (names only) -- for the folder picker */
    function nas_list_subdirs($base, $sid, $path) {
        $url = $base . "/webapi/entry.cgi?api=SYNO.FileStation.List&version=2&method=list"
             . "&folder_path=" . rawurlencode($path)
             . "&filetype=dir&_sid=" . rawurlencode($sid);
        $r = json_decode(nas_http_get($url), true);
        $dirs = array();
        if ($r && !empty($r["success"])) {
            foreach ($r["data"]["files"] as $f) {
                if (!empty($f["isdir"])) $dirs[] = $f["name"];
            }
        }
        sort($dirs);
        return $dirs;
    }

    function nas_download($base, $sid, $remotePath, $localFile) {
        $url = $base . "/webapi/entry.cgi?api=SYNO.FileStation.Download&version=2&method=download"
             . "&path=" . rawurlencode($remotePath)
             . "&mode=download&_sid=" . rawurlencode($sid);
        return nas_http_download($url, $localFile);
    }

    /* high-level: try to log in and list the base dir; returns array(ok,msg) */
    function nas_test($base, $user, $pass, $dir) {
        if ($base === "" || $user === "") return array(false, "NAS-Adresse oder Benutzer fehlt.");
        $sid = nas_login($base, $user, $pass);
        if (!$sid) return array(false, $GLOBALS["NAS_ERR"]);
        $dirs = nas_list_subdirs($base, $sid, $dir);
        nas_logout($base, $sid);
        return array(true, "Verbindung ok. " . count($dirs) . " Unterordner in " . $dir . ".");
    }

    function nas_auth_err($code) {
        switch ((int)$code) {
            case 400: return "Konto oder Passwort falsch";
            case 401: return "Konto deaktiviert";
            case 402: return "Berechtigung verweigert";
            case 403: return "2-Faktor-Code (OTP) erforderlich";
            case 404: return "2-Faktor-Code falsch";
            case 406: return "OTP-Erzwingung; App-Passwort noetig";
            case 119: return "Sitzung/SID ungueltig";
            default:  return "siehe Synology-API-Doku";
        }
    }

    function nas_ext_ok($name, $exts) {
        $dot = strrpos($name, ".");
        if ($dot === false) return false;
        return in_array(strtolower(substr($name, $dot + 1)), $exts, true);
    }

    function nas_http_get($url) {
        $GLOBALS["NAS_ERR"] = "";
        if (function_exists("curl_init")) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $body = curl_exec($ch);
            if ($body === false) {
                $GLOBALS["NAS_ERR"] = "curl: " . curl_error($ch)
                                    . " (HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ")";
            }
            return $body;
        }
        $body = @file_get_contents($url);
        if ($body === false) $GLOBALS["NAS_ERR"] = "file_get_contents fehlgeschlagen (allow_url_fopen?)";
        return $body;
    }

    function nas_http_download($url, $localFile) {
        if (function_exists("curl_init")) {
            $fp = fopen($localFile, "wb");
            if (!$fp) return false;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            $ok = curl_exec($ch);
            fclose($fp);
            if (!$ok) @unlink($localFile);
            return (bool)$ok;
        }
        $data = @file_get_contents($url);
        if ($data === false) return false;
        return file_put_contents($localFile, $data) !== false;
    }
}
