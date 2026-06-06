<?php
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
chdir('/var/www/html');
require_once "./config.php";
require_once "./lib/loader.php";
require_once "./load_settings.php";
$placeholder_id = (int)$argv[1];
$user_id = (int)$argv[2];
$message = $argv[3];
require_once "./modules/opencode/opencode.class.php";
$m = new opencode();
$m->action = 'admin';
$bg_timeout = isset($m->config['OC_BG_TIMEOUT']) ? (int)$m->config['OC_BG_TIMEOUT'] : 90;
set_time_limit($bg_timeout + 30);
$oc_debug = !empty($m->config['OC_DEBUG']);
if ($oc_debug) {
    $m->debug_message_id = $placeholder_id;
    $msg = '…🔄 Подключение к opencode...';
    SQLExec("UPDATE opencode_messages SET MESSAGE='" . DBSafe($msg) . "' WHERE ID='$placeholder_id'");
}
$response = $m->processWithOpencode($message, $bg_timeout);
if ($oc_debug) {
    if ($response) {
        $rec = SQLSelectOne("SELECT * FROM opencode_messages WHERE ID='$placeholder_id'");
        if (!$rec['ID']) exit;
        $m->processDeviceCommands($response);
        $rec['MESSAGE'] = $response;
        $rec['ROLE'] = 'assistant';
        SQLUpdate('opencode_messages', $rec);
    } else {
        $msg = '…⚠️ OpenCode не ответил за ' . $bg_timeout . ' сек. Проверьте debmes/ логи.';
        SQLExec("UPDATE opencode_messages SET MESSAGE='" . DBSafe($msg) . "' WHERE ID='$placeholder_id'");
    }
} else {
    $rec = SQLSelectOne("SELECT * FROM opencode_messages WHERE ID='$placeholder_id'");
    if (!$rec['ID']) exit;
    if ($response) {
        $m->processDeviceCommands($response);
        $rec['MESSAGE'] = $response;
    } else {
        $rec['MESSAGE'] = defined('LANG_OPENCODE_NO_RESPONSE') ? LANG_OPENCODE_NO_RESPONSE : 'Извините, не удалось получить ответ';
    }
    $rec['ROLE'] = 'assistant';
    SQLUpdate('opencode_messages', $rec);
}
