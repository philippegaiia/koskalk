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

export function initializePrintDocument(documentRef = globalThis.document, windowRef = globalThis.window) {
    if (!documentRef || !windowRef) {
        return;
    }

    documentRef.addEventListener('click', (event) => {
        const target = event.target && typeof event.target.closest === 'function' ? event.target : null;

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
