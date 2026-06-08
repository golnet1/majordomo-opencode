<?php

class opencode extends module {

    var $opencode_bin;
    var $opencode_db;
    var $opencode_config_dir;
    var $api_base = 'http://127.0.0.1:4096';
    var $api_user = 'opencode';
    var $api_pass = 'opencode';

    function __construct() {
        $this->name = 'opencode';
        $this->loadLanguage();
        $this->title = defined('LANG_OPENCODE_TITLE') ? LANG_OPENCODE_TITLE : 'OpenCode AI';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
        $this->getConfig();

        $this->opencode_bin = '/usr/local/bin/opencode';
        $this->opencode_db = '/var/www/.local/share/opencode/opencode.db';
        $this->opencode_config_dir = '/var/www/.config/opencode';
    }

    function getApiPort() {
        return $this->config['OC_PORT'] ? (int)$this->config['OC_PORT'] : 4096;
    }

    function restRequest($method, $path, $body = null, $timeout = 0) {
        $port = $this->getApiPort();
        $url = "http://127.0.0.1:{$port}{$path}";
        $user = !empty($this->config['OC_AUTH_LOGIN']) ? $this->config['OC_AUTH_LOGIN'] : 'opencode';
        $pass = !empty($this->config['OC_AUTH_PASSWORD']) ? $this->config['OC_AUTH_PASSWORD'] : 'opencode';
        $auth = base64_encode($user . ':' . $pass);
        $headers = array(
            "Authorization: Basic {$auth}",
            "Content-Type: application/json",
            "Accept: application/json"
        );
        if ($timeout <= 0) $timeout = $this->config['OC_TIMEOUT'] ?: 120;
        if ($timeout > 300) $timeout = 300;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            DebMes("Opencode REST error: {$error}", 'opencode');
            return null;
        }
        $decoded = json_decode($response, true);
        return array('code' => $http_code, 'data' => $decoded ? $decoded : $response);
    }

    function loadLanguage() {
        $lang = defined('SETTINGS_SITE_LANGUAGE') ? SETTINGS_SITE_LANGUAGE : '';
        $module_lang_dir = DIR_MODULES . $this->name . '/languages/';

        $sys_lang = ROOT . 'languages/' . $this->name . '_' . $lang . '.php';
        $mod_lang = $module_lang_dir . $this->name . '_' . $lang . '.php';
        $sys_def = ROOT . 'languages/' . $this->name . '_default.php';
        $mod_def = $module_lang_dir . $this->name . '_default.php';

        if ($lang && file_exists($sys_lang)) include_once($sys_lang);
        if ($lang && file_exists($mod_lang)) include_once($mod_lang);
        if (file_exists($sys_def)) include_once($sys_def);
        if (file_exists($mod_def)) include_once($mod_def);
    }

    function stripANSI($text) {
        return preg_replace('/\e\[[0-9;]*m/', '', $text);
    }

    function saveParams($data = 1) {
        $p = array();
        if (isset($this->id) && $this->id) { $p['id'] = $this->id; }
        if (isset($this->view_mode) && $this->view_mode) { $p['view_mode'] = $this->view_mode; }
        if (isset($this->edit_mode) && $this->edit_mode) { $p['edit_mode'] = $this->edit_mode; }
        if (isset($this->tab) && $this->tab) { $p['tab'] = $this->tab; }
        return parent::saveParams($p);
    }

    function getParams($data = 1) {
        $this->id = gr('id');
        $this->mode = gr('mode');
        $this->view_mode = gr('view_mode');
        $this->edit_mode = gr('edit_mode');
        $this->tab = gr('tab');
        $this->ajax = gr('ajax');
    }

    function run() {
        global $session;
        $out = array();

        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }

        if (isset($this->owner->action)) $out['PARENT_ACTION'] = $this->owner->action;
        if (isset($this->owner->name)) $out['PARENT_NAME'] = $this->owner->name;
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;

        if ($this->single_rec) $out['SINGLE_REC'] = 1;

        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    function processSubscription($event, &$details) {
        if ($event != 'COMMAND') return;

        $message = trim(isset($details['message']) ? $details['message'] : '');
        $member_id = (int)(isset($details['member_id']) ? $details['member_id'] : 0);

        if (!$message || $member_id <= 0) return;
        if (!empty($details['PROCESSED'])) return;

        DebMes("Opencode processing message: " . $message, 'opencode');

        $this->saveMessageToHistory($message, 'user', $member_id);

        $response = $this->processWithOpencode($message);

        DebMes("Opencode response: " . ($response ? substr($response, 0, 200) : 'EMPTY'), 'opencode');

        if ($response) {
            $this->saveMessageToHistory($response, 'assistant', $member_id);
            $this->processDeviceCommands($response);

            say($response, 0, 0, 'opencode');

            $details['BREAK'] = true;
            $details['PROCESSED'] = true;
        } else {
            DebMes("Opencode: no response generated", 'opencode');
        }
    }

    function buildMessageBody($message) {
        return array(
            'parts' => array(
                array('type' => 'text', 'text' => $message)
            )
        );
    }

    function buildSessionBody() {
        $body = new stdClass();
        $prompt = isset($this->config['OC_SYSTEM_PROMPT']) ? trim($this->config['OC_SYSTEM_PROMPT']) : '';
        if (empty($this->config['OC_FULL_ACCESS'])) {
            $restrictions = "\n\nВАЖНЫЕ ОГРАНИЧЕНИЯ БЕЗОПАСНОСТИ:\n"
                . "- Файловые операции разрешены ТОЛЬКО в: /var/www/html/cms/files/, /tmp/, /var/www/tmp/opencode/\n"
                . "- НЕ изменяй, НЕ удаляй и НЕ создавай файлы за пределами этих директорий\n"
                . "- НЕ выполняй команды, которые могут завершить работу системы: reboot, shutdown, poweroff, halt, init, systemctl poweroff, systemctl reboot, shutdown\n"
                . "- НЕ выполняй команды, завершающие работу php, apache, mysql, nginx, memcached\n"
                . "- НЕ используй команды kill, killall, pkill, skill\n"
                . "- НЕ изменяй конфигурационные файлы системы (/etc/, /var/www/html/config.php и т.д.)\n"
                . "- НЕ устанавливай и не удаляй пакеты (apt, dpkg, pip, npm, gem, cpan)\n"
                . "- Запрещено изменять, удалять или перезаписывать файлы ядра Majordomo и его модули\n";
            $prompt .= $restrictions;
        }
        if ($prompt) {
            $body->system = array(
                array('type' => 'text', 'text' => $prompt)
            );
        }
        return $body;
    }

    function processWithOpencode($message, $timeout = 0) {
        $this->getConfig();
        $session_id = isset($this->config['OC_SESSION_ID']) ? $this->config['OC_SESSION_ID'] : '';

        if ($session_id) {
            $body = $this->buildMessageBody($message);
            $result = $this->restRequest('POST', "/session/{$session_id}/message", $body, $timeout);
            if ($result && $result['code'] === 200) {
                DebMes("Opencode: reused session={$session_id}", 'opencode');
                $this->saveTokensFromResponse($result);
                return $this->parseMessageResponse($result);
            }
            DebMes("Opencode: session expired or invalid, creating new one", 'opencode');
            $session_id = '';
        }

        $result = $this->restRequest('POST', '/session', $this->buildSessionBody(), $timeout);
        if (!$result || $result['code'] !== 200) {
            DebMes("Opencode: failed to create session (code=" . ($result ? $result['code'] : 'null') . ")", 'opencode');
            return '';
        }
        $this->saveTokensFromResponse($result);
        $session_id = isset($result['data']['id']) ? $result['data']['id'] : '';
        if (!$session_id) {
            DebMes("Opencode: no session id in response", 'opencode');
            return '';
        }
        DebMes("Opencode: created session={$session_id}", 'opencode');

        $this->config['OC_SESSION_ID'] = $session_id;
        $this->saveConfig();

        $body = $this->buildMessageBody($message);
        $result = $this->restRequest('POST', "/session/{$session_id}/message", $body, $timeout);
        if (!$result || $result['code'] !== 200) {
            DebMes("Opencode: failed to send message to new session", 'opencode');
            return '';
        }
        $this->saveTokensFromResponse($result);

        return $this->parseMessageResponse($result);
    }

    function parseMessageResponse($result) {
        $parts = isset($result['data']['parts']) ? $result['data']['parts'] : array();
        $text_parts = array();
        foreach ($parts as $part) {
            if (!is_array($part)) continue;
            $part_type = isset($part['type']) ? $part['type'] : '';
            if ($part_type === 'text') {
                $text_parts[] = isset($part['text']) ? $part['text'] : '';
            }
        }
        $response = trim(implode("\n", $text_parts));
        DebMes("Opencode API response: " . ($response ? substr($response, 0, 200) : 'EMPTY'), 'opencode');
        return $response;
    }

    function saveTokensFromResponse($result) {
        $tokens = array();
        $cost = null;
        if (!empty($result['data']['info']['tokens'])) {
            $tokens = $result['data']['info']['tokens'];
            $cost = isset($result['data']['info']['cost']) ? $result['data']['info']['cost'] : null;
        } elseif (!empty($result['data']['tokens'])) {
            $tokens = $result['data']['tokens'];
            $cost = isset($result['data']['cost']) ? $result['data']['cost'] : null;
        }
        if ($tokens) {
            $this->config['OC_SESSION_TOKENS'] = $tokens;
            if ($cost !== null) {
                $this->config['OC_SESSION_COST'] = $cost;
            }
            $this->saveConfig();
        }
    }

    function usual(&$out) {
        $this->getConfig();
        $out['OC_SESSION_REUSE'] = $this->config['OC_SESSION_REUSE'];
        $out['OC_LANG_SEND'] = defined('LANG_OPENCODE_SEND') ? LANG_OPENCODE_SEND : 'Send';
        $out['OC_LANG_TYPING'] = defined('LANG_OPENCODE_TYPING') ? LANG_OPENCODE_TYPING : '...';
        $out['OC_LANG_CLEAR_CONFIRM'] = defined('LANG_OPENCODE_CLEAR_CONFIRM') ? LANG_OPENCODE_CLEAR_CONFIRM : 'Clear chat history?';
        $out['OC_LANG_HISTORY_CLEARED'] = defined('LANG_OPENCODE_HISTORY_CLEARED') ? LANG_OPENCODE_HISTORY_CLEARED : 'History cleared. How can I help you?';
        $out['OC_LANG_HOW_CAN_I_HELP'] = defined('LANG_OPENCODE_HOW_CAN_I_HELP') ? LANG_OPENCODE_HOW_CAN_I_HELP : 'How can I help you?';
        $out['OC_LANG_CONNECT_ERROR'] = defined('LANG_OPENCODE_CONNECT_ERROR') ? LANG_OPENCODE_CONNECT_ERROR : 'Connection error. Please try again.';
        $out['OC_LANG_SERVER_ERROR'] = defined('LANG_OPENCODE_SERVER_ERROR') ? LANG_OPENCODE_SERVER_ERROR : 'Invalid response from server';
        $out['OC_LANG_UNKNOWN_ERROR'] = defined('LANG_OPENCODE_UNKNOWN_ERROR') ? LANG_OPENCODE_UNKNOWN_ERROR : 'Unknown error';
        $out['OC_LANG_ERROR_PREFIX'] = defined('LANG_OPENCODE_ERROR_PREFIX') ? LANG_OPENCODE_ERROR_PREFIX : 'Error: ';
    }

    function checkDependencies(&$health_result = null, $short_timeout = false) {
        $checks = array();
        $checks['opencode_binary'] = file_exists($this->opencode_bin) ? 'ok' : 'missing';
        if ($short_timeout) {
            $old_timeout = $this->config['OC_TIMEOUT'];
            $this->config['OC_TIMEOUT'] = 5;
            $health_result = $this->restRequest('GET', '/global/health');
            $this->config['OC_TIMEOUT'] = $old_timeout;
        } else {
            $health_result = $this->restRequest('GET', '/global/health');
        }
        $checks['api'] = ($health_result && $health_result['code'] === 200) ? 'ok' : 'missing';
        return $checks;
    }

    function admin(&$out) {
        global $session;
        $this->getConfig();

        $ajax = gr('ajax');
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            $op = gr('op');
            if ($op == 'send_message') {
                $msg = gr('message');
                if (!$msg) {
                    echo json_encode(array('success' => false, 'error' => defined('LANG_OPENCODE_EMPTY_MESSAGE') ? LANG_OPENCODE_EMPTY_MESSAGE : 'Пустое сообщение'));
                } else {
                    $user_id = (int)(isset($session->data['MEMBER']) ? $session->data['MEMBER'] : 1);
                    $this->saveMessageToHistory($msg, 'user', $user_id);
                    $placeholder_id = $this->saveMessageToHistory('…', 'assistant', $user_id);
                    session_write_close();
                    $bg = DIR_MODULES . 'opencode/background.php';
                    $safe_msg = escapeshellarg($msg);
                    exec("php $bg $placeholder_id $user_id $safe_msg > /dev/null 2>&1 &");
                    ob_clean();
                    echo json_encode(array('success' => true, 'processing' => true, 'message_id' => $placeholder_id));
                    flush();
                }
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
            } elseif ($op == 'check_status') {
                $health = null;
                $deps = $this->checkDependencies($health);
                $model_name = $this->config['OC_PROVIDER_MODEL'] ?: ($this->config['OC_MODEL'] ?: 'opencode/big-pickle');
                $mcp_result = $this->restRequest('GET', '/mcp');
                $mcp_status = ($mcp_result && $mcp_result['code'] === 200 && is_array($mcp_result['data'])) ? $mcp_result['data'] : array();
                echo json_encode(array(
                    'success' => true,
                    'api_ok' => ($deps['api'] === 'ok'),
                    'binary_ok' => ($deps['opencode_binary'] === 'ok'),
                    'sudo_ok' => $this->canSudo(),
                    'model' => $model_name,
                    'mcp' => $mcp_status
                ));
            } elseif ($op == 'refresh_models') {
                $models = $this->getAvailableModels(true);
                echo json_encode(array('success' => true, 'models' => $models));
            }
            exit;
        }

        $is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
        if ($is_post) session_write_close();

        $health = null;
        if ($is_post) {
            $deps = $this->checkDependencies($health, true);
        } else {
            $deps = $this->checkDependencies($health);
        }

        if ($deps['opencode_binary'] !== 'ok') {
            $this->installOpencodeBinary();
            $deps = $this->checkDependencies($health, $is_post);
        }

        $api_ok = ($deps['api'] === 'ok');
        $binary_ok = ($deps['opencode_binary'] === 'ok');

        $out['DEPS_BINARY_COLOR'] = $binary_ok ? '#5cb85c' : '#d9534f';
        $out['DEPS_API_COLOR'] = $api_ok ? '#5cb85c' : '#d9534f';
        $out['DEPS_BINARY_LABEL'] = $binary_ok ? 'OK' : 'MISSING';
        $out['DEPS_API_LABEL'] = $api_ok ? 'OK' : 'MISSING';

        $sudo_ok = $this->canSudo();
        $out['DEPS_SUDO_COLOR'] = $sudo_ok ? '#5cb85c' : '#d9534f';
        $out['DEPS_SUDO_LABEL'] = $sudo_ok ? 'OK' : 'NO SUDO';

        $model_name = $this->config['OC_PROVIDER_ENDPOINT'] ? ($this->config['OC_PROVIDER_MODEL'] ?: $this->config['OC_MODEL']) : ($this->config['OC_MODEL'] ?: 'opencode/big-pickle');
        $out['DEPS_MODEL_COLOR'] = $api_ok ? '#5cb85c' : '#d9534f';
        $out['DEPS_MODEL_LABEL'] = $api_ok ? $model_name : (defined('LANG_OPENCODE_NO_CONNECTION') ? LANG_OPENCODE_NO_CONNECTION : 'Нет подключения');

        $mcp_installed = is_dir(DIR_MODULES . 'mcp');
        $mcp_python_ok = $this->checkPythonPackage('mcp');

        DebMes("Opencode admin: vm=" . ($this->view_mode ?? 'NULL') . " mcp_inst=" . ($mcp_installed ? '1' : '0') . " mcp_py=" . ($mcp_python_ok ? '1' : '0') . " cfg=" . (is_array($this->config) ? 'array[' . count($this->config) . ']' : 'NOT_ARRAY'), 'opencode');

        if ($this->view_mode == 'update_settings') {
            $this->getConfig();
            DebMes("Opencode: SAVING SETTINGS", 'opencode');
            $saved_tab = gr('tab');

            if ($saved_tab === 'settings') {
                $model_custom = gr('oc_model_custom');
                $model_select = gr('oc_model');
                $old_model = isset($this->config['OC_MODEL']) ? $this->config['OC_MODEL'] : '';
                $this->config['OC_MODEL'] = $model_custom ? $model_custom : $model_select;
                $this->config['OC_AGENT'] = gr('oc_agent');
                $this->config['OC_SYSTEM_PROMPT'] = gr('oc_system_prompt');
                $mcp_raw = gr('oc_mcp_servers');
                if ($mcp_raw !== '') {
                    $mcp_decoded = json_decode($mcp_raw, true);
                    if ($mcp_decoded === null || !array_is_list($mcp_decoded)) {
                        $out['MCP_JSON_ERROR'] = '1';
                    } else {
                        $this->config['OC_MCP_SERVERS'] = $mcp_raw;
                    }
                } else {
                    $this->config['OC_MCP_SERVERS'] = '';
                }
                $this->config['OC_PROVIDER_API_KEY'] = gr('oc_provider_api_key');
                $this->config['OC_PROVIDER_ENDPOINT'] = gr('oc_provider_endpoint');
                $old_provider_model = isset($this->config['OC_PROVIDER_MODEL']) ? $this->config['OC_PROVIDER_MODEL'] : '';
                $this->config['OC_PROVIDER_MODEL'] = gr('oc_provider_model');
                if ($old_model != $this->config['OC_MODEL'] || $old_provider_model != $this->config['OC_PROVIDER_MODEL']) {
                    unset($this->config['OC_SESSION_ID']);
                }
                $this->config['OC_MAX_HISTORY'] = (int)gr('oc_max_history');
                $this->config['OC_TIMEOUT'] = (int)gr('oc_timeout');
                $bg_timeout = (int)gr('oc_bg_timeout');
                $this->config['OC_BG_TIMEOUT'] = max(5, min(120, $bg_timeout > 0 ? $bg_timeout : 30));
                $this->config['OC_PURE_MODE'] = gr('oc_pure_mode') ? 1 : 0;
                if ($mcp_installed) {
                    $this->config['OC_MAJORDOMO_MCP'] = gr('oc_majordomo_mcp') ? 1 : 0;
                }
                $this->config['OC_SESSION_REUSE'] = gr('oc_session_reuse') ? 1 : 0;

                $prompt = trim($this->config['OC_SYSTEM_PROMPT']);
                if (!$prompt && !empty($this->config['OC_MAJORDOMO_MCP'])) {
                    $this->config['OC_SYSTEM_PROMPT'] = 'Ты — голосовой ассистент умного дома. Твоя задача — помогать пользователю управлять устройствами умного дома. У тебя есть доступ к MCP-инструментам для управления устройствами. Используй эти инструменты когда пользователь просит что-то сделать с устройствами. Для работы с файлами используй инструменты read/edit/write, а не bash-команды. Если инструмент недоступен, объясни почему. Отвечай кратко и по делу, как голосовой ассистент.';
                }
            }

            if ($saved_tab === 'server') {
                $new_port = (int)gr('oc_port');
                if ($new_port < 1 || $new_port > 65535) $new_port = 4096;
                $old_port = isset($this->config['OC_PORT']) ? (int)$this->config['OC_PORT'] : 4096;
                if ($new_port !== $old_port && !$this->isPortAvailable($new_port)) {
                    $out['PORT_BUSY'] = "1";
                    $new_port = $old_port;
                }
                $this->config['OC_PORT'] = $new_port;
                $this->config['OC_AUTH_ENABLED'] = gr('oc_auth_enabled') ? 1 : 0;
                $this->config['OC_AUTH_LOGIN'] = gr('oc_auth_login');
                $this->config['OC_AUTH_PASSWORD'] = gr('oc_auth_password');
                $old_full_access = isset($this->config['OC_FULL_ACCESS']) ? $this->config['OC_FULL_ACCESS'] : 1;
                $this->config['OC_FULL_ACCESS'] = gr('oc_full_access') ? 1 : 0;
                if ($old_full_access != $this->config['OC_FULL_ACCESS']) {
                    unset($this->config['OC_SESSION_ID']);
                }
            }

            $this->saveConfig();
            $this->writeOpencodeConfig();

            $rec = SQLSelectOne("SELECT * FROM settings WHERE NAME='HOOK_EVENT_COMMAND'");
            if ($rec['ID']) {
                $data = json_decode($rec['VALUE'], true);
                if (isset($data[$this->name])) {
                    $data[$this->name]['priority'] = 50;
                    $rec['VALUE'] = json_encode($data);
                    SQLUpdate('settings', $rec);
                }
            }

            $out['OK_VISIBLE'] = empty($out['MCP_JSON_ERROR']) ? '' : 'style="display:none"';
        } else {
            $out['OK_VISIBLE'] = 'style="display:none"';
        }

        if ($this->view_mode == 'clear_session') {
            unset($this->config['OC_SESSION_ID']);
            unset($this->config['OC_SESSION_TOKENS']);
            unset($this->config['OC_SESSION_COST']);
            $this->saveConfig();
            $out['SESSION_CLEARED_VISIBLE'] = '';
        } else {
            $out['SESSION_CLEARED_VISIBLE'] = 'style="display:none"';
        }

        if ($this->view_mode == 'restart_opencode') {
            $this->syncServiceRestart();
            unset($this->config['OC_SESSION_ID']);
            unset($this->config['OC_SESSION_TOKENS']);
            unset($this->config['OC_SESSION_COST']);
            $this->config['OC_RESTARTED'] = 1;
            $this->saveConfig();
        } else {
            unset($this->config['OC_RESTARTED']);
        }

        if ($this->view_mode == 'remove_opencode') {
            $this->removeOpencode();
            echo '<html><head><meta http-equiv="refresh" content="0;url=/admin.php"></head><body></body></html>';
            exit;
        }

        $out['OC_MODEL'] = $this->config['OC_MODEL'] ? $this->config['OC_MODEL'] : 'opencode/big-pickle';
        $out['OC_MCP_PYTHON_OK'] = $mcp_python_ok ? '1' : '0';
        $out['OC_AGENT'] = $this->config['OC_AGENT'] ? $this->config['OC_AGENT'] : 'build';

        $system_prompt = isset($this->config['OC_SYSTEM_PROMPT']) ? $this->config['OC_SYSTEM_PROMPT'] : '';
        if (!$system_prompt) {
            $system_prompt = 'Ты — голосовой ассистент умного дома. Твоя задача — помогать пользователю управлять устройствами умного дома. У тебя есть доступ к MCP-инструментам для управления устройствами. Используй эти инструменты когда пользователь просит что-то сделать с устройствами. Для работы с файлами используй инструменты read/edit/write, а не bash-команды. Если инструмент недоступен, объясни почему. Отвечай кратко и по делу, как голосовой ассистент.';
        }
        $out['OC_SYSTEM_PROMPT'] = $system_prompt;
        $out['OC_MCP_SERVERS'] = $this->config['OC_MCP_SERVERS'];
        $out['OC_PROVIDER_API_KEY'] = $this->config['OC_PROVIDER_API_KEY'];
        $out['OC_PROVIDER_ENDPOINT'] = $this->config['OC_PROVIDER_ENDPOINT'];
        $out['OC_PROVIDER_MODEL'] = $this->config['OC_PROVIDER_MODEL'];
        $out['OC_MAX_HISTORY'] = $this->config['OC_MAX_HISTORY'] ? $this->config['OC_MAX_HISTORY'] : 50;
        $out['OC_TIMEOUT'] = $this->config['OC_TIMEOUT'] ? $this->config['OC_TIMEOUT'] : 120;
        $out['OC_BG_TIMEOUT'] = $this->config['OC_BG_TIMEOUT'] ? $this->config['OC_BG_TIMEOUT'] : 30;
        $out['OC_PORT'] = $this->config['OC_PORT'] ? $this->config['OC_PORT'] : 4096;

        $agent = $this->config['OC_AGENT'];
        $out['OC_AGENT_BUILD_SEL'] = ($agent == 'build') ? 'selected' : '';
        $out['OC_AGENT_GENERAL_SEL'] = ($agent == 'general') ? 'selected' : '';
        $out['OC_PURE_MODE_CHECKED'] = $this->config['OC_PURE_MODE'] ? 'checked' : '';
        $out['OC_MAJORDOMO_MCP_CHECKED'] = $this->config['OC_MAJORDOMO_MCP'] ? 'checked' : '';
        $out['OC_MAJORDOMO_MCP_VISIBLE'] = $mcp_installed ? '' : 'style="display:none"';
        $out['OC_SESSION_REUSE_CHECKED'] = $this->config['OC_SESSION_REUSE'] ? 'checked' : '';
        $out['OC_FULL_ACCESS_CHECKED'] = isset($this->config['OC_FULL_ACCESS']) ? ($this->config['OC_FULL_ACCESS'] ? 'checked' : '') : 'checked';
        $out['OC_SESSION_ID'] = $this->config['OC_SESSION_ID'] ?: '—';
        if (!empty($this->config['OC_SESSION_TOKENS'])) {
            $t = $this->config['OC_SESSION_TOKENS'];
            $parts = array();
            if (isset($t['total'])) $parts[] = 'Total: ' . (int)$t['total'];
            $parts[] = 'Input: ' . (int)$t['input'];
            $parts[] = 'Output: ' . (int)$t['output'];
            if (!empty($t['reasoning'])) $parts[] = 'Reasoning: ' . (int)$t['reasoning'];
            if (isset($t['cache']['read']) || isset($t['cache']['write'])) {
                $parts[] = 'Cache: ' . (int)($t['cache']['read'] ?? 0) . 'R / ' . (int)($t['cache']['write'] ?? 0) . 'W';
            }
            $out['OC_SESSION_TOKENS_DISPLAY'] = implode(' | ', $parts);
            if (isset($this->config['OC_SESSION_COST'])) {
                $out['OC_SESSION_TOKENS_DISPLAY'] .= ' | Cost: ' . number_format((float)$this->config['OC_SESSION_COST'], 6);
            }
        } else {
            $out['OC_SESSION_TOKENS_DISPLAY'] = '—';
        }
        $out['OC_AUTH_LOGIN'] = $this->config['OC_AUTH_LOGIN'] ?: '';
        $out['OC_AUTH_PASSWORD'] = $this->config['OC_AUTH_PASSWORD'] ?: '';

        $out['AVAILABLE_MODELS'] = $this->getAvailableModels();

        $models_with_selected = array();
        $current_model = $out['OC_MODEL'];
        foreach ($out['AVAILABLE_MODELS'] as $model) {
            $models_with_selected[] = array(
                'VALUE' => $model,
                'SELECTED_FLAG' => ($model == $current_model) ? 'selected' : ''
            );
        }
        $out['AVAILABLE_MODELS'] = $models_with_selected;

        $tab = gr('tab');
        if (!$tab) $tab = 'settings';
        $auth_enabled = !empty($this->config['OC_AUTH_ENABLED']);
        $out['TAB'] = $tab;
        $out['TAB_SETTINGS'] = ($tab == 'settings') ? '1' : '0';
        $out['TAB_SERVER'] = ($tab == 'server') ? '1' : '0';
        $out['TAB_CHAT'] = ($tab == 'chat') ? '1' : '0';
        $out['TAB_HELP'] = ($tab == 'help') ? '1' : '0';
        $out['TAB_WEB'] = ($tab == 'web') ? '1' : '0';
        $out['TAB_CHAT_VISIBLE'] = ($tab == 'chat') ? '' : 'style="display:none"';
        $out['TAB_WEB_VISIBLE'] = ($tab == 'web' && $auth_enabled && $api_ok) ? '' : 'style="display:none"';
        $out['TAB_WEB_NAV_VISIBLE'] = $auth_enabled && $api_ok ? '' : 'style="display:none"';
        $out['VERSION'] = '1.0.0';

        $out['OC_WEB_VISIBLE'] = ($auth_enabled && $api_ok) ? '' : 'style="display:none"';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $port = $this->getApiPort();
        if ($auth_enabled) {
            $raw_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $host = parse_url("{$scheme}://{$raw_host}", PHP_URL_HOST);
        } else {
            $host = '127.0.0.1';
        }
        $out['OC_WEB_URL'] = "{$scheme}://{$host}:{$port}";
        $out['OC_API_URL'] = "http://127.0.0.1:{$port}";

        $out['OC_LANG_SEND'] = defined('LANG_OPENCODE_SEND') ? LANG_OPENCODE_SEND : 'Send';
        $out['OC_LANG_TYPING'] = defined('LANG_OPENCODE_TYPING') ? LANG_OPENCODE_TYPING : '...';
        $out['OC_LANG_CLEAR_CONFIRM'] = defined('LANG_OPENCODE_CLEAR_CONFIRM') ? LANG_OPENCODE_CLEAR_CONFIRM : 'Clear chat history?';
        $out['OC_LANG_HISTORY_CLEARED'] = defined('LANG_OPENCODE_HISTORY_CLEARED') ? LANG_OPENCODE_HISTORY_CLEARED : 'History cleared. How can I help you?';
        $out['OC_LANG_HOW_CAN_I_HELP'] = defined('LANG_OPENCODE_HOW_CAN_I_HELP') ? LANG_OPENCODE_HOW_CAN_I_HELP : 'How can I help you?';
        $out['OC_LANG_CONNECT_ERROR'] = defined('LANG_OPENCODE_CONNECT_ERROR') ? LANG_OPENCODE_CONNECT_ERROR : 'Connection error. Please try again.';
        $out['OC_LANG_SERVER_ERROR'] = defined('LANG_OPENCODE_SERVER_ERROR') ? LANG_OPENCODE_SERVER_ERROR : 'Invalid response from server';
        $out['OC_LANG_UNKNOWN_ERROR'] = defined('LANG_OPENCODE_UNKNOWN_ERROR') ? LANG_OPENCODE_UNKNOWN_ERROR : 'Unknown error';
        $out['OC_LANG_ERROR_PREFIX'] = defined('LANG_OPENCODE_ERROR_PREFIX') ? LANG_OPENCODE_ERROR_PREFIX : 'Error: ';
    }

    function getModelsCacheFile() {
        return DIR_MODULES . $this->name . '/models_cache.json';
    }

    function getAvailableModels($force = false) {
        $cache_file = $this->getModelsCacheFile();
        $use_cache = false;

        if (!$force && file_exists($cache_file)) {
            $cache_time = filemtime($cache_file);
            if ((time() - $cache_time) < 3600) {
                $cached = json_decode(file_get_contents($cache_file), true);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

        $models = array();

        $result = $this->restRequest('GET', '/provider');
        if ($result && $result['code'] === 200) {
            $providers = isset($result['data']['all']) ? $result['data']['all'] : array();
            foreach ($providers as $prov) {
                $provider_id = isset($prov['id']) ? $prov['id'] : '';
                $provider_models = isset($prov['models']) ? $prov['models'] : array();
                foreach ($provider_models as $model_id => $model_info) {
                    $full_id = $provider_id . '/' . $model_id;
                    $models[] = $full_id;
                }
            }
            sort($models);
        }

        if (empty($models)) {
            $cmd = "timeout 10 {$this->opencode_bin} models 2>/dev/null";
            $output = array();
            exec($cmd, $output, $return_var);
            foreach ($output as $line) {
                $clean = trim(preg_replace('/\e\[[0-9;]*m/', '', $line));
                if ($clean && strpos($clean, '/') !== false) {
                    $models[] = $clean;
                }
            }
        }

        file_put_contents($cache_file, json_encode($models));

        return $models;
    }

    function writeOpencodeConfig() {
        $config = array();
        $config['$schema'] = 'https://opencode.ai/config.json';

        $provider_endpoint = $this->config['OC_PROVIDER_ENDPOINT'];
        $provider_api_key = $this->config['OC_PROVIDER_API_KEY'];
        $provider_model = $this->config['OC_PROVIDER_MODEL'];

        if ($provider_endpoint) {
            $model_id = $provider_model ? $provider_model : 'default';
            $options = array(
                'baseURL' => rtrim($provider_endpoint, '/') . '/v1'
            );
            if ($provider_api_key) {
                $options['apiKey'] = $provider_api_key;
            }
            $config['provider']['custom'] = array(
                'npm' => '@ai-sdk/openai-compatible',
                'name' => 'Custom Provider',
                'options' => $options,
                'models' => array(
                    $model_id => array(
                        'name' => $model_id,
                        'tool_call' => false
                    )
                )
            );
            $config['model'] = 'custom/' . $model_id;
        }

        // MCP always included for device control
        $mcp_servers_raw = $this->config['OC_MCP_SERVERS'];
        if ($mcp_servers_raw) {
            $mcp_servers = json_decode($mcp_servers_raw, true);
            if (is_array($mcp_servers)) {
                foreach ($mcp_servers as $server) {
                    if ($server['enabled'] && $server['name'] && $server['command']) {
                        $cmd = array($server['command']);
                        if ($server['args']) {
                            $cmd = array_merge($cmd, explode(' ', $server['args']));
                        }
                        $mcp_python = $this->getMcpPython();
                        if ($mcp_python !== 'python3' && $cmd[0] === 'python3') {
                            $cmd[0] = $mcp_python;
                        }
                        $config['mcp'][$server['name']] = array(
                            'type' => $server['type'] ? $server['type'] : 'local',
                            'command' => $cmd
                        );
                    }
                }
            }
        }

        if ($this->config['OC_MAJORDOMO_MCP']) {
            $mcp_module_path = DIR_MODULES . 'mcp';
            if (is_dir($mcp_module_path)) {
                $config['mcp']['majordomo'] = array(
                    'type' => 'local',
                    'command' => array($this->getMcpPython(), SERVER_ROOT . '/modules/mcp/lib/mcp-xiaozhi.py')
                );
            }
        }

        if ($this->config['OC_AGENT']) {
            $config['default_agent'] = $this->config['OC_AGENT'];
        }

        if (empty($this->config['OC_FULL_ACCESS'])) {
            $config['permission'] = array(
                'read' => array(
                    '*' => 'allow'
                ),
                'edit' => array(
                    '*' => 'deny',
                    '/var/www/html/cms/files/**' => 'allow',
                    '/var/www/tmp/opencode/**' => 'allow',
                    '/tmp/**' => 'allow'
                ),
                'glob' => array(
                    '*' => 'allow'
                ),
                'grep' => array(
                    '*' => 'allow'
                ),
                'bash' => array(
                    '*' => 'deny',
                    'git *' => 'allow',
                    'ls *' => 'allow',
                    'which *' => 'allow',
                    'id' => 'allow',
                    'pwd' => 'allow',
                    'whoami' => 'allow',
                    'date' => 'allow',
                    'uptime' => 'allow',
                    'uname *' => 'allow',
                    'ps *' => 'allow',
                    'df *' => 'allow',
                    'du *' => 'allow',
                    'wc *' => 'allow',
                    'ping *' => 'allow',
                    'php *' => 'allow',
                    'python3 *' => 'allow'
                ),
                'external_directory' => array(
                    '*' => 'deny',
                    '/var/www/tmp/opencode/**' => 'allow',
                    '/tmp/**' => 'allow'
                )
            );
        }

        $config_dir = $this->opencode_config_dir;
        $this->ensureDirExists($config_dir);
        $config_content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($config_dir . '/opencode.jsonc', $config_content);
        @chmod($config_dir . '/opencode.jsonc', 0644);

        $ok = $this->setupServiceDropin();
        if ($ok) {
            $this->syncServiceRestart();
        } else {
            DebMes("Opencode: config saved but service drop-in update failed", 'opencode');
        }
    }

    function isRoot() {
        return function_exists('posix_getuid') && posix_getuid() === 0;
    }

    function canSudo() {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        exec($sudo . 'id 2>&1', $out, $rc);
        return $rc === 0;
    }

    function isPortAvailable($port) {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        exec("{$sudo}ss -tlnp sport = :{$port} 2>&1", $out, $rc);
        $listening = implode("\n", $out);
        if ($rc !== 0) return true;
        $has_listen = false;
        foreach ($out as $line) {
            if (strpos($line, 'LISTEN') !== false) { $has_listen = true; break; }
        }
        if (!$has_listen) return true;
        if (strpos($listening, 'opencode') !== false) return true;
        return false;
    }

    function setupServiceDropin() {
        $hostname = !empty($this->config['OC_AUTH_ENABLED']) ? '0.0.0.0' : '127.0.0.1';
        $port = $this->getApiPort();
        $sudo = $this->isRoot() ? '' : 'sudo ';

        $service_file = '/etc/systemd/system/opencode-web.service';
        if (!file_exists($service_file)) {
            $unit = "[Unit]\n";
            $unit .= "Description=Opencode AI Service\n";
            $unit .= "After=network.target\n\n";
            $unit .= "[Service]\n";
            $unit .= "Type=simple\n";
            $unit .= "ExecStart=/usr/local/bin/opencode web --port {$port} --hostname {$hostname}\n";
            $unit .= "Restart=always\n";
            $unit .= "RestartSec=5\n\n";
            $unit .= "[Install]\n";
            $unit .= "WantedBy=multi-user.target\n";
            $tmp = '/var/www/tmp/opencode-web.service';
            file_put_contents($tmp, $unit);
            exec("{$sudo}cp " . escapeshellarg($tmp) . " " . escapeshellarg($service_file) . " 2>&1", $out, $rc);
            @unlink($tmp);
            if ($rc !== 0) {
                DebMes("Opencode: cp service file failed (rc={$rc}): " . implode(' ', $out), 'opencode');
            }
        }

        $content = "[Service]\n";
        $content .= "Environment=\n";
        $content .= "Environment=HOME=/var/www\n";
        $content .= "WorkingDirectory=/var/www/html\n";
        $content .= "ExecStart=\n";
        if (!empty($this->config['OC_AUTH_ENABLED'])) {
            $login = !empty($this->config['OC_AUTH_LOGIN']) ? $this->config['OC_AUTH_LOGIN'] : 'opencode';
            $password = !empty($this->config['OC_AUTH_PASSWORD']) ? $this->config['OC_AUTH_PASSWORD'] : 'opencode';
            $content .= "Environment=OPENCODE_SERVER_USERNAME=" . escapeshellarg($login) . "\n";
            $content .= "Environment=OPENCODE_SERVER_PASSWORD=" . escapeshellarg($password) . "\n";
        }
        $content .= "ExecStart=/usr/local/bin/opencode web --port {$port} --hostname {$hostname}\n";
        $override_dir = '/etc/systemd/system/opencode-web.service.d';
        $override_file = $override_dir . '/override.conf';

        exec("{$sudo}mkdir -p " . escapeshellarg($override_dir) . " 2>&1", $out, $rc);
        if ($rc !== 0) {
            DebMes("Opencode: mkdir drop-in dir failed (rc={$rc}): " . implode(' ', $out), 'opencode');
            return false;
        }

        $tmp = '/var/www/tmp/opencode-override.conf';
        if (file_put_contents($tmp, $content) === false) {
            DebMes("Opencode: failed to write temp file: {$tmp}", 'opencode');
            return false;
        }

        exec("{$sudo}cp " . escapeshellarg($tmp) . " " . escapeshellarg($override_file) . " 2>&1", $out, $rc);
        @unlink($tmp);
        if ($rc !== 0) {
            DebMes("Opencode: cp override.conf failed (rc={$rc}): " . implode(' ', $out), 'opencode');
            return false;
        }

        exec("{$sudo}systemctl daemon-reload 2>&1", $out, $rc);
        if ($rc !== 0) {
            DebMes("Opencode: daemon-reload failed (rc={$rc}): " . implode(' ', $out), 'opencode');
            return false;
        }

        exec("{$sudo}systemctl enable opencode-web.service 2>&1", $out, $rc);
        if ($rc !== 0) {
            DebMes("Opencode: enable service failed (rc={$rc}): " . implode(' ', $out), 'opencode');
        }

        DebMes("Opencode: service unit + drop-in updated", 'opencode');
        return true;
    }

    function syncServiceRestart() {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        exec("{$sudo}systemctl restart opencode-web.service 2>/dev/null >/dev/null &");
        DebMes("Opencode: service restart triggered in background", 'opencode');
        return true;
    }

    function ensureDirExists($dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    function executeMajordomoCommand($command) {
        if (preg_match('/^(set|get|call|turn)\s+(.+)$/i', $command, $m)) {
            $action = strtolower($m[1]);
            $target = trim($m[2]);

            if ($action == 'get') {
                $value = gg($target);
                return array('success' => true, 'result' => $value);
            } elseif ($action == 'set') {
                if (preg_match('/^(.+?)\s*=\s*(.+)$/', $target, $parts)) {
                    sg(trim($parts[1]), trim($parts[2]));
                    return array('success' => true, 'result' => 'OK');
                }
            } elseif ($action == 'turn') {
                if (preg_match('/^(.+?)\s+(on|off|1|0)$/i', $target, $parts)) {
                    $value = in_array(strtolower($parts[2]), array('on', '1')) ? '1' : '0';
                    sg(trim($parts[1]) . '.status', $value);
                    return array('success' => true, 'result' => 'OK');
                }
            } elseif ($action == 'call') {
                $result = callMethod($target);
                return array('success' => true, 'result' => $result);
            }
        }
        return array('success' => false, 'error' => 'Unknown command format');
    }

    function processDeviceCommands($text) {
        if (preg_match_all('/\[EXEC:([^\]]+)\]/i', $text, $matches)) {
            foreach ($matches[1] as $cmd) {
                $this->executeMajordomoCommand($cmd);
            }
        }
    }

    function saveMessageToHistory($message, $role, $member_id = 0) {
        $rec = array();
        $rec['USER_ID'] = $member_id > 0 ? $member_id : 1;
        $rec['MESSAGE'] = $message;
        $rec['ROLE'] = $role;
        $rec['ADDED'] = date('Y-m-d H:i:s');
        $rec['ID'] = SQLInsert('opencode_messages', $rec);
        return $rec['ID'];
    }

    function processCycle() {
        $max_history = $this->config['OC_MAX_HISTORY'] ? $this->config['OC_MAX_HISTORY'] : 50;
        $old_records = SQLSelect("SELECT ID FROM opencode_messages WHERE ID NOT IN (SELECT ID FROM opencode_messages ORDER BY ID DESC LIMIT {$max_history})");
        foreach ($old_records as $rec) {
            SQLExec("DELETE FROM opencode_messages WHERE ID='" . $rec['ID'] . "'");
        }
    }

    function install($parent_name = '') {
        $arch = trim(shell_exec('uname -m'));
        if (preg_match('/^(i[3456]86|armv[567]l)$/', $arch)) {
            DebMes("OPENCODE INSTALL ERROR: 32-bit system detected ({$arch})", 'opencode');
            register_shutdown_function(function() {
                echo '<div class="alert alert-danger">' . LANG_OPENCODE_INSTALL_ARCH_ERROR . '</div>';
            });
            return false;
        }

        parent::install($parent_name);
        subscribeToEvent($this->name, 'COMMAND', '', 50);
        $tmpdir = '/var/www/tmp';
        if (!is_dir($tmpdir)) {
            @mkdir($tmpdir, 0755, true);
            @chown($tmpdir, 'www-data');
            @chgrp($tmpdir, 'www-data');
        }

        if (!file_exists($this->opencode_bin)) {
            $this->installOpencodeBinary();
        }

        $this->installPythonDeps();
        $this->setupServiceDropin();
        $this->syncServiceRestart();
    }

    function installPythonDeps() {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        $pip_cmd = trim(shell_exec('which pip3 2>/dev/null')) ?: trim(shell_exec('which pip 2>/dev/null'));
        if ($pip_cmd) {
            exec("cd /tmp && {$sudo}{$pip_cmd} install mcp 2>&1", $output, $return_var);
            if ($return_var !== 0) {
                DebMes("Opencode: pip install mcp failed: " . implode("\n", $output), 'opencode');
            } else {
                DebMes("Opencode: mcp package installed successfully", 'opencode');
            }
        } else {
            DebMes("Opencode: pip not found, skipping system install", 'opencode');
        }
        $venv_python = $this->findMcpVenvPython();
        if ($venv_python) {
            exec("cd /tmp && " . $venv_python . " -c 'import mcp' 2>&1", $out, $rc);
            if ($rc !== 0) {
                $venv_pip = $this->findMcpVenvPip();
                if ($venv_pip) {
                    exec($venv_pip . " install mcp 2>&1", $output2, $rc2);
                    if ($rc2 === 0) {
                        DebMes("Opencode: mcp installed in MCP venv", 'opencode');
                    }
                }
            } else {
                DebMes("Opencode: mcp already available in MCP venv, skipping", 'opencode');
            }
        }
    }

    function findMcpVenvPython() {
        $candidates = array(
            realpath(DIR_MODULES) . '/mcp/.venv/bin/python3',
            realpath(DIR_MODULES) . '/mcp/lib/.venv/bin/python3',
        );
        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        return null;
    }

    function findMcpVenvPip() {
        $py = $this->findMcpVenvPython();
        if (!$py) return null;
        $dir = dirname($py);
        $candidates = array($dir . '/pip3', $dir . '/pip');
        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        return null;
    }

    function getMcpPython() {
        $venv_python = $this->findMcpVenvPython();
        if ($venv_python) {
            exec("cd /tmp && " . $venv_python . " -c 'import mcp' 2>&1", $out, $rc);
            if ($rc === 0) {
                return $venv_python;
            }
        }
        return 'python3';
    }

    function checkPythonPackage($package) {
        exec("cd /tmp && python3 -c 'import " . $package . "' 2>&1", $output, $return_var);
        if ($return_var === 0) return true;
        $venv_python = $this->findMcpVenvPython();
        if ($venv_python) {
            exec("cd /tmp && " . $venv_python . " -c 'import " . $package . "' 2>&1", $output, $return_var);
            if ($return_var === 0) return true;
        }
        return false;
    }

    function installOpencodeBinary() {
        DebMes("Opencode binary not found, attempting to install...", 'opencode');
        $sudo = $this->isRoot() ? '' : 'sudo ';
        $install_output = array();
        $return_var = 0;
        exec("{$sudo}npm i -g opencode-ai 2>&1", $install_output, $return_var);
        if ($return_var !== 0) {
            DebMes("npm install failed, trying curl install...", 'opencode');
            exec("{$sudo}curl -fsSL https://opencode.ai/install | {$sudo}bash 2>&1", $install_output, $return_var);
        }
        if ($return_var !== 0) {
            DebMes("Opencode installation failed", 'opencode');
        } else {
            exec("{$sudo}chmod 755 /usr/local/bin/opencode 2>/dev/null");
        }
    }

    function removeOpencode() {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        exec("{$sudo}systemctl stop opencode-web.service 2>/dev/null");
        exec("{$sudo}systemctl disable opencode-web.service 2>/dev/null");
        exec("{$sudo}rm -f /etc/systemd/system/opencode-web.service 2>/dev/null");
        exec("{$sudo}rm -f /etc/systemd/system/opencode-web.service.d/override.conf 2>/dev/null");
        exec("{$sudo}rmdir /etc/systemd/system/opencode-web.service.d 2>/dev/null");
        exec("{$sudo}systemctl daemon-reload 2>/dev/null");
        $this->ensureDirExists($this->opencode_config_dir);
        file_put_contents($this->opencode_config_dir . '/opencode.jsonc', '{}');
        DebMes("Opencode: binary uninstall started in background", 'opencode');
        exec("nohup {$sudo} {$this->opencode_bin} uninstall --force >/dev/null 2>&1 &");
        DebMes("Opencode: remove complete", 'opencode');
    }

    function uninstall() {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        SQLExec('DROP TABLE IF EXISTS opencode_messages');
        exec("{$sudo}systemctl stop opencode-web.service 2>/dev/null");
        exec("{$sudo}systemctl disable opencode-web.service 2>/dev/null");
        exec("{$sudo}rm -f /etc/systemd/system/opencode-web.service 2>/dev/null");
        exec("{$sudo}rm -f /etc/systemd/system/opencode-web.service.d/override.conf 2>/dev/null");
        exec("{$sudo}rmdir /etc/systemd/system/opencode-web.service.d 2>/dev/null");
        exec("{$sudo}systemctl daemon-reload 2>/dev/null");
        $this->ensureDirExists($this->opencode_config_dir);
        file_put_contents($this->opencode_config_dir . '/opencode.jsonc', '{}');
        exec("{$sudo}rm -rf /root/.opencode 2>/dev/null");
        exec("{$sudo}rm -f /usr/local/bin/opencode 2>/dev/null");
        parent::uninstall();
    }

    function dbInstall($data) {
        $data = "opencode_messages: ID int(10) unsigned NOT NULL auto_increment\n";
        $data .= "opencode_messages: USER_ID int(10) NOT NULL DEFAULT '0'\n";
        $data .= "opencode_messages: ROLE varchar(50) NOT NULL DEFAULT ''\n";
        $data .= "opencode_messages: MESSAGE text\n";
        $data .= "opencode_messages: ADDED datetime\n";
        parent::dbInstall($data);
    }
}

