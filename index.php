<?php
/* index.php -> slideshow page. Display settings are injected from config.php
 * (which reads schema defaults + settings.json + .env). */
$cfg = require __DIR__ . "/config.php";
function jsbool($b) { return $b ? "true" : "false"; }
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Fotorahmen</title>
<style>
  html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
    background: #000;
    overflow: hidden;
    cursor: none;
  }
  /* Two stacked layers for the crossfade */
  .layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-position: center center;
    background-repeat: no-repeat;
    background-size: contain;          /* show whole image; "cover" = fill the frame */
    opacity: 0;
    -webkit-transition: opacity 1.5s ease-in-out;
    transition: opacity 1.5s ease-in-out;
  }
  .layer.visible { opacity: 1; }

  #message {
    position: absolute;
    top: 50%;
    left: 0;
    width: 100%;
    margin-top: -1em;
    text-align: center;
    color: #888;
    font-family: sans-serif;
    font-size: 20px;
  }

  /* Shared styling of the overlays */
  .overlay {
    position: absolute;
    bottom: 16px;
    color: rgba(255,255,255,0.85);
    font-family: sans-serif;
    text-shadow: 0 1px 4px rgba(0,0,0,0.85);
  }
  /* clock + date bottom right */
  #datetime { right: 24px; text-align: right; }
  #clock { font-size: 30px; line-height: 1.1; }
  #date  { font-size: 17px; color: rgba(255,255,255,0.7); }

  /* weather bottom left */
  #weather { left: 24px; display: none; }
  #weather .wsym  { font-size: 26px; vertical-align: middle; margin-right: 6px; }
  #weather .wtemp { font-size: 30px; vertical-align: middle; }
  #weather .wdesc { font-size: 17px; color: rgba(255,255,255,0.7); }
</style>
</head>
<body>
  <div id="a" class="layer"></div>
  <div id="b" class="layer"></div>
  <div id="message">Lade Bilder &hellip;</div>

  <div id="datetime" class="overlay">
    <div id="clock"></div>
    <div id="date"></div>
  </div>

  <div id="weather" class="overlay">
    <span class="wsym"></span><span class="wtemp"></span>
    <div class="wdesc"></div>
  </div>

<script>
(function () {
  "use strict";

  /* ===== settings injected from config.php (.env / settings.json) ===== */
  var INTERVAL_MS    = <?= (int)$cfg["interval_ms"] ?>;
  var MAX_WIDTH      = <?= (int)$cfg["max_width"] ?>;
  var SHUFFLE        = true;
  var RELOAD_LIST_MS = <?= (int)$cfg["reload_list_min"] ?> * 60 * 1000;

  var SHOW_CLOCK   = <?= jsbool($cfg["show_clock"]) ?>;
  var SHOW_DATE    = <?= jsbool($cfg["show_date"]) ?>;
  var SHOW_WEATHER = <?= jsbool($cfg["show_weather"]) ?>;

  var WEATHER_LAT  = <?= json_encode((float)$cfg["weather_lat"]) ?>;
  var WEATHER_LON  = <?= json_encode((float)$cfg["weather_lon"]) ?>;
  var WEATHER_CITY = <?= json_encode((string)$cfg["weather_city"]) ?>;
  var WEATHER_MS   = 30 * 60 * 1000;   // refresh every 30 min

  /* URL override, e.g. ?interval=30000&width=1024&album=Familie */
  /* =================================================================== */

  var q = parseQuery();
  if (q.interval) INTERVAL_MS = parseInt(q.interval, 10) || INTERVAL_MS;
  if (q.width)    MAX_WIDTH   = parseInt(q.width, 10);

  var layers  = [document.getElementById("a"), document.getElementById("b")];
  var front   = 0;            // currently visible layer
  var images  = [];          // list of image paths (relative)
  var idx     = 0;
  var timer   = null;
  var msgEl   = document.getElementById("message");

  function parseQuery() {
    var out = {}, s = window.location.search.replace(/^\?/, "");
    if (!s) return out;
    var parts = s.split("&");
    for (var i = 0; i < parts.length; i++) {
      var kv = parts[i].split("=");
      out[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || "");
    }
    return out;
  }

  function imageUrl(path) {
    var u = "img.php?p=" + encodeURIComponent(path);
    if (MAX_WIDTH > 0) u += "&w=" + MAX_WIDTH;
    return u;
  }

  function shuffle(arr) {
    for (var i = arr.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
    }
  }

  function loadList(initial) {
    var url = "list.php?t=" + Date.now();
    if (q.album) url += "&album=" + encodeURIComponent(q.album);
    var xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        var data;
        try { data = JSON.parse(xhr.responseText); }
        catch (e) { showMessage("Fehler beim Lesen der Bilderliste."); return; }
        if (!data || !data.length) { showMessage("Keine Bilder gefunden."); return; }
        if (SHUFFLE) shuffle(data);
        images = data;
        if (initial) {
          hideMessage();
          idx = 0;
          showNext();
          if (timer) clearInterval(timer);
          timer = setInterval(showNext, INTERVAL_MS);
        }
      } else if (initial) {
        showMessage("NAS nicht erreichbar (HTTP " + xhr.status + "). Neuer Versuch …");
        setTimeout(function () { loadList(true); }, 10000);
      }
    };
    xhr.send();
  }

  function showNext() {
    if (!images.length) return;
    var path = images[idx];
    idx = (idx + 1) % images.length;
    if (idx === 0 && SHUFFLE) shuffle(images); // reshuffle each round

    var img = new Image();
    img.onload = function () {
      var back = 1 - front;
      layers[back].style.backgroundImage = "url('" + imageUrl(path).replace(/'/g, "%27") + "')";
      layers[back].className = "layer visible";
      layers[front].className = "layer";
      front = back;
    };
    img.onerror = function () { showNext(); }; // skip broken image
    img.src = imageUrl(path);
  }

  function showMessage(t) { msgEl.style.display = "block"; msgEl.innerHTML = t; }
  function hideMessage()  { msgEl.style.display = "none"; }

  /* ---------------- clock & date ---------------- */
  var clockEl = document.getElementById("clock");
  var dateEl  = document.getElementById("date");
  var WTAGE   = ["Sonntag","Montag","Dienstag","Mittwoch","Donnerstag","Freitag","Samstag"];
  var MONATE  = ["Januar","Februar","März","April","Mai","Juni","Juli",
                 "August","September","Oktober","November","Dezember"];
  function p2(n) { return (n < 10 ? "0" : "") + n; }
  function tick() {
    var d = new Date();
    clockEl.innerHTML = SHOW_CLOCK ? (p2(d.getHours()) + ":" + p2(d.getMinutes())) : "";
    dateEl.innerHTML  = SHOW_DATE
      ? (WTAGE[d.getDay()] + ", " + d.getDate() + ". " + MONATE[d.getMonth()] + " " + d.getFullYear())
      : "";
  }
  if (SHOW_CLOCK || SHOW_DATE) { tick(); setInterval(tick, 20000); }
  else { document.getElementById("datetime").style.display = "none"; }

  /* ---------------- weather (Open-Meteo) ---------------- */
  /* WMO weather codes -> symbol + German text */
  function weatherInfo(code) {
    if (code === 0)                       return ["☀", "Klar"];
    if (code === 1 || code === 2)         return ["⛅", "Heiter"];
    if (code === 3)                       return ["☁", "Bewölkt"];
    if (code === 45 || code === 48)       return ["☁", "Nebel"];
    if (code >= 51 && code <= 57)         return ["☂", "Niesel"];
    if (code >= 61 && code <= 67)         return ["☔", "Regen"];
    if (code >= 71 && code <= 77)         return ["❄", "Schnee"];
    if (code >= 80 && code <= 82)         return ["☔", "Regenschauer"];
    if (code === 85 || code === 86)       return ["❄", "Schneeschauer"];
    if (code >= 95)                       return ["⛈", "Gewitter"];
    return ["", ""];
  }
  var wBox  = document.getElementById("weather");
  var wSym  = wBox.getElementsByClassName("wsym")[0];
  var wTemp = wBox.getElementsByClassName("wtemp")[0];
  var wDesc = wBox.getElementsByClassName("wdesc")[0];
  function loadWeather() {
    var url = "https://api.open-meteo.com/v1/forecast?latitude=" + WEATHER_LAT +
              "&longitude=" + WEATHER_LON +
              "&current=temperature_2m,weather_code&timezone=auto";
    var xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4 || xhr.status < 200 || xhr.status >= 300) return;
      try {
        var d = JSON.parse(xhr.responseText);
        var t = Math.round(d.current.temperature_2m);
        var info = weatherInfo(d.current.weather_code);
        wSym.innerHTML  = info[0];
        wTemp.innerHTML = t + "°C";
        wDesc.innerHTML = (WEATHER_CITY ? WEATHER_CITY + " · " : "") + info[1];
        wBox.style.display = "block";
      } catch (e) {}
    };
    xhr.send();
  }
  if (SHOW_WEATHER) { loadWeather(); setInterval(loadWeather, WEATHER_MS); }

  /* tap = try fullscreen (best effort) */
  document.body.addEventListener("click", function () {
    var el = document.documentElement;
    var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.webkitRequestFullScreen;
    if (fn) { try { fn.call(el); } catch (e) {} }
  }, false);

  /* refresh list periodically (new photos) */
  setInterval(function () { loadList(false); }, RELOAD_LIST_MS);

  loadList(true);
})();
</script>
</body>
</html>
