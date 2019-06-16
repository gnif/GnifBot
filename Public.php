<?PHP
namespace GnifBot;

Core::addPublicCommand("claim", true,
  "Issue a request to claim a twitch account",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) > 0)
    {
      $source->sendPrivMessage($from, "This command doesn't accept any arguments");
      return;
    }

    if (!is_null($from->twitch_id))
    {
      $source->sendPrivMessage($from, "Your account is already linked to a Twitch account");
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

    $source->sendPrivMessage($from, "Please paste the following text into Twitch chat to claim the account:\n`!confirm $str`");
  }
);

Core::addPublicCommand("confirm", false,
  "Claim a twitch account using the claim token",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (!($source instanceof Twitch))
    {
      $source->sendPrivMessage($from, "You must issue this command from Twitch");
      return;
    }

    if (!is_null($from->discord_id))
    {
      $source->sendPrivMessage($from, "Your account is already linked to a Discord account");
      return;
    }

    if (count($args) != 1)
    {
      $source->sendPrivMessage($from, "You must specify the claim token, ie:\n`!confirm xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`");
      return;
    }

    if (!preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $args[0]))
    {
      $source->sendPrivMessage($from, "Invalid token format");
      return;
    }

    $ds      = new DS\TPeople();
    $discord = $ds->addFilter('merge_token', '=', pack('H*', str_replace('-', '', $args[0])))->fetch();
    if (!$discord)
    {
      $source->sendPrivMessage($from, "Invalid token");
      return;
    }

    Core::mergePeople($from, $discord);
    $source->sendPrivMessage($from, "Done, your account has been linked.");
  }
);

Core::addPublicCommand("stats", false,
  "Show your statistics",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 0)
    {
      $source->sendPrivMessage($from, "This command doesn't accept any arguments");
      return;
    }

    $ds = new DS\TPeople();
    $ds
      ->setColumns()
      ->addCalcColumn('COUNT(*)', 'rank')
      ->addFilter('message_count', '>=', $from->message_count);

    $rank = $ds->fetch()->rank;
    if     ($rank > 3) $rank .= "th most ";
    elseif ($rank > 2) $rank .= "rd most ";
    elseif ($rank > 1) $rank .= "nd most ";
    else               $rank  = "";

    $msg  = "You were first seen at " . gmdate('Y-m-d H:i:s', $from->first_seen) . " GMT and since then sent a total of " . number_format($from->message_count) . " chat messages making you the ${rank}chattiest person here.";
    $source->sendPrivMessage($from, $msg);
  }
);

?>