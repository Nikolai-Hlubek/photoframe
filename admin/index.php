<?php
/*
 * admin/index.php  ->  Management interface (run on port 8081).
 *
 *   php -S 0.0.0.0:8081 -t admin
 *
 * Edits .env, settings.json and remote_subdirs.txt from a form generated
 * out of schema.php. Password gate via ADMIN_PASS (.env). LAN-only.
 */

session_start();

$ROOT     = dirname(__DIR__);
$cfg      = require $ROOT . "/config.php";
$schema   = require $ROOT . "/schema.php";
require $ROOT . "/log.php";
require $ROOT . "/nas.php";
require $ROOT . "/store.php";
log_set_max($cfg["log_max_kb"]);
$GLOBALS["NAS_PIN"] = $cfg["nas_pin"];

$ENV_FILE = $ROOT . "/.env";
$SET_FILE = $ROOT . "/settings.json";
$SUB_FILE = $ROOT . "/remote_subdirs.txt";

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$need_auth = ($cfg["admin_pass"] !== "");

/* ---- login / logout ---- */
if (isset($_POST["do"]) && $_POST["do"] === "login") {
    $given = isset($_POST["password"]) ? (string)$_POST["password"] : "";
    if ($need_auth && hash_equals((string)$cfg["admin_pass"], $given)) {
        $_SESSION["fr_auth"] = true;
    }
    header("Location: index.php"); exit;
}
if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    $_SESSION = array(); session_destroy();
    header("Location: index.php"); exit;
}

$authed = (!$need_auth) || !empty($_SESSION["fr_auth"]);

/* ---- AJAX: connection test / folder browse (uses submitted or saved NAS values) ---- */
if (isset($_GET["action"]) && ($_GET["action"] === "test" || $_GET["action"] === "browse")) {
    header("Content-Type: application/json; charset=utf-8");
    if (!$authed) { echo json_encode(array("ok" => false, "msg" => "Nicht angemeldet.")); exit; }
    $base = isset($_GET["base"]) && $_GET["base"] !== "" ? $_GET["base"] : $cfg["nas_base"];
    $user = isset($_GET["user"]) && $_GET["user"] !== "" ? $_GET["user"] : $cfg["nas_user"];
    $pass = isset($_GET["pass"]) && $_GET["pass"] !== "" ? $_GET["pass"] : $cfg["nas_pass"];
    $dir  = isset($_GET["dir"])  && $_GET["dir"]  !== "" ? $_GET["dir"]  : $cfg["remote_dir"];
    if ($_GET["action"] === "test") {
        list($ok, $msg) = nas_test($base, $user, $pass, $dir);
        echo json_encode(array("ok" => $ok, "msg" => $msg));
    } else {
        $path = isset($_GET["path"]) && $_GET["path"] !== "" ? $_GET["path"] : $dir;
        $sid = nas_login($base, $user, $pass);
        if (!$sid) { echo json_encode(array("ok" => false, "msg" => $GLOBALS["NAS_ERR"])); exit; }
        $dirs = nas_list_subdirs($base, $sid, $path);
        nas_logout($base, $sid);
        echo json_encode(array("ok" => true, "path" => $path, "dirs" => $dirs));
    }
    exit;
}

/* ---- save ---- */
if (isset($_POST["do"]) && $_POST["do"] === "save" && $authed) {
    $envpairs = array();
    $setarr   = load_settings($SET_FILE);   // preserve unknown keys
    foreach ($schema as $f) {
        $key = $f["key"]; $field = "f_" . $key;
        if ($f["type"] === "bool") {
            $val = isset($_POST[$field]);
        } else {
            $raw = isset($_POST[$field]) ? $_POST[$field] : "";
            $val = cfg_coerce($raw, $f["type"]);
        }
        if ($f["store"] === "env") {
            if (!empty($f["secret"]) && (string)$val === "") continue;  // keep existing secret
            $envpairs[$f["env"]] = is_array($val) ? implode(",", $val) : (string)$val;
        } else {
            $setarr[$key] = $val;
        }
    }
    store_write_env($ENV_FILE, $envpairs);
    store_write_settings($SET_FILE, $setarr);
    if (isset($_POST["remote_subdirs"])) store_write_lines($SUB_FILE, $_POST["remote_subdirs"]);
    logweb("admin: Einstellungen gespeichert");
    header("Location: index.php?saved=1"); exit;
}

/* ---- current values for the form ---- */
$subs_text = is_file($SUB_FILE) ? file_get_contents($SUB_FILE) : "";
$groups = array();
foreach ($schema as $f) { $groups[$f["group"]][] = $f; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fotorahmen · Verwaltung</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 760px; margin: 0 auto; padding: 20px;
         background: #f4f4f6; color: #1a1a1a; }
  h1 { font-size: 22px; } h2 { font-size: 15px; margin: 22px 0 8px; color: #444;
       border-bottom: 1px solid #ddd; padding-bottom: 4px; }
  .card { background: #fff; border: 1px solid #e2e2e6; border-radius: 10px; padding: 18px 20px; margin-bottom: 16px; }
  label { display: block; font-size: 13px; margin: 10px 0 2px; font-weight: 600; }
  .help { font-weight: 400; color: #888; font-size: 12px; }
  input[type=text], input[type=password], select, textarea {
    width: 100%; box-sizing: border-box; padding: 7px 9px; border: 1px solid #ccc;
    border-radius: 6px; font-size: 14px; font-family: inherit; }
  textarea { min-height: 110px; font-family: monospace; }
  .row { display: flex; gap: 8px; align-items: center; }
  .row input[type=checkbox] { width: 18px; height: 18px; }
  button { padding: 8px 16px; border: 0; border-radius: 6px; background: #2f6fed; color: #fff;
           font-size: 14px; cursor: pointer; }
  button.sec { background: #e7e7ec; color: #222; }
  .bar { position: sticky; bottom: 0; background: #f4f4f6; padding: 12px 0; display: flex; gap: 10px; }
  .ok { background: #e3f5e6; border: 1px solid #9bd6a6; color: #1c6b2c; padding: 8px 12px; border-radius: 6px; }
  .warn { background: #fdf2d6; border: 1px solid #e6c86a; color: #6b5510; padding: 8px 12px; border-radius: 6px; }
  #result { margin: 8px 0; font-size: 14px; }
  .chip { display: inline-block; background: #eef; border: 1px solid #ccd; border-radius: 14px;
          padding: 3px 10px; margin: 3px; font-size: 13px; cursor: pointer; }
  #crumb { margin: 4px 0 10px; }
  #crumb a { margin: 0 2px; }
  .prow { display: flex; align-items: center; justify-content: space-between;
          padding: 5px 2px; border-bottom: 1px solid #eee; }
  .prow label { margin: 0; font-weight: 400; display: flex; align-items: center; gap: 8px; }
  .prow input[type=checkbox] { width: 18px; height: 18px; }
  .open { padding: 3px 10px; font-size: 12px; }
  pre.log { background: #111; color: #cfcfcf; padding: 12px; border-radius: 8px; font-size: 12px;
            max-height: 260px; overflow: auto; }
  a { color: #2f6fed; }
</style>
</head>
<body>
<h1>Fotorahmen · Verwaltung</h1>

<?php if (!$authed): ?>
  <div class="card">
    <p>Bitte mit dem Admin-Passwort anmelden.</p>
    <form method="post">
      <input type="hidden" name="do" value="login">
      <label>Passwort</label>
      <input type="password" name="password" autofocus>
      <div class="bar"><button type="submit">Anmelden</button></div>
    </form>
  </div>
<?php else: ?>

  <?php if (isset($_GET["saved"])): ?><p class="ok">Gespeichert. Änderungen am Sync/Server wirken ab dem nächsten Lauf.</p><?php endif; ?>
  <?php if (!$need_auth): ?><p class="warn">Kein Admin-Passwort gesetzt — bitte unter „Admin" eines vergeben und speichern.</p><?php endif; ?>

  <p>
    <a href="/" target="_blank">Diashow öffnen</a> ·
    <a href="?action=logout">Abmelden</a>
  </p>

  <div id="result"></div>

  <form method="post">
    <input type="hidden" name="do" value="save">

    <?php foreach ($groups as $gname => $fields): ?>
      <div class="card">
        <h2><?= h($gname) ?></h2>
        <?php foreach ($fields as $f):
          $key = $f["key"]; $name = "f_" . $key; $val = $cfg[$key];
          $help = $f["help"] !== "" ? '<span class="help"> — ' . h($f["help"]) . '</span>' : ''; ?>

          <?php if ($f["type"] === "bool"): ?>
            <label class="row"><input type="checkbox" name="<?= h($name) ?>" <?= $val ? "checked" : "" ?>> <?= h($f["label"]) ?><?= $help ?></label>

          <?php elseif ($f["type"] === "select"): ?>
            <label><?= h($f["label"]) ?><?= $help ?></label>
            <select name="<?= h($name) ?>">
              <?php foreach ($f["options"] as $opt): ?>
                <option value="<?= h($opt) ?>" <?= ($val === $opt ? "selected" : "") ?>><?= h($opt) ?></option>
              <?php endforeach; ?>
            </select>

          <?php elseif (!empty($f["secret"])): ?>
            <label><?= h($f["label"]) ?><?= $help ?></label>
            <input type="password" name="<?= h($name) ?>" placeholder="•••• (leer = unverändert)">

          <?php else:
            $disp = is_array($val) ? implode(", ", $val) : $val; ?>
            <label><?= h($f["label"]) ?><?= $help ?></label>
            <input type="text" name="<?= h($name) ?>" value="<?= h($disp) ?>">
          <?php endif; ?>

        <?php endforeach; ?>

        <?php if ($gname === "NAS"): ?>
          <div class="bar" style="position:static;background:none;padding-top:12px">
            <button type="button" class="sec" onclick="nasTest()">Verbindung testen</button>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <h2>Ordnerauswahl</h2>
      <p class="help">Durch die NAS-Ordner klicken und anhaken, was synchronisiert
        werden soll. „öffnen" geht in einen Unterordner. Nichts ausgewählt = alle Ordner.</p>
      <div class="bar" style="position:static;background:none;padding:0 0 8px">
        <button type="button" class="sec" onclick="pickerLoad('')">Ordner von der NAS laden</button>
      </div>
      <div id="crumb" class="help"></div>
      <div id="picker"></div>
      <details style="margin-top:12px">
        <summary class="help">Manuell bearbeiten (eine Zeile = ein Ordner)</summary>
        <textarea name="remote_subdirs" id="subs"><?= h($subs_text) ?></textarea>
      </details>
    </div>

    <div class="bar"><button type="submit">Speichern</button></div>
  </form>

  <div class="card">
    <h2>Log (letzte Zeilen)</h2>
    <pre class="log"><?php
      $tail = log_tail(120);
      echo $tail ? h(implode("\n", $tail)) : "(noch nichts geloggt)";
    ?></pre>
  </div>

  <script>
    function v(n){ var e=document.getElementsByName(n)[0]; return e ? e.value : ""; }
    function nasParams(){
      return "base="+encodeURIComponent(v("f_nas_base"))+"&user="+encodeURIComponent(v("f_nas_user"))
            +"&pass="+encodeURIComponent(v("f_nas_pass"))+"&dir="+encodeURIComponent(v("f_remote_dir"));
    }
    function nasTest(){
      var r=document.getElementById("result"); r.textContent="Teste …";
      fetch("?action=test&"+nasParams()).then(function(x){return x.json();}).then(function(d){
        r.innerHTML='<span class="'+(d.ok?"ok":"warn")+'">'+(d.ok?"✓ ":"✗ ")+d.msg+'</span>';
      }).catch(function(){ r.textContent="Test fehlgeschlagen."; });
    }

    /* ---- folder picker (navigate + checkbox select) ---- */
    var curRel = "";   // current location relative to remote_dir

    function isSel(rel){
      var sel = document.getElementById("subs").value.split("\n");
      for (var i=0;i<sel.length;i++){ if (sel[i].trim() === rel) return true; }
      return false;
    }
    function setSel(rel, on){
      var t=document.getElementById("subs"), kept=[], found=false;
      t.value.split("\n").forEach(function(l){
        if (l.trim() === rel){ found=true; if (on) kept.push(l); }   // drop line if unchecking
        else kept.push(l);
      });
      if (on && !found) kept.push(rel);
      t.value = kept.join("\n");
    }
    function relPath(name){ return curRel ? curRel + "/" + name : name; }
    function absPath(){ var b=v("f_remote_dir"); return curRel ? b + "/" + curRel : b; }

    function pickerLoad(rel){
      curRel = rel;
      renderCrumb();
      var p=document.getElementById("picker"); p.textContent="Lade …";
      fetch("?action=browse&"+nasParams()+"&path="+encodeURIComponent(absPath()))
        .then(function(x){return x.json();}).then(function(d){
          if(!d.ok){ p.innerHTML='<span class="warn">'+d.msg+'</span>'; return; }
          if(!d.dirs.length){ p.innerHTML="<p class='help'>Keine Unterordner hier.</p>"; return; }
          p.innerHTML="";
          d.dirs.forEach(function(name){
            var rel=relPath(name);
            var row=document.createElement("div"); row.className="prow";
            var lab=document.createElement("label");
            var cb=document.createElement("input"); cb.type="checkbox"; cb.checked=isSel(rel);
            cb.onchange=function(){ setSel(rel, cb.checked); };
            lab.appendChild(cb); lab.appendChild(document.createTextNode(" "+name));
            var open=document.createElement("button");
            open.type="button"; open.className="sec open"; open.textContent="öffnen ›";
            open.onclick=function(){ pickerLoad(rel); };
            row.appendChild(lab); row.appendChild(open);
            p.appendChild(row);
          });
        }).catch(function(){ p.innerHTML='<span class="warn">Konnte Ordner nicht laden.</span>'; });
    }

    function renderCrumb(){
      var c=document.getElementById("crumb"); c.innerHTML="";
      function seg(label, rel){
        var a=document.createElement("a"); a.href="#"; a.textContent=label;
        a.onclick=function(e){ e.preventDefault(); pickerLoad(rel); };
        return a;
      }
      c.appendChild(document.createTextNode("Ort: "));
      c.appendChild(seg("Basis",""));
      if(curRel){
        var acc="";
        curRel.split("/").forEach(function(part){
          acc = acc ? acc+"/"+part : part;
          c.appendChild(document.createTextNode(" / "));
          c.appendChild(seg(part, acc));
        });
      }
    }
  </script>

<?php endif; ?>
</body>
</html>
