# php-websocket-client
stream wrapper for websockets

Makes a websocket client easy.

Limitations websocket packets larger than 8192 characters.
This is a limitation of fread.
usage:
```php
include "./Websocket.php";
$stream = fopen("wss://echo.websocket.org",1);
$test = '{"setID":"YOURID","passwd":"ANYTHING"}';
fwrite($stream, $test);
fwrite($stream, $test);
$data = fread($stream,100000);
print_r(json_decode($data));
```
