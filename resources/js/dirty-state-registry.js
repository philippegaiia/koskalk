const NAVIGATION_BLOCKING_STATES = new Set(['dirty', 'saving', 'failed']);

export function createDirtyStateRegistry() {
    const states = new Map();

    return {
        set(key, state) {
            states.set(key, state);
        },

        remove(key) {
            states.delete(key);
        },

        blocksNavigation() {
            return [...states.values()].some((state) => NAVIGATION_BLOCKING_STATES.has(state));
        },
    };
}
