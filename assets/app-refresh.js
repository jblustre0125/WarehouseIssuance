(function () {
    function createRefreshController(tasks) {
        const state = {};

        function taskState(name) {
            if (!state[name]) {
                state[name] = {
                    busy: false,
                    timer: null
                };
            }

            return state[name];
        }

        async function run(name, fn, options) {
            const opts = options || {};
            const item = taskState(name);

            if (item.busy || (document.hidden && opts.skipWhenHidden !== false)) {
                return false;
            }

            item.busy = true;

            try {
                await fn();
                return true;
            } finally {
                item.busy = false;
            }
        }

        function schedule(name, fn, intervalMs, options) {
            const opts = options || {};
            const item = taskState(name);

            clearInterval(item.timer);
            item.timer = setInterval(function () {
                run(name, fn, opts);
            }, intervalMs);

            if (opts.immediate !== false) {
                run(name, fn, opts);
            }

            return item.timer;
        }

        function scheduleAll() {
            (tasks || []).forEach(function (task) {
                schedule(task.name, task.fn, task.intervalMs, task.options);
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                return;
            }

            (tasks || []).forEach(function (task) {
                if (task.options && task.options.refreshOnVisible === false) {
                    return;
                }

                run(task.name, task.fn, task.options);
            });
        });

        return {
            run: run,
            schedule: schedule,
            scheduleAll: scheduleAll
        };
    }

    window.createRefreshController = createRefreshController;
}());
