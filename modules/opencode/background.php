<?php
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
chdir('/var/www/html');
require_once "./config.php";
require_once "./lib/loader.php";
require_once "./load_settings.php";
$placeholder_id = (int)$argv[1];
// $argv[2] — id пользователя: не нужен, USER_ID уже записан в саму запись сообщения
$message = $argv[3];
require_once "./modules/opencode/opencode.class.php";
$m = new opencode();
$m->action = 'admin';
$bg_timeout = isset($m->config['OC_BG_TIMEOUT']) ? (int)$m->config['OC_BG_TIMEOUT'] : 30;
set_time_limit($bg_timeout + 30);
$response = $m->processWithOpencode($message);
$rec = SQLSelectOne("SELECT * FROM opencode_messages WHERE ID='$placeholder_id'");
if (!$rec['ID']) exit;
if ($response) {
    $m->processDeviceCommands($response);
    $rec['MESSAGE'] = $response;
} else {
    $rec['MESSAGE'] = $m->getLastError() ?: LANG_OPENCODE_NO_RESPONSE;
}
$rec['ROLE'] = 'assistant';
// Запись создавалась как плейсхолдер '…' в момент вопроса, поэтому время
// обновляем на момент готовности ответа — иначе у пары «вопрос → ответ»
// в истории стоял бы час вопроса.
$rec['ADDED'] = date('Y-m-d H:i:s');
SQLUpdate('opencode_messages', $rec);
