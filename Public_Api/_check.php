<?php
header('Content-Type: text/plain; charset=utf-8');

echo "PHP_BINARY=" . PHP_BINARY . PHP_EOL;
echo "INI=" . (php_ini_loaded_file() ?: "(none)") . PHP_EOL;
echo "pdo_mysql=" . (extension_loaded("pdo_mysql") ? "yes" : "no") . PHP_EOL;
echo "MYSQL_ATTR=" . (defined("PDO::MYSQL_ATTR_INIT_COMMAND") ? "yes" : "no") . PHP_EOL;
