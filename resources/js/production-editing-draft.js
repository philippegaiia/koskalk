const normalize = value => {
    if (Array.isArray(value)) return value.map(normalize);
    if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(key => [key, normalize(value[key])]));
    return value;
};
const copy = value => normalize(JSON.parse(JSON.stringify(value)));

export function createProductionDraft(initial) {
    const groups = new Map(Object.entries(initial).map(([name, value]) => [name, { value: copy(value), saved: copy(value), generation: 0 }]));
    const group = name => {
        if (!groups.has(name)) throw new Error(`Unknown production form group: ${name}`);
        return groups.get(name);
    };
    return {
        get(name) { return copy(group(name).value); },
        set(name, value) { const state = group(name); state.value = copy(value); state.generation++; },
        capture(name) { const state = group(name); return { name, generation: state.generation, value: copy(state.value) }; },
        acknowledge(submitted, canonical) {
            const state = group(submitted.name);
            state.saved = copy(canonical);
            if (state.generation === submitted.generation) state.value = copy(canonical);
        },
        acknowledgeTaskDate(submitted, canonical, taskId) {
            const state = group(submitted.name);
            const dates = { ...state.value.taskDates };
            for (const [id, date] of Object.entries(canonical.taskDates)) {
                const previous = String(taskId) === id ? submitted.value.taskDates[id] : state.saved.taskDates[id];
                if (dates[id] === previous) dates[id] = date;
            }
            state.saved = copy(canonical);
            state.value = copy({ ...state.value, taskDates: dates });
        },
        isDirty(name) { const state = group(name); return JSON.stringify(state.value) !== JSON.stringify(state.saved); },
        hasChanges() { return [...groups.keys()].some(name => this.isDirty(name)); },
        synchronizeCleanGroups(snapshot) {
            const synchronized = {};
            for (const [name, value] of Object.entries(snapshot)) {
                if (this.isDirty(name)) continue;
                const state = group(name); state.value = copy(value); state.saved = copy(value); state.generation++;
                synchronized[name] = copy(value);
            }
            return synchronized;
        },
        replaceClean(snapshot) {
            if (this.hasChanges()) return false;
            for (const [name, value] of Object.entries(snapshot)) {
                const state = group(name); state.value = copy(value); state.saved = copy(value); state.generation++;
            }
            return true;
        },
        discard(snapshot) {
            for (const [name, value] of Object.entries(snapshot)) {
                const state = group(name); state.value = copy(value); state.saved = copy(value); state.generation++;
            }
        },
    };
}
