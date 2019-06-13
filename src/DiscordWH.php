<?PHP
namespace GnifBot;

class DiscordWH
{
  private $webhook;
  private $ch;
  private $headers;

  private $emoticons = [
    ':)'  => ':smile:',
    ':('  => ':frowning:',
    ':D'  => ':smiley:',
    '>('  => ':angry:',
    ':|'  => ':neutral_face:',
    'B)'  => ':sunglasses:',
    ':O'  => ':open_mouth:',
    '<3'  => ':heart:',
    ':/'  => ':confused:',
    ';)'  => ':wink:',
    ':P'  => ':stuck_out_tongue:',
    ';P'  => ':stuck_out_tongue_winking_eye:'
  ];

  public function __construct($webhook)
  {
    $this->webhook = $webhook;
    $this->ch      = curl_init();
    curl_setopt_array($this->ch, [
      CURLOPT_URL            => $this->webhook . "?wait=true",
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
      CURLOPT_RETURNTRANSFER => true
    ]);
  }

  public function sendMessage($nick, $msg)
  {
    $msg = explode(' ', $msg);
    foreach($msg as &$word)
    {
      foreach($this->emoticons as $from => $to)
      {
        if ($word == $from)
          $word = $to;
      }
    }
    $msg = implode(' ', $msg);

    $payload = json_encode([
      'username' => $nick,
      'content'  => $msg,
    ]);

    curl_setopt($this->ch, CURLOPT_POSTFIELDS, $payload);
    $data = json_decode(curl_exec($this->ch));
    return $data->id;
  }
}
?>