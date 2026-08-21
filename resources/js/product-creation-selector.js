function normalizeSearchValue(value) {
    return value.trim().toLocaleLowerCase();
}

function formatCount(element, count) {
    const template = count === 0
        ? element.dataset.countZero
        : count === 1
            ? element.dataset.countOne
            : element.dataset.countMany;

    element.textContent = (template ?? '').replace(':count', String(count));
}

function initializeProductCreationSelector(root) {
    if (root.dataset.initialized === 'true') {
        return;
    }

    const familyFilters = [...root.querySelectorAll('[data-product-family-filter]')];
    const searchInput = root.querySelector('[data-product-type-search]');
    const options = [...root.querySelectorAll('[data-product-type-option]')];
    const count = root.querySelector('[data-product-type-count]');
    const emptyState = root.querySelector('[data-product-type-empty]');

    if (!(searchInput instanceof HTMLInputElement) || !(count instanceof HTMLElement) || !(emptyState instanceof HTMLElement)) {
        return;
    }

    root.dataset.initialized = 'true';
    let selectedFamily = 'all';

    const filterOptions = () => {
        const query = normalizeSearchValue(searchInput.value);
        let visibleCount = 0;

        options.forEach((option) => {
            const matchesFamily = selectedFamily === 'all' || option.dataset.productEntry === selectedFamily;
            const matchesSearch = query === '' || normalizeSearchValue(option.dataset.productSearch ?? '').includes(query);
            const isVisible = matchesFamily && matchesSearch;

            option.hidden = !isVisible;
            visibleCount += isVisible ? 1 : 0;
        });

        formatCount(count, visibleCount);
        emptyState.hidden = visibleCount !== 0;
    };

    familyFilters.forEach((filter) => {
        filter.addEventListener('click', () => {
            selectedFamily = filter.dataset.productFamilyFilter ?? 'all';

            familyFilters.forEach((candidate) => {
                candidate.setAttribute('aria-pressed', candidate === filter ? 'true' : 'false');
            });

            filterOptions();
        });
    });

    searchInput.addEventListener('input', filterOptions);
    filterOptions();
}

export function initializeProductCreationSelectors() {
    document.querySelectorAll('[data-product-creation-selector]').forEach(initializeProductCreationSelector);
}
