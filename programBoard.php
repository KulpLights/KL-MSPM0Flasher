<?php
$skipJSsettings = 1;
require_once("/opt/fpp/www/common.php");
DisableOutputBuffering();

$board = isset($_GET['board']) ? $_GET['board'] : 'not specified';
echo "Programming Board: $board\n";
echo "----------------------------------------------------------------------------------\n";

$command = "sudo /home/fpp/media/plugins/KL-MSPM0Flasher/bin/Programmer " . escapeshellarg($board);
echo "Command: $command\n";
system($command);
echo "\n";
echo "----------------------------------------------------------------------------------\n";
?>


