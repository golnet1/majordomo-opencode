# -*- coding: utf-8 -*-
import json, subprocess, sys

H = 'http://127.0.0.1:4096'

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

c, j, _ = api('/api/session')
sessions = (j or {}).get('data') or []
def ts(s):
    return ((s.get('time') or {}).get('updated') or 0)
sessions.sort(key=ts, reverse=True)
print('=== last %d sessions ===' % min(6, len(sessions)))
for s in sessions[:6]:
    t = s.get('time') or {}
    print('  %s  upd=%s  title=%r' % (s.get('id'), t.get('updated'), (s.get('title') or '')[:70]))

if len(sys.argv) > 1:
    sid = sys.argv[1]
    print('=== session %s messages (chronological) ===' % sid)
    c, j, body = api('/api/session/%s/message?order=desc&limit=26' % sid)
    msgs = (j or {}).get('data') or []
    msgs = list(reversed(msgs))
    for m in msgs:
        info = m.get('info', m)
        parts = m.get('content') or []
        t = info.get('time') or {}
        tools = []
        texts = []
        others = []
        for p in parts:
            if not isinstance(p, dict):
                continue
            pt = p.get('type')
            if pt == 'text':
                texts.append(p.get('text') or '')
            elif pt == 'tool':
                st = p.get('state') or {}
                tools.append('%s[%s]' % (p.get('name'), st.get('status')))
                others.append(p)
            else:
                others.append(p)
        print('--- %s id=%s created=%s completed=%s' % (info.get('type'), info.get('id'), t.get('created'), t.get('completed')))
        if tools:
            print('    tools: %s' % ', '.join(tools))
        for tx in texts:
            print('    TEXT: %r' % tx[:500])
        for o in others:
            if o.get('type') == 'tool':
                print('    TOOL %s input=%s' % (o.get('name'), json.dumps((o.get('state') or {}).get('input'), ensure_ascii=False)[:300]))
    print('=== forms ===')
    print(body if False else api('/api/session/%s/form' % sid)[2][:800])
    open('/tmp/_diag_full.json', 'w').write(json.dumps(msgs, ensure_ascii=False, indent=1))
    print('(full json -> /tmp/_diag_full.json)')
