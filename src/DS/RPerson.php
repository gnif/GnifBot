<?PHP
namespace GnifBot\DS;

class RPerson extends \GnifBot\Record
{
  const FLAG_ADMIN = 0x01;
  const FLAG_MOD   = 0x02;
  const FLAG_VIP   = 0x04;
  const FLAG_SUB   = 0x08;
  const FLAG_BOT   = 0x10;

  public function save(): bool
  {
    if (is_null($this->twitch_login) && is_null($this->discord_login))
      throw new \GnifBot\Exception("Record is missing both a twitch_login and a discord_login");

    /* ensure we have twitch information */
    if (!is_null($this->twitch_login) && (is_null($this->twitch_id) || is_null($this->twitch_name)))
    {
      list($info) = array_values(\GnifBot\Core::$twitch_api->getUserInfo([$this->twitch_login]));
      $this->twitch_id    = $info['id'          ];
      $this->twitch_name  = $info['display_name'];
      $this->is_bot       = $info['type'] != 'user';
    }

    /* ensure a display name is set */
    if (empty($this->display_name))
    {
      if (!empty($this->twitch_name))
        $this->display_name = $this->twitch_name;
      else
        $this->display_name = $this->discord_name;
    }

    if ($this->isNew())
      $this->first_seen = time();

    /* ensure the display name can be used and if not, make it unique */
    $name   = $this->display_name;
    $suffix = 0;
    while(true)
    {
      try
      {
        $result = parent::save();
      }
      catch(\PDOException $e)
      {
        /* only retry if the error was due to a duplicate name */
        if ($e->getCode() != 1062)
          throw $e;

        $this->display_name = $name . '#' . (++$suffix);
        continue;
      }
      break;
    }

    return $result;
  }

  public function __destruct()
  {
    if (!$this->IsInvalid() && !$this->IsReadOnly())
      $this->save();
  }

  public function IsAdmin() : bool
  {
    return ($this->flags & self::FLAG_ADMIN) != 0;
  }

  public function IsMod() : bool
  {
    return ($this->flags & self::FLAG_MOD) != 0;
  }

  public function IsVIP() : bool
  {
    return ($this->flags & self::FLAG_VIP) != 0;
  }

  public function IsSub() : bool
  {
    return ($this->flags & self::FLAG_SUB) != 0;
  }

  public function IsBot() : bool
  {
    return ($this->flags & self::FLAG_BOT) != 0;
  }

  public function SetAdmin(bool $value)
  {
    if ($value)
      $this->flags |= self::FLAG_ADMIN;
    else
      $this->flags &= ~self::FLAG_ADMIN;
  }

  public function SetMod(bool $value)
  {
    if ($value)
      $this->flags |= self::FLAG_MOD;
    else
      $this->flags &= ~self::FLAG_MOD;
  }

  public function SetVIP(bool $value)
  {
    if ($value)
      $this->flags |= self::FLAG_VIP;
    else
      $this->flags &= ~self::FLAG_VIP;
  }

  public function SetSub(bool $value)
  {
    if ($value)
      $this->flags |= self::FLAG_SUB;
    else
      $this->flags &= ~self::FLAG_SUB;
  }

  public function SetBot(bool $value)
  {
    if ($value)
      $this->flags |= self::FLAG_BOT;
    else
      $this->flags &= ~self::FLAG_BOT;
  }
}

?>