<?PHP

namespace GnifBot;

class Core
{
  static private $config;

  static private $running  = true;
  static private $sockets  = [];
  static private $dbc;

  static private $discord_wh;
  static private $twitch_api;

  static private $publicCommands = [];
  static private $adminCommands  = [];

  static private $emojiMap =
  [
    ':)'  => "\xF0\x9F\x98\x83",
    ':D'  => "\xF0\x9F\x98\x84",
    ';)'  => "\xF0\x9F\x98\x89",
    '<3'  => "\xE2\x9D\xA4",
    ':('  => "\xF0\x9F\x98\xA2",
    '>('  => "\xF0\x9F\x98\xA1",
    'O_o' => "\xF0\x9F\x98\x95",
    'B)'  => "\xF0\x9F\x98\x8E",
    ':O'  => "\xF0\x9F\x98\xAE",
    ':/'  => "\xF0\x9F\x98\x92",
    ':P'  => "\xF0\x9F\x98\x9B",
    ';P'  => "\xF0\x9F\x98\x9C",
    ':|'  => "\xF0\x9F\x98\x90"
  ];

  static public function initialize($config)
  {
    self::$config = $config;
    spl_autoload_register([self::class, 'autoloader']);
    Log::setDebug($config->debug);

    self::$twitch_api = new TwitchAPI($config->twitch->clientid);
    self::initPeople();

    $regex  = [];
    $values = [];
    foreach(self::$emojiMap as $key => $value)
    {
      $regex [] = '/(?<=\s|^)' . str_replace('/', '\/', preg_quote($key)) . '(?=\s|$|[.,])/';
      $values[] = $value;
    }
    self::$emojiMap = [$regex, $values];
  }

  static public function utf8($num)
  {
    if($num <= 0x7F    ) return chr($num);
    if($num <= 0x7FF   ) return chr(($num>>6)+192).chr(($num&63)+128);
    if($num <= 0xFFFF  ) return chr(($num>>12)+224).chr((($num>>6)&63)+128).chr(($num&63)+128);
    if($num <= 0x1FFFFF) return chr(($num>>18)+240).chr((($num>>12)&63)+128).chr((($num>>6)&63)+128).chr(($num&63)+128);
    return '';
  }

  static private function initPeople()
  {
    /* set the initial user state */
    $chatters = self::$twitch_api->getChatters();
    $online   = [];
    foreach($chatters->chatters as $group)
      foreach($group as $user)
      {
        if ($user == self::$config->twitch->username)
          continue;

        $person   = self::getPerson('twitch', null, $user);
        $online[] = $person->id;
        $person->is_online = true;
        $person->save();
      }

    $ds = new DS\TPeople();
    $ds
      ->addFilter('id', '!=', $online)
      ->update('is_online'  , false  );
  }

  static public function getEmotes()
  {
    $emotes = [];

    // get the noto emojis only
    $noto = [
      'basePath'  => 'emoticons/noto',
      'emoticons' => []
    ];
    $dh = opendir(__DIR__ . '/../public_html/emoticons/noto');
    while(($file = readdir($dh)) !== false)
    {
      if ($file[0] == '.')
        continue;

      $base  = basename($file, '.png');
      $parts = explode('_', basename($base, '.png'));
      if (count($parts) < 2 || $parts[0] != 'emoji' || $parts[1][0] != 'u')
        continue;

      array_shift($parts);
      $parts[0] = substr($parts[0], 1);
      foreach($parts as &$part)
        $part = hexdec($part);

      if ($parts[0] < 0xa9)
        continue;

      $chars = '';
      foreach($parts as $part)
        $chars .= self::utf8($part);

      $noto['emoticons'][] = [$chars, $base];
    }
    closedir($dh);
    $emotes[] = $noto;

    $twitch = self::$twitch_api->getEmoteSet(0);
    foreach($twitch as &$exp)
      $exp[0] = "(?<=\s|^)${exp[0]}(?=\s|$|[.,])";

    // get the twitch global emojis
    $emotes[] =
    [
      'basePath'  => 'emoticons/twitch',
      'emoticons' => $twitch
    ];

    return $emotes;
  }

  static public function autoloader($class)
  {
    if (strpos($class, "GnifBot\\") === false)
      return;

    $path = explode('\\', $class, 2);
    if (count($path) != 2)
      return;

    $path = __DIR__ . '/' . str_replace('\\', '/', $path[1]) . '.php';
    if (!is_readable($path) || !is_file($path))
      return;

    include($path);
  }

  static public function signalHandler($signo)
  {
    if ($signo == \SIGINT)
    {
      Log::Info("Signal caught, shutting down.");
      self::$running = false;
    }
  }

  static public function start()
  {
    pcntl_async_signals(true);
    pcntl_signal(\SIGINT, [self::class, 'signalHandler']);

    self::$sockets['twitch'] = new Twitch(
      self::$config->twitch->server,
      self::$config->twitch->username,
      self::$config->twitch->password,
      self::$config->twitch->channel
    );

    self::$sockets['discord'] = new Discord(
      self::$config->discord->token,
      self::$config->discord->guild,
      self::$config->discord->channel,
      self::$config->discord->webhook->id,
      self::$config->discord->ignore
    );

    self::$discord_wh = new DiscordWH(
      sprintf(
        self::$config->discord->webhook->url,
        self::$config->discord->webhook->id,
        self::$config->discord->webhook->token
      )
    );
  }

  static public function isRunning()
  {
    return self::$running;
  }

  static public function run()
  {
    self::$running = true;

    foreach(self::$sockets as $socket)
    {
      if (!$socket->connect())
        Log::Fatal("Failed to connect a socket");
    }

    while(self::$running)
    {
      $readers  = [];
      $writers  = [];
      $readfds  = [];
      $writefds = [];
      foreach(self::$sockets as $socket)
      {
        $socket->tick();
        $sock = $socket->getReadSocket();
        if (!is_null($sock))
        {
          $readers[] = [$socket, $sock];
          $readfds[] = $sock;
        }

        $sock = $socket->getWriteSocket();
        if (!is_null($sock))
        {
          $writers[] = [$socket, $sock];
          $writefds[] = $sock;
        }
      }

      $exceptfds = [];
      if (@stream_select($readfds, $writefds, $exceptfds, 0, 100000) === false)
      {
        if (self::$running)
          Log::Fatal("Socket select failed");
        else
          break;
      }

      foreach($readfds as $fd)
      {
        foreach($readers as $reader)
          if ($reader[1] == $fd)
          {
            $reader[0]->socketRead($fd);
            break;
          }
      }

      foreach($writefds as $fd)
      {
        foreach($writers as $writer)
          if ($writer[1] == $fd)
          {
            $writer[0]->socketWrite($fd);
            break;
          }
      }
    }
  }

  static public function getDB() : \PDO
  {
    if (isset(self::$dbc))
    {
      try
      {
        if(@self::$dbc->query('SELECT 1') !== false)
          return self::$dbc;
      }
      catch (\PDOException $e)
      {
      }
    }

    $conn  = 'mysql:host=' . self::$config->db->host . ';dbname=' . self::$config->db->name;
    $conn .= ';charset=utf8mb4;';

    try
    {
      self::$dbc = new \PDO
      (
        $conn,
        self::$config->db->user,
        self::$config->db->pass
      );
      self::$dbc->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }
    catch (\Exception $e)
    {
      throw new \Exception('Failed to connect to the database');
    }

    return self::$dbc;
  }


  static public function stop()
  {
    self::$running = false;
  }

  static public function getPerson(
    string $source     , // the source (discord or twitter)
    ?int   $source_id  , // the author id (null if unknown)
    string $source_name  // the author name
  ): Record
  {
    if ($source != 'discord' && $source != 'twitch')
      throw new Exception('Invalid message source, must be either discord or twitch');

    $field_id   = $source . '_id';
    $field_name = $source . '_name';

    // lookup the person by ID if possible, otherwise the author name
    $ds = new DS\TPeople();
    if (!is_null($source_id))
         $ds->addFilter($field_id  , '=', $source_id  );
    else $ds->addFilter($field_name, '=', $source_name);

    $person = $ds->fetch();
    if (!$person)
    {
      // not found, create a new record with a unique display name
      if ($source == 'discord')
      {
        // split off the discriminator
        $parts = explode('#', $source_name, 2);
        $names = [$parts[0], $source_name, 'd:' . $source_name];
      }
      else
      {
        $names = [$source_name, 't:' . $source_name];
      }

      $ok = false;
      foreach($names as $name)
        if ($ds->reset()->addFilter('display_name', '=', $name)->count() == 0)
        {
          $ok = true;
          break;
        }

      if (!$ok)
      {
        // this really should never happen as discord and twitch both ensure unique names
        throw new Exception('Failed to get a unique display name for the user');
      }

      $person = DS\TPeople::createRecord();
      $person->display_name  = $name;
      $person->message_count = 0;
      $person->is_admin      = false;
      $person->$field_name   = $source_name;

      if (!is_null($source_id))
        $person->$field_id = $source_id;

      $person->save();
    }
    else
    {
      if (is_null($person->$field_id) && !is_null($source_id))
        $person->$field_id = $source_id;

      if ($person->$field_name != $source_name)
        $person->$field_name = $source_name;

      $person->save();
    }

    return $person;
  }

  static public function handleJoin(string $source, Record $person)
  {
    if ($source != 'discord' && $source != 'twitch')
      throw new Exception('Invalid message source, must be either discord or twitch');

    if ($person->is_online)
      return;

    Log::Info("Join: %s", $person->display_name);
    $newUser = false;

    if (is_null($person->first_seen))
    {
      $person->first_seen = microtime(true);
      $newUser = true;
    }

    $person->last_seen = microtime(true);

    // if the person was already online there is nothing more to do
    if ($person->is_online)
    {
      $person->save();
      return;
    }

    $person->is_online = true;
    $person->save();

    if ($person->is_bot)
      return;

    foreach(self::$sockets as $dest => $socket)
    {
      $field = $dest . '_name';
      if (is_null($person->$field))
        $name = $person->display_name;
      else
        $name = $person->$field;

      if ($newUser)
        $msg = "Welcome @" . $name;
      else
        $msg = "Welcome back @" . $name;

      $socket->sendMessage($msg);
    }
  }

  static public function handlePart(string $source, Record $person)
  {
    if ($source != 'discord' && $source != 'twitch')
      throw new Exception('Invalid message source, must be either discord or twitch');

    Log::Info("Part: %s", $person->display_name);
    $source = self::$sockets[$source];

    $person->is_online = false;
    $person->save();

    if ($person->is_bot)
      return;

    foreach(self::$sockets as $dest => $socket)
    {
      $field = $dest . '_name';
      if (is_null($person->$field))
        $name = $person->display_name;
      else
        $name = $person->$field;

      $msg = "@$name left the channel";
      $socket->sendMessage($msg);
    }
  }

  static public function handleMessage(
    bool   $dm         , // direct message
    float  $ts         , // the timestamp
    string $source     , // the source (discord or twitter)
    Record $person     , // the person
    string $id         , // the message id
    string $msg          // the message
  ): void
  {
    if ($source != 'discord' && $source != 'twitch')
      throw new Exception('Invalid message source, must be either discord or twitch');

    if (is_null($person->first_seen))
      $person->first_seen = $ts;

    if (!$person->is_online)
      self::handleJoin($source, $person);

    $msg = preg_replace(self::$emojiMap[0], self::$emojiMap[1], $msg);

    // admin commands
    if (substr($msg, 0, 2) == '!!')
    {
      if ($person->is_admin)
      {
        $person->save();
        self::handleAdminCommand($source, $person, $msg);
        return;
      }
    }
    elseif ($msg[0] == '!')
    {
      $person->save();
      self::handleCommand($source, $person, $msg);
      return;
    }

    if ($dm)
      return;

    $person->message_count++;
    $person->save();

    $message = DS\TChat::createRecord();
    $message->person_id  = $person->id;
    $message->ts         = $ts;
    $message->updated_ts = $ts;
    $message->source     = $source;
    $message->source_id  = $id;
    $message->is_deleted = false;
    $message->message    = $msg;

    //relay the message to discord
    if ($source != 'discord')
      $message->webhook_id = self::$discord_wh->sendMessage($person->display_name, $msg);

    $message->save();
  }

  static public function updateMessage(
    $ts,
    $source,
    $source_id,
    $msg
  ) : void
  {
    $ds = new DS\TChat();
    $message = $ds
      ->addFilter('source'   , '=', $source)
      ->addFilter('source_id', '=', $source_id)
      ->fetch();

    if (!$message)
    {
      Log::Warn("Failed to find the message to update it");
      return;
    }

    $message->message    = $msg;
    $message->updated_ts = $ts;
    $message->save();
    Log::Info("Updated message %d", $message->id);
  }

  static public function lookupPerson($name) : ?Record
  {
    $name = explode(':', $name, 2);
        if (count($name) == 1) $field = 'display_name';
    elseif ($name[0] == 't'  ) $field = 'twitch_name';
    elseif ($name[0] == 'd'  ) $field = 'discord_name';
    else
      return NULL;

    $name = array_pop($name);
    $ds   = new DS\TPeople();

    return $ds->addFilter($field, '=', $name)->fetch();
  }

  static private function parseArgs($str)
  {
    $len    = strlen($str);
    $args   = [];
    $arg    = '';
    $quoted = false;

    for($i = 0; $i < $len; ++$i)
    {
      $c = $str[$i];
      if (!$quoted && empty($arg))
      {
        if ($c == ' ' || $c == '\t')
          continue;

        if ($c == '"')
        {
          $quoted = true;
          continue;
        }
      }
      else
      {
        if (
          (!$quoted && ($c == ' ' || $c == '\t')) ||
          ($quoted && $c == '"')
        )
        {
          if ($quoted && $i < $len - 1 && $str[$i + 1] == '"')
          {
            $arg .= $c;
            ++$i;
            continue;
          }

          $quoted = false;
          $args[] = $arg;
          $arg = '';
          continue;
        }
      }

      $arg .= $c;
    }

    if (!empty($arg))
      $args[] = $arg;

    return $args;
  }

  static public function addPublicCommand(string $command, bool $secure, string $help, \Closure $handler)
  {
    self::$publicCommands[$command] = [$secure, $help, $handler];
  }

  static public function addAdminCommand(string $command, bool $secure, string $help, \Closure $handler)
  {
    self::$adminCommands[$command] = [$secure, $help, $handler];
  }

  static private function handleAdminCommand(string $source, Record $person, string $msg): void
  {
    Log::Info("Admin Command: %s", $msg);
    $msg    = explode(' ', $msg, 2);
    $cmd    = substr($msg[0], 2);
    $args   = self::parseArgs($msg[1] ?? '');
    $secure = $source == 'discord';
    $source = self::$sockets[$source];

    if (!array_key_exists($cmd, self::$adminCommands))
    {
      $source->sendPrivMessage($person, "Invalid admin command");
      return;
    }

    $handler = self::$adminCommands[$cmd];
    if ($handler[0] && !$secure)
    {
      $source->sendPrivMessage($person, "This command requires a secure channel, use Discord");
      return;
    }

    $handler[2]($cmd, $source, $person, $args);
  }

  static private function handleCommand(string $source, Record $person, string $msg): void
  {
    Log::Info("Command: %s", $msg);
    $msg    = explode(' ', $msg, 2);
    $cmd    = substr($msg[0], 1);
    $args   = self::parseArgs($msg[1] ?? '');
    $secure = $source == 'discord';
    $source = self::$sockets[$source];

    if (!array_key_exists($cmd, self::$publicCommands))
    {
      $source->sendPrivMessage($person, "Invalid command");
      return;
    }

    $handler = self::$publicCommands[$cmd];
    if ($handler[0] && !$secure)
    {
      $source->sendPrivMessage($person, "This command requires a secure channel, use Discord");
      return;
    }

    $handler[2]($cmd, $source, $person, $args);
  }

  static public function mergePeople(Record $twitch, Record $discord)
  {
    // clear out the token
    $twitch->merge_token = NULL;

    // nothing to do if the records are already merged
    if ($twitch->id == $discord->id)
    {
      $twitch->save();
      return;
    }

    // copy the discord record into the twitch record
    $twitch->discord_id     = $discord->discord_id;
    $twitch->discord_name   = $discord->discord_name;
    $twitch->first_seen     = min($twitch->first_seen, $discord->first_seen);
    $twitch->last_seen      = max($twitch->last_seen , $discord->last_seen );
    $twitch->message_count += $discord->message_count;
    $twitch->is_admin       = $twitch->is_admin || $discord->is_admin;
    $twitch->is_mod         = $twitch->is_mod   || $discord->is_mod;
    $twitch->is_vip         = $twitch->is_vip   || $discord->is_vip;

    // reassign all of the discord records to the twitch person
    $ds = new DS\TChat();
    $ds->addFilter('person_id', '=', $discord->id)->update('person_id', $twitch->id);

    // delete the now unused discord user
    $discord->delete();

    // save the twitch record last to prevent unique clashes
    $twitch->save();

    // add the discord user to the twitch role
    self::$sockets['discord']->addPersonToRole($twitch, self::$config->discord->roles->twitch);
  }

  static public function delMessage($source, string $id): void
  {
    $ds = new DS\TChat();
    $message = $ds
      ->addFilter('source'   , '=', $source)
      ->addFilter('source_id', '=', $id)
      ->fetch();

    if (!$message && substr($id, 8) == "\0\0\0\0\0\0\0\0")
    {
      $webhook_id = substr($id, 0, 8);
      list(, $webhook_id) = unpack('P', $webhook_id);

      // look for a webhook message
      $message = $ds->reset()
        ->addFilter('source'    , '!=', 'discord')
        ->addFilter('webhook_id', '=' , $webhook_id)
        ->fetch();
    }

    list(, $idStr) = unpack("H*", $id);
    if (!$message)
    {
      Log::Warn("Unable to delete %s:%s", $source, $idStr);
      return;
    }

    $ds     = new DS\TPeople();
    $person = $ds
      ->addFilter('id', '=', $message->person_id)
      ->fetch();

    --$person->message_count;
    $person->save();

    Log::Info("Delete message %s:%s", $source, $idStr);
    self::$sockets[$message->source]->delMessage($message->source_id);
    $message->is_deleted = true;
    $message->save();
  }
}

?>