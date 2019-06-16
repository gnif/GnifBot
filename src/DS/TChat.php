<?PHP
namespace GnifBot\DS;
use \GnifBot\DataSource as DS;

class TChat extends DS
{
  protected static $table        = 'chat';
  protected static $autoCreate   = true;
  protected static $columns      =
  [
    'id'            => DS::COL_PRIMARY,
    'person_id'     => [DS::FLAG_TYPE_UINT],
    'ts'            => [DS::FLAG_TYPE_DEC, 13, 3],
    'updated_ts'    => [DS::FLAG_TYPE_DEC, 13, 3],
    'source'        => [DS::FLAG_TYPE_STR, 10],
    'source_id'     => [DS::FLAG_TYPE_BIN, 16],
    'webhook_id'    => [DS::FLAG_TYPE_UINT | DS::FLAG_MOD_NULLABLE | DS::FLAG_MOD_BIG],
    'is_deleted'    => [DS::FLAG_TYPE_BOOL],
    'message'       => [DS::FLAG_TYPE_TXT]
  ];

  protected static $indexes =
  [
    'ts_is_deleted'    => [DS::FLAG_MOD_INDEX , 'ts'    , 'is_deleted'],
    'source_source_id' => [DS::FLAG_MOD_UNIQUE, 'source', 'source_id' ],
    'webhook_id'       => [DS::FLAG_MOD_INDEX , 'webhook_id']
  ];
};

?>