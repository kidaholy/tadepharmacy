const fs = require('fs');
const html = fs.readFileSync('C:/Users/11/AppData/Local/Temp/adj2.html', 'utf8');
const m = html.match(/<script>([\s\S]*?)<\/script>/i);
if (!m) { console.log('no script'); process.exit(0); }
let js = m[1].replace(/<\/?script>/gi, '').trim();
const lines = js.split('\n');
for (let i = 0; i < lines.length; i++) {
  const l = lines[i];
  if (l.includes('<?=')) {
    console.log('LINE ' + (i + 1) + ': ' + l.slice(0, 200));
  }
}
console.log('total lines:', lines.length);
// Try to find the syntax error: look for the line after the items = ...
const idx = js.indexOf('var items = ');
if (idx >= 0) {
  console.log('--- context around items ---');
  const start = Math.max(0, js.lastIndexOf('\n', idx));
  const end = js.indexOf('\n', idx + 60);
  console.log(js.slice(start, end !== -1 ? end : start + 200));
}
