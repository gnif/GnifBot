<?PHP
namespace GnifBot;

class DiscordAPI
{
  private $token;

  private $ch;

  public function __construct(string $token)
  {
    $this->token = $token;
    $this->ch    = curl_init();

    curl_setopt_array($this->ch, [
      CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "Authorization: Bot " . $this->token
      ],
    ]);
  }

  private function curlPost($endpoint, $data)
  {
    curl_setopt_array($this->ch, [
      CURLOPT_POST           => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_URL            => "https://discordapp.com/api/" . $endpoint,
      CURLOPT_POSTFIELDS     => json_encode($data)
    ]);

    return json_decode(curl_exec($this->ch));
  }

  private function curlPut($endpoint, $data = '')
  {
    curl_setopt_array($this->ch, [
      CURLOPT_POST           => false,
      CURLOPT_CUSTOMREQUEST  => "PUT",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_URL            => "https://discordapp.com/api/" . $endpoint,
      CURLOPT_POSTFIELDS     => $data
    ]);

    return curl_exec($this->ch);
  }

  public function createDM($recipient_id)
  {
    return $this->curlPost('users/@me/channels', [
      'recipient_id' => $recipient_id
    ]);
  }

  public function createMessage($channel_id, $msg)
  {
    return $this->curlPost("channels/$channel_id/messages", [
      'content' => $msg,
      'tts'     => false
    ]);
  }

  public function addGuildMemberRole(int $guild_id, int $member_id, int $role_id)
  {
    return $this->curlPut("guilds/$guild_id/members/$member_id/roles/$role_id");
  }
}
?>