<?php
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
chdir('/var/www/html');
require_once "./config.php";
require_once "./lib/loader.php";
require_once "./load_settings.php";
$r = SQLSelectOne("SELECT COUNT(*) AS C, MAX(ID) AS M, MIN(ID) AS N FROM opencode_messages");
echo "count=" . $r['C'] . " max=" . $r['M'] . " min=" . $r['N'] . "\n";
$rows = SQLSelect("SELECT ID,ROLE,ADDED,MESSAGE FROM opencode_messages ORDER BY ID DESC LIMIT 8");
foreach ($rows as $x) {
    $msg = str_replace(array("\r", "\n"), array('', ' | '), $x['MESSAGE']);
    echo $x['ID'] . " " . $x['ROLE'] . " " . $x['ADDED'] . " " . mb_substr($msg, 0, 160) . "\n";
}
echo "OC_TIMEOUT=" . (isset($x) ? '' : '') . "\n";
