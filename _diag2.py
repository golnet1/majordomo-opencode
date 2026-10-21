# -*- coding: utf-8 -*-
import json, subprocess

H = 'http://127.0.0.1:4096'
SID = 'ses_ebddd25e7ffe2UXLwvY1YhRaP7'

def api(path, method='GET', data=None, max_time=30):
    args = ['curl', '-s', '-u', 'opencode:1', '-X', method, '--max-time', str(max_time),
            '-w', '\n__CODE__%{http_code}']
    if data is not None:
        args += ['-H', 'Content-Type: application/json', '-d', json.dumps(data)]
    args.append(H + path)
    r = subprocess.run(args, capture_output=True, text=True)
    out = r.stdout
    code = ''
    if '__CODE__' in out:
        body, code = out.rsplit('__CODE__', 1)
        code = code.strip()
    else:
        body = out
    body = body.rstrip('\n')
    try:
        j = json.loads(body) if body else None
    except Exception:
        j = None
    return (int(code) if code.isdigit() else 0), j, body

print('=== final assistant message info ===')
c, j, b = api('/api/session/%s/message?order=desc&limit=3' % SID)
for m in (j or {}).get('data') or []:
    info = m.get('info', m)
    if info.get('type') == 'assistant':
        print(json.dumps({k: info.get(k) for k in ('id', 'cost', 'tokens', 'finish', 'rawFinish', 'model')}, ensure_ascii=False, indent=1))
        print('   content:', json.dumps(m.get('content'), ensure_ascii=False)[:200])

print('=== websearch form schema ===')
print(api('/api/session/%s/form/frm_14270956f001fHPrZHIpgbx6HD' % SID)[2][:1500])
print('=== other recent forms (session list may retain) ===')
print(api('/api/session/%s/form' % SID)[2][:600])
