<?PHP
namespace GnifBot;

Core::addPublicCommand("claim", true,
  "Issue a request to claim a twitch account",
  function($cmd, $source, Record $from, array $args) : void
  {
    if (count($args) > 0)
    {
      $source->sendMessage($from, "This command doesn't accept any arguments");
      return;
    }

    if (!is_null($from->twitch_id))
    {
      $source->sendMessage($from, "Your account is already linked to a Twitch account");
      return;
    }

    $token             = openssl_random_pseudo_bytes(16);
    $from->merge_token = $token;
    $from->save();

    list(, $str) = unpack('H*', $token);
    $str =
      substr($str, 0 , 8) . '-' .
      substr($str, 8 , 4) . '-' .
      substr($str, 12, 4) . '-' .
      substr($str, 16, 4) . '-' .
      substr($str, 20);

    $source->sendMessage($from, "Please paste the following text into Twitch chat to claim the account:\n`!confirm $str`");
  }
);

Core::addPublicCommand("confirm", false,
  "Claim a twitch account using the claim token",
  function($cmd, $source, Record $from, array $args) : void
  {
    if (!($source instanceof Twitch))
    {
      $source->sendMessage($from, "You must issue this command from Twitch");
      return;
    }

    if (!is_null($from->discord_id))
    {
      $source->sendMessage($from, "Your account is already linked to a Discord account");
      return;
    }

    if (count($args) != 1)
    {
      $source->sendMessage($from, "You must specify the claim token, ie:\n`!confirm xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`");
      return;
    }

    if (!preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $args[0]))
    {
      $source->sendMessage($from, "Invalid token format");
      return;
    }

    $ds      = new DS\TPeople();
    $discord = $ds->addFilter('merge_token', '=', pack('H*', str_replace('-', '', $args[0])))->fetch();
    if (!$discord)
    {
      $source->sendMessage($from, "Invalid token");
      return;
    }

    Core::mergePeople($from, $discord);
    $source->sendMessage($from, "Done, your account has been linked.");
  }
);

?>