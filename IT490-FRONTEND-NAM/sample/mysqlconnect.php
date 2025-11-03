#!/usr/bin/php
<?php
$pass_salt;
$password_hash;
$query;

$mydb = new mysqli('127.0.0.1','testuser','Password1234!','testdb');

if ($mydb->errno != 0)
{
	echo "failed to connect to database: ". $mydb->error . PHP_EOL;
	exit(0);
}

echo "successfully connected to database".PHP_EOL;

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER["REQUEST_METHOD"] == "POST"){
	$username = $_POST["username"];
	$password = $_POST["password"];
	$email = $_POST["email"];
	$my_salt_value = [
		"cost" => 12,
	];

	$password = password_hash($password, PASSWORD_BCRYPT, $my_salt_value);
	#$pass_salt = bin2hex(random_bytes(16));
	#$password_hash = password_hash($password . $pass_salt, PASSWORD_BCRYPT);
	$query = "INSERT INTO users (username, password, email) VALUES ('$username',  '$password', '$email')";
	//_hash, password_salt, email) VALUES ('$username',  '$password_hash', '$pass_salt', '$email')";
}

$response = $mydb->query($query);
if ($mydb->errno != 0)
{
	echo "failed to execute query:".PHP_EOL;
	echo __FILE__.':'.__LINE__.":error: ".$mydb->error.PHP_EOL;
	exit(0);
}


?>
