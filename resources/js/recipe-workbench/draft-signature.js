/**
 * Persistence regenerates ingredient-row IDs. Compare the formula values and
 * row order without treating those presentation identities as unsaved edits.
 */
export function draftSignature(draft) {
    const phaseItems = Object.fromEntries(
        Object.entries(draft.phase_items ?? {}).map(([phaseKey, rows]) => [
            phaseKey,
            rows.map(({ id, ...row }) => row),
        ]),
    );

    return JSON.stringify({ ...draft, phase_items: phaseItems });
}
