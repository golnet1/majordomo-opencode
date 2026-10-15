<?php

class opencode extends module {

    var $opencode_bin;
    var $opencode_db;
    var $opencode_config_dir;
    var $api_base = 'http://127.0.0.1:4096';
    var $api_user = 'opencode';
    var $api_pass = 'opencode';
    var $last_error = '';
    var $is_v2_cache = null;
    var $mcp_python_cache = null;

    function __construct() {
        $this->name = 'opencode';
        $this->loadLanguage();
        $this->title = LANG_OPENCODE_TITLE;
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
        $this->getConfig();

        $this->opencode_bin = $this->findOpencodeBinary();
        $this->opencode_db = '/var/www/.local/share/opencode/opencode.db';
        $this->opencode_config_dir = '/var/www/.config/opencode';
    }

    function getApiPort() {
        return $this->config['OC_PORT'] ? (int)$this->config['OC_PORT'] : 4096;
    }

    /**
     * Установлен ли opencode локально (либо настроен удалённый сервер). Пока
     * бинарника нет, модуль не должен никуда подключаться: иначе в лог каждые
     * несколько секунд сыпется "Failed to connect to 127.0.0.1 port 4096".
     */
    function isOpencodeInstalled() {
        if (!empty($this->config['OC_REMOTE_ENABLED']) && !empty($this->config['OC_REMOTE_URL'])) {
            return true;
        }
        if (!empty($this->opencode_bin) && file_exists($this->opencode_bin)) {
            return true;
        }
        foreach ($this->getBinaryCandidates() as $path) {
            if (file_exists($path)) return true;
        }
        return false;
    }

    function isRecentlyInstalled($grace = 120) {
        if (empty($this->config['OC_INSTALL_TS'])) return false;
        if ((time() - (int)$this->config['OC_INSTALL_TS']) <= $grace) return true;
        return false;
    }

    function restRequest($method, $path, $body = null, $timeout = 0) {
        // OpenCode не установлен — не пытаемся подключиться к API вообще.
        if (!$this->isOpencodeInstalled()) {
            $this->last_error = LANG_OPENCODE_NOT_INSTALLED;
            return null;
        }
        $path = $this->apiPath($path);
        if (!empty($this->config['OC_REMOTE_ENABLED']) && !empty($this->config['OC_REMOTE_URL'])) {
            $url = rtrim($this->config['OC_REMOTE_URL'], '/') . $path;
        } else {
            $port = $this->getApiPort();
            $url = "http://127.0.0.1:{$port}{$path}";
        }
        // OpenCode v2 требует Basic-авторизацию строго с логином 'opencode'
        // (переменную OPENCODE_SERVER_USERNAME сервер не читает, пароль берёт из
        // OPENCODE_SERVER_PASSWORD). Для v1 логин берём из настроек.
        if ($this->isOpencodeV2()) {
            $user = 'opencode';
        } else {
            $user = !empty($this->config['OC_AUTH_LOGIN']) ? $this->config['OC_AUTH_LOGIN'] : 'opencode';
        }
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
            // Не логируем спам ошибок подключения в течение короткого окна после
            // свежей установки: сервис может ещё не подняться.
            if (!$this->isRecentlyInstalled(120)) {
                DebMes("Opencode REST error: {$error}", 'opencode');
            }
            $this->last_error = LANG_OPENCODE_CONNECT_ERROR . ': ' . $error;
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

    function getLastError() {
        return $this->last_error;
    }

    function getOpencodeVersion() {
        return $this->binaryVersion($this->opencode_bin);
    }

    /**
     * Версия конкретного бинарника opencode ('' если неизвестна/недоступна).
     */
    /**
     * Запускает бинарник opencode с временным writable HOME/XDG.
     *
     * Бинарник v2 при старте пишет лог в ~/.local/share/opencode. Сервис
     * запущен от root и создаёт там root-овые файлы, поэтому из-под
     * веб-пользователя (www-data) `opencode --version` падает с EACCES и
     * вернуть версию не удаётся (модуль принимает v2 за v1). Подменяем HOME
     * на временный каталог — версия и подкоманды видны из любого контекста.
     */
    function runBinaryProbe($path, $args, $want_stderr = false) {
        if (!is_string($path) || $path === '' || !file_exists($path)) return '';
        $redirect = $want_stderr ? ' 2>&1' : ' 2>/dev/null';
        $base = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
        $dir = rtrim($base, '/') . '/ocprobe-' . substr(md5(uniqid('', true)), 0, 10);
        if (!@mkdir($dir, 0777, true)) {
            return (string)shell_exec(escapeshellarg($path) . ' ' . $args . $redirect);
        }
        @chmod($dir, 0777);
        $env = 'HOME=' . escapeshellarg($dir)
             . ' XDG_DATA_HOME=' . escapeshellarg($dir . '/data')
             . ' XDG_STATE_HOME=' . escapeshellarg($dir . '/state')
             . ' XDG_CACHE_HOME=' . escapeshellarg($dir . '/cache')
             . ' XDG_CONFIG_HOME=' . escapeshellarg($dir . '/config');
        $raw = (string)shell_exec($env . ' ' . escapeshellarg($path) . ' ' . $args . $redirect);
        $this->removeDirRecursive($dir);
        return $raw;
    }

    function removeDirRecursive($dir) {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) $this->removeDirRecursive($p);
            else @unlink($p);
        }
        @rmdir($dir);
    }

    function binaryVersion($path) {
        if (!is_string($path) || $path === '' || !file_exists($path)) return '';
        // Запуск бинарника opencode стоит ~0.5 c (это большой bundled-бинарь),
        // а версия нужна на каждой отрисовке страницы модуля и в опросе статуса.
        // Кэшируем результат по сигнатуре файла (путь+размер+mtime): обновление
        // или замена бинарника меняет сигнатуру и автоматически сбрасывает кэш.
        $sig = $path . ':' . @filesize($path) . ':' . @filemtime($path);
        $cache_file = DIR_MODULES . $this->name . '/binver_cache.json';
        $cache = @json_decode((string)@file_get_contents($cache_file), true);
        if (is_array($cache) && isset($cache['sig'], $cache['v']) && $cache['sig'] === $sig) {
            return (string)$cache['v'];
        }
        // Сразу после установки/обновления бинарник может не успеть ответить.
        // Делаем несколько попыток, иначе v2 ошибочно принимается за v1 и в
        // systemd-юнит попадает команда 'web' вместо 'serve' (сервис падает).
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = trim($this->runBinaryProbe($path, '--version'));
            if (preg_match('/\d+\.\d+\.\d+/', $raw, $m)) {
                @file_put_contents($cache_file, json_encode(array('sig' => $sig, 'v' => $m[0])));
                @chmod($cache_file, 0666);
                return $m[0];
            }
            if ($attempt < 2) usleep(300000);
        }
        return '';
    }

    /**
     * Отдаёт ли бинарник подкоманду 'serve' (признак OpenCode v2). Используется,
     * когда версию получить не удалось: подкоманда сервиса важнее номера версии.
     */
    function binarySupportsServe($path) {
        if (!is_string($path) || $path === '' || !file_exists($path)) return false;
        $raw = $this->runBinaryProbe($path, '--help', true);
        return (bool)preg_match('/^\s*serve\b/m', $raw);
    }

    /**
     * Установлен OpenCode v2? В v2 подкоманда запуска сервера переименована:
     * `opencode web --port ...` -> `opencode serve --port ...`. Поэтому юнит
     * systemd собирается по-разному для v1 и v2.
     */
    function isOpencodeV2() {
        if ($this->is_v2_cache !== null) return $this->is_v2_cache;
        $version = $this->getOpencodeVersion();
        if ($version === '' && file_exists($this->opencode_bin)) {
            // Версию бинарник не отдал — определяем по наличию подкоманды serve.
            $version = $this->binarySupportsServe($this->opencode_bin) ? '2.0.0' : '';
        }
        if ($version === '') {
            $version = $this->getCurrentVersion();
        }
        if ($version === '') {
            $this->is_v2_cache = false;
            return false;
        }
        $this->is_v2_cache = version_compare($version, '2.0.0', '>=');
        return $this->is_v2_cache;
    }

    function serviceSubcommand() {
        return $this->isOpencodeV2() ? 'serve' : 'web';
    }

    /**
     * В v2 весь HTTP API живёт под префиксом /api (в v1 — в корне).
     * Абсолютные пути (http://...) и уже префиксованные не трогаем.
     */
    function apiPath($path) {
        if (!$this->isOpencodeV2()) return $path;
        if (strpos($path, '/api/') === 0 || strpos($path, 'http') === 0) return $path;
        return '/api' . $path;
    }

    /**
     * Полезная нагрузка ответа API. Ответы v2 обычно обёрнуты в конверт
     * {"data": ...} (иногда с дополнительными полями вроде location/cursor),
     * тогда как v1 отдаёт объект напрямую. Возвращаем вложенный data, если он
     * есть, иначе — тело как есть.
     */
    function apiData($result) {
        if (!is_array($result) || !array_key_exists('data', $result)) return null;
        $body = $result['data'];
        if (is_array($body) && array_key_exists('data', $body)) return $body['data'];
        return $body;
    }

    /**
     * Статусы MCP-серверов в едином виде: array(array('name' => ..., 'status' => ...)).
     *
     * В v2 ответ /mcp — это конверт {"location": {...}, "data": [{"name": ...,
     * "status": {"status": "connected"}}]}, в v1 — объект, где ключ — имя сервера.
     * Раньше конверт v2 попадал в шаблон как есть, и вместо серверов выводились
     * строки "location" и "data" с красными индикаторами.
     */
    function getMcpStatuses() {
        $result = $this->restRequest('GET', '/mcp');
        if (!$result || $result['code'] !== 200) return array();
        $data = $this->apiData($result);
        if (!is_array($data)) return array();
        $servers = array();
        foreach ($data as $key => $item) {
            $name = '';
            $status = '';
            if (is_array($item)) {
                if (isset($item['name'])) {
                    $name = $item['name'];
                } elseif (is_string($key)) {
                    $name = $key;
                }
                if (isset($item['status'])) {
                    $status = is_array($item['status'])
                        ? (isset($item['status']['status']) ? $item['status']['status'] : '')
                        : $item['status'];
                }
            } elseif (is_string($item) && is_string($key)) {
                $name = $key;
                $status = $item;
            }
            if ($name === '') continue;
            $servers[] = array('name' => (string)$name, 'status' => (string)$status);
        }
        return $servers;
    }

    /**
     * saveConfig() rewrites the whole serialized array from a snapshot taken by
     * getConfig(). Long-lived callers (the background worker, the status poll)
     * therefore overwrite whatever the admin saved in the meantime, which is how
     * the MCP server list kept disappearing. This re-reads the row and merges
     * only the given keys.
     */
    function updateConfig($changes) {
        $rec = SQLSelectOne("SELECT * FROM project_modules WHERE NAME = '" . $this->name . "'");
        $fresh = array();
        if (isset($rec['DATA']) && $rec['DATA']) {
            $fresh = unserialize($rec['DATA']);
        }
        if (!is_array($fresh)) $fresh = array();
        foreach ($changes as $key => $value) {
            if ($value === null) unset($fresh[$key]);
            else $fresh[$key] = $value;
        }
        $rec['DATA'] = serialize($fresh);
        SQLUpdate('project_modules', $rec);
        $this->config = $fresh;
    }

    function getCurrentVersion() {
        $cached = isset($this->config['OC_RUNNING_VERSION']) ? trim((string)$this->config['OC_RUNNING_VERSION']) : '';
        // Кэш версии действителен, только пока на месте сам бинарник. Если opencode
        // удалили (кнопкой «Удалить» или вручную из системы), старую версию не
        // показываем и сбрасываем устаревший кэш.
        if ($cached !== '' && file_exists($this->opencode_bin)) return $cached;
        $detected = $this->getOpencodeVersion();
        if ($detected !== '') {
            $this->updateConfig(array('OC_RUNNING_VERSION' => $detected));
        } elseif ($cached !== '') {
            $this->updateConfig(array('OC_RUNNING_VERSION' => null));
        }
        return $detected;
    }

    function getMinVersion() {
        return isset($this->config['OC_MIN_VERSION']) ? trim((string)$this->config['OC_MIN_VERSION']) : '';
    }

    function isVersionOutdated() {
        $min = $this->getMinVersion();
        if ($min === '') return false;
        $cur = $this->getCurrentVersion();
        if ($cur === '') return false;
        return version_compare($cur, $min) < 0;
    }

    /**
     * Последняя опубликованная версия OpenCode в npm — из того же источника,
     * откуда ставится бинарник. Ответ кэшируется в файле, чтобы не обращаться
     * к сети на каждом открытии модуля: с версией — 6 часов, без — 15 минут.
     */
    function getLatestOpencodeVersion() {
        $file = sys_get_temp_dir() . '/opencode-update.json';
        $data = @json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && isset($data['t'])) {
            $ttl = !empty($data['latest']) ? 21600 : 900;
            if ((time() - (int)$data['t']) < $ttl) {
                return isset($data['latest']) ? (string)$data['latest'] : '';
            }
        }
        $latest = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://registry.npmjs.org/opencode-ai/latest');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'MajorDoMo-opencode',
            ));
            $body = curl_exec($ch);
            curl_close($ch);
            if (is_string($body) && $body !== '') {
                $json = @json_decode($body, true);
                if (isset($json['version'])) $latest = (string)$json['version'];
            }
        }
        @file_put_contents($file, json_encode(array('t' => time(), 'latest' => $latest)));
        return $latest;
    }

    /** Есть ли в сети версия новее установленной. */
    function isUpdateAvailable($installed, $latest) {
        return ($installed !== '' && $latest !== '' && version_compare($latest, $installed, '>'));
    }

    /**
     * Последняя версия OpenCode 2. В npm-пакете opencode-ai публикуется только
     * 1.x (ветка 2.x идёт отдельным пакетом @opencode/cli), поэтому номер
     * версии 2.x берём из git-тегов основного репозитория на GitHub (v2.*).
     * Ответ кэшируется так же, как для 1.x.
     */
    function getLatestOpencodeV2() {
        $file = sys_get_temp_dir() . '/opencode-update-v2.json';
        $data = @json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && isset($data['t'])) {
            $ttl = !empty($data['latest']) ? 21600 : 900;
            if ((time() - (int)$data['t']) < $ttl) {
                return isset($data['latest']) ? (string)$data['latest'] : '';
            }
        }
        $latest = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.github.com/repos/anomalyco/opencode/git/matching-refs/tags/v2.');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'MajorDoMo-opencode',
                CURLOPT_HTTPHEADER => array('Accept: application/vnd.github+json'),
            ));
            $body = curl_exec($ch);
            curl_close($ch);
            $refs = @json_decode((string)$body, true);
            if (is_array($refs)) {
                foreach ($refs as $ref) {
                    if (!isset($ref['ref'])) continue;
                    $name = ltrim((string)$ref['ref'], 'refs/tags/');
                    $name = ltrim($name, 'v');
                    if (preg_match('/^2\.\d+/', $name) && ($latest === '' || version_compare($name, $latest, '>'))) {
                        $latest = $name;
                    }
                }
            }
        }
        @file_put_contents($file, json_encode(array('t' => time(), 'latest' => $latest)));
        return $latest;
    }

    /**
     * Собирает в $out всю информацию о версиях и о том, что показывает кнопка
     * обновления: пока есть более новая 1.x — это обычное обновление; когда
     * 1.x уже максимальная, та же кнопка предлагает переход на OpenCode 2.
     */
    function applyOpencodeVersionInfo(&$out, $binary_ok) {
        $cur = $this->getCurrentVersion();
        $min = $this->getMinVersion();
        $latest = $this->getLatestOpencodeVersion();
        $latest_v2 = $this->getLatestOpencodeV2();
        $outdated = $this->isVersionOutdated();
        $update_available = $this->isUpdateAvailable($cur, $latest);
        $v2_available = ($latest_v2 !== '' && $cur !== '' && version_compare($latest_v2, $cur, '>'));
        $offer_major = (!$outdated && !$update_available && $v2_available);

        $out['OC_VERSION_CURRENT'] = $cur !== '' ? $cur : '?';
        $out['OC_VERSION_MIN'] = $min !== '' ? $min : '?';
        $out['OC_VERSION_LATEST'] = $latest !== '' ? $latest : '?';
        $out['OC_VERSION_V2'] = $latest_v2;
        $out['OC_V2_AVAILABLE'] = $v2_available ? '1' : '0';
        $out['OC_VERSION_OUTDATED'] = $outdated ? '1' : '0';
        $out['OC_UPDATE_AVAILABLE'] = $update_available ? '1' : '0';
        $out['OC_VERSION_BADGE_COLOR'] = $outdated ? '#d9534f' : '#5cb85c';
        $out['OC_VERSION_BADGE_VISIBLE'] = $outdated ? '' : 'style="display:none"';
        $out['OC_VERSION_ROW_VISIBLE'] = $cur !== '' ? '' : 'style="display:none"';

        $hint_visible = ($update_available || $offer_major);
        $out['OC_UPDATE_HINT_VISIBLE'] = $hint_visible ? '' : 'style="display:none"';
        $out['OC_UPDATE_HINT_TEXT'] = $offer_major
            ? LANG_OPENCODE_V2_AVAILABLE . ' ' . $latest_v2
            : (LANG_OPENCODE_VERSION_AVAILABLE . ' ' . $latest);

        $out['OC_UPGRADE_BTN_LABEL'] = $offer_major ? LANG_OPENCODE_UPGRADE_V2 : LANG_OPENCODE_UPGRADE;
        $out['OC_UPGRADE_VIEW_MODE'] = $offer_major ? 'upgrade_opencode_major' : 'upgrade_opencode';
        $out['OC_UPGRADE_BTN_TITLE'] = $offer_major ? LANG_OPENCODE_UPGRADE_V2_HELP : LANG_OPENCODE_UPGRADE_BTN_HELP;
        $out['OC_UPGRADE_BTN_VISIBLE'] = ($binary_ok && ($outdated || $update_available || $offer_major)) ? '' : 'style="display:none"';
    }

    /**
     * Журнал модуля — как «журнал установки» в Piper TTS. Берётся самый свежий
     * файл cms/debmes/<дата>/opencode.log (туда пишутся шаги установки,
     * обновления и работы модуля), последние $max_bytes байт.
     */
    function getJournalLog($max_bytes = 65536) {
        $base = rtrim(defined('ROOT') ? ROOT : '/var/www/html', '/') . '/cms/debmes';
        if (!is_dir($base)) return '';
        $files = array();
        $pattern1 = $base . '/*/opencode.log';
        $found = glob($pattern1);
        if ($found) $files = array_merge($files, $found);
        $alt = $base . '/opencode.log';
        if (file_exists($alt)) $files[] = $alt;
        if (empty($files) && function_exists('shell_exec')) {
            $cmd = "find " . escapeshellarg($base) . " -name 'opencode*.log' 2>/dev/null | head -50";
            $out = trim(shell_exec($cmd));
            if ($out) {
                foreach (preg_split('/\r?\n/', $out) as $f) {
                    $f = trim($f);
                    if ($f && is_file($f)) $files[] = $f;
                }
            }
        }
        $files = array_values(array_unique($files));
        if (empty($files)) return '';
        $latest = '';
        $latest_mtime = -1;
        foreach ($files as $f) {
            $mt = @filemtime($f);
            if ($mt !== false && $mt > $latest_mtime) { $latest_mtime = $mt; $latest = $f; }
        }
        if (!$latest) return '';
        $size = @filesize($latest);
        if ($size === false) return '';
        $offset = ($size > $max_bytes) ? ($size - $max_bytes) : 0;
        $text = @file_get_contents($latest, false, null, $offset, $max_bytes);
        if ($text === false) return '';
        // Читаем с середины файла — первая строка обычно обрезана, убираем её.
        if ($offset > 0) {
            $nl = strpos($text, "\n");
            if ($nl !== false) $text = substr($text, $nl + 1);
        }
        return $this->cleanJournalText($text);
    }

    /**
     * Чистит журнал от управляющих последовательностей терминала, которые
     * оставляет установщик opencode (спиннер прогресса, перерисовка строки).
     * Без этого «Журнал» в браузере выглядит как мусор из "[999D[J".
     */
    function cleanJournalText($text) {
        if ($text === '') return '';
        // Журнал может содержать не-UTF-8 байты, поэтому /u не используем —
        // иначе preg_replace вернёт null и журнал пропадёт целиком.
        $replace = function ($pattern, $replacement, $subject) {
            $res = @preg_replace($pattern, $replacement, $subject);
            return $res === null ? $subject : $res;
        };
        // Возврат каретки и перерисовка кадра спиннера — это перевод строки.
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        $text = $replace('/(?:\x1b)?\[999D/', "\n", $text);
        // ANSI/CSI-последовательности — со ESC и без него.
        $text = $replace('/\x1b\][^\x07]*(?:\x07|\x1b\\\\)?/', '', $text);
        $text = $replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
        $text = $replace('/\x1b[@-Z\\\\-_]/', '', $text);
        $text = $replace('/\[[0-9;?]*[ABCDHJKMSTfhlmnsuG]/', '', $text);
        // Символы-спиннеры прогресса: Braille (U+2800..U+28FF) и кружки
        // (U+25D0..U+25D3) — задаём байтовыми последовательностями UTF-8.
        $text = $replace('/\xE2[\xA0-\xA3][\x80-\xBF]|\xE2\x97[\x90-\x93]/', '', $text);
        // Схлопываем подряд идущие одинаковые строки (кадры спиннера).
        $lines = explode("\n", $text);
        $out = array();
        $prev = null;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line !== '' && $line === $prev) continue;
            $out[] = $line;
            $prev = $line;
        }
        return trim(implode("\n", $out));
    }

    function noteMinVersion($min) {
        $min = trim((string)$min);
        if ($min === '') return;
        $cur = $this->getMinVersion();
        if ($cur !== '' && version_compare($min, $cur, '<=')) return;
        $this->updateConfig(array('OC_MIN_VERSION' => $min));
        DebMes("Opencode: minimum required version is now {$min}", 'opencode');
    }

    function noteRunningVersion($version) {
        $version = trim((string)$version);
        if ($version === '') return;
        if (isset($this->config['OC_RUNNING_VERSION']) && $this->config['OC_RUNNING_VERSION'] === $version) return;
        $this->updateConfig(array('OC_RUNNING_VERSION' => $version));
    }

    function extractApiError($result) {
        $err = array();
        if (!empty($result['data']['info']['error'])) $err = $result['data']['info']['error'];
        elseif (!empty($result['data']['error'])) $err = $result['data']['error'];
        if (is_string($err)) {
            $err = array('message' => $err);
        }
        if (!$err) return '';
        if (isset($err['data']) && is_array($err['data'])) $err = $err['data'];
        if (!is_array($err)) return '';

        $msg = '';
        if (isset($err['message'])) $msg = $err['message'];
        elseif (isset($err['error']['message'])) $msg = $err['error']['message'];
        $msg = trim($this->stripANSI((string)$msg));
        if ($msg === '') return '';

        $needs_upgrade = false;
        if (isset($err['statusCode']) && (int)$err['statusCode'] === 426) $needs_upgrade = true;
        if (preg_match('/OpenCode (\d+\.\d+\.\d+) or newer is required/i', $msg, $m)) {
            $needs_upgrade = true;
            $this->noteMinVersion($m[1]);
        }

        if ($needs_upgrade) {
            $min = $this->getMinVersion();
            $cur = $this->getCurrentVersion();
            $this->last_error = LANG_OPENCODE_UPGRADE_REQUIRED . ' ' . $min
                . ($cur !== '' ? ' (' . LANG_OPENCODE_VERSION_INSTALLED . ' ' . $cur . ')' : '') . '.';
        } else {
            $this->last_error = $msg;
        }
        return $msg;
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
        if (!isset($out['TAB'])) $out['TAB'] = $this->tab;

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
            DebMes("Opencode: no response generated" . ($this->last_error ? ': ' . $this->last_error : ''), 'opencode');
        }
    }

    /**
     * Системный промпт: настройка модуля плюс ограничения безопасности.
     */
    function buildSystemPrompt() {
        $prompt = isset($this->config['OC_SYSTEM_PROMPT']) ? trim($this->config['OC_SYSTEM_PROMPT']) : '';
        if (empty($this->config['OC_FULL_ACCESS'])) {
            $restrictions = "\n\n" . LANG_OPENCODE_RESTRICTION_HEADER . "\n"
                . LANG_OPENCODE_RESTRICTION_FILE_OPS . "\n"
                . LANG_OPENCODE_RESTRICTION_SYSTEM_CMDS . "\n"
                . LANG_OPENCODE_RESTRICTION_SERVICE_CMDS . "\n"
                . LANG_OPENCODE_RESTRICTION_KILL_CMDS . "\n"
                . LANG_OPENCODE_RESTRICTION_CONFIG . "\n"
                . LANG_OPENCODE_RESTRICTION_PACKAGES . "\n"
                . LANG_OPENCODE_RESTRICTION_CORE . "\n";
            $prompt .= $restrictions;
        }
        // v1 принимает system вместе с каждым сообщением (см. buildMessageBody),
        // v2 — только через параметр system агента в opencode.jsonc
        // (см. writeOpencodeConfig).
        return trim($prompt);
    }

    function buildMessageBody($message) {
        if ($this->isOpencodeV2()) {
            return array('text' => $message);
        }
        $body = array(
            'parts' => array(
                array('type' => 'text', 'text' => $message)
            )
        );
        $prompt = $this->buildSystemPrompt();
        if ($prompt !== '') {
            $body['system'] = $prompt;
        }
        return $body;
    }

    function buildSessionBody() {
        $body = new stdClass();
        if (!$this->isOpencodeV2()) {
            $prompt = $this->buildSystemPrompt();
            if ($prompt !== '') {
                $body->system = $prompt;
            }
        }
        return $body;
    }

    function processWithOpencode($message, $timeout = 0) {
        $this->getConfig();
        $this->last_error = '';
        // Не установлен opencode — не шлём запрос и не пишем "server error".
        if (!$this->isOpencodeInstalled()) {
            $this->last_error = LANG_OPENCODE_NOT_INSTALLED;
            return '';
        }
        $session_id = isset($this->config['OC_SESSION_ID']) ? $this->config['OC_SESSION_ID'] : '';

        if ($this->isOpencodeV2()) {
            return $this->processWithOpencodeV2($message, $timeout, $session_id);
        }

        if ($session_id) {
            $body = $this->buildMessageBody($message);
            $result = $this->restRequest('POST', "/session/{$session_id}/message", $body, $timeout);
            if ($result && $result['code'] === 200) {
                $this->saveTokensFromResponse($result);
                if ($this->extractApiError($result) !== '') {
                    DebMes("Opencode: reused session={$session_id} returned API error", 'opencode');
                    return '';
                }
                DebMes("Opencode: reused session={$session_id}", 'opencode');
                return $this->parseMessageResponse($result);
            }
            DebMes("Opencode: session expired or invalid, creating new one", 'opencode');
            $session_id = '';
        }

        $result = $this->restRequest('POST', '/session', $this->buildSessionBody(), $timeout);
        if (!$result || $result['code'] !== 200) {
            $this->last_error = LANG_OPENCODE_SERVER_ERROR . ' (code=' . ($result ? $result['code'] : 'null') . ')';
            DebMes("Opencode: failed to create session (code=" . ($result ? $result['code'] : 'null') . ")", 'opencode');
            return '';
        }
        if (!empty($result['data']['version'])) {
            $this->noteRunningVersion($result['data']['version']);
        }
        $this->saveTokensFromResponse($result);
        $session_id = isset($result['data']['id']) ? $result['data']['id'] : '';
        if (!$session_id) {
            $this->last_error = LANG_OPENCODE_SERVER_ERROR . ' (no session id)';
            DebMes("Opencode: no session id in response", 'opencode');
            return '';
        }
        DebMes("Opencode: created session={$session_id}", 'opencode');

        $this->updateConfig(array('OC_SESSION_ID' => $session_id));

        $body = $this->buildMessageBody($message);
        $result = $this->restRequest('POST', "/session/{$session_id}/message", $body, $timeout);
        if (!$result || $result['code'] !== 200) {
            $this->last_error = LANG_OPENCODE_SERVER_ERROR . ' (code=' . ($result ? $result['code'] : 'null') . ')';
            DebMes("Opencode: failed to send message to new session", 'opencode');
            return '';
        }
        $this->saveTokensFromResponse($result);
        if ($this->extractApiError($result) !== '') return '';

        return $this->parseMessageResponse($result);
    }

    function cleanAssistantText($response) {
        $re_think = 'think(?:ing)?|response|reasoning';
        $response = preg_replace('#^.*</?\s*(?:' . $re_think . ')\s*>#is', '', $response);
        $response = trim(preg_replace('#</?\s*(?:' . $re_think . ')\s*>#i', '', $response));
        DebMes("Opencode API response: " . ($response ? substr($response, 0, 200) : 'EMPTY'), 'opencode');
        return $response;
    }

    function parseAssistantMessageV2($msg) {
        if (empty($msg['content']) || !is_array($msg['content'])) return '';
        $text = array();
        foreach ($msg['content'] as $c) {
            if (is_array($c) && isset($c['type']) && $c['type'] === 'text') {
                $text[] = isset($c['text']) ? $c['text'] : '';
            }
        }
        return $this->cleanAssistantText(trim(implode("\n", $text)));
    }

    /**
     * Путь к списку сообщений ассистента v2. Явно задаём type=assistant и
     * order=desc (сначала самые свежие), чтобы не зависеть от порядка по
     * умолчанию — так первый элемент всегда последний ответ агента.
     */
    function assistantMessagesPathV2($session_id) {
        return "/session/{$session_id}/message?type=assistant&order=desc&limit=50";
    }

    /**
     * Текст сообщения ассистента v2 без очистки и без записи в журнал —
     * используется для сравнения ответов при опросе.
     */
    function assistantTextV2($msg) {
        if (empty($msg['content']) || !is_array($msg['content'])) return '';
        $text = array();
        foreach ($msg['content'] as $c) {
            if (is_array($c) && isset($c['type']) && $c['type'] === 'text') {
                $text[] = isset($c['text']) ? $c['text'] : '';
            }
        }
        return trim(implode("\n", $text));
    }

    /**
     * Снимок состояния сообщений сессии до отправки промпта: id всех сообщений
     * и текст самого свежего ответа ассистента. Нужен, чтобы после генерации
     * отличить новый ответ от предыдущих (сессия переиспользуется).
     */
    function getSessionStateV2($session_id) {
        $state = array('ids' => array(), 'has_ids' => false, 'newest_text' => '');
        $result = $this->restRequest('GET', $this->assistantMessagesPathV2($session_id), null, 30);
        $messages = $this->apiData($result);
        if (is_array($messages)) {
            foreach ($messages as $m) {
                if (!is_array($m)) continue;
                if (!empty($m['id'])) {
                    $state['ids'][$m['id']] = true;
                    $state['has_ids'] = true;
                }
                if ($state['newest_text'] === '' && (isset($m['type']) ? $m['type'] : '') === 'assistant') {
                    $state['newest_text'] = $this->assistantTextV2($m);
                }
            }
        }
        return $state;
    }

    /**
     * Ждёт появления нового ответа ассистента. Сообщения приходят от новых к
     * старым, поэтому проверяем первый (самый свежий) ответ ассистента.
     */
    function getAssistantResponseV2($session_id, $state, $timeout = 0) {
        $timeout = $timeout > 0 ? $timeout : 120;
        $start = time();
        do {
            $result = $this->restRequest('GET', $this->assistantMessagesPathV2($session_id), null, 30);
            $messages = ($result && (int)$result['code'] === 200) ? $this->apiData($result) : null;
            if (is_array($messages)) {
                foreach ($messages as $m) {
                    if (!is_array($m) || (isset($m['type']) ? $m['type'] : '') !== 'assistant') continue;
                    if ($state['has_ids']) {
                        $id = isset($m['id']) ? $m['id'] : '';
                        if ($id !== '' && !isset($state['ids'][$id])) return $m;
                    } else {
                        $text = $this->assistantTextV2($m);
                        if ($text !== '' && $text !== $state['newest_text']) return $m;
                    }
                    break;
                }
            }
            if (time() - $start >= $timeout) break;
            sleep(1);
        } while (true);
        return null;
    }

    /**
     * Модель для сессии v2 ("provider/model") из настроек модуля.
     */
    function buildModelRef() {
        if (!empty($this->config['OC_PROVIDER_ENDPOINT'])) {
            $model = !empty($this->config['OC_PROVIDER_MODEL']) ? $this->config['OC_PROVIDER_MODEL'] : 'default';
            $full = 'custom/' . $model;
        } else {
            $full = !empty($this->config['OC_MODEL']) ? $this->config['OC_MODEL'] : 'opencode/big-pickle';
        }
        if (strpos($full, '/') === false) return null;
        list($provider, $id) = explode('/', $full, 2);
        if ($provider === '' || $id === '') return null;
        return array('id' => $id, 'providerID' => $provider);
    }

    /**
     * Отправляет сообщение в сессию v2 и возвращает текст ответа.
     * null — сессию нужно пересоздать; '' — ошибка (текст в last_error).
     */
    function sendPromptV2($session_id, $message, $timeout = 0) {
        $state = $this->getSessionStateV2($session_id);
        $result = $this->restRequest('POST', "/session/{$session_id}/prompt", array('text' => $message), min($timeout, 60));
        if (!$result || !in_array((int)$result['code'], array(200, 201, 202, 204), true)) {
            DebMes("Opencode v2: prompt failed (code=" . ($result ? $result['code'] : 'null') . ")", 'opencode');
            return null;
        }
        // v2 обрабатывает prompt асинхронно — ждём завершения генерации.
        $this->restRequest('POST', "/experimental/session/{$session_id}/wait", new stdClass(), $timeout);

        $assistant = $this->getAssistantResponseV2($session_id, $state, $timeout);
        if (!$assistant) {
            $this->last_error = LANG_OPENCODE_SERVER_ERROR . ' (no response)';
            DebMes("Opencode v2: no assistant response for session={$session_id}", 'opencode');
            return '';
        }
        $this->saveTokensFromResponse(array('data' => $assistant));
        if ($this->extractApiError(array('data' => $assistant)) !== '') return '';
        return $this->parseAssistantMessageV2($assistant);
    }

    function processWithOpencodeV2($message, $timeout = 0, $session_id = '') {
        $this->getConfig();
        $this->last_error = '';
        $timeout = $timeout > 0 ? $timeout : ($this->config['OC_TIMEOUT'] ?: 120);
        if ($timeout > 300) $timeout = 300;

        if ($session_id) {
            $response = $this->sendPromptV2($session_id, $message, $timeout);
            if ($response !== null) {
                DebMes("Opencode v2: reused session={$session_id}", 'opencode');
                return $response;
            }
            DebMes("Opencode v2: session expired or invalid, creating new one", 'opencode');
            $session_id = '';
        }

        $session_body = new stdClass();
        $model = $this->buildModelRef();
        if ($model) $session_body->model = $model;
        // Агент задаёт системный промпт (agents.<id>.system в opencode.jsonc).
        if (!empty($this->config['OC_AGENT'])) $session_body->agent = $this->config['OC_AGENT'];
        $result = $this->restRequest('POST', '/session', $session_body, min($timeout, 60));
        if (!$result || ((int)$result['code'] !== 200 && (int)$result['code'] !== 201)) {
            $this->last_error = LANG_OPENCODE_SERVER_ERROR . ' (code=' . ($result ? $result['code'] : 'null') . ')';
            DebMes("Opencode v2: failed to create session (code=" . ($result ? $result['code'] : 'null') . ")", 'opencode');
            return '';
        }
        $session_id = '';
        if (($created = $this->apiData($result)) && !empty($created['id'])) {
            $session_id = $created['id'];
        }
        if (!$session_id) {
            $this->last_error = LANG_OPENCODE_SERVER_ERROR . ' (no session id)';
            DebMes("Opencode v2: no session id in response", 'opencode');
            return '';
        }
        DebMes("Opencode v2: created session={$session_id}", 'opencode');
        $this->updateConfig(array('OC_SESSION_ID' => $session_id));

        $response = $this->sendPromptV2($session_id, $message, $timeout);
        return $response === null ? '' : $response;
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
        return $this->cleanAssistantText(trim(implode("\n", $text_parts)));
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
            $changes = array('OC_SESSION_TOKENS' => $tokens);
            if ($cost !== null) {
                $changes['OC_SESSION_COST'] = $cost;
            }
            $this->updateConfig($changes);
        }
    }

    function usual(&$out) {
        $this->getConfig();
        $out['OC_SESSION_REUSE'] = $this->config['OC_SESSION_REUSE'];
        $out['OC_LANG_SEND'] = LANG_OPENCODE_SEND;
        $out['OC_LANG_TYPING'] = LANG_OPENCODE_TYPING;
        $out['OC_LANG_CLEAR_CONFIRM'] = LANG_OPENCODE_CLEAR_CONFIRM;
        $out['OC_LANG_HISTORY_CLEARED'] = LANG_OPENCODE_HISTORY_CLEARED;
        $out['OC_LANG_HOW_CAN_I_HELP'] = LANG_OPENCODE_HOW_CAN_I_HELP;
        $out['OC_LANG_CONNECT_ERROR'] = LANG_OPENCODE_CONNECT_ERROR;
        $out['OC_LANG_SERVER_ERROR'] = LANG_OPENCODE_SERVER_ERROR;
        $out['OC_LANG_UNKNOWN_ERROR'] = LANG_OPENCODE_UNKNOWN_ERROR;
        $out['OC_LANG_ERROR_PREFIX'] = LANG_OPENCODE_ERROR_PREFIX;
    }

    function checkDependencies(&$health_result = null, $short_timeout = false) {
        $checks = array();
        $bin_found = file_exists($this->opencode_bin);
        if (!$bin_found) {
            foreach ($this->getBinaryCandidates() as $p) {
                if (file_exists($p)) { $bin_found = true; $this->opencode_bin = $p; break; }
            }
        }
        $checks['opencode_binary'] = $bin_found ? 'ok' : 'missing';
        // В v2 нет /global/health — признак живости API и версию сервера даёт /api/info.
        $health_path = $this->isOpencodeV2() ? '/info' : '/global/health';
        if ($short_timeout) {
            $old_timeout = $this->config['OC_TIMEOUT'];
            $this->config['OC_TIMEOUT'] = 5;
            $health_result = $this->restRequest('GET', $health_path);
            $this->config['OC_TIMEOUT'] = $old_timeout;
        } else {
            $health_result = $this->restRequest('GET', $health_path);
        }
        if ($health_result && $health_result['code'] === 200 && !empty($health_result['data']['version'])) {
            $this->noteRunningVersion($health_result['data']['version']);
        }
        $checks['api'] = ($health_result && $health_result['code'] === 200) ? 'ok' : 'missing';
        return $checks;
    }

    function admin(&$out) {
        global $session;
        $this->getConfig();

        $is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
        if ($is_post) session_write_close();

        $health = null;
        if ($is_post) {
            $deps = $this->checkDependencies($health, true);
        } else {
            $deps = $this->checkDependencies($health);
        }

        if ($deps['opencode_binary'] !== 'ok' && empty($this->config['OC_REMOVED'])) {
            $last_attempt = (int)(isset($this->config['OC_INSTALL_ATTEMPT']) ? $this->config['OC_INSTALL_ATTEMPT'] : 0);
            if ((time() - $last_attempt) >= 3600) {
                $this->updateConfig(array('OC_INSTALL_ATTEMPT' => time()));
                $this->installOpencodeBinary();
                $deps = $this->checkDependencies($health, $is_post);
            }
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
        $out['DEPS_MODEL_LABEL'] = $api_ok ? $model_name : LANG_OPENCODE_NO_CONNECTION;

        // Версия, признак «устарел», наличие обновления в ветке 1.x и — когда
        // 1.x уже максимальная — предложение перейти на OpenCode 2 (та же кнопка).
        $this->applyOpencodeVersionInfo($out, $binary_ok);
        $out['UPGRADE_RESULT_VISIBLE'] = 'style="display:none"';
        $out['UPGRADE_RESULT'] = '';
        $out['UPGRADE_RESULT_CLASS'] = 'alert-info';

        $mcp_installed = is_dir(DIR_MODULES . 'mcp');
        $mcp_python_ok = $this->checkPythonPackage('mcp');

        DebMes("Opencode admin: vm=" . ($this->view_mode ?? 'NULL') . " mcp_inst=" . ($mcp_installed ? '1' : '0') . " mcp_py=" . ($mcp_python_ok ? '1' : '0') . " cfg=" . (is_array($this->config) ? 'array[' . count($this->config) . ']' : 'NOT_ARRAY'), 'opencode');

        if ($is_post && $this->view_mode == 'update_settings') {
            $this->getConfig();
            DebMes("Opencode: SAVING SETTINGS (post_vars=" . count($_POST) . " get_vars=" . count($_GET) . ")", 'opencode');
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
                $oc_majordomo_mcp_val = gr('oc_majordomo_mcp');
                if ($mcp_installed) {
                    $this->config['OC_MAJORDOMO_MCP'] = $oc_majordomo_mcp_val ? 1 : 0;
                }
                DebMes("Opencode: MCP checkbox raw='{$oc_majordomo_mcp_val}' mcp_installed=" . ($mcp_installed ? '1' : '0') . " saved=" . ($this->config['OC_MAJORDOMO_MCP'] ?? 'NULL'), 'opencode');
                $this->config['OC_SESSION_REUSE'] = gr('oc_session_reuse') ? 1 : 0;
                DebMes("Opencode: saveConfig pre-call MAX_HISTORY=" . ($this->config['OC_MAX_HISTORY'] ?? 'NULL') . " TIMEOUT=" . ($this->config['OC_TIMEOUT'] ?? 'NULL') . " BG_TO=" . ($this->config['OC_BG_TIMEOUT'] ?? 'NULL'), 'opencode');

                $prompt = trim($this->config['OC_SYSTEM_PROMPT']);
                if (!$prompt && !empty($this->config['OC_MAJORDOMO_MCP'])) {
                    $this->config['OC_SYSTEM_PROMPT'] = LANG_OPENCODE_SYSTEM_PROMPT_DEFAULT;
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
                $this->config['OC_AUTH_LOGIN'] = $this->isOpencodeV2() ? 'opencode' : gr('oc_auth_login');
                $this->config['OC_AUTH_PASSWORD'] = gr('oc_auth_password');
                $old_full_access = isset($this->config['OC_FULL_ACCESS']) ? $this->config['OC_FULL_ACCESS'] : 1;
                $this->config['OC_FULL_ACCESS'] = gr('oc_full_access') ? 1 : 0;
                if ($old_full_access != $this->config['OC_FULL_ACCESS']) {
                    unset($this->config['OC_SESSION_ID']);
                }
                $this->config['OC_MEMORY_LIMIT'] = gr('oc_memory_limit') ? 1 : 0;
                $this->config['OC_MEMORY_MAX'] = gr('oc_memory_max');
                $this->config['OC_MEMORY_HIGH'] = gr('oc_memory_high');
                $this->config['OC_REMOTE_ENABLED'] = gr('oc_remote_enabled') ? 1 : 0;
                $this->config['OC_REMOTE_URL'] = gr('oc_remote_url');
            }

            unset($this->config['OC_REMOVED']);
            $this->saveConfig();
            DebMes("Opencode: after saveConfig (memory) MAJORDOMO_MCP=" . ($this->config['OC_MAJORDOMO_MCP'] ?? 'NULL') . " MAX_HISTORY=" . ($this->config['OC_MAX_HISTORY'] ?? 'NULL') . " TIMEOUT=" . ($this->config['OC_TIMEOUT'] ?? 'NULL'), 'opencode');
            $verify = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='" . $this->name . "'");
            $verify_data = $verify['DATA'] ? unserialize($verify['DATA']) : array();
            DebMes("Opencode: after saveConfig (db) MAJORDOMO_MCP=" . ($verify_data['OC_MAJORDOMO_MCP'] ?? 'NULL') . " MAX_HISTORY=" . ($verify_data['OC_MAX_HISTORY'] ?? 'NULL') . " MODEL=" . ($verify_data['OC_MODEL'] ?? 'NULL'), 'opencode');
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
            $this->updateConfig(array('OC_SESSION_ID' => null, 'OC_SESSION_TOKENS' => null, 'OC_SESSION_COST' => null));
            $out['SESSION_CLEARED_VISIBLE'] = '';
        } else {
            $out['SESSION_CLEARED_VISIBLE'] = 'style="display:none"';
        }

        if ($this->view_mode == 'restart_opencode') {
            $this->syncServiceRestart();
            $this->updateConfig(array('OC_SESSION_ID' => null, 'OC_SESSION_TOKENS' => null, 'OC_SESSION_COST' => null, 'OC_RESTARTED' => 1));
        } else {
            unset($this->config['OC_RESTARTED']);
        }

        if ($this->view_mode == 'upgrade_opencode' || $this->view_mode == 'upgrade_opencode_major') {
            $is_major = ($this->view_mode == 'upgrade_opencode_major');
            $upgrade = $this->upgradeOpencode('', $is_major);
            $out['UPGRADE_RESULT'] = htmlspecialchars($upgrade['message'], ENT_QUOTES, 'UTF-8');
            $out['UPGRADE_RESULT_CLASS'] = $upgrade['ok'] ? 'alert-success' : 'alert-danger';
            $out['UPGRADE_RESULT_VISIBLE'] = '';
            $this->applyOpencodeVersionInfo($out, $binary_ok);
        }

        if ($this->view_mode == 'toggle_opencode') {
            if ($binary_ok) {
                $this->removeOpencode();
            } else {
                $this->updateConfig(array('OC_REMOVED' => null));
                $this->installOpencodeBinary();
                $this->writeOpencodeConfig();
            }
            // Возвращаемся на страницу модуля с той же вкладкой, а не на
            // главную страницу админки: установка/удаление — это действие
            // внутри модуля, и контекст терять не нужно.
            $back_tab = gr('tab');
            if (!in_array($back_tab, array('settings', 'server', 'chat', 'web', 'help'), true)) {
                $back_tab = 'server';
            }
            $back_url = '?action=opencode&md=opencode&tab=' . urlencode($back_tab);
            echo '<html><head><meta http-equiv="refresh" content="0;url=' . $back_url . '"></head><body></body></html>';
            exit;
        }

        $out['OC_MODEL'] = $this->config['OC_MODEL'] ? $this->config['OC_MODEL'] : 'opencode/big-pickle';
        $out['OC_MCP_PYTHON_OK'] = $mcp_python_ok ? '1' : '0';
        $out['OC_AGENT'] = $this->config['OC_AGENT'] ? $this->config['OC_AGENT'] : 'build';

        $system_prompt = isset($this->config['OC_SYSTEM_PROMPT']) ? $this->config['OC_SYSTEM_PROMPT'] : '';
        if (!$system_prompt) {
            $system_prompt = LANG_OPENCODE_SYSTEM_PROMPT_DEFAULT;
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
        $out['OC_IS_V2'] = $this->isOpencodeV2() ? '1' : '0';
        $out['OC_AUTH_LOGIN'] = $this->isOpencodeV2() ? 'opencode' : ($this->config['OC_AUTH_LOGIN'] ?: '');
        $out['OC_AUTH_PASSWORD'] = $this->config['OC_AUTH_PASSWORD'] ?: '';

        $out['OC_MEMORY_LIMIT'] = isset($this->config['OC_MEMORY_LIMIT']) ? $this->config['OC_MEMORY_LIMIT'] : '0';
        $out['OC_MEMORY_MAX'] = $this->config['OC_MEMORY_MAX'] ?: '768M';
        $out['OC_MEMORY_HIGH'] = $this->config['OC_MEMORY_HIGH'] ?: '512M';
        $out['OC_REMOTE_ENABLED'] = isset($this->config['OC_REMOTE_ENABLED']) ? $this->config['OC_REMOTE_ENABLED'] : '0';
        $out['OC_REMOTE_URL'] = $this->config['OC_REMOTE_URL'] ?: '';
        $out['OC_REMOTE_VISIBLE'] = (!empty($this->config['OC_REMOTE_ENABLED'])) ? '' : 'style="display:none"';

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
        $out['OC_AUTH_ENABLED'] = $auth_enabled ? '1' : '0';
        $out['TAB'] = $tab;
        $out['TAB_SETTINGS'] = ($tab == 'settings') ? '1' : '0';
        $out['TAB_SERVER'] = ($tab == 'server') ? '1' : '0';
        $out['TAB_CHAT'] = ($tab == 'chat') ? '1' : '0';
        $out['TAB_HELP'] = ($tab == 'help') ? '1' : '0';
        $out['TAB_WEB'] = ($tab == 'web') ? '1' : '0';
        $out['TAB_CHAT_VISIBLE'] = ($tab == 'chat') ? '' : 'style="display:none"';
        $out['TAB_WEB_VISIBLE'] = ($tab == 'web' && $auth_enabled && $api_ok) ? '' : 'style="display:none"';
        $out['TAB_WEB_NAV_VISIBLE'] = $auth_enabled && $api_ok ? '' : 'style="display:none"';
        $out['VERSION'] = '2.0.4';

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
        $api_url = "http://127.0.0.1:{$port}";
        if (!empty($this->config['OC_REMOTE_ENABLED']) && !empty($this->config['OC_REMOTE_URL'])) {
            $api_url = rtrim($this->config['OC_REMOTE_URL'], '/');
        }
        $out['OC_API_URL'] = $api_url;

        $out['OC_LANG_SEND'] = LANG_OPENCODE_SEND;
        $out['OC_LANG_TYPING'] = LANG_OPENCODE_TYPING;
        $out['OC_LANG_CLEAR_CONFIRM'] = LANG_OPENCODE_CLEAR_CONFIRM;
        $out['OC_LANG_HISTORY_CLEARED'] = LANG_OPENCODE_HISTORY_CLEARED;
        $out['OC_LANG_HOW_CAN_I_HELP'] = LANG_OPENCODE_HOW_CAN_I_HELP;
        $out['OC_LANG_CONNECT_ERROR'] = LANG_OPENCODE_CONNECT_ERROR;
        $out['OC_LANG_SERVER_ERROR'] = LANG_OPENCODE_SERVER_ERROR;
        $out['OC_LANG_UNKNOWN_ERROR'] = LANG_OPENCODE_UNKNOWN_ERROR;
        $out['OC_LANG_ERROR_PREFIX'] = LANG_OPENCODE_ERROR_PREFIX;
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

        $path = $this->isOpencodeV2() ? '/model' : '/provider';
        $result = $this->restRequest('GET', $path);
        if ($result && $result['code'] === 200) {
            if ($this->isOpencodeV2()) {
                $list = $this->apiData($result);
                if (!is_array($list)) $list = array();
                foreach ($list as $m) {
                    if (!is_array($m)) continue;
                    $pid = isset($m['providerID']) ? $m['providerID'] : '';
                    $mid = isset($m['modelID']) ? $m['modelID'] : (isset($m['id']) ? $m['id'] : '');
                    if ($pid && $mid) {
                        $models[] = $pid . '/' . $mid;
                    } elseif ($mid) {
                        $models[] = $mid;
                    }
                }
                sort($models);
            } else {
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
                        'tool_call' => true
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

        // В v2 системный промпт передаётся не сообщением (поле system в запросе
        // игнорируется), а параметром system агента в конфиге. В v1 промпт идёт
        // вместе с каждым сообщением, поэтому блок агента туда не пишем.
        if ($this->isOpencodeV2()) {
            $agent = !empty($this->config['OC_AGENT']) ? $this->config['OC_AGENT'] : 'build';
            $system_prompt = $this->buildSystemPrompt();
            if ($system_prompt !== '') {
                $config['agents'][$agent] = array('system' => $system_prompt);
            }
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

    function canSudo($max_age = 3600) {
        $ts = isset($this->config['OC_SUDO_CHECK_TS']) ? (int)$this->config['OC_SUDO_CHECK_TS'] : 0;
        $val = isset($this->config['OC_SUDO_OK']) ? (int)$this->config['OC_SUDO_OK'] : 0;
        if ($ts > 0 && (time() - $ts) < $max_age) return $val === 1;
        $sudo = $this->isRoot() ? '' : 'sudo ';
        exec($sudo . 'id 2>&1', $out, $rc);
        $ok = ($rc === 0);
        $this->updateConfig(array('OC_SUDO_CHECK_TS' => time(), 'OC_SUDO_OK' => $ok ? 1 : 0));
        return $ok;
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

    function systemdEnvValue($value) {
        return '"' . str_replace(array('\\', '"', "\n"), array('\\\\', '\\"', ''), (string)$value) . '"';
    }

    function sanitizeMemoryValue($value, $default) {
        $value = trim((string)$value);
        if ($value === '') return $default;
        if (!preg_match('/^\d+[KMGT]?$/', $value)) {
            DebMes("Opencode: rejected invalid memory value '{$value}', using {$default}", 'opencode');
            return $default;
        }
        return $value;
    }

    /**
     * Случайный пароль для локального HTTP API OpenCode.
     */
    function generateRandomPassword() {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (Exception $e) {
            }
        }
        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 8)), 0, 32);
    }

    /**
     * Пароль для HTTP API OpenCode. В v2 пароль обязателен всегда (даже когда
     * сервер слушает только 127.0.0.1). Если авторизация выключена и пароль не
     * задан, генерируем случайный и сохраняем его — вместо дефолтного
     * 'opencode'. При включённой авторизации пароль задаёт пользователь.
     */
    function ensureAuthPassword() {
        if (!empty($this->config['OC_AUTH_PASSWORD'])) {
            return $this->config['OC_AUTH_PASSWORD'];
        }
        if ($this->isOpencodeV2() && empty($this->config['OC_AUTH_ENABLED'])) {
            $generated = $this->generateRandomPassword();
            $this->updateConfig(array('OC_AUTH_PASSWORD' => $generated));
            DebMes("Opencode: сгенерирован случайный пароль для локального API OpenCode 2", 'opencode');
            return $generated;
        }
        return 'opencode';
    }

    function setupServiceDropin() {
        $hostname = !empty($this->config['OC_AUTH_ENABLED']) ? '0.0.0.0' : '127.0.0.1';
        $port = $this->getApiPort();
        $sub = $this->serviceSubcommand();
        $sudo = $this->isRoot() ? '' : 'sudo ';

        $service_file = '/etc/systemd/system/opencode-web.service';
        // Пишем основной юнит каждый раз, а не только при создании: подкоманда
        // сервиса меняется вместе с версией (v1 'web' -> v2 'serve'), и старый
        // юнит с 'web' ломает запуск на v2. Drop-in перекрывает ExecStart, но
        // базовый файл тоже должен быть актуальным.
        $unit = "[Unit]\n";
        $unit .= "Description=Opencode AI Service\n";
        $unit .= "After=network.target\n\n";
        $unit .= "[Service]\n";
        $unit .= "Type=simple\n";
        $unit .= "ExecStart=" . $this->opencode_bin . " {$sub} --port {$port} --hostname {$hostname}\n";
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

        $content = "[Service]\n";
        $content .= "Environment=\n";
        $content .= "Environment=HOME=/var/www\n";
        $content .= "WorkingDirectory=/var/www/html\n";
        $content .= "ExecStart=\n";
        // v2 всегда требует пароль для HTTP API (даже слушая только 127.0.0.1),
        // иначе любой запрос получает 401. Поэтому для v2 выставляем его всегда.
        if (!empty($this->config['OC_AUTH_ENABLED']) || $this->isOpencodeV2()) {
            // В v2 логин жёстко 'opencode' (сервер читает только пароль).
            $login = $this->isOpencodeV2() ? 'opencode' : (!empty($this->config['OC_AUTH_LOGIN']) ? $this->config['OC_AUTH_LOGIN'] : 'opencode');
            $password = $this->ensureAuthPassword();
            $content .= "Environment=OPENCODE_SERVER_USERNAME=" . $this->systemdEnvValue($login) . "\n";
            $content .= "Environment=OPENCODE_SERVER_PASSWORD=" . $this->systemdEnvValue($password) . "\n";
        }
        $content .= "ExecStart=" . $this->opencode_bin . " {$sub} --port {$port} --hostname {$hostname}\n";
        if (!empty($this->config['OC_MEMORY_LIMIT'])) {
            $mem_max = $this->sanitizeMemoryValue($this->config['OC_MEMORY_MAX'], '768M');
            $mem_high = $this->sanitizeMemoryValue($this->config['OC_MEMORY_HIGH'], '512M');
            $content .= "MemoryMax={$mem_max}\nMemoryHigh={$mem_high}\n";
        }
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

        // Сервис запущен от root и создаёт в state-каталоге root-овые файлы,
        // из-за чего веб-пользователь (www-data) не может запустить бинарник
        // (`opencode --version` -> EACCES). Возвращаем владельца веб-пользователю,
        // иначе модуль не определит версию и будет считать v2 за v1.
        $web_user = 'www-data';
        exec("{$sudo}chown -R {$web_user}:{$web_user} /var/www/.local/share/opencode /var/www/.config/opencode 2>&1", $out, $rc);
        if ($rc !== 0) {
            DebMes("Opencode: chown state dirs failed (rc={$rc}): " . implode(' ', $out), 'opencode');
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

    function getBinaryCandidates() {
        $candidates = array('/usr/local/bin/opencode', '/usr/bin/opencode');
        // Установщик opencode кладёт бинарник в $HOME/.opencode/bin — модуль
        // (www-data, HOME=/var/www) должен его тоже видеть, иначе после
        // перехода на v2 он не найдёт новый бинарник.
        $home = getenv('HOME');
        if ($home) $candidates[] = rtrim($home, '/') . '/.opencode/bin/opencode';
        $candidates[] = '/var/www/.opencode/bin/opencode';
        return array_values(array_unique($candidates));
    }

    function findOpencodeBinary() {
        $candidates = array();
        $bin = trim(exec('command -v opencode 2>/dev/null'));
        if ($bin && file_exists($bin)) $candidates[] = $bin;
        foreach ($this->getBinaryCandidates() as $p) {
            if (file_exists($p) && !in_array($p, $candidates)) $candidates[] = $p;
        }
        if (empty($candidates)) return '/usr/local/bin/opencode';
        if (count($candidates) === 1) return $candidates[0];
        // Несколько бинарей (например, оставшийся v1 и только что
        // установленный v2): берём самый свежий, чтобы модуль не цеплялся
        // за старый бинарник после обновления.
        $best = $candidates[0];
        $best_ver = $this->binaryVersion($best);
        for ($i = 1; $i < count($candidates); $i++) {
            $v = $this->binaryVersion($candidates[$i]);
            if ($v !== '' && ($best_ver === '' || version_compare($v, $best_ver, '>'))) {
                $best = $candidates[$i];
                $best_ver = $v;
            }
        }
        return $best;
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
        if (!preg_match_all('/\[EXEC:([^\]]+)\]/i', $text, $matches)) return;
        if (empty($this->config['OC_FULL_ACCESS'])) {
            DebMes("Opencode: [EXEC:] ignored, full access is disabled: " . substr(trim($matches[1][0]), 0, 100), 'opencode');
            return;
        }
        foreach ($matches[1] as $cmd) {
            $this->executeMajordomoCommand($cmd);
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
        $max_history = isset($this->config['OC_MAX_HISTORY']) ? (int)$this->config['OC_MAX_HISTORY'] : 50;
        if ($max_history < 1) return 0;
        if ($max_history > 100000) $max_history = 100000;

        $total_rec = SQLSelectOne("SELECT COUNT(*) AS CNT FROM opencode_messages");
        $total = isset($total_rec['CNT']) ? (int)$total_rec['CNT'] : 0;
        $excess = $total - $max_history;
        if ($excess <= 0) return 0;

        $batch = ($excess > 1000) ? 1000 : $excess;
        $old_ids = SQLSelect("SELECT ID FROM opencode_messages ORDER BY ID ASC LIMIT {$batch}");
        if (!$old_ids) return 0;

        $id_list = array();
        foreach ($old_ids as $rec) {
            $id_list[] = (int)$rec['ID'];
        }
        SQLExec("DELETE FROM opencode_messages WHERE ID IN (" . implode(',', $id_list) . ")");
        DebMes("Opencode: pruned " . count($id_list) . " old messages (keep {$max_history})", 'opencode');
        return count($id_list);
    }

    function install($parent_name = '') {
        $arch = trim(shell_exec('uname -m'));
        $is_64bit = !preg_match('/^(i[3456]86|armv[567]l)$/', $arch);
        $install_web = $is_64bit;

        if (!$is_64bit) {
            DebMes("Opencode install: 32-bit arch ({$arch}), skipping opencode web", 'opencode');
        }

        parent::install($parent_name);
        subscribeToEvent($this->name, 'COMMAND', '', 50);
        $tmpdir = '/var/www/tmp';
        if (!is_dir($tmpdir)) {
            @mkdir($tmpdir, 0755, true);
            @chown($tmpdir, 'www-data');
            @chgrp($tmpdir, 'www-data');
        }

        if (!$install_web) {
            DebMes("Opencode install: module-only mode, skipping binary and service setup", 'opencode');
            $this->getConfig();
            return true;
        }

        if (!file_exists($this->opencode_bin)) {
            foreach ($this->getBinaryCandidates() as $p) {
                if (file_exists($p)) { $this->opencode_bin = $p; break; }
            }
        }
        if (!file_exists($this->opencode_bin)) {
            $this->installOpencodeBinary();
        }
        $this->installPythonDeps();
        $service_active = trim(shell_exec('systemctl is-active opencode-web.service 2>/dev/null'));
        if ($service_active !== 'active') {
            $this->setupServiceDropin();
            $this->syncServiceRestart();
        }

        $this->getConfig();
        $config_file = $this->opencode_config_dir . '/opencode.jsonc';
        if (!file_exists($config_file)) {
            $this->updateConfig(array('OC_MCP_SERVERS' => json_encode(array(
                array('name' => 'music', 'type' => 'local', 'command' => 'python3', 'args' => DIR_MODULES . 'opencode/mcp/mcp_music.py', 'enabled' => true)
            ), JSON_UNESCAPED_SLASHES)));
            $this->writeOpencodeConfig();
        }
    }

    function installPythonDeps() {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        $errors = array();

        if ($this->checkPythonPackage('mcp')) {
            DebMes("Opencode: mcp already available in system python, skipping pip install", 'opencode');
        } else {
            $pip_cmd = trim(shell_exec('which pip3 2>/dev/null')) ?: trim(shell_exec('which pip 2>/dev/null')) ?: trim(shell_exec('command -v pip3 2>/dev/null')) ?: trim(shell_exec('command -v pip 2>/dev/null'));
            if ($pip_cmd) {
                exec("cd /tmp && {$sudo}{$pip_cmd} install mcp --ignore-installed rpds-py 2>&1", $output, $return_var);
                if ($return_var !== 0) {
                    $msg = "pip install mcp failed: " . implode("\n", $output);
                    DebMes("Opencode: " . $msg, 'opencode');
                    $errors[] = $msg;
                } else {
                    DebMes("Opencode: mcp package installed successfully", 'opencode');
                }
            } else {
                $msg = "pip3/pip not found";
                DebMes("Opencode: " . $msg, 'opencode');
                $errors[] = $msg;
            }
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
                    } else {
                        $errors[] = "venv pip install failed";
                    }
                } else {
                    $errors[] = "venv pip not found";
                }
            } else {
                DebMes("Opencode: mcp already available in MCP venv, skipping", 'opencode');
            }
        }
        return implode('; ', $errors);
    }

    function findMcpVenvPython() {
        $p = realpath(DIR_MODULES) . '/mcp/lib/.venv/bin/python3';
        return file_exists($p) ? $p : null;
    }

    function findMcpVenvPip() {
        $py = $this->findMcpVenvPython();
        if (!$py) return null;
        $pip = dirname($py) . '/pip3';
        return file_exists($pip) ? $pip : null;
    }

    function getMcpPython() {
        if ($this->mcp_python_cache !== null) return $this->mcp_python_cache;
        $venv_python = $this->findMcpVenvPython();
        if ($venv_python) {
            // Наличие пакета проверяем без тяжёлого импорта (~0.1 c против
            // ~2.4 c): функция вызывается для каждого MCP-сервера при записи
            // конфига, а сам импорт mcp и так делает запускаемый процесс.
            exec("cd /tmp && " . $venv_python . " -c 'import importlib.util, sys; sys.exit(0 if importlib.util.find_spec(\"mcp\") else 1)' 2>&1", $out, $rc);
            if ($rc === 0) {
                $this->mcp_python_cache = $venv_python;
                return $venv_python;
            }
        }
        $this->mcp_python_cache = 'python3';
        return 'python3';
    }

    /**
     * Есть ли Python-пакет.
     *
     * По умолчанию пакет НЕ импортируется: `import mcp` тянет pydantic/httpx/
     * anyio и занимает ~4 секунды, а на странице модуля и в опросе статуса эта
     * проверка выполняется постоянно — из-за неё страницы открывались по 4-5 с.
     * Быстрая проверка через importlib.util.find_spec находит пакет за ~0.1 с.
     * Полноценный импорт ($deep = true) нужен только после установки пакета,
     * чтобы убедиться, что он действительно рабочий.
     */
    function checkPythonPackage($package, $deep = false) {
        $check = $deep
            ? "import " . $package
            : 'import importlib.util, sys; sys.exit(0 if importlib.util.find_spec("' . $package . '") else 1)';
        exec("cd /tmp && python3 -c '" . $check . "' 2>&1", $output, $return_var);
        if ($return_var === 0) return true;
        $venv_python = $this->findMcpVenvPython();
        if ($venv_python) {
            exec("cd /tmp && " . $venv_python . " -c '" . $check . "' 2>&1", $output, $return_var);
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
        $new_bin = $this->findOpencodeBinary();
        if (file_exists($new_bin)) {
            $this->opencode_bin = $new_bin;
            $this->is_v2_cache = null;
            DebMes("Opencode binary found at: " . $this->opencode_bin, 'opencode');
            exec("{$sudo}chmod 755 " . escapeshellarg($this->opencode_bin) . " 2>/dev/null");
            $this->updateConfig(array('OC_INSTALL_ATTEMPT' => null, 'OC_INSTALL_TS' => time()));
        } else {
            DebMes("Opencode installation failed", 'opencode');
        }
    }

    function cleanNpmUpgradeLeftovers() {
        $sudo = $this->isRoot() ? '' : 'sudo ';
        $stale = array();
        foreach (array('/usr/local/lib/node_modules', '/usr/lib/node_modules') as $modules_dir) {
            $cmd = $sudo . 'find ' . escapeshellarg($modules_dir) . ' -maxdepth 1 -name ".opencode-ai-*" 2>/dev/null';
            exec($cmd, $stale);
        }
        foreach ($stale as $path) {
            $path = trim($path);
            if ($path === '' || !file_exists($path)) continue;
            DebMes("Opencode: removing stale npm temp dir {$path}", 'opencode');
            exec($sudo . 'rm -rf ' . escapeshellarg($path));
        }
    }

    function upgradeOpencode($target = '', $major = false) {
        $sudo = $this->isRoot() ? '' : 'sudo ';

        if (!file_exists($this->opencode_bin)) {
            $this->installOpencodeBinary();
            $this->opencode_bin = $this->findOpencodeBinary();
            if (!file_exists($this->opencode_bin)) {
                return array('ok' => false, 'message' => LANG_OPENCODE_UPGRADE_FAILED . ' ' . LANG_OPENCODE_UPGRADE_NO_BINARY);
            }
        }

        $this->cleanNpmUpgradeLeftovers();

        $old_version = $this->getOpencodeVersion();

        // Флаг --major есть только у OpenCode 1 (переход на 2). В OpenCode 2
        // его нет — CLI вернёт "Unrecognized flag: --major". Поэтому передаём
        // --major лишь тогда, когда установлена именно v1.
        if ($major && $this->isOpencodeV2()) {
            $major = false;
        }

        $cmd = escapeshellarg($this->opencode_bin) . ' upgrade';
        if ($target !== '') $cmd .= ' ' . escapeshellarg($target);
        if ($major) {
            // Переход на OpenCode 2 (удаляет OpenCode 1). Метод установки
            // выбирает сам CLI (2.x публикуется отдельным пакетом @opencode/cli).
            $cmd .= ' --major';
        } else {
            $cmd .= ' --method npm';
        }
        $cmd .= ' 2>&1';

        DebMes("Opencode: running '{$sudo}{$cmd}'", 'opencode');
        $output = array();
        $return_var = 0;
        exec($sudo . $cmd, $output, $return_var);
        $log = $this->stripANSI(trim(implode("\n", $output)));
        DebMes("Opencode upgrade output: " . substr($log, -500), 'opencode');

        // 'opencode upgrade' returns 0 even when the underlying npm install failed,
        // so the output has to be inspected as well.
        $markers = array('Upgrade failed', 'failed for', 'npm ERR!', 'ENOTEMPTY', 'EEXIST');
        $failed = ($return_var !== 0);
        foreach ($markers as $marker) {
            if (stripos($log, $marker) !== false) $failed = true;
        }
        if ($failed) {
            $this->cleanNpmUpgradeLeftovers();
            return array('ok' => false, 'message' => LANG_OPENCODE_UPGRADE_FAILED . ' ' . $this->stripANSI(substr($log, -200)));
        }

        $this->opencode_bin = $this->findOpencodeBinary();
        // Сбрасываем кэш версии: после обновления подкоманда сервиса может
        // смениться (web -> serve), и setupServiceDropin должен это увидеть.
        $this->is_v2_cache = null;
        $new_version = $this->getOpencodeVersion();

        if ($old_version !== '' && $new_version === $old_version) {
            return array('ok' => false, 'message' => LANG_OPENCODE_UPGRADE_FAILED . ' ' . LANG_OPENCODE_UPGRADE_NO_CHANGE . ' ' . $old_version);
        }

        // Сбрасываем кэш «последней версии», чтобы проверка сразу пересчиталась.
        @unlink(sys_get_temp_dir() . '/opencode-update.json');
        @unlink(sys_get_temp_dir() . '/opencode-update-v2.json');

        $this->updateConfig(array(
            'OC_RUNNING_VERSION' => $new_version,
            'OC_SESSION_ID' => null,
            'OC_SESSION_TOKENS' => null,
            'OC_SESSION_COST' => null,
            'OC_INSTALL_ATTEMPT' => null
        ));

        // Перегенерируем конфиг вместе с юнитом: в v2 системный промпт живёт
        // в opencode.jsonc (agents.<agent>.system), поэтому после перехода на
        // v2 его обязательно нужно записать заново. writeOpencodeConfig() сам
        // вызывает setupServiceDropin() и syncServiceRestart().
        $this->writeOpencodeConfig();

        $min = $this->getMinVersion();
        $still_old = ($min !== '' && $new_version !== '' && version_compare($new_version, $min) < 0);
        $msg = LANG_OPENCODE_UPGRADE_DONE . ' ' . ($new_version !== '' ? $new_version : '?');
        if ($still_old) $msg .= '. ' . LANG_OPENCODE_UPGRADE_REQUIRED . ' ' . $min;
        return array('ok' => !$still_old, 'message' => $msg);
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
        DebMes("Opencode: removing binary", 'opencode');
        // Удаляем все известные копии бинарника, а не только предпочтительную:
        // иначе оставшаяся версия (например, старая v1) снова найдётся, и модуль
        // снова покажет версию.
        $paths = $this->getBinaryCandidates();
        if (!empty($this->opencode_bin)) $paths[] = $this->opencode_bin;
        foreach (array_unique($paths) as $path) {
            exec("{$sudo}rm -f " . escapeshellarg($path) . " 2>/dev/null");
        }
        $this->is_v2_cache = null;
        $this->updateConfig(array('OC_REMOVED' => 1, 'OC_RUNNING_VERSION' => null));
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
        exec("{$sudo}rm -f " . escapeshellarg($this->opencode_bin) . " 2>/dev/null");
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

