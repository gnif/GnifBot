<?PHP
namespace GnifBot;

class TwitchAPI
{
  private $clientid;

  private $ch;

  public function __construct(string $clientid)
  {
    $this->clientid = $clientid;
    $this->ch       = curl_init();

    curl_setopt_array($this->ch, [
      CURLOPT_HTTPHEADER     => [
        "Accept: application/vnd.twitchtv.v5+json",
        "Client-ID: " . $this->clientid
      ],
    ]);
  }

  private function curlGet($endpoint)
  {
    curl_setopt_array($this->ch, [
      CURLOPT_POST           => false,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_URL            => "https://api.twitch.tv/kraken/" . $endpoint,
    ]);

    return json_decode(curl_exec($this->ch));
  }

  public function getEmoteSet($emotesets)
  {
    $data = $this->curlGet('chat/emoticon_images?emotesets=' . $emotesets);
    $out  = [];
    foreach($data->emoticon_sets as $set)
      foreach($set as $emote)
      {
        $code = $emote->code; //htmlspecialchars_decode(stripslashes($emote->code));
        $uri  = '/emoticons/twitch/' . $emote->id . '.png';
        $file = __DIR__ . '/../public_html' . $uri;
        if (!file_exists($file))
        {
          Log::Info("Caching emoticon: %d - %s", $emote->id, $code);
          $data = file_get_contents("https://static-cdn.jtvnw.net/emoticons/v1/" . $emote->id . "/1.0");
          if ($data !== false)
            file_put_contents($file, $data);
        }

        $out[] = [$emote->code, $emote->id];
      }
    return $out;
  }
}
?>