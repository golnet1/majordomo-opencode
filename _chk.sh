#!/bin/sh
echo "===== DB ====="
php /tmp/_db2.php 2>&1
echo "===== opencode.log 04:5x-05:1x ====="
LOG=/var/www/html/cms/debmes/2026-10-16/opencode.log
if [ -f "$LOG" ]; then grep -E '04:5[0-9]|05:0[0-9]|05:1[0-9]' "$LOG" | tail -40; else ls /var/www/html/cms/debmes/ | tail -3; fi
echo "===== background.php processes ====="
ps aux | grep '[b]ackground.php' | head -5
echo "(count: $(ps aux | grep -c '[b]ackground.php'))"
echo "===== apache/php error log tail ====="
for L in /var/log/apache2/error.log /var/log/httpd/error_log /var/log/php_errors.log; do
  if [ -f "$L" ]; then echo "--- $L ---"; tail -15 "$L"; fi
done
echo "===== module config timeouts ====="
php -r '$_SERVER["DOCUMENT_ROOT"]="/var/www/html"; chdir("/var/www/html"); require_once "./config.php"; require_once "./lib/loader.php"; require_once "./load_settings.php"; $rows=SQLSelect("SELECT * FROM opencode"); foreach($rows as $r){ echo $r["PARAM"]."=".$r["VALUE"]."\n"; }' 2>&1 | head -40
