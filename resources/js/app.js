import './bootstrap';
import { createRecipeWorkbench } from './recipe-workbench/component';

window.recipeWorkbench = createRecipeWorkbench;

const SIDEBAR_STORAGE_KEY = 'koskalk:sidebar-open';
const DESKTOP_MEDIA_QUERY = '(min-width: 1024px)';

function sidebarIsDesktop() {
    return window.matchMedia(DESKTOP_MEDIA_QUERY).matches;
}

function sidebarStoredState() {
    const stored = window.localStorage.getItem(SIDEBAR_STORAGE_KEY);

    if (stored === null) {
        return sidebarIsDesktop();
    }

    return stored === 'true';
}

function setSidebarState(open, persist = true) {
    const shell = document.querySelector('[data-app-shell]');
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const headerToggle = document.querySelector('[data-sidebar-header-toggle]');

    if (!shell || !sidebar) {
        return;
    }

    const isDesktop = sidebarIsDesktop();
    const nextOpen = isDesktop ? open : false;

    shell.dataset.sidebarOpen = nextOpen ? 'true' : 'false';
    shell.style.gridTemplateColumns = isDesktop
        ? `${nextOpen ? '17rem' : '0'} minmax(0, 1fr)`
        : '';

    sidebar.style.width = isDesktop ? (nextOpen ? '17rem' : '0') : '';
    sidebar.style.opacity = isDesktop ? (nextOpen ? '1' : '0') : '';
    sidebar.style.padding = isDesktop ? (nextOpen ? '1.5rem 1.25rem' : '0') : '';
    sidebar.style.pointerEvents = isDesktop && !nextOpen ? 'none' : '';

    sidebar.classList.toggle('-translate-x-full', !nextOpen);
    sidebar.classList.toggle('translate-x-0', nextOpen);
    sidebar.classList.toggle('lg:w-0', isDesktop && !nextOpen);
    sidebar.classList.toggle('lg:px-0', isDesktop && !nextOpen);
    sidebar.classList.toggle('lg:py-0', isDesktop && !nextOpen);
    sidebar.classList.toggle('lg:opacity-0', isDesktop && !nextOpen);
    sidebar.classList.toggle('lg:pointer-events-none', isDesktop && !nextOpen);

    overlay?.classList.toggle('hidden', !nextOpen || isDesktop);

    if (headerToggle) {
        headerToggle.classList.toggle('lg:pointer-events-none', nextOpen && isDesktop);
        headerToggle.classList.toggle('lg:-translate-x-2', nextOpen && isDesktop);
        headerToggle.classList.toggle('lg:opacity-0', nextOpen && isDesktop);
    }

    if (persist) {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, nextOpen ? 'true' : 'false');
    }
}

function initializeSidebar() {
    setSidebarState(sidebarStoredState(), false);
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;

    if (target?.closest('[data-sidebar-toggle]')) {
        const shell = document.querySelector('[data-app-shell]');

        setSidebarState(shell?.dataset.sidebarOpen !== 'true');
    }

    if (target?.closest('[data-sidebar-close]')) {
        setSidebarState(false);
    }

    if (target?.closest('[data-sidebar-mobile-close]') && !sidebarIsDesktop()) {
        setSidebarState(false);
    }
});

window.addEventListener('resize', initializeSidebar);
document.addEventListener('DOMContentLoaded', initializeSidebar);
document.addEventListener('livewire:navigated', initializeSidebar);
