function sectionToggles(documentRef) {
    return Array.from(documentRef.querySelectorAll('[data-formula-section-toggle]'));
}

function sectionId(toggle) {
    return toggle.getAttribute('data-formula-section-toggle') || toggle.value;
}

function selectedSectionIds(toggles) {
    return new Set(toggles.filter((toggle) => toggle.checked).map(sectionId));
}

function updateSectionVisibility(documentRef, toggles) {
    const selected = selectedSectionIds(toggles);

    documentRef.querySelectorAll('[data-formula-section]').forEach((section) => {
        section.hidden = !selected.has(section.getAttribute('data-formula-section'));
    });
}

function showEmptySelectionMessage(documentRef) {
    const status = documentRef.querySelector('[data-formula-section-status]');

    if (status) {
        status.textContent = status.getAttribute('data-empty-selection-message') || '';
    }
}

function returnToWorkbench(event, link, windowRef) {
    try {
        const opener = windowRef.opener;

        if (!opener || opener.closed) {
            return;
        }

        const destination = new URL(link.href);
        const original = new URL(opener.location.href);
        const sameWorkbench = original.origin === destination.origin
            && original.pathname === destination.pathname
            && Array.from(destination.searchParams).every(([key, value]) => original.searchParams.get(key) === value);

        if (!sameWorkbench) {
            return;
        }

        opener.focus();
        event.preventDefault();
        windowRef.close();
    } catch {
        // Cross-origin openers cannot be inspected; follow the normal workbench link.
    }
}

export function initializePrintDocument(documentRef = globalThis.document, windowRef = globalThis.window) {
    if (!documentRef || !windowRef) {
        return;
    }

    documentRef.addEventListener('click', (event) => {
        const target = event.target && typeof event.target.closest === 'function' ? event.target : null;
        const returnLink = target?.closest('[data-return-to-workbench]');

        if (returnLink && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            returnToWorkbench(event, returnLink, windowRef);

            return;
        }

        if (!target?.closest('[data-print-document]')) {
            return;
        }

        const toggles = sectionToggles(documentRef);

        if (toggles.length > 0 && selectedSectionIds(toggles).size === 0) {
            showEmptySelectionMessage(documentRef);

            return;
        }

        windowRef.print();
    });

    documentRef.addEventListener('change', (event) => {
        const target = event.target && typeof event.target.closest === 'function'
            ? event.target.closest('[data-formula-section-toggle]')
            : null;

        if (!target) {
            return;
        }

        const toggles = sectionToggles(documentRef);

        if (selectedSectionIds(toggles).size === 0) {
            target.checked = true;
            showEmptySelectionMessage(documentRef);
        } else {
            const status = documentRef.querySelector('[data-formula-section-status]');

            if (status) {
                status.textContent = '';
            }
        }

        updateSectionVisibility(documentRef, toggles);
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    initializePrintDocument(document, window);
}
