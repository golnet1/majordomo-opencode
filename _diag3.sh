#!/bin/sh
H="http://127.0.0.1:4096"
curl -s "$H/openapi.json" > /tmp/_oa.json
echo "=== paths with message/part/form ==="
python3 - <<'PY'
import json
d=json.load(open('/tmp/_oa.json'))
for p in sorted(d.get('paths',{})):
    if 'message' in p or 'part' in p or 'form' in p:
        ms=','.join(sorted(k.upper() for k in d['paths'][p] if k in ('get','post','put','delete','patch')))
        print('%-14s %s' % (ms,p))
PY
echo "=== GET single message ==="
curl -s -u opencode:1 "$H/api/session/ses_ebddd25e7ffe2UXLwvY1YhRaP7/message/msg_142717d68001JFMttP4QYA1zTX" | head -c 1500
echo
echo "=== GET parts of session ==="
curl -s -u opencode:1 "$H/api/session/ses_ebddd25e7ffe2UXLwvY1YhRaP7/part" | head -c 600
echo
echo "=== GET message parts (alt) ==="
curl -s -u opencode:1 "$H/api/message/msg_142717d68001JFMttP4QYA1zTX/part" | head -c 600
echo
