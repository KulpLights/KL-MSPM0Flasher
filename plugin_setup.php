<?php
$boards = Array();
foreach(scandir("/home/fpp/media/plugins/KL-MSPM0Flasher/boards") as $pFile)
    if ($pFile != "." && $pFile != "..") {
        if (preg_match('/\.json$/', $pFile))  {
            $pFile = preg_replace('/\.json$/', '', $pFile);
            $boards[$pFile] = $pFile;
        }
    }
?>
<script type="text/javascript">

function ProgramDone() {
	$("#programBoardCloseDialogButton").prop("disabled", false);
	EnableModalDialogCloseButton("programBoardPopupStatus");
}

function FlashBoard() {
	// This function will handle the flashing of the selected board
	var board = document.getElementById("BoardSelect").value;
	if (board === "0") {
		alert("Please select a board to flash.");
		return;
	}
	
	// Add your flashing logic here
	console.log("Flashing board: " + board);
	

	var options = {
		id: "programBoardPopupStatus",
		title: "KulpLights MSPM0Flasher",
		body: "<textarea style='max-width:100%; max-height:100%; width: 100%; height:100%;' disabled id='programBoardText'></textarea>",
		class: "modal-dialog-scrollable",
		noClose: true,
		keyboard: false,
		backdrop: "static",
		footer: "",
		buttons: {
			"Close": {
				id: 'programBoardCloseDialogButton',
				click: function () {
					CloseModalDialog("programBoardPopupStatus");
					location.reload();
				},
				disabled: true,
				class: 'btn-success'
			}
		}
	};
	$("#programBoardCloseDialogButton").prop("disabled", true);
	DoModalDialog(options);

	clearTimeout(statusTimeout);
	statusTimeout = null;

	StreamURL('plugin.php?_menu=status&plugin=KL-MSPM0Flasher&page=programBoard.php&nopage=1&board=' + board, 'programBoardText', 'ProgramDone', 'ProgramDone');
}

</script>
<div id="start" class="settings">
<fieldset>
<legend>KulpLights MSPM0Flasher</legend>

<p>Board: <?php PrintSettingSelect("Board", "BoardSelect", "0", "0", "disabled", $boards, "KL-MSPM0Flasher"); ?> </p>

<p><input class="buttons" onClick="FlashBoard();" type="submit" value="Start" /></p>

</fieldset>
</div>

<br />
