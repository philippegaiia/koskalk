# Production editing — browser verification checklist

**Status:** not started. Automated coverage proves the server and client *contracts*; it cannot prove
timing, focus, real network departure, two real profiles, or rendered layout. This is the human pass.

**Why this exists:** `07b2a50f` shipped with Tasks 1–3 of
`docs/superpowers/plans/2026-10-01-production-editing-final-verification.md` never browser-verified
(computer-use failed with ScreenCaptureKit `-3811`). Release is not closed until these are recorded as
verified — or explicitly recorded as not verified.

## Environment guidance

- **Write passes (A–D): run on Herd (`koskalk.test`) or on a throwaway draft production.** The code is
  identical to production; the data is not. Confirm the URL before saving anything.
- **Pass E (layout/locales): run on production**, since it is read-only and that is where the deployed
  build actually is.
- Record which environment each pass ran in. A result without an environment is not a result.
- Two profiles must be two **different users** (e.g. your Owner/Admin account and an Editor account),
  not two tabs of the same user. Two browsers or one normal + one private window keeps the tokens
  distinct.
- Do **not** execute a real stock reservation on an operational batch to test Pass C. Atomic stock
  writes are covered by factory-backed tests; you are verifying ownership and draft behaviour only.

Timings you are testing against: **90-second lease**, **15-second status poll**, **180 KB PDF limit**.

---

## Pass A — one operator, one production (~15 min)

- [ ] **A1** Open a production detail page. Do not click anything. Expected: page renders read-only,
      status shows *viewing*, and **no lease is taken** (check `production_edit_leases` — zero rows for
      this production). This is the load-bearing check: viewing must never reserve.
- [ ] **A2** Click **Edit production**. Expected: status becomes *active*, editing controls enable.
- [ ] **A3** Change the production date to a valid working day, save. Expected: header date updates,
      status stays *active*, **no unsaved badge** for that date.
- [ ] **A4** Change and save the date again **without reopening the page**. Expected: same as A3. This
      is the repeated-save case the client coordinator was rewritten for.
- [ ] **A5** Leave the page focused and idle for **more than 90 seconds** (watch a clock, don't touch
      it). Then save a changed date. Expected: still *active*, save succeeds. Proves the heartbeat.
- [ ] **A6** Background the tab (switch to another window) for **more than 90 seconds**, come back,
      save a changed date. Expected: quiet renewal, save succeeds. If something else claimed it or the
      revision moved, you should get the stale indication instead — that is also a pass.
- [ ] **A7** Start a save, and **type new input while it is in flight**. Expected: the saved field
      becomes clean, the newly typed input **remains unsaved**. The dirty indicator and the save must
      agree.
- [ ] **A8** With unsaved input, click **Finish editing**. Expected: discard confirmation appears.
      **Cancel** must retain both the draft and the lease (still *active*). Confirming discards the
      draft, releases the lease, returns to *viewing*.
- [ ] **A9** Restore the production's original date and confirm it saved.

## Pass B — two profiles, same production (~15 min)

- [ ] **B1** Owner opens the production and **does not** edit. Editor opens the same production.
      Expected: Editor is **not** blocked. Owner viewing does not reserve.
- [ ] **B2** Owner clicks **Edit production**. Editor refreshes/polls. Expected: Editor sees
      "*<name> is editing this production. You can still view it.*", writes disabled, saved data still
      readable, and **no take-over button** (Editor cannot force takeover).
- [ ] **B3** Owner saves a change. Editor waits for the next poll. Expected: Editor gets the
      changed-production indication and can reload — **without** obtaining editing access.
- [ ] **B4** Owner clicks **Finish editing** (or closes the tab). Editor waits up to one poll.
      Expected: Editor sees availability **without a manual page reload**, and must click **Resume
      editing** explicitly — it must not acquire by itself.
- [ ] **B5** Owner cancels a navigation away (prompt declined) while editing. Expected: Owner keeps
      the reservation.
- [ ] **B6** Let Owner's reservation **expire** (background >90 s). Editor resumes. Owner returns.
      Expected: Owner does **not** displace Editor; Owner's pending draft is still intact on their
      page.
- [ ] **B7** Owner/Admin only: while Editor holds the lease, click **Take over editing**. Expected: a
      reason is required, the takeover succeeds, and an audit row is written. Confirm an Editor
      account never sees this control.

## Pass C — stock preparation (~10 min)

- [ ] **C1** Open stock preparation. Expected: it is a **preview** — no reservation taken.
- [ ] **C2** Select several productions, click **Edit allocations**. Expected: status becomes active
      for the group; a preview does not become an edit session on its own.
- [ ] **C3** Enter manual allocations, then trigger a failing command (or have the other profile
      claim one of the productions). Expected: **your dirty manual allocations survive**, and the
      failure names the affected productions — not just numeric IDs.
- [ ] **C4** Select productions from two different workspaces. Expected: rejected explicitly, not
      silently skipped or partially acquired.

## Pass D — journal document (~10 min)

- [ ] **D1** With a PDF **at or below 180 KB**: upload → *Uploading* → *Ready to attach* → *Attaching*
      → *Journal document attached*. Expected: document link appears, the completed upload clears.
- [ ] **D2** With the known **oversized** PDF: expected rejection shown **beside Attach**, no success
      confirmation, no new document. Keep the file and note selected.
- [ ] **D3** Wait through at least one heartbeat/poll with that rejection on screen. Expected: the
      rejection **persists**.
- [ ] **D4** Replace the rejected file with an acceptable one and retry. Expected: success clears the
      previous rejection.
- [ ] **D5** With a pending file or note, click **Finish editing**. Expected: discard confirmation;
      cancelling keeps draft + upload + lease; confirming clears the temporary upload and releases.

## Pass E — layout, messaging, locales (~10 min, production)

- [ ] **E1** Production-location select: same compact control height in read-only and editing modes,
      at desktop and at mobile width. (An earlier round reported inconsistent sizing.)
- [ ] **E2** Status area: repeated polls update **one** area. No repeated notifications, no recurring
      availability banner while merely viewing, and no "unsaved changes" claim when nothing is typed.
- [ ] **E3** Disabled-write states are visually obvious and the saved data stays readable.
- [ ] **E4** Check the new `production_bench.editing.*` copy in **English and French**. All keys must
      render (no raw `production_bench.editing.…` strings).
- [ ] **E5** Deleted-production case (safe to do on Herd): open a production, delete it elsewhere.
      Expected: shows unavailable, stops editing attempts, retains local unsaved input — and is **not**
      presented as temporary availability.

---

## If something fails

Capture, in this order:

1. The exact step and which profile/environment.
2. Browser console output around the failure.
3. Recent backend logs (Boost `browser-logs` / `read-log-entries`).
4. Read-only lease and revision state for that production:
   `select * from production_edit_leases where production_run_id = ?` and the run's `edit_revision`.

Then add a **failing regression test before changing any implementation** — that is the rule the whole
phase was built under. Do not weaken the server guard or auto-renew a stale/competing reservation to
make a symptom go away.

## Definition of done

Every line above is either **verified** (with environment noted) or **explicitly recorded as not
verified**. A blank box is not a pass. Update
`docs/superpowers/plans/2026-10-01-production-editing-final-verification.md` with the results and the
real counts, and list any case that remains unverified.

---

## Browser findings reported by Philippe (2026-10-04) — both resolved

### Finding 1 — a rejected PDF leaves the page permanently dirty with no way to clear it

**Observed:** pick a PDF over 180 KB, click Attach, the size error appears; the file stays selected,
"Unsaved changes" never clears, and there is no control to remove the selected file.

**Cause (traced, not guessed):**
- `attachJournalDocument` keeps `journalDocumentUpload` after a rejection on purpose —
  `ProductionEditingDocumentsTest:30` asserts the file is retained. Correct for the "retry after
  reload" case, but it means the client `dirty` getter
  (`pendingUpload || Boolean(uploadedDocument) || draft.hasChanges()`) stays true indefinitely.
- The only code path that cleared the property was `discardAndContinue()`, reachable only through
  Finish editing / Reload production + confirm the discard dialog. `reload()` also refuses while
  dirty. So the page was a trap: `beforeunload` fires and `livewire:navigate` is blocked.
- `canAttach` did not account for a rejection, so Attach stayed enabled and re-failed on every click.

**Fix:** added `clearDocument()` to `createProductionEditing()` in `resources/js/production-editing.js`
and a **Clear** button next to Attach in
`resources/views/livewire/production-bench/production/production-detail.blade.php`. It clears the
Livewire property, blanks the native file input (`[data-production-document-input]`) and resets
`pendingUpload` / `uploadFailed` / `documentErrors` / `documentAttached`; it is a no-op while an upload
is in flight. The label reuses the existing `production_bench.common.clear` key, so the six-locale
catalogue needs no edit. The inline `@error` is now `x-show="documentErrors.length || uploadFailed"`
so it disappears when the selection is cleared.

**Deliberately not changed:** no `documentRejected` flag on `canAttach` — it would disable a
legitimate retry after a reload. Attach still re-reports the error if pressed again, which is
informative rather than harmful.

**Verification:** 43/43 client tests (one new), 27 Feature tests in
`ProductionEditingDetailTest` + `ProductionEditingDocumentsTest`, Pint PASS.

**Still open (not done):** the 180 KB limit is only enforced server-side inside
`attachJournalDocument`, so the error cannot appear until Attach is pressed. A client-side size check
at selection time was offered to Philippe and is not implemented.

### Finding 2 — "Editing" / "Unsaved changes" render in English

**Cause:** not a code bug — a database gap. `language_lines` (`App\Models\InterfaceTranslation`) is
the runtime source for non-English, and it is **not** populated from
`database/seeders/data/interface-translations.json` automatically. Measured: 3,644 owned English keys
vs 3,628 DB rows = **36 missing**, every one added by this feature (31 ×
`production_bench.editing.*`, plus `production.uploading`, `upload_ready`, `upload_failed`,
`attaching`, `save_date`). All of them fell back to `lang/en/production_bench.php` in all six
locales — which is exactly the reported pattern: older strings translated, new ones not.

**Fix:** `php artisan translations:catalogue:import --mode=preserve-existing` →
**36 created, 0 updated, 3,608 unchanged, 270 production values preserved**. Verified:
NL "Bewerken / Niet-opgeslagen wijzigingen", FR "Modification en cours / Modifications non
enregistrées", DE "Bearbeitung / Ungespeicherte Änderungen". Missing-key count is now 0.

**Deploy step still required:** the same gap exists on production until
`php artisan translations:catalogue:import --mode=authoritative --force --no-interaction` is run
there (`docs/developer/translation-handoff.md:66`, `docs/developer/deployment-readiness.md:51`).
`translations:sync` alone is not enough — it creates rows with empty text.

**Test gap:** `InterfaceTranslationCatalogueTest` only asserts English keys against the catalogue
*file*, and the suite runs on a fresh database, so English-vs-DB drift is invisible to CI. Consider a
deployment check rather than a test.
