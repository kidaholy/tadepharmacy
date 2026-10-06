const fs = require('fs');
const html = fs.readFileSync('C:/Users/11/AppData/Local/Temp/adj2.html', 'utf8');
const m = html.match(/<script>([\s\S]*?)<\/script>/i);
if (!m) { console.log('no script'); process.exit(0); }
let js = m[1].replace(/<\/?script>/gi, '').trim();
const orig = js;
const phpOut = [];
js = js.replace(/<\?=[\s\S]*?\?>/g, (match) => {
  phpOut.push(match);
  return '"__PHP__"';
});
const lines = js.split('\n');
try {
  new Function(js);
  console.log('JS parses OK after PHP replacement');
} catch (e) {
  console.log('ERROR:', e.message);
  // Use a regex to find the line number from V8 stack
  const m2 = e.stack && e.stack.match(/:\d+\)/);
  if (m2) {
    const token = m2[0];
    const lineno = parseInt(token.match(/\d+/)[0], 10);
    console.log('Reported at line', lineno);
    for (let i = Math.max(0, lineno - 4); i < Math.min(lines.length, lineno + 2); i++) {
      console.log((i + 1) + ': ' + lines[i]);
    }
  } else {
    console.log('Full script (first 40 lines):');
    lines.slice(0,40).forEach((l,i)=>console.log((i+1)+': '+l));
  }
}
