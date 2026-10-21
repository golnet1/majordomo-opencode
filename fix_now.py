# -*- coding: utf-8 -*-
import os, re
root = r'C:\Users\oleg.GOLNET\tongyi-lingma\majordomo-modules\opencode'
out = r'C:\Users\oleg.GOLNET\tongyi-lingma\majordomo-modules\OPENCODE_PROJECT_FULL_DESCRIPTION.md'
s = []
s.append('# Описание проекта модуля Majordomo OpenCode\n\n')
files = []
for dp, dn, fn in os.walk(root):
    dpn = dp.replace('\\', '/')
    if '/.git' in dpn:
        continue
    dn[:] = [d for d in dn if d != '.git']
    for f in fn:
        full = os.path.join(dp, f)
        rel = os.path.relpath(full, root).replace('\\', '/')
        if rel.startswith('.git/'):
            continue
        low = f.lower()
        if low.endswith(('.png', '.jpg', '.jpeg', '.gif', '.ico', '.webp', '.bmp', '.svg', '.ttf', '.woff', '.woff2')):
            continue
        files.append((rel, full))
files.sort(key=lambda x: x[0])
s.append('## 1. Список файлов проекта\n\n')
for rel, _ in files:
    s.append('- ' + rel + '\n')
s.append('\n## 2. Описание файлов, классов, функций и методов\n\n')
for rel, full in files:
    s.append('### ' + rel + '\n\n')
    try:
        with open(full, 'r', encoding='utf-8', errors='replace') as fh:
            data = fh.read()
    except:
        s.append('Назначение: файл модуля.\n\nКлассы:\n- нет\n\nФункции и методы:\n- нет\n\n')
        continue
    ext = os.path.splitext(rel)[1].lower()
    if ext == '.php':
        s.append('Назначение: PHP-файл модуля Majordomo OpenCode.\n\n')
    elif ext == '.js':
        s.append('Назначение: JavaScript-файл клиентской части модуля.\n\n')
    elif ext == '.html':
        s.append('Назначение: HTML-шаблон интерфейса модуля.\n\n')
    elif ext == '.css':
        s.append('Назначение: CSS-стили модуля.\n\n')
    elif ext == '.json':
        s.append('Назначение: Конфигурационный файл модуля.\n\n')
    elif ext == '.md':
        s.append('Назначение: Документация модуля.\n\n')
    elif ext == '.sh':
        s.append('Назначение: Shell-скрипт модуля.\n\n')
    else:
        s.append('Назначение: Файл модуля.\n\n')
    s.append('Классы:\n')
    cls = sorted(set(re.findall(r'class\s+([A-Za-z_][A-Za-z0-9_]*)', data)))
    if cls:
        for c in cls:
            s.append('- ' + c + '\n')
    else:
        s.append('- нет\n')
    s.append('\nФункции и методы:\n')
    items = []
    for m in re.finditer(r'(public|private|protected)\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(', data):
        items.append(m.group(1) + ' метод: ' + m.group(2) + '()')
    for m in re.finditer(r'^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(', data, re.M):
        items.append('функция: ' + m.group(1) + '()')
    # dedup
    res = []
    seen = set()
    for it in items:
        if it not in seen:
            res.append(it)
            seen.add(it)
    if res:
        for it in sorted(res):
            s.append('- ' + it + '\n')
    else:
        s.append('- нет\n')
    s.append('\n')
with open(out, 'w', encoding='utf-8') as fh:
    fh.write(''.join(s))
print('ok')
