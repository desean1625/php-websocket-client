# php-websocket-client

##PHP websocket stream wrapper

php stream wrapper for websockets

Makes a websocket client easy.


usage:
```php
include "./Websocket.php";
$stream = fopen("wss://echo.websocket.org",1);
$test = '{"setID":"YOURID","passwd":"ANYTHING"}';
fwrite($stream, $test);
$data = fread($stream,100000);
print_r(json_decode($data));
```
More advanced websocket using react stream select loop

```php
include "./Websocket.php";
include "./EventLoop/Factory.php";
$stream = fopen("wss://echo.websocket.org",1);
$test = '{"setID":"YOURID","passwd":"ANYTHING"}';
fwrite($stream, $test);


$loop = EventLoop\Factory::create();
$loop->addReadStream($stream, function ($stream) use ($loop) {
    $data = fread($stream, 100000);
    print_r(json_decode($data));
});
$loop->addPeriodicTimer(2, function () use ($stream) {
    $test = '{"setID":"YOURID","passwd":"ANYTHING"}';
    echo "sending $test\n";
    fwrite($stream, $test);
});
$loop->run();
```
