(function () {
    'use strict';
    function ready() {
        var root = document.documentElement;
        var themeButton = document.querySelector('[data-napay-theme-toggle]');
        var sidebarButton = document.querySelector('[data-napay-sidebar-toggle]');
        var sidebarCheckbox = document.getElementById('pushmenu_collapsed');
        function save(key, value) {
            try { localStorage.setItem(key, value); } catch (_) { /* page remains usable */ }
        }
        function updateThemeButton() {
            if (!themeButton) return;
            var dark = root.dataset.bsTheme === 'dark';
            var label = dark ? themeButton.dataset.labelLight : themeButton.dataset.labelDark;
            themeButton.setAttribute('aria-pressed', String(dark));
            themeButton.setAttribute('aria-label', label);
            themeButton.setAttribute('title', label);
            var icon = themeButton.querySelector('[data-napay-theme-icon]');
            if (icon) icon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
        }
        function updateSidebarButton() {
            var folded = root.dataset.napaySidebar === 'folded';
            if (sidebarButton) sidebarButton.setAttribute('aria-pressed', String(folded));
            if (sidebarCheckbox) {
                sidebarCheckbox.checked = !folded;
                var label = document.querySelector('label[for="pushmenu_collapsed"]');
                if (label) label.textContent = folded ? label.dataset.sidebarFoldedLabel : label.dataset.sidebarExpandedLabel;
            }
        }
        if (themeButton) themeButton.addEventListener('click', function () {
            root.dataset.bsTheme = root.dataset.bsTheme === 'dark' ? 'light' : 'dark';
            save('napay.theme', root.dataset.bsTheme);
            updateThemeButton();
            document.dispatchEvent(new CustomEvent('napay:themechange', { detail: { theme: root.dataset.bsTheme } }));
        });
        if (sidebarButton) sidebarButton.addEventListener('click', function () {
            root.dataset.napaySidebar = root.dataset.napaySidebar === 'folded' ? 'expanded' : 'folded';
            save('napay.sidebar', root.dataset.napaySidebar);
            updateSidebarButton();
        });
        if (sidebarCheckbox) sidebarCheckbox.addEventListener('change', function () {
            root.dataset.napaySidebar = sidebarCheckbox.checked ? 'expanded' : 'folded';
            save('napay.sidebar', root.dataset.napaySidebar);
            updateSidebarButton();
        });
        updateThemeButton();
        updateSidebarButton();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
