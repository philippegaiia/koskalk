export const SIDEBAR_STORAGE_KEY = 'koskalk:sidebar-open';
export const DESKTOP_MEDIA_QUERY = '(min-width: 1024px)';

const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'summary',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function setAttribute(element, name, value) {
    element?.setAttribute?.(name, value);
}

function removeAttribute(element, name) {
    element?.removeAttribute?.(name);
}

function setInert(element, inert) {
    if (!element) {
        return;
    }

    if ('inert' in element) {
        element.inert = inert;
    }

    if (inert) {
        setAttribute(element, 'inert', '');
    } else {
        removeAttribute(element, 'inert');
    }
}

function toggleClass(element, className, force) {
    element?.classList?.toggle?.(className, force);
}

function focusElement(element) {
    if (!element || typeof element.focus !== 'function') {
        return;
    }

    try {
        element.focus({ preventScroll: true });
    } catch {
        element.focus();
    }
}

function isHidden(element, windowRef) {
    const computedStyle = windowRef?.getComputedStyle?.(element);

    return element?.hidden === true
        || element?.getAttribute?.('aria-hidden') === 'true'
        || Boolean(element?.closest?.('[inert]'))
        || computedStyle?.display === 'none'
        || computedStyle?.visibility === 'hidden'
        || (typeof element?.getClientRects === 'function' && element.getClientRects().length === 0);
}

function isFocusable(element, windowRef) {
    if (!element || isHidden(element, windowRef)) {
        return false;
    }

    if (element.disabled === true || element.getAttribute?.('disabled') !== null) {
        return false;
    }

    return element.getAttribute?.('tabindex') !== '-1';
}

function focusableElements(root, windowRef) {
    if (!root?.querySelectorAll) {
        return [];
    }

    return [...root.querySelectorAll(FOCUSABLE_SELECTOR)].filter((element) => isFocusable(element, windowRef));
}

function elementWithin(element, root) {
    return element === root || root?.contains?.(element) === true;
}

export function createSidebarController({
    document: documentRef = globalThis.document,
    window: windowRef = globalThis.window,
    storageKey = SIDEBAR_STORAGE_KEY,
    mediaQuery = DESKTOP_MEDIA_QUERY,
    applySidebarWidth = () => {},
} = {}) {
    let lastDesktop = null;
    let mounted = false;
    let openState = null;
    let opener = null;
    let previousBodyOverflow = null;
    let previousBodyOverscrollBehavior = null;

    function shell() {
        return documentRef?.querySelector?.('[data-app-shell]') ?? null;
    }

    function sidebar() {
        return documentRef?.querySelector?.('[data-sidebar]') ?? null;
    }

    function content() {
        const appShell = shell();

        return appShell?.querySelector?.('[data-sidebar-background]') ?? null;
    }

    function overlay() {
        return documentRef?.querySelector?.('[data-sidebar-overlay]') ?? null;
    }

    function headerToggle() {
        return documentRef?.querySelector?.('[data-sidebar-header-toggle]') ?? null;
    }

    function desktopToggle() {
        return sidebar()?.querySelector?.('[data-sidebar-toggle]') ?? null;
    }

    function isDesktop() {
        return windowRef?.matchMedia?.(mediaQuery)?.matches === true;
    }

    function storedState() {
        let stored = null;

        try {
            stored = windowRef?.localStorage?.getItem?.(storageKey) ?? null;
        } catch {
            stored = null;
        }

        if (stored === null) {
            return isDesktop();
        }

        return stored === 'true';
    }

    function currentState() {
        const appShell = shell();

        if (openState !== null) {
            return openState;
        }

        if (appShell?.dataset?.sidebarOpen !== undefined) {
            return appShell.dataset.sidebarOpen === 'true';
        }

        return false;
    }

    function setBodyScrollLock(locked) {
        const body = documentRef?.body;

        if (!body?.style) {
            return;
        }

        if (locked) {
            if (previousBodyOverflow === null) {
                previousBodyOverflow = body.style.overflow ?? '';
            }

            if (previousBodyOverscrollBehavior === null) {
                previousBodyOverscrollBehavior = body.style.overscrollBehavior ?? '';
            }

            body.style.overflow = 'hidden';
            body.style.overscrollBehavior = 'contain';

            return;
        }

        if (previousBodyOverflow !== null) {
            body.style.overflow = previousBodyOverflow;
            previousBodyOverflow = null;
        }

        if (previousBodyOverscrollBehavior !== null) {
            body.style.overscrollBehavior = previousBodyOverscrollBehavior;
            previousBodyOverscrollBehavior = null;
        }
    }

    function setHeaderToggleState(element, nextOpen, desktop) {
        if (!element) {
            return;
        }

        const hidden = desktop && nextOpen;

        toggleClass(element, 'lg:pointer-events-none', hidden);
        toggleClass(element, 'lg:-translate-x-2', hidden);
        toggleClass(element, 'lg:opacity-0', hidden);
        setInert(element, hidden);

        if (hidden) {
            setAttribute(element, 'aria-hidden', 'true');
            setAttribute(element, 'tabindex', '-1');
        } else {
            removeAttribute(element, 'aria-hidden');
            removeAttribute(element, 'tabindex');
        }
    }

    function setControlState(nextOpen) {
        const appShell = shell();
        const controls = appShell?.querySelectorAll?.('button[data-sidebar-toggle]') ?? [];

        for (const control of controls) {
            setAttribute(control, 'aria-controls', 'app-sidebar');
            setAttribute(control, 'aria-expanded', nextOpen ? 'true' : 'false');
        }
    }

    function closeAndRestoreFocus(fallback = null) {
        const restoreTarget = opener ?? fallback ?? headerToggle();

        opener = null;

        if (restoreTarget && !isHidden(restoreTarget, windowRef)) {
            focusElement(restoreTarget);
        }
    }

    function setState(nextOpen, {
        persist = true,
        opener: nextOpener = null,
        restoreFocus = false,
        focusInside = false,
    } = {}) {
        const appShell = shell();
        const sidebarElement = sidebar();

        if (!appShell || !sidebarElement) {
            return false;
        }

        const desktop = isDesktop();
        const wasOpen = currentState();
        const nextState = Boolean(nextOpen);
        const wasMobileOpen = wasOpen && !desktop;
        const restoreTarget = opener;

        if (nextState && !desktop && nextOpener) {
            opener = nextOpener;
        }

        openState = nextState;
        appShell.dataset.sidebarOpen = nextState ? 'true' : 'false';
        applySidebarWidth({ isDesktop: desktop, nextOpen: nextState });
        appShell.style.gridTemplateColumns = desktop
            ? `${nextState ? '17rem' : '0'} minmax(0, 1fr)`
            : '';

        sidebarElement.style.width = desktop ? (nextState ? '17rem' : '0') : '';
        sidebarElement.style.opacity = desktop ? (nextState ? '1' : '0') : '';
        sidebarElement.style.padding = desktop ? (nextState ? '1.5rem 1.25rem' : '0') : '';
        sidebarElement.style.pointerEvents = nextState ? '' : 'none';

        toggleClass(sidebarElement, '-translate-x-full', !nextState);
        toggleClass(sidebarElement, 'translate-x-0', nextState);
        toggleClass(sidebarElement, 'lg:w-0', desktop && !nextState);
        toggleClass(sidebarElement, 'lg:px-0', desktop && !nextState);
        toggleClass(sidebarElement, 'lg:py-0', desktop && !nextState);
        toggleClass(sidebarElement, 'lg:opacity-0', desktop && !nextState);
        toggleClass(sidebarElement, 'lg:pointer-events-none', desktop && !nextState);
        setInert(sidebarElement, !nextState);
        setAttribute(sidebarElement, 'aria-hidden', nextState ? 'false' : 'true');
        if (!desktop && nextState) {
            setAttribute(sidebarElement, 'role', 'dialog');
            setAttribute(sidebarElement, 'aria-modal', 'true');
        } else {
            removeAttribute(sidebarElement, 'role');
            removeAttribute(sidebarElement, 'aria-modal');
        }

        setControlState(nextState);
        setHeaderToggleState(headerToggle(), nextState, desktop);

        const overlayElement = overlay();
        const overlayHidden = !nextState || desktop;

        if (overlayElement) {
            overlayElement.hidden = overlayHidden;
            toggleClass(overlayElement, 'hidden', overlayHidden);
            setAttribute(overlayElement, 'aria-hidden', overlayHidden ? 'true' : 'false');
        }

        const contentElement = content();
        const backgroundInert = !desktop && nextState;

        setInert(contentElement, backgroundInert);

        if (backgroundInert) {
            setBodyScrollLock(true);
        } else {
            setBodyScrollLock(false);
        }

        if (nextState && focusInside) {
            const focusTarget = focusableElements(sidebarElement, windowRef)[0];

            if (!focusTarget) {
                setAttribute(sidebarElement, 'tabindex', '-1');
            }

            focusElement(focusTarget ?? sidebarElement);
        }

        if (!nextState && restoreFocus && (wasMobileOpen || desktop)) {
            closeAndRestoreFocus(restoreTarget ?? headerToggle());
        }

        if (persist && desktop) {
            try {
                windowRef?.localStorage?.setItem?.(storageKey, nextState ? 'true' : 'false');
            } catch {
                // Storage can be unavailable in private browsing contexts.
            }
        }

        return true;
    }

    function initialize() {
        const desktop = isDesktop();

        if (!shell() || !sidebar()) {
            return false;
        }

        lastDesktop = desktop;

        return setState(desktop ? storedState() : false, {
            persist: false,
            restoreFocus: false,
            focusInside: false,
        });
    }

    function refresh() {
        const appShell = shell();
        const sidebarElement = sidebar();

        if (!appShell || !sidebarElement) {
            setBodyScrollLock(false);
            openState = false;
            opener = null;

            return false;
        }

        if (lastDesktop === null) {
            return initialize();
        }

        if (opener && opener.isConnected === false) {
            opener = null;
        }

        return setState(isDesktop() ? storedState() : false, {
            persist: false,
            restoreFocus: false,
            focusInside: false,
        });
    }

    function handleResize() {
        const desktop = isDesktop();

        if (lastDesktop === null) {
            initialize();

            return;
        }

        if (desktop === lastDesktop) {
            return;
        }

        const activeElement = documentRef?.activeElement ?? null;
        const activeWasInSidebar = elementWithin(activeElement, sidebar());
        const activeWasHeaderToggle = activeElement === headerToggle();
        const nextState = desktop ? storedState() : false;

        lastDesktop = desktop;
        setState(nextState, {
            persist: false,
            restoreFocus: false,
            focusInside: false,
        });

        if (desktop && nextState && (activeWasInSidebar || activeWasHeaderToggle)) {
            focusElement(desktopToggle() ?? focusableElements(sidebar(), windowRef)[0]);
        } else if (!nextState && (activeWasInSidebar || activeWasHeaderToggle)) {
            focusElement(headerToggle());
        }
    }

    function handleClick(event) {
        const target = event?.target;

        if (!target?.closest) {
            return;
        }

        const toggle = target.closest('[data-sidebar-toggle]');

        if (toggle) {
            const nextState = !currentState();

            setState(nextState, {
                persist: true,
                opener: nextState ? toggle : null,
                restoreFocus: !nextState,
                focusInside: nextState,
            });

            return;
        }

        const close = target.closest('[data-sidebar-close]');

        if (close) {
            setState(false, {
                persist: true,
                restoreFocus: true,
                focusInside: false,
            });

            return;
        }

        const mobileClose = target.closest('[data-sidebar-mobile-close]');

        if (mobileClose && !isDesktop()) {
            setState(false, {
                persist: false,
                restoreFocus: false,
                focusInside: false,
            });
        }
    }

    function handleKeydown(event) {
        if (event?.defaultPrevented || isDesktop() || !currentState()) {
            return;
        }

        const sidebarElement = sidebar();

        if (!sidebarElement) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault?.();
            setState(false, {
                persist: false,
                restoreFocus: true,
                focusInside: false,
            });

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusables = focusableElements(sidebarElement, windowRef);

        if (focusables.length === 0) {
            event.preventDefault?.();
            setAttribute(sidebarElement, 'tabindex', '-1');
            focusElement(sidebarElement);

            return;
        }

        const activeElement = documentRef?.activeElement ?? null;
        const activeIndex = focusables.indexOf(activeElement);

        if (event.shiftKey) {
            if (activeIndex > 0) {
                return;
            }

            event.preventDefault?.();
            focusElement(focusables[focusables.length - 1]);

            return;
        }

        if (activeIndex >= 0 && activeIndex < focusables.length - 1) {
            return;
        }

        event.preventDefault?.();
        focusElement(focusables[0]);
    }

    function mount() {
        if (mounted) {
            return;
        }

        mounted = true;
        documentRef?.addEventListener?.('click', handleClick);
        documentRef?.addEventListener?.('keydown', handleKeydown);
        windowRef?.addEventListener?.('resize', handleResize);
    }

    function unmount() {
        if (!mounted) {
            return;
        }

        mounted = false;
        documentRef?.removeEventListener?.('click', handleClick);
        documentRef?.removeEventListener?.('keydown', handleKeydown);
        windowRef?.removeEventListener?.('resize', handleResize);
        setBodyScrollLock(false);
    }

    return {
        handleClick,
        handleKeydown,
        handleResize,
        initialize,
        isDesktop,
        mount,
        refresh,
        setState,
        storedState,
        unmount,
    };
}
