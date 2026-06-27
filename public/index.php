<?php
/* index.php -> slideshow page. Display settings are injected from config.php
 * (which reads schema defaults + settings.json + .env). */
$cfg = require __DIR__ . "/../config.php";
function jsbool($b) { return $b ? "true" : "false"; }
$fit    = $cfg["image_fit"];                       // blur | contain | cover
$fgsize = ($fit === "cover") ? "cover" : "contain";
$showbg = ($fit === "blur");
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Fotorahmen</title>
<style>
  /* --uiscale: fine-tuning; base sizes use vmin so they scale with the display */
  :root { --uiscale: <?= json_encode((float)$cfg["ui_scale"]) ?>; }
  html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
    background: #000;
    overflow: hidden;
    cursor: none;
  }
  /* Two stacked layers for the crossfade; each has a (blurred) bg + the fg image */
  .layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    -webkit-transition: opacity 1.5s ease-in-out;
    transition: opacity 1.5s ease-in-out;
  }
  .layer.visible { opacity: 1; }
  .layer .bg, .layer .fg {
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background-position: center center;
    background-repeat: no-repeat;
  }
  .layer .bg {                          /* blurred fill behind the photo */
    background-size: cover;
    -webkit-filter: blur(2.2vmin) brightness(0.55);
    filter: blur(2.2vmin) brightness(0.55);
    -webkit-transform: scale(1.12);
    transform: scale(1.12);
    display: <?= $showbg ? "block" : "none" ?>;
  }
  .layer .fg {                          /* the actual photo */
    background-size: <?= $fgsize ?>;    /* contain | cover */
  }

  #message {
    position: absolute;
    top: 50%;
    left: 0;
    width: 100%;
    margin-top: -1em;
    text-align: center;
    color: #888;
    font-family: sans-serif;
    font-size: 20px;                                   /* fallback */
    font-size: calc(3vmin * var(--uiscale));
  }

  /* Shared styling of the overlays */
  .overlay {
    position: absolute;
    bottom: 16px;                                      /* fallback */
    bottom: 2.5vmin;
    color: rgba(255,255,255,0.85);
    font-family: sans-serif;
    text-shadow: 0 1px 4px rgba(0,0,0,0.85);
  }
  /* clock + date bottom right */
  #datetime { right: 24px; right: 3vmin; text-align: right; }
  #clock { font-size: 30px; font-size: calc(4.6vmin * var(--uiscale)); line-height: 1.1; }
  #date  { font-size: 17px; font-size: calc(2.6vmin * var(--uiscale)); color: rgba(255,255,255,0.7); }

  /* weather bottom left */
  #weather { left: 24px; left: 3vmin; display: none; }
  #weather .wsym  { font-size: 26px; font-size: calc(4vmin * var(--uiscale)); vertical-align: middle; margin-right: 6px; }
  #weather .wtemp { font-size: 30px; font-size: calc(4.6vmin * var(--uiscale)); vertical-align: middle; }
  #weather .wdesc { font-size: 17px; font-size: calc(2.6vmin * var(--uiscale)); color: rgba(255,255,255,0.7); }

  /* swipe direction hint */
  #hint {
    position: absolute; top: 50%;
    -webkit-transform: translateY(-50%); transform: translateY(-50%);
    font-family: sans-serif; font-weight: 300; font-size: 13vmin;
    color: #fff; text-shadow: 0 1px 8px rgba(0,0,0,0.7);
    opacity: 0; pointer-events: none;
    -webkit-transition: opacity 0.3s ease; transition: opacity 0.3s ease;
  }
</style>
</head>
<body>
  <div id="a" class="layer"><div class="bg"></div><div class="fg"></div></div>
  <div id="b" class="layer"><div class="bg"></div><div class="fg"></div></div>
  <div id="message">Lade Bilder &hellip;</div>
  <div id="hint"></div>

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

  var aEl = document.getElementById("a"), bEl = document.getElementById("b");
  var layers = [aEl, bEl];
  var fgs = [aEl.getElementsByClassName("fg")[0], bEl.getElementsByClassName("fg")[0]];
  var bgs = [aEl.getElementsByClassName("bg")[0], bEl.getElementsByClassName("bg")[0]];
  var FIT = <?= json_encode($fit) ?>;
  var SWIPE_REVERSE = <?= jsbool($cfg["swipe_reverse"]) ?>;
  var front   = 0;            // currently visible layer
  var images  = [];          // list of image paths (relative)
  var cur     = -1;          // index of the currently shown image
  var gen     = 0;           // navigation token (drops stale image loads)
  var timer   = null;
  var msgEl   = document.getElementById("message");
  var hintEl  = document.getElementById("hint");
  var hintT   = null;

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
          cur = -1;
          step(1, false);          // show the first image
          restartTimer();
        }
      } else if (initial) {
        showMessage("NAS nicht erreichbar (HTTP " + xhr.status + "). Neuer Versuch …");
        setTimeout(function () { loadList(true); }, 10000);
      }
    };
    xhr.send();
  }

  function restartTimer() {
    if (timer) clearInterval(timer);
    timer = setInterval(function () { step(1, false); }, INTERVAL_MS);
  }

  /* advance by dir (+1 next, -1 prev); manual nav also resets the autoplay timer */
  function step(dir, manual) {
    if (!images.length) return;
    showAt((cur + dir + images.length) % images.length, dir, 0);
    if (manual) { restartTimer(); showHint(dir); }
  }

  function showHint(dir) {
    if (!hintEl) return;
    if (dir > 0) { hintEl.innerHTML = "›"; hintEl.style.right = "4vmin"; hintEl.style.left = "auto"; }
    else         { hintEl.innerHTML = "‹"; hintEl.style.left = "4vmin"; hintEl.style.right = "auto"; }
    hintEl.style.opacity = "0.85";
    if (hintT) clearTimeout(hintT);
    hintT = setTimeout(function () { hintEl.style.opacity = "0"; }, 350);
  }

  function showAt(i, dir, tries) {
    if (tries > images.length) return;          // every image broken -> give up
    cur = i;
    var myGen = ++gen;                           // newest navigation wins
    var path  = images[i];
    var img = new Image();
    img.onload = function () {
      if (myGen !== gen) return;                 // superseded by a newer swipe
      var back = 1 - front;
      var u = "url('" + imageUrl(path).replace(/'/g, "%27") + "')";
      fgs[back].style.backgroundImage = u;
      if (FIT === "blur") bgs[back].style.backgroundImage = u;
      layers[back].className = "layer visible";
      layers[front].className = "layer";
      front = back;
    };
    img.onerror = function () {                   // skip broken image, same direction
      if (myGen !== gen) return;
      showAt((i + dir + images.length) % images.length, dir, tries + 1);
    };
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

  /* ---- touch: swipe = previous/next, tap = fullscreen ---- */
  var sx = 0, sy = 0, stime = 0;
  document.body.addEventListener("touchstart", function (e) {
    var t = e.changedTouches[0]; sx = t.clientX; sy = t.clientY; stime = Date.now();
  }, false);
  document.body.addEventListener("touchend", function (e) {
    var t = e.changedTouches[0];
    var dx = t.clientX - sx, dy = t.clientY - sy, dt = Date.now() - stime;
    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) {
      var d = dx < 0 ? 1 : -1;              // swipe left = next, swipe right = previous
      if (SWIPE_REVERSE) d = -d;            // ... unless reversed in the settings
      step(d, true);
    } else if (Math.abs(dx) < 12 && Math.abs(dy) < 12 && dt < 400) {
      tryFullscreen();                       // tap
    }
  }, false);

  /* keyboard arrows (handy when testing on a PC) */
  document.addEventListener("keydown", function (e) {
    if (e.keyCode === 39) step(1, true);          // right arrow = next
    else if (e.keyCode === 37) step(-1, true);    // left arrow = previous
  }, false);

  function tryFullscreen() {
    var el = document.documentElement;
    var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.webkitRequestFullScreen;
    if (fn) { try { fn.call(el); } catch (e) {} }
  }

  /* refresh list periodically (new photos) */
  setInterval(function () { loadList(false); }, RELOAD_LIST_MS);

  loadList(true);
})();
</script>
</body>
</html>
