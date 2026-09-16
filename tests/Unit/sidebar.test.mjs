import assert from 'node:assert/strict';
import test from 'node:test';

import { createSidebarController } from '../../resources/js/sidebar.js';

class FakeClassList {
    constructor() {
        this.values = new Set();
    }

    toggle(name, force) {
        const shouldHave = force ?? !this.values.has(name);

        if (shouldHave) {
            this.values.add(name);
        } else {
            this.values.delete(name);
        }

        return shouldHave;
    }

    contains(name) {
        return this.values.has(name);
    }
}

class FakeElement {
    constructor(documentRef, {
        attributes = {},
        children = [],
        display = 'block',
        hidden = false,
        name = '',
        rects = [{}],
    } = {}) {
        this.documentRef = documentRef;
        this.attributes = new Map(Object.entries(attributes));
        this.children = [];
        this.classList = new FakeClassList();
        this.dataset = {};
        this.display = display;
        this.focusCalls = [];
        this.hidden = hidden;
        this.isConnected = true;
        this.name = name;
        this.parentElement = null;
        this.rects = rects;
        this.style = {};

        children.forEach((child) => this.append(child));
    }

    append(child) {
        child.parentElement = this;
        this.children.push(child);
    }

    contains(candidate) {
        return this === candidate || this.children.some((child) => child.contains(candidate));
    }

    closest(selector) {
        let current = this;

        while (current) {
            if (current.matches(selector)) {
                return current;
            }

            current = current.parentElement;
        }

        return null;
    }

    focus(options) {
        this.focusCalls.push(options);
        this.documentRef.activeElement = this;
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    getClientRects() {
        return this.rects;
    }

    matches(selector) {
        if (selector === '[data-sidebar-overlay]') {
            return this.getAttribute('data-sidebar-overlay') !== null;
        }

        if (selector === '[data-sidebar-toggle]') {
            return this.getAttribute('data-sidebar-toggle') !== null;
        }

        if (selector === '[data-sidebar-close]') {
            return this.getAttribute('data-sidebar-close') !== null;
        }

        if (selector === '[data-sidebar-mobile-close]') {
            return this.getAttribute('data-sidebar-mobile-close') !== null;
        }

        if (selector === '[inert]') {
            return this.getAttribute('inert') !== null;
        }

        return false;
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    querySelectorAll(selector) {
        const descendants = [];
        const visit = (element) => {
            element.children.forEach((child) => {
                descendants.push(child);
                visit(child);
            });
        };

        visit(this);

        if (selector === '[data-sidebar-content]' || selector === '[data-sidebar-background]' || selector === '[data-sidebar-main]') {
            return descendants.filter((element) => element.getAttribute(selector.slice(1, -1)) !== null);
        }

        if (selector === '[data-sidebar-toggle]') {
            return descendants.filter((element) => element.getAttribute('data-sidebar-toggle') !== null);
        }

        if (selector === 'button[data-sidebar-toggle]') {
            return descendants.filter((element) => element.getAttribute('data-sidebar-toggle') !== null);
        }

        if (selector.includes('a[href]') || selector.includes('button:not([disabled])')) {
            return this.focusables ?? descendants;
        }

        return descendants;
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }
}

class FakeDocument {
    constructor() {
        this.activeElement = null;
        this.body = { style: { overflow: 'scroll', overscrollBehavior: 'auto' } };
        this.documentElement = { style: { setProperty: () => {} } };
        this.listeners = new Map();
        this.nodes = new Map();
    }

    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }

    install({ content, headerToggle, overlay, shell, sidebar }) {
        this.nodes = new Map([
            ['[data-app-shell]', shell],
            ['[data-sidebar]', sidebar],
            ['[data-sidebar-content]', content],
            ['[data-sidebar-background]', content],
            ['[data-sidebar-overlay]', overlay],
            ['[data-sidebar-header-toggle]', headerToggle],
        ]);
    }

    querySelector(selector) {
        return this.nodes.get(selector) ?? null;
    }

    removeEventListener(name) {
        this.listeners.delete(name);
    }
}

class FakeWindow {
    constructor(isDesktop = false, stored = null) {
        this.isDesktop = isDesktop;
        this.listeners = new Map();
        this.localStorage = {
            getItem: () => stored,
            setItem: (_key, value) => {
                stored = value;
            },
        };
    }

    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }

    getComputedStyle(element) {
        return { display: element.display, visibility: 'visible' };
    }

    matchMedia() {
        return { matches: this.isDesktop };
    }

    removeEventListener(name) {
        this.listeners.delete(name);
    }
}

function createFixture({ desktop = false, stored = null } = {}) {
    const documentRef = new FakeDocument();
    const windowRef = new FakeWindow(desktop, stored);
    const content = new FakeElement(documentRef, {
        attributes: { 'data-sidebar-background': '' },
        name: 'content',
    });
    const headerToggle = new FakeElement(documentRef, {
        attributes: { 'data-sidebar-toggle': '', 'data-sidebar-header-toggle': '' },
        name: 'header-toggle',
    });
    content.append(headerToggle);
    const overlay = new FakeElement(documentRef, {
        attributes: { 'data-sidebar-overlay': '', 'data-sidebar-close': '' },
        name: 'overlay',
    });
    const close = new FakeElement(documentRef, {
        attributes: { 'data-sidebar-close': '' },
        display: desktop ? 'none' : 'block',
        name: 'close',
    });
    const collapse = new FakeElement(documentRef, {
        attributes: { 'data-sidebar-toggle': '' },
        display: desktop ? 'block' : 'none',
        name: 'collapse',
    });
    const link = new FakeElement(documentRef, {
        attributes: { href: '/dashboard' },
        name: 'link',
    });
    const sidebar = new FakeElement(documentRef, {
        attributes: { 'data-sidebar': '' },
        children: [close, collapse, link],
        name: 'sidebar',
    });
    sidebar.focusables = [close, collapse, link];
    const shell = new FakeElement(documentRef, {
        attributes: { 'data-app-shell': '' },
        children: [sidebar, content],
        name: 'shell',
    });
    shell.dataset.sidebarOpen = 'true';
    documentRef.install({ content, headerToggle, overlay, shell, sidebar });

    const controller = createSidebarController({
        document: documentRef,
        window: windowRef,
    });

    return {
        close,
        collapse,
        content,
        controller,
        documentRef,
        headerToggle,
        link,
        overlay,
        shell,
        sidebar,
        windowRef,
    };
}

function click(target) {
    return { target };
}

function keydown(key, options = {}) {
    let prevented = false;

    return {
        key,
        ...options,
        get defaultPrevented() {
            return prevented;
        },
        preventDefault() {
            prevented = true;
        },
        wasPrevented() {
            return prevented;
        },
    };
}

test('opens the mobile drawer as a modal and restores focus, inert state, and scroll on close', () => {
    const fixture = createFixture();
    const { controller, documentRef, content, headerToggle, overlay, sidebar, shell, windowRef } = fixture;

    controller.initialize();
    documentRef.activeElement = headerToggle;
    controller.handleClick(click(headerToggle));

    assert.equal(shell.dataset.sidebarOpen, 'true');
    assert.equal(sidebar.getAttribute('inert'), null);
    assert.equal(sidebar.getAttribute('role'), 'dialog');
    assert.equal(sidebar.getAttribute('aria-modal'), 'true');
    assert.equal(content.getAttribute('inert'), '');
    assert.equal(documentRef.body.style.overflow, 'hidden');
    assert.equal(overlay.hidden, false);
    assert.equal(documentRef.activeElement, fixture.close);

    controller.handleKeydown(keydown('Escape'));

    assert.equal(shell.dataset.sidebarOpen, 'false');
    assert.equal(sidebar.getAttribute('inert'), '');
    assert.equal(sidebar.getAttribute('role'), null);
    assert.equal(sidebar.getAttribute('aria-modal'), null);
    assert.equal(content.getAttribute('inert'), null);
    assert.equal(documentRef.body.style.overflow, 'scroll');
    assert.equal(documentRef.activeElement, headerToggle);
    assert.equal(windowRef.localStorage.getItem(), null);
});

test('traps Tab in the mobile drawer and excludes hidden controls', () => {
    const fixture = createFixture();
    const { close, collapse, controller, documentRef, link } = fixture;

    collapse.rects = [];
    controller.initialize();
    documentRef.activeElement = close;
    controller.handleClick(click(fixture.headerToggle));

    documentRef.activeElement = link;
    const forward = keydown('Tab');
    controller.handleKeydown(forward);

    assert.equal(forward.wasPrevented(), true);
    assert.equal(documentRef.activeElement, close);

    const backward = keydown('Tab', { shiftKey: true });
    controller.handleKeydown(backward);

    assert.equal(backward.wasPrevented(), true);
    assert.equal(documentRef.activeElement, link);
});

test('preserves the open state and focus during same-breakpoint resizes', () => {
    const fixture = createFixture();
    const { controller, documentRef, link, windowRef } = fixture;

    controller.initialize();
    controller.handleClick(click(fixture.headerToggle));
    documentRef.activeElement = link;

    controller.handleResize();

    assert.equal(fixture.shell.dataset.sidebarOpen, 'true');
    assert.equal(documentRef.activeElement, link);
    assert.equal(windowRef.listeners.has('resize'), false);
});

test('moves focus into the visible desktop drawer when crossing from a focused mobile opener', () => {
    const fixture = createFixture({ stored: 'true' });
    const { controller, documentRef, headerToggle, windowRef } = fixture;

    controller.initialize();
    documentRef.activeElement = headerToggle;
    windowRef.isDesktop = true;
    controller.handleResize();

    assert.equal(fixture.shell.dataset.sidebarOpen, 'true');
    assert.equal(headerToggle.getAttribute('inert'), '');
    assert.equal(documentRef.activeElement, fixture.collapse);
});

test('returns focus to the mobile opener when crossing from an open desktop drawer', () => {
    const fixture = createFixture({ desktop: true, stored: 'true' });
    const { controller, documentRef, windowRef } = fixture;

    controller.initialize();
    documentRef.activeElement = fixture.collapse;
    windowRef.isDesktop = false;
    controller.handleResize();

    assert.equal(fixture.shell.dataset.sidebarOpen, 'false');
    assert.equal(documentRef.activeElement, fixture.headerToggle);
});

test('overlay close restores focus to the opener', () => {
    const fixture = createFixture();
    const { controller, documentRef, headerToggle, overlay } = fixture;

    controller.initialize();
    documentRef.activeElement = headerToggle;
    controller.handleClick(click(headerToggle));
    controller.handleClick(click(overlay));

    assert.equal(documentRef.activeElement, headerToggle);
});

test('closes the mobile drawer and releases scrolling when Livewire replaces the shell', () => {
    const fixture = createFixture();
    const { controller, documentRef, headerToggle, windowRef } = fixture;

    controller.initialize();
    documentRef.activeElement = headerToggle;
    controller.handleClick(click(headerToggle));

    const replacement = createFixture();
    fixture.shell.isConnected = false;
    fixture.sidebar.isConnected = false;
    documentRef.install({
        content: replacement.content,
        headerToggle: replacement.headerToggle,
        overlay: replacement.overlay,
        shell: replacement.shell,
        sidebar: replacement.sidebar,
    });
    windowRef.isDesktop = false;
    controller.refresh();

    assert.equal(replacement.shell.dataset.sidebarOpen, 'false');
    assert.equal(replacement.content.getAttribute('inert'), null);
    assert.equal(documentRef.body.style.overflow, 'scroll');
    assert.equal(documentRef.body.style.overscrollBehavior, 'auto');
});


test('desktop collapse and reopening keep focus on the visible toggle', () => {
    const fixture = createFixture({ desktop: true });
    const { controller, documentRef, headerToggle, collapse, sidebar } = fixture;
    controller.initialize();
    documentRef.activeElement = collapse;

    controller.handleClick(click(collapse));

    assert.equal(documentRef.activeElement, headerToggle);
    assert.equal(sidebar.getAttribute('inert'), '');
    assert.equal(headerToggle.getAttribute('aria-expanded'), 'false');

    controller.handleClick(click(headerToggle));

    assert.equal(documentRef.activeElement, collapse);
    assert.equal(headerToggle.getAttribute('inert'), '');
    assert.equal(sidebar.getAttribute('inert'), null);
    assert.equal(headerToggle.getAttribute('aria-expanded'), 'true');
});
