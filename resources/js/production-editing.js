import { createProductionDraft } from './production-editing-draft.js';

export function createProductionEditing(payload, environment = {}) {
    const doc = environment.document ?? globalThis.document;
    const win = environment.window ?? globalThis.window;
    const request = environment.fetch ?? globalThis.fetch;
    const every = environment.setInterval ?? globalThis.setInterval;
    const stop = environment.clearInterval ?? globalThis.clearInterval;
    let queue = Promise.resolve();
    let departing = false;
    let generation = 0;
    let releasePromise = null;
    let interval = null;
    let formerHolder = false;
    let installed = false;
    let disposed = false;
    const listeners = [];
    const enqueue = operation => {
        const expectedGeneration = generation;
        const next = queue.then(() => {
            if (departing || expectedGeneration !== generation) return null;
            return operation(expectedGeneration);
        });
        queue = next.catch(() => {});
        return next;
    };
    const visible = () => doc?.visibilityState !== 'hidden' && (doc?.hasFocus?.() ?? true);
    const sendRelease = () => Promise.resolve().then(() => request(payload.releaseUrl, {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': doc?.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content') ?? '' },
        body: JSON.stringify({ token: payload.token, production_ids: payload.publicIds }),
    })).catch(() => null);
    const release = force => {
        if (!releasePromise || force) releasePromise = Promise.resolve(releasePromise).then(sendRelease);
        return releasePromise;
    };
    const runtime = {
        state: payload.state, busy: false, owns: false, stale: false, unavailable: false,
        canWrite: false, message: '', presentationFailed: false, reloadUnconfirmed: false, takeoverOpen: false, takeoverReason: '', discardOpen: false,
        draftGeneration: 0, waiting: false, pendingUpload: false, uploading: false, uploadFailed: false, forms: structuredClone(payload.groups ?? {}),
        documentErrors: [], documentAttached: false, attaching: false,
        draft: createProductionDraft(payload.groups ?? {}),
        async call(method, ...args) { return environment.call ? environment.call(method, ...args) : this.$wire.$call(method, ...args); },
        changed(group) { this.draft.set(group, this.forms[group]); this.draftGeneration++; },
        field(group, path, value) {
            if (group === 'document') this.documentAttached = false;
            const keys = path.split('.'); let target = this.forms[group];
            for (const key of keys.slice(0, -1)) target = target[key] ??= {};
            target[keys.at(-1)] = value; this.changed(group);
        },
        value(group, path) { return path.split('.').reduce((value, key) => value?.[key], this.forms[group]) ?? ''; },
        get uploadedDocument() { return this.forms.document ? this.$wire?.journalDocumentUpload : null; },
        get dirty() { void this.draftGeneration; return this.pendingUpload || Boolean(this.uploadedDocument) || this.draft.hasChanges(); },
        get canAttach() { return this.canWrite && !this.busy && !this.uploading && !this.uploadFailed && Boolean(this.uploadedDocument); },
        uploadStarted() { this.uploading = true; this.uploadFailed = false; this.pendingUpload = true; this.documentErrors = []; this.documentAttached = false; },
        uploadFinished() { this.uploading = false; this.uploadFailed = false; this.pendingUpload = Boolean(this.uploadedDocument); },
        uploadErrored() { this.uploading = false; this.uploadFailed = true; this.pendingUpload = Boolean(this.uploadedDocument); },
        uploadCancelled() { this.uploading = false; this.uploadFailed = false; this.pendingUpload = Boolean(this.uploadedDocument); },
        apply(state) {
            this.state = state;
            this.stale = state.status === 'stale'; this.unavailable = state.status === 'unavailable';
            this.owns = state.status === 'acquired'; this.canWrite = this.owns && state.can_edit && !this.stale && !this.unavailable && !this.reloadUnconfirmed;
            if (this.owns) { formerHolder = true; this.waiting = false; }
            if (state.status === 'blocked') this.waiting = true;
            const holder = Object.values(state.productions ?? {}).find(row => row.status === 'blocked')?.holder_name ?? '';
            const blockedMessage = payload.publicIds.length > 1 ? payload.messages.group_blocked : payload.messages.blocked.replace(':name', holder);
            this.message = state.status === 'blocked' ? blockedMessage
                : this.stale ? payload.messages.stale : this.unavailable ? payload.messages.unavailable : this.waiting && !this.owns ? payload.messages.available : this.presentationFailed ? payload.messages.refresh_failed : '';
            if (this.reloadUnconfirmed && !this.stale && !this.unavailable && state.status !== 'blocked') this.message = payload.messages.reload_failed;
            if (state.errors) this.message = Object.values(state.errors).flat().join(' ');
        },
        init() {
            if (installed) return;
            installed = true; this.apply(payload.state);
            const listen = (target, event, handler) => { target?.addEventListener(event, handler); listeners.push(() => target?.removeEventListener(event, handler)); };
            listen(win, 'beforeunload', event => { if (this.dirty || this.busy) { event.preventDefault(); event.returnValue = ''; } });
            listen(doc, 'livewire:navigate', event => { if ((this.dirty || this.busy) && !(environment.confirm ?? win?.confirm)?.(payload.messages.discard_confirmation)) event.preventDefault(); });
            listen(doc, 'livewire:navigating', () => this.depart());
            listen(win, 'pagehide', () => this.depart());
            listen(win, 'pageshow', event => { if (event.persisted) void this.restore(); });
            listen(win, 'focus', () => { if (!departing) void this.poll(); });
            listen(doc, 'visibilitychange', () => { if (visible() && !departing) void this.poll(); });
            interval = every(() => { if (visible() && !departing) void this.poll(); }, 15000);
        },
        operate(method, ...args) {
            return enqueue(async expected => {
                this.busy = true;
                try {
                    const state = await this.call(method, ...args);
                    if (departing || expected !== generation) { if (state?.status === 'acquired') await release(true); return null; }
                    this.apply(state); return state;
                } catch (error) {
                    if (!departing && expected === generation) { this.canWrite = false; this.owns = false; this.message = payload.messages.status_failed; }
                    return null;
                } finally { this.busy = false; }
            });
        },
        begin() { return this.operate('beginEditing'); },
        takeover(reason = this.takeoverReason) { return this.operate('takeOverProductionEditing', reason).then(state => { if (state?.status === 'acquired') this.takeoverOpen = false; return state; }); },
        poll() {
            if (!visible()) return Promise.resolve(null);
            return enqueue(async expected => {
                try {
                    let state = await this.call(this.owns ? 'heartbeatEditing' : 'pollEditing');
                    if (departing || expected !== generation) { if (state?.status === 'acquired') await release(true); return null; }
                    this.apply(state);
                    if (formerHolder && !this.reloadUnconfirmed && state.status === 'available' && state.can_edit) {
                        state = await this.call('beginEditing');
                        if (departing || expected !== generation) { if (state?.status === 'acquired') await release(true); return null; }
                        this.apply(state);
                    }
                    return state;
                } catch (error) { if (!departing) { this.canWrite = false; this.owns = false; this.message = payload.messages.status_failed; } return null; }
            });
        },
        runCommand(method, args = [], group = null, confirmation = null) {
            if (confirmation && !(environment.confirm ?? win?.confirm)?.(confirmation)) return Promise.resolve(null);
            const submitted = group ? this.draft.capture(group) : null;
            return enqueue(async expected => {
                if (!this.canWrite || (method === 'attachJournalDocument' && !this.canAttach)) return null;
                this.busy = true;
                const isAttachment = method === 'attachJournalDocument';
                if (isAttachment) { this.attaching = true; this.documentErrors = []; this.documentAttached = false; }
                try {
                    let checked = await this.call('heartbeatEditing');
                    if (departing || expected !== generation) { if (checked?.status === 'acquired') await release(true); return null; }
                    this.apply(checked);
                    if (formerHolder && checked.status === 'available' && checked.can_edit) {
                        checked = await this.call('beginEditing');
                        if (departing || expected !== generation) { if (checked?.status === 'acquired') await release(true); return null; }
                        this.apply(checked);
                    }
                    if (!this.canWrite) { if (isAttachment) this.documentErrors = [this.message]; return null; }
                    const reply = await this.call('executeEditingCommand', method, args, submitted?.value ?? null, group);
                    if (departing || expected !== generation) { if (reply?.state?.status === 'acquired') await release(true); return null; }
                    if (reply.state) this.apply(reply.state);
                    if (!reply.ok) {
                        const errors = Object.values(reply.errors ?? {}).flat();
                        this.message = errors.join(' ');
                        if (isAttachment) this.documentErrors = errors;
                        return reply;
                    }
                    Object.assign(payload.revisions, reply.revisions);
                    if (submitted) {
                        if (group === 'tasks' && ['rescheduleTask', 'resetTaskDate'].includes(method)) this.draft.acknowledgeTaskDate(submitted, reply.canonical, args[0]);
                        else this.draft.acknowledge(submitted, reply.canonical);
                        this.forms[group] = this.draft.get(group); payload.groups[group] = reply.canonical; this.draftGeneration++;
                    }
                    const cleanGroups = this.draft.synchronizeCleanGroups(reply.groups ?? {});
                    Object.assign(this.forms, cleanGroups); Object.assign(payload.groups, cleanGroups); this.draftGeneration++;
                    if (isAttachment) { this.pendingUpload = false; this.uploadFailed = false; this.documentAttached = true; }
                    // The renderless receipt owns form normalization. A later presentation refresh never rebases drafts.
                    await this.refreshPresentation(expected);
                    return reply;
                } catch (error) {
                    if (!departing && expected === generation) {
                        this.canWrite = false; this.owns = false; this.message = payload.messages.status_failed;
                        if (isAttachment) this.documentErrors = [this.message];
                    }
                    return null;
                }
                finally { this.busy = false; this.attaching = false; }
            });
        },
        async refreshPresentation(expected) {
            try {
                await this.call('refreshProductionPresentation');
                if (!departing && expected === generation) { this.presentationFailed = false; this.apply(this.state); }
            } catch (error) {
                if (!departing && expected === generation) { this.presentationFailed = true; this.apply(this.state); }
            }
        },
        confirmDiscard(action) {
            if (this.dirty) { this.discardOpen = true; this.discardAction = action; return Promise.resolve(null); }
            return action();
        },
        discardAndContinue() {
            if (this.uploading) return Promise.resolve(null);
            this.discardOpen = false; this.pendingUpload = false; this.uploadFailed = false; this.documentErrors = []; this.documentAttached = false;
            if (this.uploadedDocument) this.$wire.$set('journalDocumentUpload', null, false);
            this.draft.discard(payload.groups); this.forms = structuredClone(payload.groups); this.draftGeneration++;
            return this.discardAction?.();
        },
        finish() { return this.uploading ? Promise.resolve(null) : this.confirmDiscard(() => this.operate('finishEditing').then(state => { if (state) formerHolder = false; return state; })); },
        reload() {
            return this.confirmDiscard(() => enqueue(async expected => {
                if (this.dirty) { this.message = payload.messages.reload_failed; return null; }
                this.busy = true; this.canWrite = false;
                const draftGeneration = this.draftGeneration;
                try {
                    const prepared = await this.call('reloadProductionEditing');
                    if (departing || expected !== generation) return null;
                    if (!prepared.id) { this.reloadUnconfirmed = false; this.apply(prepared.state); return prepared; }
                    if (this.dirty || this.draftGeneration !== draftGeneration || !this.draft.replaceClean(prepared.groups)) {
                        this.apply(this.state); this.message = payload.messages.reload_failed; return null;
                    }
                    payload.groups = prepared.groups; this.forms = structuredClone(prepared.groups); this.draftGeneration++;
                    payload.revisions = prepared.revisions;
                    this.reloadUnconfirmed = true;
                    const reply = await this.call('acceptProductionReload', prepared.id);
                    if (departing || expected !== generation) return null;
                    this.reloadUnconfirmed = reply.accepted !== true;
                    if (!this.reloadUnconfirmed) this.presentationFailed = false;
                    this.apply(reply.state);
                    return reply;
                } catch (error) {
                    if (!departing && expected === generation) { this.canWrite = false; this.owns = false; this.message = payload.messages.reload_failed; }
                    return null;
                } finally { this.busy = false; }
            }));
        },
        depart() {
            if (departing) return releasePromise;
            formerHolder ||= this.owns; departing = true; generation++; this.canWrite = false; this.owns = false;
            if (interval !== null) { stop(interval); interval = null; }
            return release(false);
        },
        async restore() {
            await queue; await releasePromise;
            if (disposed) return;
            departing = false; generation++; releasePromise = null;
            if (interval === null) interval = every(() => { if (visible()) void this.poll(); }, 15000);
            await this.poll();
        },
        destroy() { disposed = true; this.depart(); for (const remove of listeners) remove(); listeners.length = 0; installed = false; },
    };
    return runtime;
}

export function createProductionRegister() {
    let queue = Promise.resolve();
    return {
        busy: false, message: '',
        run(method, args = [], confirmation = null) {
            if (confirmation && !window.confirm(confirmation)) return Promise.resolve(null);
            const next = queue.then(async () => {
                this.busy = true; this.message = '';
                try { return await this.$wire.$call(method, ...args); }
                catch (error) { this.message = this.$el.dataset.failureMessage; return null; }
                finally { this.busy = false; }
            });
            queue = next.catch(() => {});
            return next;
        },
    };
}
