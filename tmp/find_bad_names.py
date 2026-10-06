import sys, re, json

html = sys.stdin.read()
m = re.search(r'var items = (\[.*\]);', html, re.S)
if not m:
    print('NO MATCH')
    sys.exit(0)
data = json.loads(m.group(1))
print('total items:', len(data))
problems = []
for it in data:
    n = it['name']
    # Check for chars that could cause issues
    for ch in ['"', '\\', '&', '<', '>']:
        if ch in n:
            problems.append((ch, it))
            break
if problems:
    print(f'Found {len(problems)} problems:')
    for ch, it in problems[:20]:
        print(f'  char={ch!r}: {it}')
else:
    print('No problematic characters found in names')
