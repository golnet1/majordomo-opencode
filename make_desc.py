import os

root = r'C:\Users\oleg.GOLNET\tongyi-lingma\majordomo-modules\opencode'
out = r'C:\Users\oleg.GOLNET\tongyi-lingma\majordomo-modules\OPENCODE_PROJECT_FULL_DESCRIPTION.md'

buf = []
buf.append('# Полное детальное описание проекта Majordomo OpenCode module\n\n')
buf.append(f'Путь проекта: {root}\n\n')
buf.append('## Структура и список файлов\n\n')

files = []
for dp, dn, fn in os.walk(root):
    for f in fn:
        full = os.path.join(dp, f)
        rel = os.path.relpath(full, root).replace('\\', '/')
        files.append((rel, full))
files.sort(key=lambda x: x[0])

for rel, _ in files:
    buf.append(f'- {rel}\n')

buf.append('\n## Полное содержимое каждого файла\n\n')

for rel, full in files:
    buf.append(f'### {rel}\n\n')
    try:
        with open(full, 'r', encoding='utf-8', errors='replace') as fh:
            data = fh.read()
        ext = os.path.splitext(rel)[1][1:]
        if not ext:
            ext = 'text'
        buf.append(f'```{ext}\n')
        buf.append(data)
        if data and not data.endswith('\n'):
            buf.append('\n')
        buf.append('```\n\n')
    except Exception as e:
        buf.append(f'```text\n[ОШИБКА ЧТЕНИЯ: {e}]\n```\n\n')

with open(out, 'w', encoding='utf-8') as fh:
    fh.write(''.join(buf))

print('done', os.path.getsize(out))