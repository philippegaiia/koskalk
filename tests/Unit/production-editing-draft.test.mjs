import test from 'node:test';
import assert from 'node:assert/strict';
import { createProductionDraft } from '../../resources/js/production-editing-draft.js';
test('acknowledges only submitted input and leaves other groups dirty', () => {
 const draft = createProductionDraft({ actuals: {quantity: '1'}, journal: {body: ''} });
 draft.set('actuals', {quantity: '2'}); const sent = draft.capture('actuals');
 draft.set('actuals', {quantity: '3'}); draft.set('journal', {body:'pending'});
 draft.acknowledge(sent, {quantity:'2'});
 assert.deepEqual(draft.get('actuals'), {quantity:'3'});
 assert.equal(draft.isDirty('actuals'), true); assert.equal(draft.isDirty('journal'), true);
 assert.equal(draft.replaceClean({actuals:{quantity:'4'}}), false);
});
test('canonical no-op and reordered map keys do not create false changes', () => {
 const draft = createProductionDraft({actuals:{b:2,a:1}});
 draft.set('actuals', {a:1,b:2}); assert.equal(draft.hasChanges(), false);
 draft.acknowledge(draft.capture('actuals'), {a:1,b:2});
 assert.equal(draft.hasChanges(), false);
 assert.throws(() => draft.set('invalid', {}));
});
test('task acknowledgments preserve input typed during the save and rejected sibling dates', () => {
 const draft=createProductionDraft({tasks:{taskDates:{11:'2026-10-05',12:'2026-10-05',13:'2026-10-05'}}});
 draft.set('tasks',{taskDates:{11:'2026-10-04',12:'2026-10-06',13:'2026-10-05'}});
 const sent=draft.capture('tasks');
 draft.set('tasks',{taskDates:{11:'2026-10-11',12:'2026-10-08',13:'2026-10-05'}});
 draft.acknowledgeTaskDate(sent,{taskDates:{11:'2026-10-05',12:'2026-10-06',13:'2026-10-07'}},12);
 assert.deepEqual(draft.get('tasks').taskDates,{11:'2026-10-11',12:'2026-10-08',13:'2026-10-07'});
 assert.equal(draft.isDirty('tasks'),true);
 draft.discard({tasks:{taskDates:{11:'2026-10-05',12:'2026-10-06',13:'2026-10-07'}}});
 assert.equal(draft.hasChanges(),false);
});
