<html><body> 
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/> 
<meta http-equiv="refresh" content="10; URL=index_eng.php">
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
  <H3>¡Bienvenidos al check-in automático Mechlerstraße!</h3>
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
		echo "<p class='pin'>Por favor, suban las escaleras hasta el ático. Ahí el apartamento se encuentra a la derecha en el centro. <br>Muchas gracias. <br><br>¡Les deseo una buena estancia!</p>";
	} else {
		echo "<p class='pin'>Hagan click en el símbolo para abrir la puerta. <br>Muchas gracias. <br><br>¡Les deseo una buena estancia!</p>";
	}
	$bruteforceschutz=@fopen('bruteforceschutz.txt','w');
	ftruncate($bruteforceschutz,0);
	fclose($bruteforceschutz);
} else { 
if (strlen($bruteforce)<=$maxLogins) {
	echo "<p class='pin'>¡Pin incorrecto! Serán dirigidos automáticamente a la página de inicio de sesión.</p>"; 
} else {
	echo "<p class='pinrot'> ¡Inicio de sesión bloqueado! Por favor, llamar al anfitrión. Serán dirigidos de nuevo a la página de inicio de sesión.</p>"; 	
}

flush();
?>
<p class="pin">
<a href="tel:015253453390">Problemas con el check-in - Hagan click aquí para llamar a Falko.</a>
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
	    <p class='pinrot'><b>El autocheckin ha fallado</b></p>
		<a href="tel:015253453390" class="ui-btn ui-icon-phone ui-btn-icon-left">Llamar a Falko</a>
		<?php
	}
}

flush();
?>
	<script language="javascript" type="text/javascript">
		var auto_refresh = setInterval( function() { submitform(); }, 6000);
		function submitform() { document.f1.submit(); }
	</script>
	<form action="index_es.php" name="f1" id="f1" method="post"> 
		<input type="submit" alt="atrás" id="zurueck" name="zurueck" value="atrás">
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
