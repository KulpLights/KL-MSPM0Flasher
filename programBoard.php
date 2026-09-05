<?php
/*
 * Streams a programming run to the page.  Everything the flash needs - the
 * eeprom, the instructions, the MSPM0 firmware, the vendor image, the serial
 * number script - is fetched by the programmer from the published tree, so
 * this only has to pick the board and the revision.
 */
$skipJSsettings = 1;
require_once("/opt/fpp/www/common.php");
DisableOutputBuffering();

$pluginDir = dirname(__FILE__);
$binary = "$pluginDir/programmer-cli";

$board = isset($_GET['board']) ? $_GET['board'] : '';
$version = isset($_GET['version']) ? $_GET['version'] : '';

// Board and revision reach the shell, so accept only what the published names
// can legitimately contain rather than relying on escaping alone.
if (!preg_match('/^[A-Za-z0-9._-]+$/', $board)) {
    echo "Invalid board name.\n";
    exit(1);
}
if ($version !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
    echo "Invalid board revision.\n";
    exit(1);
}
if (!is_executable($binary)) {
    echo "The programmer binary is not installed.\n";
    echo "Run plugins/KL-MSPM0Flasher/scripts/fetch-binary.sh to download it.\n";
    exit(1);
}

echo "Programming $board" . ($version !== '' ? " revision $version" : "") . "\n";
echo "----------------------------------------------------------------------\n";

$cmd = "sudo " . escapeshellarg($binary) . " --board " . escapeshellarg($board);
if ($version !== '') {
    $cmd .= " --version " . escapeshellarg($version);
}
// stderr matters here: a failed step explains itself there.
$cmd .= " 2>&1";

$rc = 0;
passthru($cmd, $rc);

echo "----------------------------------------------------------------------\n";
if ($rc === 0) {
    echo "Done. Reboot the controller, then check that the cape is detected\n";
    echo "under Status/Control -> Cape Info.\n";
    echo "Record the serial number shown above - it is the board's identity\n";
    echo "and is not stored anywhere else.\n";
} else {
    echo "FAILED (exit $rc). Nothing further was written.\n";
    echo "Check that the correct board and revision were selected, and that\n";
    echo "the cape is seated properly.\n";
}
