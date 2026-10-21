<?php
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
chdir('/var/www/html');
require_once "./config.php";
require_once "./lib/loader.php";
require_once "./load_settings.php";
$rows = SQLSelect("SELECT * FROM opencode_messages ORDER BY ID DESC LIMIT 14");
foreach ($rows as $r) {
    $msg = str_replace(array("\r", "\n"), array('', ' | '), $r['MESSAGE']);
    echo sprintf("#%s %s [%s] %s\n", $r['ID'], $r['ROLE'], $r['ADDED'], mb_substr($msg, 0, 500));
    echo "----\n";
}
