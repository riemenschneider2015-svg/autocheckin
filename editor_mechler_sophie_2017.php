<html>
<head>
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PIN Ändern Mechlerstr.</title>
<link rel="stylesheet" href="//wkns.eu/mechler/jquery/jquery.mobile-1.4.5.min.css" />
<script src="//wkns.eu/mechler/jquery/jquery-1.11.1.min.js"></script>
<script src="//wkns.eu/mechler/jquery/jquery.mobile-1.4.5.min.js"></script>
<!--
-->
</head>
<body>
<div data-role="page" id="page1">
	<div data-role="header">   <h2>Bitte neuen PIN <br>festlegen,nur <br> ein Pin pro Zeile</h2>  </div>

	<div role="main" class="ui-content">

  <script>
    $.mobile.changePage("//wkns.eu/mechler/editor_mechler_sophie_2017.php", { reloadPage: false, transition: "none", changeHash: false } );
  </script>



<?php
// configuration
$url = "https://wkns.eu/mechler/editor_mechler_sophie_2017.php";
$file = 'pin04157258.txt';

// check if form has been submitted
if (isset($_POST['text']))
{
    // save the text contents
	file_put_contents($file,$_POST['text']);
//file_put_contents($file, preg_replace('/\\r/','\\n',$_POST['text'].trim));

    // redirect to form again
	header(sprintf('Location: %s', $url));
    printf('<a href="%s">Moved</a>.', htmlspecialchars($url));
	die();
}

// read the textfile
$text = file_get_contents($file);

$bruteforceschutz=@fopen('bruteforceschutz.txt','r');
$bruteforce=fgets($bruteforceschutz);
fclose($bruteforceschutz);
if (isset($_POST['resetCounter'])) {
	$bruteforceschutz=@fopen('bruteforceschutz.txt','w');
	ftruncate($bruteforceschutz,0);
	fclose($bruteforceschutz);
}
$neuePin = sprintf('%09u',mt_rand(0,100000));
?>
<!-- HTML form -->  
<div data-role="main" class="ui-content">
  
<form action="" method="post" id="f1">
  <textarea name="text"><?php echo htmlspecialchars($text) ?></textarea><br>
  <input type="submit" value="Speichern">
</form>

<form action="" method="post" id="f2">
  <textarea name="text" style="display:none;"><?php echo htmlspecialchars(sprintf('%05u',mt_rand(0,100000))) ?></textarea><br>
  <input type="submit" value="Neue Pin (wird automatisch gespeichert)" onLoad="window.location.href=window.location.href">
</form>

<form action="" method="post" id="formCounter">
  <?php echo strlen($bruteforce) ." fehlerhafte Logins<br>"; ?>
  <input type="submit" name="resetCounter" value="Login entsperren" />
</form> 

</div>   </div>

<div data-role="footer">    <h2>Vielen Dank!</h2>  </div>
</div>
</body>
