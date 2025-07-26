<?php

$pages = Array(
	'name' => "Kulp Lights MSPM0 Flasher",
	'type' => "status",
	'page' => "plugin_setup.php",
	);

?>

<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=plugin_setup.php', dirname(__FILE__)); ?>">Kulp Lights MSPM0 Flasher</a></li>
