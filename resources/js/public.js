document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-mobile-menu-toggle]');
    const menu = document.querySelector('[data-mobile-menu]');
    const overlay = document.querySelector('[data-mobile-menu-overlay]');

    if (!toggle || !menu || !overlay) {
        return;
    }

    function closeMobileMenu() {
        menu.classList.add('hidden');
        overlay.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function openMobileMenu() {
        menu.classList.remove('hidden');
        overlay.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');
    }

    toggle.addEventListener('click', () => {
        if (menu.classList.contains('hidden')) {
            openMobileMenu();
        } else {
            closeMobileMenu();
        }
    });

    overlay.addEventListener('click', closeMobileMenu);

    menu.querySelectorAll('a[href^="#"]').forEach((link) => {
        link.addEventListener('click', closeMobileMenu);
    });
});
