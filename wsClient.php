<?php
include "./Websocket.php";

$stream = fopen("wss://echo.websocket.org",1);

$test = '{"setID":"YOURID","passwd":"ANYTHING"}';
fwrite($stream, $test);

fwrite($stream, $test);
$data = fread($stream,100000);
print_r(json_decode($data));
