import { createCalculatorStorage } from '../../guest-calculator-storage.js';
import { serializeDraft } from '../payload';

const fields = ['name', 'oil_unit', 'oil_weight', 'editing_mode', 'lye_type', 'koh_purity_percentage', 'dual_lye_koh_percentage', 'water_mode', 'water_value', 'superfat', 'cured_water_percentage', 'phases', 'phase_items', 'liquid_process'];
const previewSignature = (draft) => JSON.stringify({ ...draft, name: '', notes: '' });
const clone = (value) => JSON.parse(JSON.stringify(value));

/** Guest transport and recovery for the shared workbench, without a second editor. */
export function captureGuestDraft(workbench, serialized = serializeDraft(workbench)) {
    const draft = Object.fromEntries(fields.filter((key) => serialized[key] !== undefined).map((key) => [key, serialized[key]]));
    draft.notes = workbench.guestNotes;
    draft.phase_items = Object.fromEntries(Object.entries(serialized.phase_items).map(([phase, rows]) => [phase, rows.map((row, index) => {
        const source = workbench.phaseItems[phase][index];
        const ingredient = workbench.ingredients.find((item) => item.id === row.ingredient_id);
        return { ...row, name: source.name, fingerprint: ingredient?.fingerprint ?? source.fingerprint ?? null };
    })]));
    if (draft.liquid_process) {
        const measured = workbench.liquidProcess?.measuredPasteWeight;
        draft.liquid_process.measured_paste_weight = measured === '' || measured == null ? null : Number(measured);
    }
    return clone(draft);
}

export function hydrateGuestDraft(draft, catalogue) {
    const ingredientMap = new Map(catalogue.map((ingredient) => [ingredient.id, ingredient]));
    return {
        formulaName: draft.name ?? '', oilUnit: draft.oil_unit, oilWeight: draft.oil_weight,
        editMode: draft.editing_mode, lyeType: draft.lye_type, kohPurity: draft.koh_purity_percentage,
        dualKohPercentage: draft.dual_lye_koh_percentage, waterMode: draft.water_mode,
        waterValue: draft.water_value, superfat: draft.superfat, curedWaterPercentage: draft.cured_water_percentage,
        phases: draft.phases,
        phaseItems: Object.fromEntries(Object.entries(draft.phase_items).map(([phase, rows]) => [phase, rows.map((row) => ({
            ...ingredientMap.get(row.ingredient_id), ...row,
            name: ingredientMap.get(row.ingredient_id)?.name ?? row.name,
            inci_name: ingredientMap.get(row.ingredient_id)?.inci_name ?? '',
            fatty_acid_profile: ingredientMap.get(row.ingredient_id)?.fatty_acid_profile ?? {},
            available_phases: ingredientMap.get(row.ingredient_id)?.available_phases ?? [],
            fingerprint: ingredientMap.get(row.ingredient_id)?.fingerprint ?? row.fingerprint,
        }))])),
        ...(draft.liquid_process ? { liquidProcess: {
            firstDilutionPercentage: draft.liquid_process.first_dilution_percentage,
            finalDilutionPercentage: draft.liquid_process.final_dilution_percentage,
            neutralizerSolutionPercentage: draft.liquid_process.neutralizer_solution_percentage,
        } } : {}),
    };
}

export function createGuestSection(payload, dependencies = {}) {
    const configuration = payload.guestCalculator;
    const storage = configuration ? (dependencies.storage ?? createCalculatorStorage(configuration.key)) : null;
    let references = {};
    let saveTimer;
    let lastPreview = '';
    let ready = false;
    let pageHideHandler;
    let storageHandler;
    return {
        isGuestCalculator: Boolean(configuration),
        guestNotes: '', guestNotices: [], guestStorageMessage: '', guestResetPending: false,
        guestTemporaryName: '', guestTemporaryPhase: configuration?.key === 'cosmetics' ? 'phase_a' : 'additives',
        guestWarnings: [], guestLabeling: { text: '', incomplete: true }, guestOutputPending: false, guestPreviewRetryAt: 0,
        guestAnalysisAvailable: true,
        guestText(key) { return configuration?.messages?.[key] ?? key; },
        get guestCanPrint() {
            return this.isGuestCalculator && !this.isPreviewingCalculation && !this.calculationPreviewPending
                && lastPreview === previewSignature(captureGuestDraft(this));
        },
        initializeGuestCalculator() {
            if (!configuration) { return; }
            const recovery = storage.read(this.ingredients);
            references = recovery.references ?? {};
            const draft = recovery.draft ?? configuration.defaults;
            this.applyDraft(hydrateGuestDraft(draft, this.ingredients));
            this.guestNotes = draft.notes ?? '';
            // A restored calculation already has its settings; show the summary like a saved formula.
            if (recovery.status === 'recovered') { this.isFormulaSettingsOpen = false; this.formulaSettingsOverflow = false; }
            if (draft.liquid_process && this.liquidProcess) {
                this.liquidProcess.measuredPasteWeight = draft.liquid_process.measured_paste_weight ?? '';
            }
            this.guestNotices = [
                ...(recovery.status === 'recovered' ? [this.guestText('recovered')] : []),
                ...(recovery.changed?.length ? [this.guestText('reference_changed')] : []),
                ...(recovery.retired?.length ? [this.guestText('reference_retired')] : []),
                ...(recovery.status === 'corrupt' ? [this.guestText('storage_corrupt')] : []),
            ];
            this.guestStorageMessage = this.guestText(recovery.status === 'unavailable' ? 'unavailable_storage' : 'browser_notice');
            ready = true;
            pageHideHandler = () => this.storeGuestDraft();
            window.addEventListener?.('pagehide', pageHideHandler);
            storageHandler = (event) => {
                if (event.key === storage.key && storage.hasConflict?.()) { this.guestStorageMessage = this.guestText('storage_conflict'); }
            };
            window.addEventListener?.('storage', storageHandler);
            ['phaseItems', 'phaseOrder', 'formulaName', 'guestNotes', 'oilUnit', 'oilWeight', 'editMode', 'lyeType', 'kohPurity', 'dualKohPercentage', 'waterMode', 'waterValue', 'superfat', 'curedWaterPercentage', 'liquidProcess'].forEach((key) => {
                this.$watch(key, () => {
                    if (['oilUnit', 'editMode', 'phaseOrder', 'curedWaterPercentage'].includes(key)) { this.scheduleCalculationPreview(); }
                    if (!ready) { return; }
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(() => this.storeGuestDraft(), 200);
                });
            });
        },
        storeGuestDraft() {
            if (!configuration || !ready) { return; }
            const draft = captureGuestDraft(this);
            const used = {};
            Object.values(draft.phase_items).flat().forEach((row) => {
                if (row.ingredient_id != null) {
                    used[row.ingredient_id] = references[row.ingredient_id] ?? { name: row.name, fingerprint: row.fingerprint };
                }
            });
            const status = storage.write(draft, used).status;
            this.guestStorageMessage = this.guestText(status === 'saved' ? 'browser_notice' : (status === 'conflict' ? 'storage_conflict' : 'storage_failed')); 
        },
        async previewGuestCalculation(serialized) {
            const submitted = captureGuestDraft(this, serialized);
            const response = await (dependencies.fetch ?? globalThis.fetch)(configuration.previewUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': configuration.csrfToken },
                body: JSON.stringify({ draft: submitted }),
            });
            if (response.status === 429) {
                const seconds = Math.min(300, Math.max(1, Number(response.headers?.get('Retry-After')) || 60));
                return { ok: false, message: this.guestText('preview_throttled'), retryAt: Date.now() + seconds * 1000 };
            }
            const result = await response.json();
            return response.ok ? { ok: true, calculation: result.calculation, labeling: result.workbenchLabeling,
                restrictions: null, guest: result, guestSignature: previewSignature(submitted) }
                : { ok: false, message: Object.values(result.errors ?? {}).flat().join(' ') || result.message || this.guestText('preview_failed') };
        },
        applyGuestPreview(response) {
            this.guestPreviewRetryAt = response.retryAt ?? 0;
            if (response.retryAt) { this.scheduleCalculationPreview(); }
            lastPreview = response.ok ? response.guestSignature : '';
            this.guestWarnings = response.guest?.warnings ?? [];
            this.guestLabeling = response.guest?.labeling ?? { text: '', incomplete: true };
            this.guestAnalysisAvailable = response.guest?.document?.soap_analysis != null;
            if (response.ok) {
                references = response.guest.references ?? {};
                this.storeGuestDraft();
            }
        },
        addGuestTemporaryIngredient() {
            const name = this.guestTemporaryName.trim();
            const phase = this.guestTemporaryPhase;
            if (!name || this.formulaItemLimitReached() || !Array.isArray(this.phaseItems[phase])
                || (!this.isCosmeticFormula && !['additives', 'fragrance'].includes(phase))) { return; }
            this.phaseItems[phase].push({ id: `temporary-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                ingredient_id: null, name, inci_name: '', category: '', available_phases: [],
                can_add_to_saponified_oils: false, fatty_acid_profile: {}, note: '',
                percentage: this.isCosmeticFormula && this.cosmeticFormulaRows().length === 0 ? 100 : 0,
                weight: 0, ...(this.isLiquidSoapFormula ? { final_percentage: 0 } : {}),
            });
            this.guestTemporaryName = '';
        },
        requestGuestReset() { this.guestResetPending = true; },
        cancelGuestReset() { this.guestResetPending = false; },
        resetGuestCalculation() {
            clearTimeout(saveTimer);
            const cleared = storage.clear();
            if (cleared.status === 'conflict') { this.guestStorageMessage = this.guestText('storage_conflict'); this.guestResetPending = false; return; }
            ready = false;
            this.guestStorageMessage = this.guestText(cleared.status === 'cleared' ? 'browser_notice' : 'storage_failed');
            references = {};
            this.applyDraft(hydrateGuestDraft(clone(configuration.defaults), this.ingredients));
            this.addDefaultSoapOil();
            this.guestNotes = ''; this.guestNotices = []; this.guestResetPending = false;
            if (this.liquidProcess) { this.liquidProcess.measuredPasteWeight = ''; }
            this.applyGuestPreview({ ok: false });
            this.backendCalculation = null; this.backendLabeling = null; this.backendRestrictions = null;
            this.$nextTick(() => { ready = true; this.scheduleCalculationPreview(); });
        },
        printGuestFormula() {
            if (!this.guestCanPrint) { return; }
            const form = document.createElement('form');
            form.method = 'POST'; form.action = configuration.printUrl; form.target = '_blank';
            for (const [name, value] of Object.entries({ _token: configuration.csrfToken, draft: JSON.stringify(captureGuestDraft(this)) })) {
                const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
            }
            document.body.append(form); form.submit(); form.remove();
        },
        destroyGuestCalculator() {
            clearTimeout(saveTimer);
            if (pageHideHandler) { window.removeEventListener?.('pagehide', pageHideHandler); }
            if (storageHandler) { window.removeEventListener?.('storage', storageHandler); }
            if (configuration && ready) { this.storeGuestDraft(); }
        },
    };
}
