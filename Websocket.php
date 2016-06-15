<?php
/**
 * Stream wrapper for websocket client
 * Supporting draft hybi-10.
 *
 * @author Sean Sullivan
 * @version 2011-10-18
 */
class Websocket
{
    private $buffer = '';
    private $_host;
    private $_port;
    private $_path;
    private $_origin;
    private $_Socket    = null;
    private $_connected = false;
    private $opcodes    = array(
        1  => 'text',
        2  => 'binary',
        8  => 'close',
        9  => 'ping',
        10 => 'pong',
    );

    public function __construct($test1 = null, $test2 = null, $test3 = null)
    {
        print_r($test1);
        print_r($test2);
        print_r($test3);
    }

    public function __destruct()
    {
        $this->disconnect();
    }
    public function sendData($data, $type = 'text', $masked = true)
    {
        if ($this->_connected === false) {
            trigger_error("Not connected", E_USER_WARNING);
            return false;
        }
        if (!is_string($data)) {
            trigger_error("Not a string data was given.", E_USER_WARNING);
            return false;
        }
        if (strlen($data) == 0) {
            return false;
        }
        //echo "sending $data\n\n";
        $res = @fwrite($this->stream, $this->_hybi10Encode($data, $type, $masked));
        if ($res === 0 || $res === false) {
            return false;
        }
        return true;
    }

    public function checkConnection()
    {
        $this->_connected = false;

        // send ping:
        $data = 'ping?';
        @fwrite($this->_Socket, $this->_hybi10Encode($data, 'ping', true));
        $response = @fread($this->_Socket, 300);
        if (empty($response)) {
            return false;
        }
        $response = $this->_hybi10Decode($response);
        if (!is_array($response)) {
            return false;
        }
        if (!isset($response['type']) || $response['type'] !== 'pong') {
            return false;
        }
        $this->_connected = true;
        return true;
    }
    public function disconnect()
    {
        $this->_connected = false;
        is_resource($this->_Socket) and fclose($this->_Socket);
    }

    public function reconnect()
    {
        sleep(10);
        $this->_connected = false;
        fclose($this->_Socket);
        $this->connect($this->_host, $this->_port, $this->_path, $this->_origin);
    }
    private function _generateRandomString($length = 10, $addSpaces = true, $addNumbers = true)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
        $useChars   = array();
        // select some random chars:
        for ($i = 0; $i < $length; $i++) {
            $useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
        }
        // add spaces and numbers:
        if ($addSpaces === true) {
            array_push($useChars, ' ', ' ', ' ', ' ', ' ', ' ');
        }
        if ($addNumbers === true) {
            array_push($useChars, rand(0, 9), rand(0, 9), rand(0, 9));
        }
        shuffle($useChars);
        $randomString = trim(implode('', $useChars));
        $randomString = substr($randomString, 0, $length);
        return $randomString;
    }

    private function _hybi10Encode($payload, $type = 'text', $masked = true)
    {
        $frameHead     = array();
        $frame         = '';
        $payloadLength = strlen($payload);

        switch ($type) {
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;

            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;

            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;

            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1]     = ($masked === true) ? 255 : 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127) {
                $this->close(1004);
                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1]     = ($masked === true) ? 254 : 126;
            $frameHead[2]     = bindec($payloadLengthBin[0]);
            $frameHead[3]     = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }
        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        if ($masked === true) {
            // generate a random mask:
            $mask = array();
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);
        // append payload to frame:
        $framePayload = array();
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }
        return $frame;
    }

    private function _hybi10Decode($data)
    {
        $mask            = '';
        $unmaskedPayload = '';
        $decodedData     = array();
        $hdr             = unpack("Cb1/Cb2", $data);
        $fin             = (boolean) ($hdr["b1"] & 0b10000000);
        $opcode          = $hdr["b1"] & 0b00001111;
        $isMasked            = (boolean) ($hdr["b2"] & 0b10000000);
        $payloadLength       = $hdr["b2"] & 0b01111111;
        $decodedData['type'] = $this->opcodes[$opcode];
        if ($payloadLength === 126) {
        	$this->buffer .= fread($this->stream, 2);
        	$data = $this->buffer;
            $mask          = substr($data, 4, 4);
            $payloadOffset = 4;
            $dataLength    = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3])));
        } elseif ($payloadLength === 127) {
        	$this->buffer .= fread($this->stream, 8);
        	$data = $this->buffer;
            $mask          = substr($data, 10, 4);
            $payloadOffset = 10;
            $tmp           = '';
            for ($i = 0; $i < 8; $i++) {
                $tmp .= sprintf('%08b', ord($data[$i + 2]));
            }
            $dataLength = bindec($tmp);
            unset($tmp);
        } else {
            $mask          = substr($data, 2, 4);
            $payloadOffset = 2;
            $dataLength    = $payloadLength;
        }
        while (strlen($this->buffer) < $dataLength) {
            $this->buffer .= fread($this->stream, $dataLength);
            echo "\n".strlen($this->buffer) ." ". $dataLength."\n\n";
            //echo $this->buffer;
        }
        $data = $this->buffer;
        if ($isMasked === true) {
            for ($i = $payloadOffset; $i < $dataLength; $i++) {
                $j = $i - $payloadOffset;
                if (isset($data[$i])) {
                    $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
                }
            }
            $decodedData['payload'] = $unmaskedPayload;
        } else {
            $payloadOffset          = $payloadOffset;
            $decodedData['payload'] = substr($data, $payloadOffset);
        }
        if (strlen($data) == $dataLength + $payloadOffset) {
            $this->buffer = '';
            return $decodedData;
        } else {
            $this->buffer = $data;
            return false;
        }
    }

    public function connect($origin = false)
    {

        $key    = base64_encode($this->_generateRandomString(16, false, true));
        $header = "GET " . $this->_path . " HTTP/1.1\r\n";
        $header .= "Host: " . $this->_host . ":" . $this->_port . "\r\n";
        $header .= "Upgrade: websocket\r\n";
        $header .= "Connection: Upgrade\r\n";
        $header .= "Sec-WebSocket-Key: " . $key . "\r\n";
        if ($origin !== false) {
            $header .= "Sec-WebSocket-Origin: " . $origin . "\r\n";
        }
        $header .= "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($this->stream, $header);
        $response = fread($this->stream, 1500);
        preg_match('#Sec-WebSocket-Accept:\s(.*)$#mU', $response, $matches);
        if ($matches) {
            $keyAccept        = trim($matches[1]);
            $expectedResonse  = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
            $this->_connected = ($keyAccept === $expectedResonse) ? true : false;
        }
        return $this->_connected;
    }

    public function stream_open($url, $options, $test, $test1)
    {
        $url             = parse_url($url);
        $protocol        = ($url['scheme'] == 'ws') ? "tcp" : "ssl";
        $this->_protocol = ($url['scheme'] == 'ws') ? "tcp" : "ssl";
        if (!@$url['port']) {
            $this->_port = ($this->_protocol == "tcp") ? 80 : 443;
        } else {
            $this->_port = $url['port'];
        }
        if (!@$url['path']) {
            $this->_path = "/";
        } else {
            $this->_path = $url['path'];
        }
        $this->_host  = $url['host'];
        $this->stream = stream_socket_client($this->_protocol . "://" . $this->_host . ":" . $this->_port . $this->_path);
        $connected    = $this->connect();
        return $this->_connected;
    }
    public function stream_write($data)
    {
        if ($this->sendData($data)) {
            return strlen($data);
        }

        return false;
    }
    public function stream_read()
    {
        return $this->getData();
    }
    public function stream_eof()
    {
        return feof($this->stream);
    }
    public function getData()
    {
        if (strlen($this->buffer) < 2) {
            $this->buffer .= fread($this->stream, 2 - strlen($this->buffer));
        }
        $frame = $this->_hybi10Decode($this->buffer);
        return json_encode($frame);
    }
    public function stream_cast($cast_as)
    {
        return $this->stream ? $this->stream : false;
    }
    public function stream_stat()
    {
        return stream_get_meta_data($this->stream);
    }
}
stream_wrapper_register("ws", "Websocket")
or die("Failed to register protocol");
stream_wrapper_register("wss", "Websocket")
or die("Failed to register protocol");
