<?PHP
namespace GnifBot\DS;
use \GnifBot\DataSource as DS;

class TPeople extends DS
{
  protected static $table        = 'people';
  protected static $autoCreate   = true;
  protected static $recordClass  = RPerson::class;
  protected static $columns      =
  [
    'id'            => DS::COL_PRIMARY,
    'display_name'  => [DS::FLAG_TYPE_STR  | DS::FLAG_MOD_UNIQUE, 255],
    'flags'         => [DS::FLAG_TYPE_UINT],
    'is_online'     => [DS::FLAG_TYPE_BOOL],
    'first_seen'    => [DS::FLAG_TYPE_UINT],
    'last_seen'     => [DS::FLAG_TYPE_UINT],
    'message_count' => [DS::FLAG_TYPE_UINT],
    'twitch_id'     => [DS::FLAG_TYPE_UINT | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE | DS::FLAG_MOD_BIG],
    'twitch_login'  => [DS::FLAG_TYPE_STR  | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE, 255],
    'twitch_name'   => [DS::FLAG_TYPE_STR  | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE, 255],
    'discord_id'    => [DS::FLAG_TYPE_UINT | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE | DS::FLAG_MOD_BIG],
    'discord_login' => [DS::FLAG_TYPE_STR  | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE, 255],
    'discord_name'  => [DS::FLAG_TYPE_STR  | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE, 255],
    'discord_dm'    => [DS::FLAG_TYPE_UINT | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE | DS::FLAG_MOD_BIG],
    'merge_token'   => [DS::FLAG_TYPE_BIN  | DS::FLAG_MOD_UNIQUE | DS::FLAG_MOD_NULLABLE, 16 ]
  ];
};

?>