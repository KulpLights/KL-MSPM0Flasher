<?php
/*
 * Board and revision pickers for reflashing a cape whose MSPM0 was never
 * programmed.  Without that part the eeprom and the ADC never appear, so FPP
 * cannot identify the cape and its normal eeprom tooling has nothing to talk
 * to - which is why this page cannot just read the installed cape's identity
 * and has to ask.
 *
 * The board list comes from boards.json in the published eeprom tree, so a
 * board or a revision added there shows up here with no change to the plugin.
 */

$pluginDir = dirname(__FILE__);
$config = @json_decode(file_get_contents("$pluginDir/programmer-config.json"), true) ?: [];
$base = rtrim($config['eepromBase'] ?? 'https://kulplights.com/firmwares', '/');
$boardsURL = str_replace('%BASE%', $base, $config['instructionsDir'] ?? '%BASE%/programmer/instructions') . '/boards.json';

$boards = [];
$loadError = '';

// Prefer the copy the programmer has already cached: if this device has flashed
// a board before, the page still works with the site unreachable.
$cacheDir = $config['cacheDir'] ?? 'cache';
$cached = $pluginDir . '/' . $cacheDir . '/' . preg_replace('#^https?://#', '', $boardsURL);

$ctx = stream_context_create(['http' => ['timeout' => 10], 'https' => ['timeout' => 10]]);
$raw = @file_get_contents($boardsURL, false, $ctx);
if ($raw === false && is_readable($cached)) {
    $raw = @file_get_contents($cached);
}
if ($raw === false) {
    $loadError = "Could not read the board list from $boardsURL";
} else {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $loadError = "The board list at $boardsURL is not valid JSON";
    } else {
        // Only boards whose MSPM0 needs flashing. The others have a plain
        // eeprom that FPP's own cape tooling already writes, so offering them
        // here would be a second, worse way to do something that works.
        foreach ($decoded as $name => $info) {
            if (!empty($info['mspm0'])) {
                $boards[$name] = $info;
            }
        }
        ksort($boards, SORT_NATURAL);
    }
}

$binary = "$pluginDir/programmer-cli";
$haveBinary = is_executable($binary);
?>
<script type="text/javascript">
var KLBoards = <?php echo json_encode($boards); ?>;

function KLBoardChanged() {
    var board = document.getElementById("BoardSelect").value;
    var sel = document.getElementById("VersionSelect");
    sel.innerHTML = "";
    var info = KLBoards[board];
    if (!info) {
        document.getElementById("KLGoButton").disabled = true;
        return;
    }
    info.versions.forEach(function (v) {
        var o = document.createElement("option");
        o.value = v;
        o.text = v + (v === info["default"] ? " (current)" : "");
        if (v === info["default"]) { o.selected = true; }
        sel.appendChild(o);
    });
    document.getElementById("KLGoButton").disabled = false;
}

function KLProgramDone() {
    $("#klCloseButton").prop("disabled", false);
    EnableModalDialogCloseButton("klProgramStatus");
}

function KLFlashBoard() {
    var board = document.getElementById("BoardSelect").value;
    var version = document.getElementById("VersionSelect").value;
    if (!board) {
        alert("Please select a board.");
        return;
    }
    // The revision is the one thing here that cannot be checked against the
    // hardware: an unprogrammed cape has no eeprom to read it from, so a wrong
    // pick writes a wrong-but-valid eeprom. Make the operator confirm it
    // against the silkscreen.
    if (!confirm("Program a " + board + " revision " + version + "?\n\n"
                 + "Check this against the version printed on the board. "
                 + "Flashing the wrong revision writes an eeprom that does not "
                 + "match your hardware.")) {
        return;
    }

    var options = {
        id: "klProgramStatus",
        title: "Programming " + board + " " + version,
        body: "<textarea style='max-width:100%; max-height:100%; width:100%; height:100%;' disabled id='klProgramText'></textarea>",
        class: "modal-dialog-scrollable",
        noClose: true,
        keyboard: false,
        backdrop: "static",
        footer: "",
        buttons: {
            "Close": {
                id: 'klCloseButton',
                click: function () {
                    CloseModalDialog("klProgramStatus");
                    location.reload();
                },
                disabled: true,
                class: 'btn-success'
            }
        }
    };
    $("#klCloseButton").prop("disabled", true);
    DoModalDialog(options);

    if (typeof statusTimeout !== 'undefined' && statusTimeout) {
        clearTimeout(statusTimeout);
        statusTimeout = null;
    }

    StreamURL('plugin.php?_menu=status&plugin=KL-MSPM0Flasher&page=programBoard.php&nopage=1'
              + '&board=' + encodeURIComponent(board)
              + '&version=' + encodeURIComponent(version),
              'klProgramText', 'KLProgramDone', 'KLProgramDone');
}

document.addEventListener("DOMContentLoaded", KLBoardChanged);
</script>

<div id="klFlasher" class="settings">
<fieldset>
<legend>Kulp Lights Cape Programmer</legend>

<?php if ($loadError != '') { ?>
  <div class="alert alert-danger"><?php echo htmlspecialchars($loadError); ?></div>
<?php } else if (count($boards) == 0) { ?>
  <div class="alert alert-warning">No programmable boards were found in the board list.</div>
<?php } else if (!$haveBinary) { ?>
  <div class="alert alert-danger">
    The programmer binary is not installed. It is downloaded when the plugin is
    installed and on each boot; re-run
    <code>plugins/KL-MSPM0Flasher/scripts/fetch-binary.sh</code> to retry.
  </div>
<?php } else { ?>
  <p>
    Use this if your cape is not detected by FPP at all. It reprograms the
    monitoring chip that carries the cape's eeprom, which on rare occasions
    leaves the factory unprogrammed &mdash; without it the cape has no identity
    for FPP to read.
  </p>
  <p>
    <b>Power the controller from its normal supply and disconnect it from
    anything else while this runs.</b>
  </p>

  <p>
    Board:
    <select id="BoardSelect" onchange="KLBoardChanged();">
      <?php foreach ($boards as $name => $info) { ?>
        <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
      <?php } ?>
    </select>
  </p>

  <p>
    Board revision: <select id="VersionSelect"></select>
    <span class="ml-2">(printed on the board)</span>
  </p>

  <p><input class="buttons" id="KLGoButton" onClick="KLFlashBoard();" type="submit" value="Program" /></p>
<?php } ?>

</fieldset>
</div>
<br />
