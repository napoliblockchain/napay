'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../web/bundles/signup/selectorganization.js'), 'utf8');
let checks = 0;
for (const initial of ['', 'pf', 'pg']) {
    const type = { value: initial, addEventListener: (event, listener) => { assert.equal(event, 'change'); type.change = listener; } };
    const fields = { hidden: false };
    vm.runInNewContext(source, { document: { getElementById: id => ({ member_type: type, 'signup-pg-fields': fields })[id] } });
    assert.equal(fields.hidden, initial !== 'pg'); checks++;
    for (const value of ['pg', 'pf', 'merchant', '']) {
        type.value = value; type.change();
        assert.equal(fields.hidden, value !== 'pg'); checks++;
    }
}
vm.runInNewContext(source, { document: { getElementById: () => null } }); checks++;
process.stdout.write(`PASS ${checks} signup member-type presentation checks (not a browser test)\n`);
