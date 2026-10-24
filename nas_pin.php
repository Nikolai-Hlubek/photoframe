<?php
/*
 * nas_pin.php  ->  prints the public-key pin of the NAS HTTPS certificate (for NAS_PIN).
 *
 *   php nas_pin.php 192.168.1.10:5001
 *
 * Trust on first use: run it inside your own network and check the certificate it shows.
 */

if ($argc < 2) { fwrite(STDERR, "Aufruf: php nas_pin.php <ip>:<port>\n"); exit(1); }

$ctx = stream_context_create(array("ssl" => array(
    "capture_peer_cert" => true, "verify_peer" => false, "verify_peer_name" => false)));
$s = @stream_socket_client("ssl://" . $argv[1], $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
if (!$s) { fwrite(STDERR, "Keine TLS-Verbindung zu " . $argv[1] . ": " . $errstr . "\n"); exit(1); }

$params = stream_context_get_params($s);
$cert   = $params["options"]["ssl"]["peer_certificate"];
$info   = openssl_x509_parse($cert);
$key    = openssl_pkey_get_details(openssl_pkey_get_public($cert));
$der    = base64_decode(preg_replace('/-----[^-]+-----|\s+/', "", $key["key"]));

echo "Zertifikat: " . $info["name"] . "\n";
echo "Aussteller: " . (isset($info["issuer"]["CN"]) ? $info["issuer"]["CN"] : "?")
   . ", gueltig bis " . date("Y-m-d", $info["validTo_time_t"]) . "\n";
echo "NAS_PIN=sha256//" . base64_encode(hash("sha256", $der, true)) . "\n";
