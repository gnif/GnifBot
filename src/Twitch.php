<?PHP
namespace GnifBot;

class Twitch extends Socket
{
  private $username;
  private $password;
  private $channel;

  private $api;
  private $pingPending;
  private $pingTime;

  public function __construct($server, $username, $password, $channel)
  {
    parent::__construct($server, 6667);

    $this->username = $username;
    $this->password = $password;
    $this->channel  = $channel;
  }

  protected function send($msg): bool
  {
    Log::Proto(Log::TWITCH, "Send: %s", $msg);
    return parent::send($msg . "\n");
  }

  public function onConnect(): bool
  {
    $this->pingPending = false;
    $this->pingTime    = 0;

    $this->send("PASS " . $this->password);
    $this->send("NICK " . $this->username);
    $this->send("CAP REQ :twitch.tv/tags twitch.tv/commands");
    $this->send("JOIN #" . $this->channel);
    return true;
  }

  protected function onDisconnect(): void
  {
    sleep(1);
    Log::Warn("Twitch disconnected");
    $this->connect();
  }

  protected function onConnectError($errno, $errstr): void
  {
    sleep(1);
    Log::Error("Twitch connection error (%d): %s", $errno, $errstr);
    $this->connect();
  }

  public function onData($data): int
  {
    if(($pos = strpos($data, "\n")) !== false)
    {
      $line = substr($data, 0, $pos);
      Log::Proto(Log::TWITCH, "Recv: %s", $line);

      // if tags have been sent
      $tags = [];
      if ($line[0] == '@')
      {
        list($t, $line) = explode(' ', $line, 2);
        $t = explode(';', $t);
        foreach($t as $tag)
        {
          list($id, $value) = explode('=', $tag, 2);
          $tags[$id] = $value;
        }
      }

      $line = explode(' ', $line, 3);
      if (count($line) == 2)
      {
        switch($line[0])
        {
          case 'PING':
            $this->send("PONG " . $line[1]);
            break;

          case 'PONG':
            $this->pingPending = false;
            Log::Proto(Log::TWITCH, "Latency: %dms", (microtime(true) - $this->pingTime) * 1000);
            break;
        }
      }

      $this->pingTime = microtime(true);

      if (count($line) != 3)
        return $pos + 1;

      $user = substr($line[0], 1, strpos($line[0], '!') - 1);

      switch($line[1])
      {
        case 'JOIN':
          Log::Info("Twitch connected");
          break;

        case 'PRIVMSG':
          list($channel, $body) = explode(' ', $line[2], 2);
          if ($body[0] == ':')
            $body = trim(substr($body, 1));

          $msg  = ['user' => $user, 'tags' => $tags, 'channel' => $channel, 'body' => $body];
          $this->handleMsg($msg);
          break;
      }

      return $pos + 1;
    }

    return 0;
  }

  private function unpackID(string $id): string
  {
    list(, $id) = unpack('H*', $id);
    return
      substr($id, 0 , 8) . '-' .
      substr($id, 8 , 4) . '-' .
      substr($id, 12, 4) . '-' .
      substr($id, 16, 4) . '-' .
      substr($id, 20);
  }

  private function handleMsg($msg)
  {
    $person = Core::getPerson(
      'twitch',
      $msg['tags']['user-id'],
      $msg['tags']['display-name'] ?? $msg['user']
    );

    Core::handleMessage(
      false,
      $msg['tags']['tmi-sent-ts'] / 1000,
      'twitch',
      $person,
      pack('H*', str_replace('-', '', $msg['tags']['id'])),
      $msg['body']
    );
  }

  public function delMessage($id)
  {
    $id = $this->unpackID($id);
    $this->send("PRIVMSG #" . $this->channel . " :/delete $id");
  }

  public function sendMessage(Record $person, $msg)
  {
    $lines = explode("\n", $msg);
    foreach($lines as $msg)
      $this->send('PRIVMSG #' . $this->channel . ' :@' . $person->twitch_name . ' ' . $msg);
  }

  public function tick(): void
  {
    if ($this->pingPending && time() > $this->pingTime + 30)
    {
      Log::Error("Ping timeout");
      $this->disconnect();
      return;
    }

    if ($this->pingTime > 0 && !$this->pingPending && time() > $this->pingTime + 30)
    {
      $this->send("PING");
      $this->pingPending = true;
      $this->pingTime    = microtime(true);
    }
  }
}
?>