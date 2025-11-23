<?php
$host = "localhost";
$user = "root";
$pass = "";
$db = "clinagenda";

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("Erro de conexão: " . mysqli_connect_error());
}
?>
