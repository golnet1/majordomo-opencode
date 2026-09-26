<?php
/**
 * OpenCode AI module cycle
 *
 * Trims the opencode_messages history table down to OC_MAX_HISTORY rows.
 * Started automatically by /var/www/html/cycle.php, which discovers every
 * scripts/cycle_*.php. Keep calling setGlobal('<name>Run') or the supervisor
 * will consider the thread hung and restart it.
 */
@ob_end_flush();
ob_implicit_flush(1);
chdir(dirname(__FILE__) . "/../");

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

$pidFile = "/tmp/opencode_cycle.pid";
$currentPid = posix_getpid();
if (file_exists($pidFile)) {
    $oldPid = (int)trim(file_get_contents($pidFile));
    if ($oldPid > 0 && $oldPid !== $currentPid && file_exists("/proc/$oldPid")) {
        fwrite(STDERR, "Another cycle_opencode instance running (PID $oldPid). Exiting.\n");
        exit(1);
    }
}
file_put_contents($pidFile, $currentPid);
register_shutdown_function(function() use ($pidFile) {
    if (@file_get_contents($pidFile) == posix_getpid()) {
        @unlink($pidFile);
    }
});

set_time_limit(0);

while (true) {
    $testConn = @mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($testConn) { mysqli_close($testConn); break; }
    echo date("H:i:s") . " MySQL unavailable, retrying in 5s...\n";
    sleep(5);
}

$db = new mysql(DB_HOST, "", DB_USER, DB_PASSWORD, DB_NAME);
include_once("./load_settings.php");
include_once("./modules/opencode/opencode.class.php");

$opencode_module = new opencode();
$opencode_module->getConfig();

echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

$last_check = 0;
$checkEvery = 600;

while (true) {
    setGlobal((str_replace(".php", "", basename(__FILE__))) . "Run", time(), 1);

    if (file_exists("./reboot") || isset($_GET["onetime"])) {
        say("stop opencode", 2);
        $db->Disconnect();
        exit;
    }

    if ((time() - $last_check) >= $checkEvery) {
        $last_check = time();
        $opencode_module->processCycle();
    }

    sleep(5);
}

DebMes("Unexpected close of cycle: " . basename(__FILE__));
