<?PHP
namespace GnifBot;

abstract class WebSocket extends Socket
{
  const STATE_START  = 0;
  const STATE_HEADER = 1;
  const STATE_DATA   = 2;

  const HASH_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

  private $uri;
  private $origin;

  private $state;
  private $key;
  private $headers;

  public function __construct(string $host, int $port, bool $ssl, string $uri, string $origin)
  {
    parent::__construct($host, $port, $ssl);
    $this->uri    = $uri;
    $this->origin = $origin;
   }

  protected function send($msg): bool
  {
    if ($this->state != self::STATE_DATA)
    {
      Log::Proto(Log::WEBSOCKET, "Send: $msg");
      $msg .= "\r\n";
    }

    return parent::send($msg);
  }

  protected function sendText($msg): bool
  {
    if ($this->state != self::STATE_DATA)
    {
      Log::Error("Connection not yet established");
      return false;
    }

    Log::Proto(Log::WEBSOCKET, "Send: $msg");

    $len    = strlen($msg);
    $header = [0x81, 0x80];

    if ($len < 126)
    {
      $header[1] |= $len;
    }
    elseif ($len < 65536)
    {
      $header[1] |= 126;
      $header[] = ($len >> 8) & 0xff;
      $header[] = ($len >> 0) & 0xff;
    }
    else
    {
      $header[1] |= 127;
      $header[] = ($len >> 56) & 0xff;
      $header[] = ($len >> 48) & 0xff;
      $header[] = ($len >> 40) & 0xff;
      $header[] = ($len >> 32) & 0xff;
      $header[] = ($len >> 24) & 0xff;
      $header[] = ($len >> 16) & 0xff;
      $header[] = ($len >> 8 ) & 0xff;
      $header[] = ($len >> 0 ) & 0xff;
    }

    $out = '';
    foreach($header as $byte)
      $out .= chr($byte);

    $mask = openssl_random_pseudo_bytes(4);
    $out .= $mask;

    for($i = 0; $i < $len; ++$i)
      $out .= chr(ord($msg[$i]) ^ ord($mask[$i % 4]));

    return $this->send($out);
  }

  protected function sendBinary($msg): bool
  {
    if ($this->state != self::STATE_DATA)
    {
      Log::Error("Connection not yet established");
      return false;
    }

    // TODO
    return false;
  }

  protected function onConnect(): bool
  {
    $this->state   = self::STATE_START;
    $this->key     = '';
    $this->headers = [];

    for($i = 0; $i < 16; ++$i)
      $this->key .= chr(rand(0, 255));
    $this->key = base64_encode($this->key);

    $this->send("GET " . $this->uri . " HTTP/1.1");
    $this->send("Host: " . $this->host);
    $this->send("Upgrade: websocket");
    $this->send("Connection: Upgrade");
    $this->send("Sec-WebSocket-Key: " . $this->key);
    $this->send("Sec-WebSocket-Protocol: chat, superchat");
    $this->send("Sec-WebSocket-Version: 13");
    $this->send("Origin: " . $this->origin);
    $this->send("");
    return true;
  }

  public function onData($data): int
  {
    if ($this->state == self::STATE_DATA)
    {
      if (strlen($data) < 2)
        return 0;

      $hlen  = 2;
      $anbf1 = ord($data[0]);
      $anbf2 = ord($data[1]);
      $fin   = ($anbf1 & 0x80) != 0;
      $rsv   = ($anbf1 & 0x70) >> 4;
      $op    = ($anbf1 & 0x0F);
      $mask  = ($anbf2 & 0x80) != 0;
      $dlen  = ($anbf2 & 0x7F);


      if ($rsv)
      {
        Log::Error("RSV bits set: 0b%03b", $rsv);
        $this->disconnect();
        return 0;
      }

      if ($mask)
      {
        Log::Error("Client frame masked!");
        $this->disconnect();
        return 0;
      }

      // extended length
      if ($dlen == 126)
      {
        if (strlen($data) < 4)
          return 0;
        $hlen = 4;
        $dlen =
          (ord($data[2]) << 8) |
          (ord($data[3]) << 0);
      }
      elseif ($dlen == 127)
      {
        if (strlen($data) < 10)
          return 0;

        $hlen = 10;
        $dlen =
          (ord($data[2]) << 56) |
          (ord($data[3]) << 48) |
          (ord($data[4]) << 40) |
          (ord($data[5]) << 32) |
          (ord($data[6]) << 24) |
          (ord($data[7]) << 16) |
          (ord($data[8]) << 8 ) |
          (ord($data[9]) << 0 );
      }

      if (strlen($data) - $hlen < $dlen)
        return 0;

      $data = substr($data, $hlen, $dlen);

      switch($op)
      {
        // text frame
        case 0x1:
          $this->onTextData($data);
          break;

        // binary frame
        case 0x2:
          $this->onBinaryData($data);
          break;

        // connection close
        case 0x8:
          Log::Info("Remote closed the connection");
          $this->disconnect();
          return 0;

        // ping
        case 0x9:
          break;

        // pong
        case 0xA:
          break;

        default:
          Log::Warn("Unknown/unsupported opcode");
          break;
      }

      return $hlen + $dlen;
    }

    if(($pos = strpos($data, "\n")) !== false)
    {
      $line = trim(substr($data, 0, $pos));
      Log::Proto(Log::WEBSOCKET, "Recv: %s", $line);

      if ($this->state == self::STATE_START)
      {
        $response = explode(' ', $line, 3);
        if (count($response) < 2)
        {
          Log::Error("Invalid GET response: %s", $line);
          $this->disconnect();
          return 0;
        }

        if ($response[0] != 'HTTP/1.1')
        {
          Log::Error("Invalid HTTP protocol: %s", $response[0]);
          $this->disconnect();
          return 0;
        }

        if ($response[1] != 101)
        {
          Log::Error("Unexpected response code: %s", $response[1]);
          $this->disconnect();
          return 0;
        }

        $this->state = self::STATE_HEADER;
        return $pos + 1;
      }

      if ($this->state == self::STATE_HEADER)
      {
        if (empty($line))
        {
          if (!array_key_exists('sec-websocket-accept', $this->headers))
          {
            Log::Error("Missing Sec-WebSocket-Accept header");
            $this->disconnect();
            return 0;
          }

          $check = base64_encode(sha1($this->key . self::HASH_GUID, true));
          if ($this->headers['sec-websocket-accept'] != $check)
          {
            Log::Error("Invalid Sec-WebSocket-Accept value");
            $this->disconnect();
            return 0;
          }

          $this->state = self::STATE_DATA;
          return $pos + 1;
        }

        $line = explode(':', $line, 2);
        $this->headers[strtolower($line[0])] = trim($line[1]);
        return $pos + 1;
      }
    }

    return 0;
  }

  protected function onTextData  ($data): void { }
  protected function onBinaryData($data): void { }
}
?>