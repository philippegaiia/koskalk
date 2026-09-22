export function createContextualHelp({ document, window }) {
    let topics = {};
    let tabs = {};
    let mounted = false;
    let isOpen = false;
    let view = 'index';
    let currentTopic = null;
    let currentKeys = [];
    let currentTab = 'formula';
    let trigger = null;
    let background = [];
    let previousOverflow = null;
    const desktop = window.matchMedia('(min-width: 1024px)');
    const panel = () => document.querySelector('[data-contextual-help-panel]');
    const focusable = () => [...(panel()?.querySelectorAll('button:not([disabled]), a[href], [tabindex="0"]') ?? [])].filter(node => !node.hidden && node.getClientRects().length);

    function releaseBackground() {
        background.forEach(([node, inert]) => { node.inert = inert; });
        background = [];
        if (previousOverflow !== null) {
            document.body.style.overflow = previousOverflow;
            previousOverflow = null;
        }
    }

    function updateMode() {
        const element = panel();
        if (!element) return;
        releaseBackground();
        element.setAttribute('role', desktop.matches ? 'complementary' : 'dialog');
        if (!desktop.matches && isOpen) {
            element.setAttribute('aria-modal', 'true');
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            background = [...document.body.children].filter(node => node !== element && !['SCRIPT', 'STYLE', 'LINK'].includes(node.tagName)).map(node => [node, node.inert]);
            background.forEach(([node]) => { node.inert = true; });
        } else {
            element.removeAttribute('aria-modal');
        }
    }

    function render({ focus = false } = {}) {
        const element = panel();
        if (!element) return;
        element.hidden = !isOpen;
        updateMode();
        if (!isOpen) return;
        const heading = element.querySelector('[data-help-heading]');
        const content = element.querySelector('[data-help-content]');
        const back = element.querySelector('[data-help-back]');
        content.replaceChildren();
        back.hidden = view !== 'topic' || currentKeys.length === 0;
        heading.textContent = view === 'topic' ? currentTopic.title : element.dataset.indexTitle;
        if (view === 'topic') {
            const summary = document.createElement('p');
            summary.textContent = currentTopic.summary;
            content.append(summary);
            if (currentTopic.body_html) {
                const body = document.createElement('div');
                body.className = 'sk-help-prose';
                // The server validator and restrictive HTML renderer own this field.
                body.innerHTML = currentTopic.body_html;
                content.append(body);
            }
        } else {
            const list = document.createElement('ul');
            list.className = 'sk-help-topic-list';
            currentKeys.forEach(key => {
                const item = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.helpKey = key;
                button.textContent = topics[key].title;
                item.append(button);
                list.append(item);
            });
            content.append(list);
        }
        if (focus) window.requestAnimationFrame(() => { if (isOpen) heading.focus({ preventScroll: true }); });
    }

    function close({ restoreFocus = true } = {}) {
        isOpen = false;
        releaseBackground();
        const element = panel();
        if (element) {
            element.hidden = true;
            element.removeAttribute('aria-modal');
        }
        if (restoreFocus) {
            const destination = trigger?.isConnected ? trigger : document.querySelector('[data-help-index]');
            destination?.focus({ preventScroll: true });
        }
        trigger = null;
    }

    function openTopic(key, origin) {
        if (!Object.hasOwn(topics, key)) return;
        if (!isOpen || (origin && !panel()?.contains(origin))) trigger = origin;
        const originTab = origin?.closest?.('[role=tabpanel]')?.id?.replace(/^panel-/, '');
        if (originTab && Object.hasOwn(tabs, originTab)) currentTab = originTab;
        currentTopic = topics[key];
        currentKeys = (tabs[currentTab] ?? []).filter(key => Object.hasOwn(topics, key));
        view = 'topic';
        isOpen = true;
        render({ focus: true });
    }

    function openIndex(tab, origin) {
        const keys = (tabs[tab] ?? []).filter(key => Object.hasOwn(topics, key));
        if (!keys.length) return;
        if (!isOpen) trigger = origin;
        currentTab = tab;
        currentKeys = keys;
        currentTopic = null;
        view = 'index';
        isOpen = true;
        render({ focus: true });
    }

    function replaceScope(scope) {
        close({ restoreFocus: false });
        topics = scope.topics ?? {};
        tabs = scope.tabs ?? {};
        currentKeys = [];
        currentTopic = null;
        currentTab = 'formula';
    }

    function refreshScope() {
        const source = document.querySelector('[data-contextual-help-scope]');
        try { replaceScope(source ? JSON.parse(source.textContent) : {}); }
        catch { replaceScope({}); }
    }

    function onClick(event) {
        const origin = event.target.closest?.('[data-help-key], [data-help-close], [data-help-back]');
        if (!origin) return;
        if (origin.hasAttribute('data-help-key')) {
            event.preventDefault();
            openTopic(origin.dataset.helpKey, origin);
        } else if (origin.hasAttribute('data-help-close')) close();
        else openIndex(currentTab, trigger);
    }

    function onKeydown(event) {
        const origin = event.target.closest?.('a[data-help-key]');
        if (origin && ['Enter', ' '].includes(event.key)) {
            event.preventDefault();
            openTopic(origin.dataset.helpKey, origin);
            return;
        }
        if (!isOpen || event.defaultPrevented) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (!desktop.matches && event.key === 'Tab') {
            const elements = focusable();
            const first = elements[0];
            const last = elements.at(-1);
            if (event.shiftKey && (document.activeElement === first || !elements.includes(document.activeElement))) {
                event.preventDefault(); last?.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !elements.includes(document.activeElement))) {
                event.preventDefault(); first?.focus();
            }
        }
    }
    const onOpen = event => openTopic(event.detail.key, event.target);
    const onIndex = event => openIndex(event.detail.tab, event.target);
    const onNavigate = () => replaceScope({});
    const onModal = () => close({ restoreFocus: false });
    const onResize = () => { if (isOpen) render({ focus: true }); };
    const handlers = { click: onClick, keydown: onKeydown, 'contextual-help:open': onOpen, 'contextual-help:index': onIndex, 'contextual-help:modal': onModal, 'livewire:navigating': onNavigate, 'livewire:navigated': refreshScope, DOMContentLoaded: refreshScope };

    return {
        mount() {
            if (mounted) return;
            mounted = true;
            Object.entries(handlers).forEach(([name, handler]) => document.addEventListener(name, handler));
            window.addEventListener('open-modal', onModal);
            desktop.addEventListener('change', onResize);
            refreshScope();
        },
        destroy() {
            close({ restoreFocus: false });
            Object.entries(handlers).forEach(([name, handler]) => document.removeEventListener(name, handler));
            window.removeEventListener('open-modal', onModal);
            desktop.removeEventListener('change', onResize);
            mounted = false;
        },
        register: replaceScope,
        replaceScope,
        openTopic,
        openIndex,
        close,
        get isOpen() { return isOpen; },
        get view() { return view; },
        get currentTopic() { return currentTopic; },
        get currentKeys() { return currentKeys; },
    };
}
