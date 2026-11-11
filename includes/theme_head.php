<script>
(function () {
    var KEY = 'granted_theme';
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try {
            var value = localStorage.getItem(KEY);
            return value === 'light' || value === 'dark' || value === 'system' ? value : 'system';
        } catch (e) {
            return 'system';
        }
    }

    function apply() {
        var pref = stored();
        var dark = pref === 'dark' || (pref === 'system' && !!mq && mq.matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    }

    apply();

    if (mq && typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', apply);
    } else if (mq && typeof mq.addListener === 'function') {
        mq.addListener(apply);
    }

    window.addEventListener('storage', function (event) {
        if (event.key === KEY) {
            apply();
        }
    });

    window.grantedTheme = {
        get: stored,
        set: function (pref) {
            if (pref !== 'light' && pref !== 'dark') {
                pref = 'system';
            }

            try {
                localStorage.setItem(KEY, pref);
            } catch (e) {}

            document.documentElement.setAttribute('data-theme',
                pref === 'dark' || (pref === 'system' && !!mq && mq.matches) ? 'dark' : 'light'
            );
        }
    };
})();
</script>
