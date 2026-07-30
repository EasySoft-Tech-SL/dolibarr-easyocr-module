/**
 * Smoke test for the JSON tree viewer's pure rendering methods.
 *
 * Extracts the real code from js/scripts.js instead of re-typing it, so a
 * regression in the shipped file actually fails here. Only the methods that
 * build markup are covered — everything from bindEvents() onwards needs a DOM.
 *
 * Run:  node tests/easyocr_jsonviewer_test.js
 */
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'js', 'scripts.js'), 'utf8');

const start = src.indexOf('function EoJsonViewer(');
const end = src.indexOf('EoJsonViewer.prototype.bindEvents');
if (start < 0 || end < 0) {
    console.error('FAIL: could not locate EoJsonViewer in scripts.js');
    process.exit(1);
}
const block = src.slice(start, end);

const L = {};
const EoJsonViewer = new Function('L', block + '\nreturn EoJsonViewer;')(L);

let pass = 0, fail = 0;
function check(name, cond) {
    if (cond) { pass++; console.log('  PASS  ' + name); }
    else { fail++; console.log('  FAIL  ' + name); }
}

const v = new EoJsonViewer(null, null);   // null el => render() not called

// --- esc ---
check('esc escapes double quotes (attribute safety)', v.esc('a"b') === 'a&quot;b');
check('esc escapes angle brackets', v.esc('<script>') === '&lt;script&gt;');
check('esc escapes ampersand first', v.esc('&lt;') === '&amp;lt;');

// --- countStats ---
const data = {
    supplier: { name: 'ACME', tax_id: 'B12345678' },
    items: [{ qty: 1000, unit_price: 0.347 }, { qty: 1500, unit_price: 3.4337 }],
    paid: false,
    note: null
};
const stats = v.countStats(data, { keys: 0, values: 0 });
check('countStats counts every key', stats.keys === 4 + 2 + 2 + 2);
check('countStats counts leaf values', stats.values === 2 + 4 + 1 + 1);

// --- renderValue ---
const html = v.renderValue(data, 0);
check('object renders a node', html.indexOf('jv-node') === 0 + html.indexOf('jv-node'));
check('keys are highlighted', html.includes('<span class="jv-key">"supplier"</span>'));
check('numbers keep full precision', html.includes('<span class="jv-num">0.347</span>'));
check('no ×1000 inflation in the viewer', html.includes('<span class="jv-num">3.4337</span>'));
check('booleans are rendered as booleans', html.includes('<span class="jv-bool">false</span>'));
check('null is rendered as null', html.includes('<span class="jv-null">null</span>'));
check('array indices are shown', html.includes('<span class="jv-idx">0</span>'));

const opens = (html.match(/<div/g) || []).length;
const closes = (html.match(/<\/div>/g) || []).length;
check('markup is balanced (' + opens + ' open / ' + closes + ' close)', opens === closes);

// --- collapsing depth ---
const deep = v.renderValue({ a: { b: { c: { d: 1 } } } }, 0);
check('nodes past expandDepth start collapsed', deep.includes('jv-node jv-collapsed'));
check('the root node starts expanded', deep.indexOf('<div class="jv-node">') === 0);

// --- strings ---
check('newlines are escaped, not emitted raw', v.renderString('a\nb') === '<span class="jv-str">"a\\nb"</span>');
check('a string that looks like markup cannot inject', v.renderString('<img onerror=x>').includes('&lt;img'));

// --- empties ---
check('empty array', v.renderValue([], 0) === '<span class="jv-bracket">[ ]</span>');
check('empty object', v.renderValue({}, 0) === '<span class="jv-bracket">{ }</span>');

// --- XSS: the payload comes from the AI service and lands in innerHTML ---
// A supplier name, a description or even an object key is attacker-influenced
// text (it is whatever the model read off the PDF), so every path that builds
// markup has to escape. These assert the absence of live markup, not a shape.
const hostile = '<img src=x onerror="alert(1)">';

const valueHtml = v.renderValue({ supplier: hostile }, 0);
check('a hostile string value is escaped', !valueHtml.includes('<img'));
check('and the escaped form is present', valueHtml.includes('&lt;img'));

const keyHtml = v.renderValue({ [hostile]: 'x' }, 0);
check('a hostile object KEY is escaped', !keyHtml.includes('<img'));
check('quotes in a key cannot break out', !v.renderValue({ 'a"b': 1 }, 0).includes('"a"b"'));

const arrHtml = v.renderValue([hostile, '</span><script>x</script>'], 0);
check('hostile array items are escaped', !arrHtml.includes('<script'));
check('a closing tag in a value cannot end the span early', !arrHtml.includes('</span><script'));

check('esc leaves no raw < or > from input', v.esc('<>&"') === '&lt;&gt;&amp;&quot;');

console.log('\n' + (fail === 0 ? 'All ' + pass + ' viewer assertions passed.' : fail + ' of ' + (pass + fail) + ' failed.'));
process.exit(fail === 0 ? 0 : 1);
