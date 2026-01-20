<?php
define('IN_MYBB', 1);
require_once __DIR__ . '/global.php';
require_once MYBB_ROOT . 'inc/functions_user.php';

function deny() {
	http_response_code(403);
	exit('Forbidden');
}

$SHARED_KEY = 'Password1234!';
$username = $_GET['username'] ?? '';
$token = $_GET['token'] ?? '';

if ($username === '' || $token === ''){
	deny();
}

if (!hash_equals($SHARED_KEY, $token)){
	deny();
}

$user = get_user_by_username($username);

if(!$user){
	exit("user not found in MyBB");
}

complete_login($user);

header("Location: index.php");
exit;
?>
