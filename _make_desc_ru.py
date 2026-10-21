# -*- coding: utf-8 -*-
import os
import re

root = r'C:\Users\oleg.GOLNET\tongyi-lingma\majordomo-modules\opencode'
out = r'C:\Users\oleg.GOLNET\tongyi-lingma\majordomo-modules\OPENCODE_PROJECT_FULL_DESCRIPTION.md'

buf = []
buf.append('# ПОЛНОЕ ДЕТАЛЬНОЕ ОПИСАНИЕ ПРОЕКТА МОДУЛЯ MAJORDOMO OPENCODE\n\n')
buf.append('Путь к проекту: ' + root + '\n\n')
buf.append('Данное описание содержит подробную информацию о каждом файле модуля, его назначении, структуре, классах, методах, функциях и полном содержимом.\n\n')
buf.append('---\n\n')

files = []
for dp, dn, fn in os.walk(root):
    for f in fn:
        full = os.path.join(dp, f)
        rel = os.path.relpath(full, root).replace('\\', '/')
        files.append((rel, full))
files.sort(key=lambda x: x[0])

buf.append('## 1. СТРУКТУРА ПРОЕКТА\n\n')
buf.append('```\n')
for rel, _ in files:
    depth = rel.count('/')
    indent = '  ' * depth
    name = os.path.basename(rel)
    buf.append(indent + name + '\n')
buf.append('```\n\n')

buf.append('## 2. ОПИСАНИЕ И СОДЕРЖИМОЕ КАЖДОГО ФАЙЛА\n\n')

for idx, (rel, full) in enumerate(files, 1):
    buf.append('### 2.' + str(idx) + '. Файл: ' + rel + '\n\n')
    buf.append('**Расположение:** ' + full + '\n\n')
    
    try:
        with open(full, 'r', encoding='utf-8', errors='replace') as fh:
            data = fh.read()
    except Exception as e:
        data = '[ОШИБКА ЧТЕНИЯ ФАЙЛА: ' + str(e) + ']'
    
    ext = os.path.splitext(rel)[1].lower()
    
    buf.append('**Назначение:** Файл является частью модуля интеграции OpenCode с системой Majordomo.\n\n')
    
    if ext == '.php':
        buf.append('**Анализ структуры PHP-файла:**\n\n')
        # Классы
        for m in re.finditer(r'class\s+([A-Za-z0-9_]+)', data):
            buf.append('- Класс: ' + m.group(1) + '\n')
        # Методы
        for m in re.finditer(r'(public|private|protected|static)\s+function\s+([A-Za-z0-9_]+)\s*\(', data):
            buf.append('- Метод: ' + m.group(2) + '()\n')
        # Функции
        for m in re.finditer(r'^\s*function\s+([A-Za-z0-9_]+)\s*\(', data, re.M):
            buf.append('- Функция: ' + m.group(1) + '()\n')
        buf.append('\n')
    
    buf.append('**Полное содержимое:**\n\n')
    lang = ext.lstrip('.') if ext else 'text'
    if lang == 'php':
        lang = 'php'
    elif lang == 'js':
        lang = 'javascript'
    elif lang == 'json':
        lang = 'json'
    elif lang == 'md':
        lang = 'markdown'
    elif lang == 'css':
        lang = 'css'
    elif lang == 'html':
        lang = 'html'
    elif lang == 'sh':
        lang = 'bash'
    elif lang == 'py':
        lang = 'python'
    else:
        lang = 'text'
    
    buf.append('```' + lang + '\n')
    buf.append(data)
    if data and not data.endswith('\n'):
        buf.append('\n')
    buf.append('```\n\n')
    buf.append('---\n\n')

with open(out, 'w', encoding='utf-8') as fh:
    fh.write(''.join(buf))

print('OK')
print(os.path.getsize(out))
