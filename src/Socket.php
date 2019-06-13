<?PHP
namespace GnifBot;

abstract class Socket
{
  protected $host;
  protected $port;
  protected $ssl;

  private $socket;
  private $shutdown;
  private $outBuffer;
  private $inBuffer;

  public function __construct(string $host, int $port, bool $ssl = false)
  {
    $this->host = $host;
    $this->port = $port;
    $this->ssl  = $ssl;
  }

  public function connect(): bool
  {
    $this->shutdown  = false;
    $this->inBuffer  = '';
    $this->outBuffer = '';

    $this->socket = @stream_socket_client(
      ($this->ssl ? "ssl://" : "tcp://") . $this->host . ":" . $this->port,
      $errno,
      $errstr,
      30
    );

    if (!$this->socket)
    {
      $this->onConnectError($errno, $errstr);
      return false;
    }

    stream_set_blocking    ($this->socket, false);
    stream_set_read_buffer ($this->socket, 0);
    stream_set_write_buffer($this->socket, 0);

    return $this->onConnect();
  }

  public function shutdown()
  {
    $this->shutdown = true;
    stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
  }

  public function disconnect()
  {
    stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
    fclose($this->socket);
    $this->socket    = NULL;
    $this->inBuffer  = '';
    $this->outBuffer = '';
    $this->onDisconnect();
  }

  public function getReadSocket()
  {
    return $this->socket;
  }

  public function getWriteSocket()
  {
    if ($this->shutdown || empty($this->outBuffer))
      return NULL;
    return $this->socket;
  }

  protected function send($data): bool
  {
    $this->outBuffer .= $data;
    return true;
  }

  protected function onConnect(): bool { return true; }
  protected function onShutdown(): void { }
  protected function onDisconnect(): void { }
  protected function onConnectError($errno, $errstr): void { }

  abstract protected function onData($data): int;

  public function socketRead($fd): bool
  {
    if (($buffer = fread($fd, 1024)) === false)
    {
      Log::Error("Failed to read from the socket: %s", socket_strerror(socket_last_error()));
      return false;
    }

    if (feof($fd))
    {
      Log::Warn("Socket disconnected");
      $this->disconnect();
      return false;
    }

    Log::Proto(Log::SOCKET, "Recv: %s", $buffer);
    $this->inBuffer .= $buffer;
    while(!empty($this->inBuffer) && ($used = $this->onData($this->inBuffer)) > 0)
      $this->inBuffer = substr($this->inBuffer, $used);

    return true;
  }

  public function socketWrite($fd): bool
  {
    if (($sent = fwrite($fd, $this->outBuffer)) === false)
    {
      Log::Error("Socket write error: %s", socket_strerror(socket_last_error()));
      $this->disconnect();
      return false;
    }

    if ($sent == 0)
    {
      Log::Warn("Socket disconnected by remote peer.");
      $this->shutdown();
      return false;
    }

    Log::Proto(Log::SOCKET, "Sent: %s", substr($this->outBuffer, 0, $sent));
    $this->outBuffer = substr($this->outBuffer, $sent);
    return true;
  }

  public function tick(): void
  {
  }
}
?>