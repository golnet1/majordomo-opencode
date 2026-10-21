#!/bin/sh
echo "=== ls .config/opencode ==="
ls -la /root/.config/opencode/ 2>&1 | head -25
for f in /root/.config/opencode/opencode.json /root/.config/opencode/opencode.jsonc /root/.config/opencode/config.json; do
  if [ -f "$f" ]; then
    echo "--- $f ---"
    cat "$f"
    echo
  fi
done
echo "=== grep -ri websearch in opencode config dirs ==="
grep -rin 'websearch\|web_search\|"disable"\|favorite' /root/.config/opencode/*.json* 2>/dev/null | head
echo "=== opencode.db tables (strings) ==="
ls -la /root/.local/share/opencode/
