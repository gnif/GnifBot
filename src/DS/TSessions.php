<?PHP
namespace GnifBot\DS;
use \GnifBot\DataSource as DS;

class TSessions extends DS
{
  protected static $table        = 'sessions';
  protected static $autoCreate   = true;
  protected static $columns      =
  [
    'id' => DS::COL_PRIMARY,
    'ts' => [DS::FLAG_TYPE_DEC, 13, 3]
  ];

  protected static $indexes =
  [
    'ts' => [DS::FLAG_MOD_INDEX, 'ts']
  ];
};

?>