const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/tablet-printer.js'), 'utf8');

function setup(native, fetcher) {
    const operations = [];
    const window = { fetch: fetcher };
    if (native) window.WarehouseTablet = {
        postMessage(text) {
            const request = JSON.parse(text);
            operations.push(request.action);
            const result = native(request);
            queueMicrotask(() => this.onmessage({ data: JSON.stringify({ id: request.id, ...result }) }));
        }
    };
    vm.runInNewContext(source, { window, Map, Promise, Error, JSON, Response, setTimeout, clearTimeout });
    return { printer: window.WarehousePrinter, operations };
}

function options(printer = 'zebra') {
    const body = new FormData();
    body.set('pick_printer', printer);
    return { method: 'POST', body };
}
const json = (data, status = 200) => new Response(JSON.stringify(data), { status });

(async () => {
    const plain = setup(null, async (_url, request) => { assert.equal(request.body.has('print_transport'), false); return json({ queued: 1 }); });
    assert.equal((await (await plain.printer.fetch('/print', options())).json()).queued, 1);

    const nitto = setup(() => assert.fail('Nitto must not use the tablet bridge'), async () => json({ queued: 1 }));
    await nitto.printer.fetch('/print', options('nitto'));
    assert.deepEqual(nitto.operations, []);

    const server = setup(() => ({ ok: true, transport: 'server' }), async (_url, request) => {
        assert.equal(request.body.has('print_transport'), false); return json({ printed: 1 });
    });
    await server.printer.fetch('/print', options());
    assert.deepEqual(server.operations, ['prepare']);

    const bluetooth = setup(request => request.action === 'prepare' ? { ok: true, transport: 'tablet_bluetooth' }
        : { ok: true, printed: 2, transport: 'tablet_bluetooth', message: 'Sent two labels.' }, async (_url, request) => {
        assert.equal(request.body.get('print_transport'), 'tablet_bluetooth');
        return json({ ok: true, transport: 'tablet_bluetooth', labels: ['XlhBXlha', 'XlhBXlha'], label_delay_ms: 0 });
    });
    const result = await (await bluetooth.printer.fetch('/print', options())).json();
    assert.equal(result.printed, 2);
    assert.equal(result.queued, 2);
    assert.equal('labels' in result, false);
    assert.deepEqual(bluetooth.operations, ['prepare', 'print']);

    const denied = setup(() => ({ ok: false, message: 'Permission denied.' }), () => assert.fail('Must not ask server to print after permission denial'));
    await assert.rejects(denied.printer.fetch('/print', options()), /Permission denied/);

    const invalid = setup(() => ({ ok: true, transport: 'tablet_bluetooth' }), async () => json({ ok: false, message: 'Invalid lot.' }, 400));
    assert.equal((await invalid.printer.fetch('/print', options())).status, 400);
    assert.deepEqual(invalid.operations, ['prepare']);

    const outdated = setup(() => ({ ok: true, transport: 'tablet_bluetooth' }), async () => json({ ok: true, queued: 1 }));
    await assert.rejects(outdated.printer.fetch('/print', options()), /Update the website/);
    assert.deepEqual(outdated.operations, ['prepare']);

    let serverCalls = 0;
    const partial = setup(request => request.action === 'prepare' ? { ok: true, transport: 'tablet_bluetooth' }
        : { ok: false, printed: 1, failed: 1, message: 'Connection lost. Check for partial labels.' }, async () => {
        serverCalls++; return json({ ok: true, transport: 'tablet_bluetooth', labels: ['one', 'two'] });
    });
    const failure = await partial.printer.fetch('/print', options());
    assert.equal(failure.status, 409);
    const failureData = await failure.json();
    assert.equal(failureData.printed, 1);
    assert.equal(failureData.queued, 0);
    assert.equal(serverCalls, 1);
    assert.deepEqual(partial.operations, ['prepare', 'print']);
    console.log('PASS: browser, Nitto, server mode, tablet Bluetooth, permission denial, validation, outdated server, and partial failure without retry.');
})().catch(error => { console.error(error); process.exitCode = 1; });
