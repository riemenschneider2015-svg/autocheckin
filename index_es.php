
<html>  
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/> 
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.css">
<script src="https://code.jquery.com/jquery-1.11.3.min.js"></script>
<script src="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.js"></script>
</head>
<body>
<style>

h3{
	    text-align: center;
}

.mitte{
	    text-align: center;
}

input[type="tel"] {
background: #fefcea; /* Old browsers */
background: -moz-linear-gradient(left, #fefcea 0%, #f1da36 100%); /* FF3.6-15 */
background: -webkit-linear-gradient(left, #fefcea 0%,#f1da36 100%); /* Chrome10-25,Safari5.1-6 */
background: linear-gradient(to right, #fefcea 0%,#f1da36 100%); /* W3C, IE10+, FF16+, Chrome26+, Opera12+, Safari7+ */
filter: progid:DXImageTransform.Microsoft.gradient( startColorstr='#fefcea', endColorstr='#f1da36',GradientType=1 ); /* IE6-9 */
}

</style>
<!--<input type="reset" value="RESET">-->
<form action="geheim_es.php" method="post">
<H3>¡Bienvenidos al check-in automático Mechlerstraße!</h3>
	
<p class="mitte">Por favor, introduzcan aquí su código pin y elijan la puerta que quieran abrir.</p>



<div data-role="fieldcontain" width="50%">
<label for="name">PIN:</label>	
<?php echo "<input id=\"id1\" type=\"tel\" name=\"pin\" value=\"".$_POST['pin']."\" >";?>	

</div>
	<input type="submit" name="haustuer" value="Abrir la puerta principal" data-icon="home">

	<input type="submit" name="wohnungauf" value="Abrir la puerta del apartamento" data-icon="forward">	
		<br>
		    <a href="tel:015253453390" class="ui-btn ui-icon-phone ui-btn-icon-left">Problemas - Llamar a Sophie</a>
		
 </form> </body> </html>
