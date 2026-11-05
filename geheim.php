<html><body> 
<meta http-equiv="refresh" content="10; URL=index.php">
<meta name="viewport" content="width=device-width, initial-scale=1">
<font FACE="Lucida Sans,Verdana,Comic Sans MS,Arial,Times New Roman"> 
<div class="pin">
<style>
.pin {
  font-size: 14px;
  margin-left: auto;
  margin-right: auto;
  width: 240px;
  }
.pinrot {
  font-size: 14px;
  margin-left: auto;
  margin-right: auto;
  width: 240px;
  color: red;
}

</style>
  <H3>Herzlich Willkommen beim AutoCheckin Mechlerstr</h3>
 <?php 

$bruteforceschutz=@fopen('bruteforceschutz.txt','a+');
fputs($bruteforceschutz,'*');
fclose($bruteforceschutz);
$bruteforceschutz=@fopen('bruteforceschutz.txt','r');
$bruteforce=fgets($bruteforceschutz);
fclose($bruteforceschutz);

$handle=@fopen('pin04157258.txt','r');
$geheim="";
while ($line=fgets($handle,100)) {
	$geheim=trim($line);
	if ($_POST['pin'] == $geheim && strlen($geheim)>3) {
		break;
	}
}
fclose($handle);
//$geheim=trim(fgets($handle,100));

// loggen von Login-Versuchen Beginn
$heutecsv = date("d.m.Y H:i:s");
$ip = $_SERVER["REMOTE_ADDR"];
$browser = $_SERVER["HTTP_USER_AGENT"];

$erfolg=$_POST['pin'] == $geheim ? "Pin OK" : "Pin '".$_POST['pin']."' falsch";
$loginversuche=fopen("loginversuche.csv","a");
fputs($loginversuche,$heutecsv.";".$ip.";".$erfolg.";".((string)strlen($bruteforce)).";\"".$browser."\"\n");
fclose($loginversuche);
// loggen von Login-Versuchen Ende


$maxLogins=10;
if($_POST['pin'] == $geheim && strlen($geheim)>3 && strlen($bruteforce)<=$maxLogins) { 
	if (isset($_POST['haustuer']) && $_POST['haustuer']!='') {
		echo "<p class='pin'>Bitte laufen Sie die Treppe hoch bis ins Dachgeschoss! Die Wohnung in der Mitte rechts ist die Ferienwohnung. <br>Vielen Dank. <br><br>Ich wünsche Ihnen einen schönen Aufenthalt!</p>";
	} else {
		echo "<p class='pin'>Bitte drücken Sie gegen die Tür und klicken Sie auf des Symbol um die Tür zu &ouml;ffnen.<br>Vielen Dank. <br><br>Ich wünsche Ihnen einen schönen Aufenthalt!</p>";
	}
	$bruteforceschutz=@fopen('bruteforceschutz.txt','w');
	ftruncate($bruteforceschutz,0);
	fclose($bruteforceschutz);
} else { 
if (strlen($bruteforce)<=$maxLogins) {
	echo "<p class='pin'>Falscher Pin! Sie werden wieder zur Login Seite weitergeleitet</p>"; 
} else {
	echo "<p class='pinrot'>Login gesperrt! Bitte Gastgeber zum Entsperren anrufen. Sie werden wieder zur Login Seite weitergeleitet</p>"; 	
}

flush();
?>
<p class="pin">
<a href="tel:+4915253453390">Problem beim Checkin - Hier klicken um Falko anrufen</a>
</p>
</div>
<?php
die("");
} ?>



<?php
$login = 'admin';
$password = 'octasias';
$url = '';
if (isset($_POST['haustuer']) && $_POST['haustuer']!='') {
	$url = 'http://hnsulh5cz5kvqzpa.myfritz.net:8083/fhem?cmd.Tuer=set%20Tuer%20on&room=all&XHR=1&fw_id=77';
} else if (isset($_POST['wohnungauf']) && $_POST['wohnungauf']!='') {
	$url = 'http://hnsulh5cz5kvqzpa.myfritz.net:8083/fhem?cmd.Wohnungstuer=set%20Wohnungstuer%20on&room=all&XHR=1&fw_id=67';
} else if (isset($_POST['wohnungzu']) && $_POST['wohnungzu']!='') {
	$url = 'http://hnsulh5cz5kvqzpa.myfritz.net:8083/fhem?cmd.Schloss=set%20Schloss%20lock&room=Eingang&XHR=1&fw_id=62';
}
if ($url != '') {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,$url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($ch, CURLOPT_USERPWD, "$login:$password");
	curl_exec($ch);
	$errno = curl_errno($ch);
	curl_close($ch);
	//echo "<p class='pinrot'>".(string)$errno."</p>";
	if ($errno!==0) {
		?>
	    <p class='pinrot'><b>Autocheckin fehlgeschlagen</b></p>
		<a href="tel:+4915253453390" class="ui-btn ui-icon-phone ui-btn-icon-left">Bitte Falko anrufen</a>
		<?php
	}
}

flush();
?>
	<script language="javascript" type="text/javascript">
		var auto_refresh = setInterval( function() { submitform(); }, 6000);
		function submitform() { document.f1.submit(); }
	</script>
	<form action="index.php" name="f1" id="f1" method="post"> 
		<input type="submit" alt="Zurück" id="zurueck" name="zurueck" value="Zur&uuml;ck">
<?php
		echo "<input type=\"hidden\" name=\"pin\" value=\"".$_POST['pin']."\">";
		?>
	</form>
<?php
if (isset($_POST['haustuer']) && $_POST['haustuer']!='') {
	$empfaenger = "falko.riemenschneider@gmx.de";
	$betreff = "Haustür Mechlerstr geöffnet";
	$from = "From: Auto-checkin <falko.riemenschneider@gmx.de>";
	$text = "Haustür Mechlerstr geöffnet";
	mail($empfaenger, $betreff, $text, $from);	
} else if (isset($_POST['wohnungauf']) && $_POST['wohnungauf']!='') {
	$empfaenger = "falko.riemenschneider@gmx.de";
	$betreff = "Wohnungstür Mechlerstr geöffnet";
	$from = "From: Auto-checkin <falko.riemenschneider@gmx.de>";
	$text = "Wohnungstür Mechlerstr geöffnet";
	mail($empfaenger, $betreff, $text, $from);
} else if (isset($_POST['wohnungzu']) && $_POST['wohnungzu']!='') {
	$empfaenger = "falko.riemenschneider@gmx.de";
	$betreff = "Wohnungstür Mechlerstr verschlossen";
	$from = "From: Auto-checkin <falko.riemenschneider@gmx.de>";
	$text = "Wohnungstür Mechlerstr verschlossen";
	mail($empfaenger, $betreff, $text, $from);

}
?>
</body></html>
