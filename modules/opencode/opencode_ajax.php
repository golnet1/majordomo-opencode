<?php
define('ALLOW_RUNNING_WITH_ERRORS', 1);
chdir('/var/www/html');
require_once "./config.php";
require_once "./lib/loader.php";
require_once "./load_settings.php";

header('Content-Type: application/json; charset=utf-8');

$session = new session("prj");
require_once "./modules/opencode/opencode.class.php";
$m = new opencode();
$m->action = 'admin';

$op = gr('op');
if ($op == 'send_message') {
    $msg = gr('message');
    if (!$msg) {
        echo json_encode(array('success' => false, 'error' => 'Пустое сообщение'));
        exit;
    }
    $user_id = (int)(isset($session->data['MEMBER']) ? $session->data['MEMBER'] : 1);
    $m->saveMessageToHistory($msg, 'user', $user_id);
    $placeholder_id = $m->saveMessageToHistory('…', 'assistant', $user_id);
    session_write_close();
    $bg = DIR_MODULES . 'opencode/background.php';
    $safe_msg = escapeshellarg($msg);
    exec("php $bg $placeholder_id $user_id $safe_msg > /dev/null 2>&1 &");
    ob_clean();
    echo json_encode(array('success' => true, 'processing' => true, 'message_id' => $placeholder_id));
    flush();
    exit;
} elseif ($op == 'check_message') {
    $msg_id = (int)gr('message_id');
    if ($msg_id) {
        $rec = SQLSelectOne("SELECT * FROM opencode_messages WHERE ID='$msg_id'");
        if ($rec['ID']) {
            if ($rec['MESSAGE'] === '…') {
                echo json_encode(array('success' => true, 'processing' => true));
            } else {
                echo json_encode(array('success' => true, 'processing' => false, 'response' => $rec['MESSAGE']));
            }
        } else {
            echo json_encode(array('success' => false, 'error' => defined('LANG_OPENCODE_MESSAGE_NOT_FOUND') ? LANG_OPENCODE_MESSAGE_NOT_FOUND : 'Сообщение не найдено'));
        }
    } else {
        echo json_encode(array('success' => false, 'error' => defined('LANG_OPENCODE_NO_MESSAGE_ID') ? LANG_OPENCODE_NO_MESSAGE_ID : 'Не указан ID сообщения'));
    }
} elseif ($op == 'clear_history') {
    $user_id = (int)(isset($session->data['MEMBER']) ? $session->data['MEMBER'] : 1);
    SQLExec("DELETE FROM opencode_messages WHERE USER_ID='" . $user_id . "'");
    echo json_encode(array('success' => true));
} elseif ($op == 'load_history') {
    $user_id = (int)(isset($session->data['MEMBER']) ? $session->data['MEMBER'] : 1);
    $messages = SQLSelect("SELECT * FROM opencode_messages WHERE USER_ID='" . $user_id . "' ORDER BY ID ASC");
    echo json_encode(array('success' => true, 'messages' => $messages));
} elseif ($op == 'load_devices') {
    echo json_encode(array('success' => true, 'devices' => array()));
} elseif ($op == 'refresh_models') {
    $models = $m->getAvailableModels(true);
    echo json_encode(array('success' => true, 'models' => $models));
} elseif ($op == 'check_status') {
    $health = null;
    $deps = $m->checkDependencies($health);
    $model_name = $m->config['OC_PROVIDER_ENDPOINT'] ? ($m->config['OC_PROVIDER_MODEL'] ?: $m->config['OC_MODEL']) : ($m->config['OC_MODEL'] ?: 'opencode/big-pickle');
    $mcp_result = $m->restRequest('GET', '/mcp');
    $mcp_status = ($mcp_result && $mcp_result['code'] === 200 && is_array($mcp_result['data'])) ? $mcp_result['data'] : array();
    echo json_encode(array(
        'success' => true,
        'api_ok' => ($deps['api'] === 'ok'),
        'binary_ok' => ($deps['opencode_binary'] === 'ok'),
        'sudo_ok' => $m->canSudo(),
        'model' => $model_name,
        'mcp' => $mcp_status,
        'mcp_python_ok' => $m->checkPythonPackage('mcp')
    ));
} elseif ($op == 'install_mcp_package') {
    ob_clean();
    $error_msg = $m->installPythonDeps();
    $ok = $m->checkPythonPackage('mcp');
    echo json_encode(array('success' => $ok, 'error' => $error_msg ?: null));
    flush();
    exit;
} elseif ($op == 'load_provider_models') {
    $endpoint = gr('endpoint');
    $api_key = gr('api_key');
    if (!$endpoint) {
        echo json_encode(array('success' => false, 'error' => defined('LANG_OPENCODE_NO_ENDPOINT') ? LANG_OPENCODE_NO_ENDPOINT : 'Не указан endpoint'));
        exit;
    }
    $endpoint = rtrim($endpoint, '/');
    if (!preg_match('#^https?://#', $endpoint)) {
        $endpoint = 'https://' . $endpoint;
    }
    $models = array();
    $found = false;
    $paths = array('/api/tags', '/v1/models', '/models');
    foreach ($paths as $path) {
        $url = $endpoint . $path;
        $headers = array('Content-Type: application/json');
        if ($api_key) {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code !== 200) continue;
        $data = json_decode($body, true);
        if (!$data) continue;
        if (isset($data['models']) && is_array($data['models'])) {
            foreach ($data['models'] as $m) {
                if (isset($m['name'])) $models[] = $m['name'];
            }
        }
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $m) {
                if (isset($m['id'])) $models[] = $m['id'];
            }
        }
        if (!empty($models)) { $found = true; break; }
    }
    if (!$found) {
        $models = array('gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo');
    }
    sort($models);
    echo json_encode(array('success' => true, 'models' => $models));
} else {
    echo json_encode(array('success' => false, 'error' => defined('LANG_OPENCODE_UNKNOWN_OPERATION') ? LANG_OPENCODE_UNKNOWN_OPERATION : 'Неизвестная операция'));
}

$session->save();

