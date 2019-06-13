<?PHP
namespace GnifBot;

class Discord extends WebSocket
{
  const OP_DISPATCH           = 0;
  const OP_HEARTBEAT          = 1;
  const OP_IDENTIFY           = 2;
  const OP_STATUS_UPDATE      = 3;
  const OP_VOICE_STATE_UPDATE = 4;
  const OP_RESUME             = 6;
  const OP_RECONNECT          = 7;
  const OP_REQ_GUILD_MEMBERS  = 8;
  const OP_INVALID_SESSION    = 9;
  const OP_HELLO              = 10;
  const OP_HEARTBEAT_ACK      = 11;

  const CHANNEL_TYPE_DM = 1;

  private $token;
  private $api;
  private $guild;
  private $channel;
  private $webhook;
  private $ignore;

  private $started = false;
  private $resume  = false;
  private $hbSeq   = NULL;
  private $hbInterval;
  private $hbPending;

  private $session;
  private $dms = [];

  public function __construct(string $token, int $guild, int $channel, int $webhook, $ignore)
  {
    $this->token   = $token;
    $this->api     = new DiscordAPI($token);
    $this->guild   = $guild;
    $this->channel = $channel;
    $this->webhook = $webhook;
    $this->ignore  = $ignore;

    if (!property_exists($this->ignore, 'users'))
      $this->ignore->users = [];

    if (!property_exists($this->ignore, 'webhooks'))
      $this->ignore->webhooks = [];

    parent::__construct('gateway.discord.gg', 443, true, '/?v=6&encoding=json', 'https://example.com');
  }

  protected function onConnect(): bool
  {
    $this->started   = false;
    $this->hbPending = false;
    $this->dms       = [];
    return parent::onConnect();
  }

  protected function onDisconnect(): void
  {
    sleep(1);
    $this->started = false;
    $this->connect();
  }

  protected function onConnectError($errno, $errstr): void
  {
    sleep(1);
    Log::Error("Discord connection error (%d): %s", $errno, $errstr);
    $this->connect();
  }

  protected function sendOp(int $op, $data): bool
  {
    $data = json_encode([
      'op' => $op,
      'd'  => $data
    ]);
    Log::Proto(LOG::DISCORD, "Send: %s", $data);
    return parent::sendText($data);
  }

  protected function onTextData($data): void
  {
    Log::Proto(LOG::DISCORD, "Recv: %s", $data);
    $data = json_decode($data);
    if ($data === NULL)
    {
      Log::Error("Failed to decode payload: %s", $data);
      return;
    }

    switch($data->op)
    {
      case self::OP_DISPATCH:
        $this->hbSeq = $data->s;
        $this->handleEvent($data->t, $data->d);
        break;

      case self::OP_HELLO:
        $this->started    = true;
        $this->hbInterval = $data->d->heartbeat_interval;
        $this->hbDue      = microtime(true) + $this->hbInterval / 1000;

        if ($this->resume)
        {
          $data =
          [
            'token'      => $this->token,
            'session_id' => $this->session->session_id,
            'seq'        => $this->hbSeq
          ];
          $this->sendOp(self::OP_RESUME, $data);
        }
        else
        {
          $data =
          [
            "token"      => $this->token,
            "properties" =>
            [
              '$os'      => 'linux',
              '$browser' => 'GnifBot',
              '$device'  => 'GnifBot'
            ]
          ];
          $this->sendOp(self::OP_IDENTIFY, $data);
        }
        break;

      case self::OP_HEARTBEAT_ACK:
        $this->hbPending = false;
        break;

      default:
        Log::Warn("Unknown op code %d", $data->op);
        break;
    }
  }

  public function tick(): void
  {
    if (!$this->started)
      return;

    if (microtime(true) >= $this->hbDue)
    {
      if ($this->hbPending)
      {
        Log::Error("Missing heartbeat ack");
        $this->disconnect();
        return;
      }

      $this->hbDue     = microtime(true) + $this->hbInterval / 1000;
      $this->hbPending = true;
      $this->sendOp(self::OP_HEARTBEAT, $this->hbSeq);
    }
  }

  private function idToBin($id)
  {
    return pack('P', $id) . str_repeat("\x0", 8);
  }

  private function tsToTime($ts)
  {
    list($ts, $end) = explode('.', $ts, 2);
    list($ms, $tz ) = preg_split('/[+-]/', $end, 2);
    $ts = $ts . $end[6] . $tz;
    return (float)strtotime($ts) + (float)$ms / 1000000;
  }

  private function handleEvent($type, $data)
  {
    switch($type)
    {
      case 'READY':
        Log::Info("Discord connected");
        $this->session = $data;
        $this->resume  = true;
        break;

      case 'RESUMED':
        Log::Info("Discord reconnected");
        break;

      case 'GUILD_CREATE':
        foreach($data->members as $member)
        {
          if ($member->user->id == $this->session->user->id)
            continue;

          $person = Core::getPerson(
            'discord',
            $member->user->id,
            $member->user->username . '#' . $member->user->discriminator
          );

          if ($person->isNew())
          {
            $person->first_seen = $this->tsToTime($member->joined_at);
            $person->last_seen  = $person->first_seen;
            $person->save();
          }
        }
        break;

      case 'CHANNEL_CREATE':
        if ($data->type != self::CHANNEL_TYPE_DM)
          break;

        $user   = $data->recipients[0];
        $person = Core::getPerson(
          'discord',
          $user->id,
          $user->username . '#' . $user->discriminator
        );

        $person->discord_dm = $data->id;
        $person->save();
        break;

      case 'MESSAGE_CREATE':
        // if a webhook, ignore ourself and the ignore list
        if (property_exists($data, 'webhook_id') && !empty($data->webhook_id))
        {
          if ($data->webhook_id == $this->webhook)
            break;

          if (in_array($data->webhook_id, $this->ignore->webhooks))
            break;
        }

        // ignore ourself
        if ($data->author->id == $this->session->user->id)
          break;

        // ignore any users in the user ignore list
        if (in_array($data->author->id, $this->ignore->users))
          break;

        $person = Core::getPerson(
          'discord',
          $data->author->id,
          $data->author->username . '#' . $data->author->discriminator
        );

        // direct message
        $dm = ($person->discord_dm == $data->channel_id);

        // ignore messages we are not interested in
        if (!$dm && $data->channel_id != $this->channel)
          break;

        Core::handleMessage(
          $dm,
          $this->tsToTime($data->timestamp),
          'discord',
          $person,
          $this->idToBin($data->id),
          $data->content
        );
        break;

      case 'MESSAGE_UPDATE':
        if ($data->channel_id != $this->channel)
          break;

        Core::updateMessage(
          microtime(true),
          'discord',
          $this->idToBin($data->id),
          $data->content
        );
        break;

      case 'MESSAGE_DELETE':
        if ($data->channel_id != $this->channel)
          break;

        Core::delMessage('discord', $this->idToBin($data->id));
        break;


      default:
        Log::Proto(Log::DISCORD, "Uhandled event %s", $type);
        break;
    }
  }

  public function delMessage($id)
  {
    // nothing to do, discord is the trigger for the delete
  }

  public function sendMessage(Record $person, $msg)
  {
    if (!$person->discord_dm)
    {
      $channel = $this->api->createDM($person->discord_id);
      $person->discord_dm = $channel->id;
      $person->save();
      return;
    }

    $this->api->createMessage(
      $person->discord_dm,
      $msg
    );
  }

  public function addPersonToRole(Record $person, int $role): void
  {
    if (is_null($person->discord_id))
    {
      Log::Error("The person '%s' doesn't have a Discord account", $person->display_name);
      return;
    }

    Log::Proto(Log::DISCORD, "addGuildMemberRole(%s, %d) = %s",
      $person->display_name,
      $role,
      $this->api->addGuildMemberRole(
        $this->guild,
        $person->discord_id,
        $role
    ));
  }
}