'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/central-auth.js'), 'utf8');
let checks = 0;
for (const mode of ['native', 'central']) {
    const calls = [];
    const originalFetch = (input, options) => { calls.push({ input, options, kind: 'fetch' }); return Promise.resolve('fixture'); };
    const printerFetch = (input, options) => { calls.push({ input, options, kind: 'printer' }); return Promise.resolve('fixture'); };
    const window = { fetch: originalFetch, WarehousePrinter: { fetch: printerFetch } };
    const document = { currentScript: { src: 'https://warehouse.invalid/WarehouseIssuance/assets/central-auth.js', dataset: { authMode: mode } },
        baseURI: 'https://warehouse.invalid/WarehouseIssuance/' };
    vm.runInNewContext(source, { window, document, URL, Request });
    const helper = window.WarehouseAuth;
    assert.equal(window.fetch, originalFetch); ++checks;
    assert.equal(window.WarehousePrinter.fetch, printerFetch); ++checks;
    for (const path of ['api/list.php', 'api/list.php?q=A%26B&page=2', 'actions/print.php?printer=zebra', 'pages/picker/picker.php#table', 'index.php']) {
        const url = new URL(helper.url(path));
        assert.equal(url.searchParams.get('wh_auth'), mode); ++checks;
        if (path.includes('q=')) { assert.equal(url.searchParams.get('q'), 'A&B'); ++checks; }
        if (path.includes('#')) { assert.equal(url.hash, '#table'); ++checks; }
    }
    assert.equal(new URL(helper.url('api/list.php?wh_auth=other')).searchParams.getAll('wh_auth').length, 1); ++checks;
    assert.equal(new URL(helper.url('pages/auth/login.php')).searchParams.get('wh_auth'), 'native'); ++checks;
    for (const path of ['https://other.invalid/api/list.php', 'http://127.0.0.1:9100/print.php',
        'https://warehouse.invalid/OtherApp/list.php', 'assets/app.js', 'api/get_part_name.php', 'pages/tags/view_tags.php']) {
        assert.equal(helper.url(path), path); ++checks;
    }
    const options = { method: 'POST', body: 'fixture-payload', headers: { 'X-Test': 'kept' } };
    helper.fetch('api/receive.php?token=fixture', options);
    assert.equal(calls.at(-1).options, options); ++checks;
    assert.equal(new URL(calls.at(-1).input).searchParams.get('token'), 'fixture'); ++checks;
    assert.equal(new URL(calls.at(-1).input).searchParams.get('wh_auth'), mode); ++checks;
    const request = new Request('https://warehouse.invalid/WarehouseIssuance/api/receive.php', { method: 'POST', body: 'fixture', headers: { 'X-Test': 'kept' } });
    helper.fetch(request);
    assert.equal(calls.at(-1).input.method, 'POST'); ++checks;
    assert.equal(calls.at(-1).input.headers.get('X-Test'), 'kept'); ++checks;
    assert.equal(new URL(calls.at(-1).input.url).searchParams.get('wh_auth'), mode); ++checks;
    helper.printFetch('actions/print.php', options);
    assert.equal(calls.at(-1).kind, 'printer'); ++checks;
    assert.equal(calls.at(-1).options, options); ++checks;
    assert.equal(new URL(calls.at(-1).input).searchParams.get('wh_auth'), mode); ++checks;
    helper.printFetch('http://127.0.0.1:9100/print.php', options);
    assert.equal(calls.at(-1).input, 'http://127.0.0.1:9100/print.php'); ++checks;
}
console.log(`Warehouse auth URLs: ${checks} checks passed; no network or printing.`);
