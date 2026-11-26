'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../models/MemberProfiles.php'), 'utf8');
const match = source.match(/'whenClient'\s*=>\s*'([^']+)'/);
assert.ok(match, 'Expected conditional client validation');
let checks = 0;
for (const value of ['0', '1', null]) {
    const element = value === null ? null : { getAttribute: name => { assert.equal(name, 'data-is-corporate'); return value; } };
    const callback = vm.runInNewContext('(' + match[1] + ')', { document: { getElementById: id => { assert.equal(id, 'member-profile-type'); return element; } } });
    assert.equal(callback(null, ''), value === '1'); checks++;
}
const view = fs.readFileSync(path.join(__dirname, '../views/association/member-form.php'), 'utf8');
assert.ok(!/field\(\$profile,\s*'is_corporate'\)/.test(view)); checks++;
assert.ok(view.includes('data-is-corporate=')); checks++;
console.log(`PASS ${checks} immutable member-type client checks (not a browser test)`);
