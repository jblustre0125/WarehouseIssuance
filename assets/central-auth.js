/* Keep each Warehouse tab's auth context on app endpoints only. */
(function () {
    'use strict';
    const script = document.currentScript;
    const context = script.dataset.authMode === 'central' ? 'central' : 'native';
    const root = new URL('../', script.src);
    const publicPaths = new Set(['api/get_part_name.php', 'api/issuer/lot_balance_lib.php', 'actions/save_tag.php', 'pages/tags/view_tags.php']);
    function url(input) {
        const destination = new URL(String(input), document.baseURI);
        if (destination.origin !== root.origin || !destination.pathname.startsWith(root.pathname)) return input;
        const path = destination.pathname.slice(root.pathname.length).toLowerCase();
        if (!/^(?:index\.php|(?:pages|actions|api)\/.+\.php|tools\/sync_scanplus_cache\.php)$/.test(path)
            || publicPaths.has(path)) return input;
        destination.searchParams.set('wh_auth', path === 'pages/auth/login.php' ? 'native' : context);
        return destination.href;
    }
    function request(input, options) {
        const target = typeof Request !== 'undefined' && input instanceof Request
            ? new Request(url(input.url), input) : url(input);
        return window.fetch(target, options);
    }
    function printFetch(input, options) {
        return window.WarehousePrinter.fetch(url(input), options);
    }
    window.WarehouseAuth = Object.freeze({ url, fetch: request, printFetch });
})();
