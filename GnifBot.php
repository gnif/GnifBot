#!/usr/bin/php7.2
<?PHP
namespace GnifBot;

$config = json_decode(file_get_contents(__DIR__ . '/config.json'));

require('src/Core.php');
Core::initialize($config);

require('Admin.php' );
require('Public.php');


Core::start();
Core::run();
?>