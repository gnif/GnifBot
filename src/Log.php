<?PHP
namespace GnifBot;

class Log
{
  const SOCKET    = 0x1;
  const WEBSOCKET = 0x2;
  const DISCORD   = 0x4;
  const TWITCH    = 0x8;

  static private $debug = 0x0;

  static public function setDebug(\StdClass $config): void
  {
    if ($config->socket   ) self::$debug |= self::SOCKET;
    if ($config->websocket) self::$debug |= self::WEBSOCKET;
    if ($config->twitch   ) self::$debug |= self::TWITCH;
    if ($config->discord  ) self::$debug |= self::DISCORD;
  }

  static private function getCaller()
  {
    $bt = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    return basename($bt[1]['file']) . ':' . $bt[1]['line'];
  }

  static public function Proto(int $source)
  {
    if (!($source & self::$debug))
      return;

    $args = func_get_args();
    array_shift($args);

    $fmt = array_shift($args);
    array_unshift($args, self::getCaller());
    array_unshift($args, microtime(true));

    vprintf("[P] %.3f %-20s | " . $fmt . "\n", $args);
  }

  static public function Info()
  {
    $args = func_get_args();
    $fmt  = array_shift($args);
    array_unshift($args, self::getCaller());
    array_unshift($args, microtime(true));

    vprintf("[I] %.3f %-20s | " . $fmt . "\n", $args);
  }

  static public function Warn()
  {
    $args = func_get_args();
    $fmt  = array_shift($args);
    array_unshift($args, self::getCaller());
    array_unshift($args, microtime(true));

    vprintf("[W] %.3f %-20s | " . $fmt . "\n", $args);
  }

  static public function Error()
  {
    $args = func_get_args();
    $fmt  = array_shift($args);
    array_unshift($args, self::getCaller());
    array_unshift($args, microtime(true));

    vprintf("[E] %.3f %-20s | " . $fmt . "\n", $args);
  }

  static public function Fatal()
  {
    $args = func_get_args();
    $fmt  = array_shift($args);
    array_unshift($args, self::getCaller());
    array_unshift($args, microtime(true));

    vprintf("[F] %.3f %-20s | " . $fmt . "\n", $args);
    die();
  }
}