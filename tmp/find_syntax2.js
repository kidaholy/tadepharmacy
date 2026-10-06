const fs = require('fs');
const html = fs.readFileSync('C:/Users/11/AppData/Local/Temp/adj2.html', 'utf8');
const m = html.match(/<script>([\s\S]*?)<\/script>/i);
if (!m) { console.log('no script'); process.exit(0); }
let js = m[1].replace(/<\/?script>/gi, '').trim();
const lines = js.split('\n');
// Try to eval line by line to find the culprit (naive but reveals breakpoint)
let acc = '';
for (let i = 0; i < lines.length; i++) {
  acc += (i > 0 ? '\n' : '') + lines[i];
  try {
    // Wrap in a function head to catch syntax mid-way
    new Function(acc + '\n;');
  } catch (e) {
    console.log('FAILED at/after line', (i+1), ':', e.message);
    for (let j = Math.max(0,i-2); j <= i; j++) {
      console.log((j+1) + ': ' + lines[j]);
    }
    break;
  }
}
