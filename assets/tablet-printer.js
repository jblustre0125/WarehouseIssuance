/* Shared by the website and the Android app's document-start injection. */
(function (root) {
    'use strict';
    if (root.WarehousePrinter && root.WarehousePrinter.confirmedClient) return;
    const originalFetch = root.fetch.bind(root);
    const pending = new Map();
    let nextId = 0;
    let bridge;
    function receive(event) {
        let result;
        try { result = JSON.parse(event.data); } catch (_) { return; }
        const entry = pending.get(result.id);
        if (!entry) return;
        pending.delete(result.id);
        clearTimeout(entry.timer);
        entry.resolve(result);
    }
    function bindBridge() {
        const current = root.WarehouseTablet;
        if (!current || typeof current.postMessage !== 'function') return;
        bridge = current;
        if (bridge.onmessage !== receive) bridge.onmessage = receive;
    }
    bindBridge();
    const nativeApp = !!bridge || /NBCWarehouse\//.test(root.navigator && root.navigator.userAgent || '');
    function request(action, payload, timeoutMs) {
        return new Promise((resolve, reject) => {
            const id = String(++nextId);
            const timer = setTimeout(() => {
                pending.delete(id);
                reject(new Error('The tablet printer did not respond. Check the printer for a label before trying again.'));
            }, timeoutMs);
            pending.set(id, { resolve, timer });
            try { bridge.postMessage(JSON.stringify({ id, action, ...payload })); }
            catch (error) { clearTimeout(timer); pending.delete(id); reject(error); }
        });
    }
    function printEndpoint(url) {
        try {
            const resolved = new URL(typeof url === 'string' ? url : url.url, root.document.baseURI);
            if (resolved.origin !== root.location.origin) return null;
            for (const suffix of ['/actions/print_pick_tags.php', '/api/issuer/print_issue_tags.php']) {
                if (resolved.pathname.endsWith(suffix)) return {
                    capabilities: resolved.origin + resolved.pathname.slice(0, -suffix.length) + '/api/tablet_print_capabilities.php'
                };
            }
        } catch (_) { /* Non-print fetches keep their normal behavior. */ }
        return null;
    }
    const client = {
        confirmedClient: true,
        async fetch(url, options) {
            bindBridge();
            const body = options && options.body;
            const printer = body && (body.get('issue_printer') || body.get('pick_printer'));
            if (printer !== 'zebra' || (!bridge && !nativeApp)) return originalFetch(url, options);
            if (!bridge) throw new Error('The tablet Bluetooth bridge is unavailable. Update Android System WebView and reopen the app.');
            const ready = await request('prepare', {}, 120000);
            if (!ready.ok) throw new Error(ready.message || 'Select a Bluetooth QLn320 in the floating menu.');
            if (ready.transport === 'server') return originalFetch(url, options);
            if (ready.transport !== 'tablet_bluetooth') throw new Error('Unsupported tablet printer connection.');
            if (ready.protocol !== 'confirmed_print_v1') throw new Error('Update NBC Warehouse to version 2.0.1 or later to verify Bluetooth printing.');
            const endpoint = printEndpoint(url);
            if (!endpoint) throw new Error('Unsupported tablet print endpoint.');
            let capabilities;
            try {
                const check = await originalFetch(endpoint.capabilities, { credentials: 'same-origin', cache: 'no-store' });
                if (check.ok) capabilities = await check.json();
            } catch (_) { /* Fail before submitting any job to an older server. */ }
            if (!capabilities || capabilities.tablet_bluetooth !== true) {
                throw new Error('Update the website with the tablet printing server update, then sign in again. No print job was submitted.');
            }
            body.set('print_transport', 'tablet_bluetooth');
            const response = await originalFetch(url, options);
            if (!response.ok) return response;
            const prepared = await response.clone().json();
            if (!prepared.ok) return response;
            if (prepared.transport !== 'tablet_bluetooth' || !Array.isArray(prepared.labels) || !prepared.labels.length) {
                throw new Error('The server did not prepare tablet labels. Check the tablet printing server update.');
            }
            let result = await request('print', {
                labels: prepared.labels, delayMs: prepared.label_delay_ms || 0
            }, 90000 + prepared.labels.length * (65000 + (prepared.label_delay_ms || 0)));
            if (result.ok && (result.confirmed !== true || result.printed !== prepared.labels.length)) {
                result = { ...result, ok: false, printed: 0, failed: prepared.labels.length,
                    message: 'The printer did not confirm this batch. Check the printer for labels before retrying.' };
            }
            const payload = { ...prepared, ...result, queued: result.ok ? result.printed : 0,
                printer_name: result.printer_name || prepared.printer_name };
            delete payload.labels;
            return new Response(JSON.stringify(payload), { status: result.ok ? 200 : 409,
                headers: { 'Content-Type': 'application/json' } });
        }
    };
    if (nativeApp) Object.defineProperty(root, 'WarehousePrinter', { value: client, writable: false, configurable: false });
    else root.WarehousePrinter = client;
    // Also cover print buttons from older cached website pages inside the app.
    if (nativeApp) root.fetch = function (url, options) {
        return printEndpoint(url) && options && String(options.method).toUpperCase() === 'POST'
            ? client.fetch(url, options) : originalFetch(url, options);
    };
})(window);
