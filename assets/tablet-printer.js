/* Native Android bridge is injected only for the configured warehouse origin.
 * Ordinary browsers and Nitto printing retain the existing server workflow. */
(function (root) {
    'use strict';
    const pending = new Map();
    let nextId = 0;
    const bridge = root.WarehouseTablet;
    if (bridge && typeof bridge.postMessage === 'function') {
        bridge.onmessage = function (event) {
            let result;
            try { result = JSON.parse(event.data); } catch (_) { return; }
            const entry = pending.get(result.id);
            if (!entry) return;
            pending.delete(result.id);
            clearTimeout(entry.timer);
            entry.resolve(result);
        };
    }

    function request(action, payload, timeoutMs) {
        return new Promise((resolve, reject) => {
            const id = String(++nextId);
            const timer = setTimeout(() => {
                pending.delete(id);
                reject(new Error('The tablet printer did not respond. Check the printer for a label before trying again.'));
            }, timeoutMs);
            pending.set(id, { resolve, timer });
            try {
                bridge.postMessage(JSON.stringify({ id, action, ...payload }));
            } catch (error) {
                clearTimeout(timer);
                pending.delete(id);
                reject(error);
            }
        });
    }

    root.WarehousePrinter = {
        async fetch(url, options) {
            const body = options && options.body;
            const printer = body && (body.get('issue_printer') || body.get('pick_printer'));
            if (!bridge || typeof bridge.postMessage !== 'function' || printer !== 'zebra') {
                return root.fetch(url, options);
            }
            // Permission and printer selection happen before any server print request.
            const ready = await request('prepare', {}, 120000);
            if (!ready.ok) throw new Error(ready.message || 'Select a Bluetooth printer in the app.');
            if (ready.transport === 'server') return root.fetch(url, options);
            if (ready.transport !== 'tablet_bluetooth') throw new Error('Unsupported tablet printer connection.');

            body.set('print_transport', 'tablet_bluetooth');
            const response = await root.fetch(url, options);
            if (!response.ok) return response;
            const prepared = await response.clone().json();
            if (!prepared.ok) return response;
            if (prepared.transport !== 'tablet_bluetooth' || !Array.isArray(prepared.labels) || !prepared.labels.length) {
                throw new Error('The server has not enabled tablet Bluetooth printing. Update the website before using this app.');
            }
            const result = await request('print', {
                labels: prepared.labels,
                delayMs: prepared.label_delay_ms || 0
            }, 30000 + prepared.labels.length * (20000 + (prepared.label_delay_ms || 0)));
            const payload = {
                ...prepared,
                ...result,
                queued: result.ok ? result.printed : 0,
                printer_name: result.printer_name || prepared.printer_name
            };
            delete payload.labels;
            return new Response(JSON.stringify(payload), {
                status: result.ok ? 200 : 409,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    };
})(window);
