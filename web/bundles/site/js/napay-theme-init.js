(function () {
    'use strict';
    var root = document.documentElement;
    try {
        var theme = localStorage.getItem('napay.theme');
        if (theme === 'light' || theme === 'dark') root.dataset.bsTheme = theme;
        var sidebar = localStorage.getItem('napay.sidebar');
        if (sidebar === 'folded' || sidebar === 'expanded') root.dataset.napaySidebar = sidebar;
    } catch (_) {
        // Storage may be disabled; server defaults remain usable.
    }
})();
