<?PHP
namespace GnifBot;

Core::addAdminCommand("op", false,
  "Make the specified user an operator",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 1)
    {
      $source->sendPrivMessage($from, "Invalid Usage, expected:\n`!!op username`");
      return;
    }

    $person = Core::lookupPerson($args[0]);
    if (!$person)
    {
      $source->sendPrivMessage($from, "unknown user");
      return;
    }

    $person->SetAdmin(true);
    $person->save();
    $source->sendPrivMessage($from, "'" . $person->display_name . "' has been given operator status");
  }
);

Core::addAdminCommand("deop", false,
  "Remove operator access from the specified user",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 1)
    {
      $source->sendPrivMessage($from, "Invalid Usage, expected:\n`!!deop username`");
      return;
    }

    $person = Core::lookupPerson($args[0]);
    if (!$person)
    {
      $source->sendPrivMessage($from, "unknown user");
      return;
    }

    $person->SetAdmin(false);
    $person->save();
    $source->sendPrivMessage($from, "'" . $person->display_name . "' has lost operator access");
  }
);

Core::addAdminCommand("mod", false,
  "Make the specified user a moderator",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 1)
    {
      $source->sendPrivMessage($from, "Invalid Usage, expected:\n`!!mod username`");
      return;
    }

    $person = Core::lookupPerson($args[0]);
    if (!$person)
    {
      $source->sendPrivMessage($from, "unknown user");
      return;
    }

    $person->SetMod(true);
    $person->save();
    $source->sendPrivMessage($from, "'" . $person->display_name . "' has been given moderator status");
  }
);

Core::addAdminCommand("demod", false,
  "Remove moderator access from the specified user",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 1)
    {
      $source->sendPrivMessage($from, "Invalid Usage, expected:\n`!!demod username`");
      return;
    }

    $person = Core::lookupPerson($args[0]);
    if (!$person)
    {
      $source->sendPrivMessage($from, "unknown user");
      return;
    }

    $person->SetMod(false);
    $person->save();
    $source->sendPrivMessage($from, "'" . $person->display_name . "' has lost moderator access");
  }
);

Core::addAdminCommand("rename", false,
  "Set the specified user's display name",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 2)
    {
      $source->sendPrivMessage($from, "Invalid usage, expected:\n`!!rename old_name new_name`");
      return;
    }

    $person = Core::lookupPerson($args[0]);
    if (!$person)
    {
      $source->sendPrivMessage($from, "Invalid user");
      return;
    }

    $old_name = $person->display_name;
    $person->display_name = $args[1];
    try
    {
      $person->save();
    }
    catch(\Exception $err)
    {
      $source->sendPrivMessage($from, "Failed, is the name unique?");
      return;
    }

    $source->sendPrivMessage($from, "'$old_name' is now known as '" . $args[1] . "'");
  }
);

Core::addAdminCommand("merge", false,
  "Merge a twitch and a discord account into a single user",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 2)
    {
      $source->sendPrivMessage($from, "Invalid usage, expected:\n`!!merge twitch_login discord_login`");
      return;
    }

    $twitch_login  = $args[0];
    $discord_login = $args[1];
    $ds = new DS\TPeople();

    $twitch = $ds->addFilter('twitch_login', '=', $twitch_login)->fetch();
    if (!$twitch)
    {
      $source->sendPrivMessage($from, "Unknown Twitch user");
      return;
    }

    if (!is_null($twitch->discord_id))
    {
      $source->sendPrivMessage($from, "The twitch account specified is is already merged");
      return;
    }

    $ds->reset();

    $discord = $ds->addFilter('discord_login', '=', $discord_login)->fetch();
    if (!$discord)
    {
      $source->sendPrivMessage($from, "Unknown Discord user");
      return;
    }

    if (!is_null($discord->twitch_id))
    {
      $source->sendPrivMessage($from, "The discord account specified is already merged");
      return;
    }

    Core::mergePeople($twitch, $discord);
    $source->sendPrivMessage($from, "Done");
  }
);

Core::addAdminCommand("newsession", false,
  "Tag the start of a new stream",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 0)
    {
      $source->sendPrivMessage($from, "Invalid usage, no arguments expected");
      return;
    }

    $session = DS\TSessions::createRecord();
    $session->ts = microtime(true);
    $session->save();
    $source->sendPrivMessage($from, "New session has been tagged");
  }
);

Core::addAdminCommand("bot", false,
  "Flag a twitch user as a bot",
  function($cmd, $source, DS\RPerson $from, array $args) : void
  {
    if (count($args) != 1)
    {
      $source->sendPrivMessage($from, "Invalid usage, expected:\n`!!bot twitch_login`");
      return;
    }

    $ds = new DS\TPeople();
    $twitch = $ds->addFilter('twitch_login', '=', $args[0])->fetch();
    if (!$twitch)
    {
      $source->sendPrivMessage($from, "Unknown Twitch user");
      return;
    }

    $twitch->SetBot(true);
    $twitch->save();

    $source->sendPrivMessage($from, "Twitch user has been flagged as a bot");
  }
);

?>