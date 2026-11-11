const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/tablet-printer.js'), 'utf8');
const ready = { ok: true, transport: 'tablet_bluetooth', protocol: 'confirmed_print_v1' };
const prepared = { ok: true, transport: 'tablet_bluetooth', labels: ['XlhBXlha', 'XlhBXlha'], label_delay_ms: 0 };
const json = (data, status = 200) => new Response(JSON.stringify(data), { status });
function setup(native, fetcher, capabilities = true) {
    const operations = [], calls = [];
    const window = { document: { baseURI: 'http://warehouse/WarehouseIssuance/' }, location: { origin: 'http://warehouse' },
        fetch: async (url, options) => {
            calls.push(String(url));
            if (String(url).endsWith('/api/tablet_print_capabilities.php')) return json({ tablet_bluetooth: capabilities }, capabilities ? 200 : 404);
            return fetcher(url, options);
        } };
    if (native) window.WarehouseTablet = { postMessage(text) {
        const request = JSON.parse(text); operations.push(request.action);
        const result = native(request);
        queueMicrotask(() => this.onmessage({ data: JSON.stringify({ id: request.id, ...result }) }));
    } };
    const context = { window, Map, Promise, Error, JSON, Response, URL, setTimeout, clearTimeout };
    vm.runInNewContext(source, context);
    return { window, printer: window.WarehousePrinter, operations, calls, reload() { vm.runInNewContext(source, context); } };
}
function options(printer = 'zebra') {
    const body = new FormData(); body.set('pick_printer', printer); return { method: 'POST', body };
}
const endpoint = 'actions/print_pick_tags.php';
(async () => {
    const plain = setup(null, async (_url, request) => { assert.equal(request.body.has('print_transport'), false); return json({ queued: 1 }); });
    assert.equal((await (await plain.printer.fetch(endpoint, options())).json()).queued, 1);
    const nitto = setup(() => assert.fail('Nitto must not use Bluetooth'), async () => json({ queued: 1 }));
    await nitto.printer.fetch(endpoint, options('nitto')); assert.deepEqual(nitto.operations, []);
    const server = setup(() => ({ ok: true, transport: 'server' }), async (_url, request) => {
        assert.equal(request.body.has('print_transport'), false); return json({ printed: 1 });
    });
    await server.printer.fetch(endpoint, options()); assert.deepEqual(server.operations, ['prepare']);
    const bluetooth = setup(request => request.action === 'prepare' ? ready : { ok: true, confirmed: true, printed: 2 }, async (_url, request) => {
        assert.equal(request.body.get('print_transport'), 'tablet_bluetooth'); return json(prepared);
    });
    const result = await (await bluetooth.printer.fetch(endpoint, options())).json();
    assert.equal(result.printed, 2); assert.equal(result.queued, 2); assert.equal('labels' in result, false);
    assert.deepEqual(bluetooth.operations, ['prepare', 'print']);
    const denied = setup(() => ({ ok: false, message: 'Permission denied.' }), () => assert.fail('No print after denial'));
    await assert.rejects(denied.printer.fetch(endpoint, options()), /Permission denied/);
    const oldApp = setup(() => ({ ok: true, transport: 'tablet_bluetooth' }), () => assert.fail('No print from old app'));
    await assert.rejects(oldApp.printer.fetch(endpoint, options()), /Update NBC Warehouse/);
    assert.equal(oldApp.calls.length, 0);
    const oldServer = setup(() => ready, () => assert.fail('No print on outdated server'), false);
    await assert.rejects(oldServer.printer.fetch(endpoint, options()), /No print job was submitted/);
    assert.deepEqual(oldServer.operations, ['prepare']);
    const invalid = setup(() => ready, async () => json({ ok: false, message: 'Invalid lot.' }, 400));
    assert.equal((await invalid.printer.fetch(endpoint, options())).status, 400); assert.deepEqual(invalid.operations, ['prepare']);
    const partial = setup(request => request.action === 'prepare' ? ready : { ok: false, confirmed: false, printed: 1, failed: 1 }, async () => json(prepared));
    const failure = await partial.printer.fetch(endpoint, options()); assert.equal(failure.status, 409);
    const failureData = await failure.json(); assert.equal(failureData.printed, 1); assert.equal(failureData.queued, 0);
    assert.deepEqual(partial.operations, ['prepare', 'print']);
    const noConfirmation = setup(request => request.action === 'prepare' ? ready : { ok: true, printed: 2 }, async () => json(prepared));
    assert.equal((await noConfirmation.printer.fetch(endpoint, options())).status, 409);
    const wrongCount = setup(request => request.action === 'prepare' ? ready : { ok: true, confirmed: true, printed: 1 }, async () => json(prepared));
    assert.equal((await wrongCount.printer.fetch(endpoint, options())).status, 409);
    const legacyPage = setup(request => request.action === 'prepare' ? ready : { ok: true, confirmed: true, printed: 2 }, async () => json(prepared));
    legacyPage.reload();
    legacyPage.window.WarehousePrinter = { fetch() { assert.fail('Cached page must not replace verified client'); } };
    legacyPage.window.WarehouseTablet.onmessage = () => assert.fail('Cached page handler must not steal native replies');
    assert.equal((await legacyPage.window.fetch(endpoint, options())).status, 200);
    assert.deepEqual(legacyPage.operations, ['prepare', 'print']);
    assert.equal(legacyPage.calls.filter(url => url.endsWith(endpoint)).length, 1);
    console.log('PASS: browser/Nitto/server compatibility, confirmed printing, missing permission, outdated app/server, validation, partial/unconfirmed batches, and legacy-page interception without retry.');
})().catch(error => { console.error(error); process.exitCode = 1; });
