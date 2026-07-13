const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('backdrop');
const closeMenu = () => { sidebar?.classList.remove('open'); backdrop?.classList.remove('show'); };
document.getElementById('menuButton')?.addEventListener('click', () => { sidebar?.classList.add('open'); backdrop?.classList.add('show'); });
backdrop?.addEventListener('click', closeMenu);
document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!confirm(form.dataset.confirm)) event.preventDefault();
}));
