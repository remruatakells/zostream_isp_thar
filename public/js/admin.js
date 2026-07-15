const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('backdrop');
const menuButton = document.getElementById('menuButton');
const desktopNavigation = window.matchMedia('(min-width: 761px)');
const closeMenu = () => {
    sidebar?.classList.remove('open');
    backdrop?.classList.remove('show');
    syncMenuButton();
};
const syncMenuButton = () => {
    if (!menuButton) return;
    if (desktopNavigation.matches) {
        const collapsed = document.documentElement.classList.contains('nav-collapsed');
        menuButton.textContent = collapsed ? '→' : '←';
        menuButton.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        menuButton.setAttribute('aria-expanded', String(!collapsed));
        return;
    }

    const open = sidebar?.classList.contains('open') ?? false;
    menuButton.textContent = '☰';
    menuButton.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    menuButton.setAttribute('aria-expanded', String(open));
};
menuButton?.addEventListener('click', () => {
    if (desktopNavigation.matches) {
        document.documentElement.classList.toggle('nav-collapsed');
        try {
            localStorage.setItem(
                'zostream.sidebar.collapsed',
                document.documentElement.classList.contains('nav-collapsed') ? '1' : '0',
            );
        } catch (error) {
            // The layout still works when browser storage is unavailable.
        }
        syncMenuButton();
        return;
    }

    sidebar?.classList.toggle('open');
    backdrop?.classList.toggle('show');
    syncMenuButton();
});
backdrop?.addEventListener('click', closeMenu);
sidebar?.querySelectorAll('nav a').forEach(link => link.addEventListener('click', () => {
    if (!desktopNavigation.matches) closeMenu();
}));
desktopNavigation.addEventListener('change', closeMenu);
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !desktopNavigation.matches) closeMenu();
});
syncMenuButton();
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
