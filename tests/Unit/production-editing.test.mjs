import test from 'node:test';
import assert from 'node:assert/strict';
import { createProductionEditing, createProductionRegister } from '../../resources/js/production-editing.js';
const deferred = () => { let resolve; const promise = new Promise(r => resolve = r); return {promise, resolve}; };
const state = status => ({ status, can_edit: true, can_take_over: true, productions: {1:{revision:0,status,holder_name:status==='blocked'?'Philippe':null}} });
function fixture(call = async method => state(method === 'beginEditing' || method === 'heartbeatEditing' ? 'acquired' : 'available'), groups = {}) {
 const doc = new EventTarget(); doc.visibilityState = 'visible'; doc.hasFocus = () => doc.focused !== false;
 const win = new EventTarget(); const releases = []; const calls = [];
 let interval; let stopped = 0;
 const payload = {token:'tab-a',publicIds:['a'],revisions:{1:0},releaseUrl:'/release',state:state('available'),groups:{journal:{journalBody:''},actuals:{quantity:'1'},document:{journalDocumentNote:''}},messages:{blocked:':name is editing',group_blocked:'selection cannot all be reserved',available:'available to edit',stale:'stale',unavailable:'deleted',status_failed:'failed',refresh_failed:'saved; display failed',reload_failed:'reload failed'}};
 Object.assign(payload.groups, groups);
 const runtime = createProductionEditing(payload, {document:doc, window:win, fetch:async (url, options) => {releases.push({url,options}); return {ok:true};}, setInterval:handler => {interval=handler;return 1;}, clearInterval:()=>stopped++, call:async (method,...args)=>{calls.push([method,...args]);return call(method,...args);}});
 runtime.init(); return {runtime, payload, doc, win, releases, calls, tick:()=>interval(), stopped:()=>stopped};
}
test('saving or resetting another task date retains a rejected date and refreshes clean sibling dates', async () => {
 for (const method of ['rescheduleTask', 'resetTaskDate']) {
  const gate=deferred();
  const f=fixture(async (command, taskMethod, args) => {
   if (command !== 'executeEditingCommand') return state('acquired');
   if (args[0] === 11) return {ok:false,state:state('acquired'),errors:{scheduled_for:['Sunday rejected']}};
   await gate.promise;
   return {ok:true,revisions:{1:1},state:state('acquired'),canonical:{taskDates:{11:'2026-10-05',12:'2026-10-06',13:'2026-10-07'}}};
  }, {tasks:{taskDates:{11:'2026-10-05',12:'2026-10-05',13:'2026-10-05'}}});
  await f.runtime.begin(); f.runtime.field('tasks','taskDates.11','2026-10-04');
  await f.runtime.runCommand('rescheduleTask',[11,'2026-10-04'],'tasks');
  f.runtime.field('tasks','taskDates.12','2026-10-06');
  const save=f.runtime.runCommand(method, method==='rescheduleTask'?[12,'2026-10-06']:[12], 'tasks');
  await Promise.resolve();await Promise.resolve();
  gate.resolve();await save;
  assert.deepEqual(f.runtime.forms.tasks.taskDates,{11:'2026-10-04',12:'2026-10-06',13:'2026-10-07'});
  assert.equal(f.runtime.draft.isDirty('tasks'),true);
  f.runtime.destroy();await f.runtime.depart();
 }
});
test('queued task saves retain newer input when the later save is rejected', async () => {
 const gate=deferred();let holdPoll=false;
 const f=fixture(async (command, method, args) => {
  if (command === 'heartbeatEditing' && holdPoll) {holdPoll=false;await gate.promise;}
  if (command !== 'executeEditingCommand') return state('acquired');
  return args[1] === '2026-10-06'
   ? {ok:true,revisions:{1:1},state:state('acquired'),canonical:{taskDates:{11:'2026-10-06'}}}
   : {ok:false,state:state('acquired'),errors:{scheduled_for:['Sunday rejected']}};
 }, {tasks:{taskDates:{11:'2026-10-05'}}});
 await f.runtime.begin();holdPoll=true;const poll=f.runtime.poll();await Promise.resolve();await Promise.resolve();
 f.runtime.field('tasks','taskDates.11','2026-10-06');
 const first=f.runtime.runCommand('rescheduleTask',[11,'2026-10-06'],'tasks');
 f.runtime.field('tasks','taskDates.11','2026-10-11');
 const second=f.runtime.runCommand('rescheduleTask',[11,'2026-10-11'],'tasks');
 gate.resolve();await poll;await first;await second;
 assert.equal(f.runtime.forms.tasks.taskDates[11],'2026-10-11');
 assert.equal(f.runtime.draft.isDirty('tasks'),true);
 f.runtime.destroy();await f.runtime.depart();
});
test('opening and cancelled navigation never acquire or release; begin is explicit', async () => {
 const f=fixture(); assert.equal(f.calls.length,0);
 f.doc.dispatchEvent(new Event('livewire:navigate')); f.win.dispatchEvent(new Event('beforeunload')); f.win.dispatchEvent(new Event('blur'));
 assert.equal(f.releases.length,0); await f.runtime.begin(); assert.equal(f.runtime.canWrite,true);
 f.runtime.destroy(); await f.runtime.depart(); assert.equal(f.releases.length,1);
});
test('commands and heartbeat share a serial queue, newer input remains dirty', async () => {
 const gate=deferred(); let active=0;let max=0;
 const f=fixture(async method => {
  active++;max=Math.max(max,active);
  if(method==='executeEditingCommand') {await gate.promise;active--;return {ok:true,revisions:{1:1},state:state('acquired'),canonical:{journalBody:''}};}
  active--;return state('acquired');
 });
 await f.runtime.begin(); f.runtime.forms.journal.journalBody='sent';f.runtime.changed('journal');
 const save=f.runtime.runCommand('saveJournalEntry',[],'journal'); await Promise.resolve();await Promise.resolve();
 const poll=f.runtime.poll(); f.runtime.forms.journal.journalBody='newer';f.runtime.changed('journal');gate.resolve();
 await save;await poll; assert.equal(max,1);assert.equal(f.runtime.forms.journal.journalBody,'newer');assert.equal(f.runtime.dirty,true);
 f.runtime.destroy();await f.runtime.depart();
});
test('failed saves retain drafts; successful saves clear only the submitted group', async () => {
 let succeeds=false;
 const f=fixture(async method=>method==='executeEditingCommand'?{ok:succeeds,revisions:succeeds?{1:1}:{},state:state('acquired'),canonical:{journalBody:''},errors:{journal:['invalid']}}:state('acquired'));
 await f.runtime.begin();f.runtime.forms.journal.journalBody='entry';f.runtime.changed('journal');f.runtime.forms.actuals.quantity='2';f.runtime.changed('actuals');
 await f.runtime.runCommand('saveJournalEntry',[],'journal');assert.equal(f.runtime.draft.isDirty('journal'),true);
 succeeds=true;await f.runtime.runCommand('saveJournalEntry',[],'journal');assert.equal(f.runtime.draft.isDirty('journal'),false);assert.equal(f.runtime.draft.isDirty('actuals'),true);
 f.runtime.destroy();await f.runtime.depart();
});
test('watchers do not acquire; expired unchanged former holders recover quietly', async () => {
 let observation='available'; const f=fixture(async method=>state(method==='beginEditing'?'acquired':observation));
 await f.runtime.poll();assert.deepEqual(f.calls.map(call=>call[0]),['pollEditing']);
 await f.runtime.begin();await f.runtime.poll();assert.equal(f.calls.at(-1)[0],'beginEditing');assert.equal(f.runtime.owns,true);
 observation='stale';await f.runtime.poll();assert.equal(f.runtime.canWrite,false);assert.equal(f.runtime.message,'stale');
 f.runtime.destroy();await f.runtime.depart();
});
test('hidden and unfocused pages stop heartbeats without releasing', async () => {
 const f=fixture();await f.runtime.begin(); const count=f.calls.length;
 f.doc.visibilityState='hidden';await f.runtime.poll();f.doc.visibilityState='visible';f.doc.focused=false;await f.runtime.poll();
 assert.equal(f.calls.length,count);assert.equal(f.releases.length,0);
 f.runtime.destroy();await f.runtime.depart();
});
test('late acquisition after departure is released again; queued writes never start', async () => {
 const gate=deferred(); const f=fixture(async method=>method==='beginEditing'?gate.promise:state('available'));
 const begin=f.runtime.begin();await Promise.resolve();await Promise.resolve();
 const pending=f.runtime.runCommand('saveJournalEntry',[],'journal');const leaving=f.runtime.depart();await leaving;
 gate.resolve(state('acquired'));await begin;await pending;
 assert.equal(f.releases.length,2);assert.equal(f.calls.some(call=>call[0]==='executeEditingCommand'),false);
 assert.equal(f.releases[0].options.keepalive,true);assert.deepEqual(JSON.parse(f.releases[0].options.body),{token:'tab-a',production_ids:['a']});
 f.runtime.destroy();
});
test('persisted restoration waits for outstanding requests and checks status before resuming', async () => {
 const f=fixture();await f.runtime.begin();await f.runtime.depart();await f.runtime.restore();
 assert.deepEqual(f.calls.slice(-2).map(call=>call[0]),['pollEditing','beginEditing']);
 assert.equal(f.runtime.canWrite,true);f.runtime.destroy();await f.runtime.depart();
});
test('finish and reload leave dirty drafts and ownership intact until discard confirmation', async () => {
 const f=fixture();await f.runtime.begin();f.runtime.forms.journal.journalBody='keep';f.runtime.changed('journal');
 await f.runtime.finish();assert.equal(f.runtime.discardOpen,true);assert.equal(f.runtime.owns,true);assert.equal(f.calls.length,1);
 f.runtime.discardOpen=false;assert.equal(f.runtime.draft.get('journal').journalBody,'keep');
 f.runtime.destroy();await f.runtime.depart();
});
test('destroy removes listeners and remount does not duplicate polling or releases', async () => {
 const f=fixture();f.runtime.init();f.runtime.destroy();await f.runtime.depart();
 f.doc.dispatchEvent(new Event('livewire:navigating'));f.win.dispatchEvent(new Event('focus'));
 assert.equal(f.stopped(),1);assert.equal(f.releases.length,1);assert.equal(f.calls.length,0);
});

test('lifecycle receipts initialize clean forms while retaining pending notes', async () => {
 const f=fixture(async method => method==='executeEditingCommand'
  ? {ok:true,revisions:{1:1},state:state('acquired'),canonical:null,groups:{actuals:{quantity:'25'},journal:{journalBody:''}}}
  : state('acquired'));
 await f.runtime.begin(); f.runtime.forms.journal.journalBody='pending';f.runtime.changed('journal');
 await f.runtime.runCommand('start');
 assert.equal(f.runtime.forms.actuals.quantity,'25');assert.equal(f.runtime.draft.isDirty('actuals'),false);
 assert.equal(f.runtime.forms.journal.journalBody,'pending');assert.equal(f.runtime.draft.isDirty('journal'),true);
 f.runtime.destroy();await f.runtime.depart();
});

test('pending uploads guard cancellation and a departure carries the CSRF token', async () => {
 const f=fixture();f.doc.querySelector=()=>({getAttribute:()=> 'csrf-test'});
 await f.runtime.begin(); f.runtime.pendingUpload=true;
 const event=new Event('beforeunload',{cancelable:true});f.win.dispatchEvent(event);
 assert.equal(event.defaultPrevented,true);assert.equal(f.releases.length,0);
 await f.runtime.finish();assert.equal(f.runtime.discardOpen,true);assert.equal(f.runtime.owns,true);
 await f.runtime.depart();assert.equal(f.releases[0].options.headers['X-CSRF-TOKEN'],'csrf-test');f.runtime.destroy();
});

test('network failure retains local input and disables writes until status can be checked', async () => {
 const f=fixture(async method=> {if(method==='executeEditingCommand') throw new Error('offline');return state('acquired');});
 await f.runtime.begin(); f.runtime.field('journal','journalBody','pending');
 await f.runtime.runCommand('saveJournalEntry',[],'journal');
 assert.equal(f.runtime.canWrite,false);assert.equal(f.runtime.dirty,true);assert.equal(f.runtime.forms.journal.journalBody,'pending');
 f.runtime.destroy();await f.runtime.depart();
});

test('register shortcuts and scheduling submissions share one queue', async () => {
 const gate=deferred();const calls=[];const runtime=createProductionRegister();
 runtime.$wire={$call:async method => {calls.push(method);if(method==='callMountedAction') await gate.promise;}};
 const modal=runtime.run('callMountedAction');await Promise.resolve();await Promise.resolve();
 const shortcut=runtime.run('bulkAssignBatchNumbers');assert.deepEqual(calls,['callMountedAction']);
 gate.resolve();await modal;await shortcut;assert.deepEqual(calls,['callMountedAction','bulkAssignBatchNumbers']);assert.equal(runtime.busy,false);
});

test('attachment readiness follows a completed Livewire upload, including an existing uploaded file after rendering', async () => {
 const f=fixture(async method => method==='executeEditingCommand'
  ? {ok:true,revisions:{1:1},state:state('acquired'),canonical:{journalBody:''}}
  : state('acquired'));
 f.runtime.$wire={journalDocumentUpload:null};await f.runtime.begin();
 assert.equal(f.runtime.canAttach,false);
 f.runtime.uploadStarted();assert.equal(f.runtime.uploading,true);assert.equal(f.runtime.dirty,true);
 f.runtime.$wire.journalDocumentUpload='livewire-file:batch.pdf';
 assert.equal(f.runtime.canAttach,false);
 f.runtime.uploadFinished();assert.equal(f.runtime.canAttach,true);
 f.runtime.pendingUpload=false;assert.equal(f.runtime.canAttach,true);
 f.runtime.uploadStarted();f.runtime.uploadErrored();assert.equal(f.runtime.canAttach,false);
 f.runtime.uploadStarted();f.runtime.uploadFinished();assert.equal(f.runtime.canAttach,true);
 f.runtime.$wire.journalDocumentUpload=null;f.runtime.uploadCancelled();assert.equal(f.runtime.canAttach,false);
 f.runtime.destroy();await f.runtime.depart();
});

test('attachments cannot submit during upload or after failure, and discarded uploads are cleared before finishing', async () => {
 const f=fixture(); const updates=[];
 f.runtime.$wire={journalDocumentUpload:'livewire-file:batch.pdf',$set:(key,value,live)=> {updates.push([key,value,live]);f.runtime.$wire[key]=value;}};
 await f.runtime.begin();f.runtime.uploadStarted();
 await f.runtime.runCommand('attachJournalDocument');await f.runtime.finish();
 assert.equal(f.calls.some(call=>call[0]==='executeEditingCommand'),false);assert.equal(f.runtime.discardOpen,false);
 f.runtime.uploadErrored();await f.runtime.runCommand('attachJournalDocument');
 assert.equal(f.calls.some(call=>call[0]==='executeEditingCommand'),false);
 await f.runtime.finish();assert.equal(f.runtime.discardOpen,true);assert.equal(f.runtime.dirty,true);
 await f.runtime.discardAndContinue();assert.deepEqual(updates,[['journalDocumentUpload',null,false]]);
 assert.equal(f.calls.at(-1)[0],'finishEditing');assert.equal(f.runtime.dirty,false);assert.equal(f.runtime.canAttach,false);
 f.runtime.destroy();await f.runtime.depart();
});

test('a successful attachment clears the upload and note but keeps newer edits in the note', async () => {
 const gate=deferred(); const f=fixture(async method=> {
  if(method==='executeEditingCommand') {await gate.promise;f.runtime.$wire.journalDocumentUpload=null;return {ok:true,revisions:{1:1},state:state('acquired'),canonical:{journalDocumentNote:''}};}
  return state('acquired');
 });
 f.runtime.$wire={journalDocumentUpload:'livewire-file:batch.pdf'};
 await f.runtime.begin();f.runtime.field('document','journalDocumentNote','submitted note');f.runtime.uploadFinished();
 const attach=f.runtime.runCommand('attachJournalDocument',[],'document');await Promise.resolve();await Promise.resolve();
 f.runtime.field('document','journalDocumentNote','newer note');gate.resolve();await attach;
 assert.equal(f.runtime.canAttach,false);assert.equal(f.runtime.pendingUpload,false);
 assert.equal(f.runtime.forms.document.journalDocumentNote,'newer note');assert.equal(f.runtime.dirty,true);
 f.runtime.destroy();await f.runtime.depart();
});

test('allocation pages remain clean without a document form or upload property', async () => {
 const f=fixture();delete f.runtime.forms.document;
 f.runtime.$wire=new Proxy({}, {get:()=>()=>{throw new Error('Unknown Livewire method');}});
 await f.runtime.begin();assert.equal(f.runtime.dirty,false);assert.equal(f.runtime.canAttach,false);
 await f.runtime.finish();assert.equal(f.runtime.discardOpen,false);assert.equal(f.calls.at(-1)[0],'finishEditing');
 f.runtime.destroy();await f.runtime.depart();
});

test('rejected attachments retain local feedback through polling and confirm a later successful attachment', async () => {
 let succeeds=false;
 const f=fixture(async method=> {
  if(method==='executeEditingCommand') {
   if(succeeds) f.runtime.$wire.journalDocumentUpload=null;
   return {ok:succeeds,revisions:succeeds?{1:1}:{},state:state('acquired'),canonical:{journalDocumentNote:''},errors:succeeds?{}:{journalDocumentUpload:['This PDF exceeds the 180 KB limit. Compress it and try again.']}};
  }
  return state('acquired');
 });
 f.runtime.$wire={journalDocumentUpload:'livewire-file:large.pdf'};await f.runtime.begin();f.runtime.uploadFinished();
 f.runtime.field('document','journalDocumentNote','Keep this note');
 await f.runtime.runCommand('attachJournalDocument',[],'document');
 assert.deepEqual(f.runtime.documentErrors,['This PDF exceeds the 180 KB limit. Compress it and try again.']);
 assert.equal(f.runtime.documentAttached,false);assert.equal(f.runtime.dirty,true);
 await f.runtime.poll();assert.equal(f.runtime.documentErrors.length,1);
 f.runtime.uploadStarted();assert.deepEqual(f.runtime.documentErrors,[]);f.runtime.uploadFinished();succeeds=true;
 await f.runtime.runCommand('attachJournalDocument',[],'document');
 assert.equal(f.runtime.documentAttached,true);assert.equal(f.runtime.dirty,false);assert.equal(f.runtime.attaching,false);
 f.runtime.field('document','journalDocumentNote','Next document');assert.equal(f.runtime.documentAttached,false);
 f.runtime.destroy();await f.runtime.depart();
});

test('a rejected attachment can be cleared without losing the rest of the draft', async () => {
 const f=fixture(async method=> method==='executeEditingCommand'
  ? {ok:false,revisions:{},state:state('acquired'),canonical:{journalDocumentNote:''},errors:{journalDocumentUpload:['This PDF exceeds the 180 KB limit. Compress it and try again.']}}
  : state('acquired'));
 const updates=[];
 f.runtime.$wire={journalDocumentUpload:'livewire-file:large.pdf',$set:(key,value,live)=>{updates.push([key,value,live]);f.runtime.$wire[key]=value;}};
 await f.runtime.begin();f.runtime.uploadFinished();
 f.runtime.field('document','journalDocumentNote','Keep this note');
 await f.runtime.runCommand('attachJournalDocument',[],'document');
 assert.equal(f.runtime.dirty,true);assert.equal(f.runtime.documentErrors.length,1);
 await f.runtime.clearDocument();
 assert.deepEqual(updates,[['journalDocumentUpload',null,false]]);
 assert.deepEqual(f.runtime.documentErrors,[]);assert.equal(f.runtime.pendingUpload,false);assert.equal(f.runtime.canAttach,false);
 assert.equal(f.runtime.dirty,true);assert.equal(f.runtime.forms.document.journalDocumentNote,'Keep this note');
 f.runtime.uploadStarted();await f.runtime.clearDocument();
 assert.equal(f.runtime.uploading,true);assert.equal(f.runtime.pendingUpload,true);
 f.runtime.destroy();await f.runtime.depart();
});

test('a save renews an expired uncontested reservation before sending the current draft', async () => {
 const f=fixture(async method=> {
  if(method==='heartbeatEditing') return state('available');
  if(method==='executeEditingCommand') return {ok:true,revisions:{1:1},state:state('acquired'),canonical:{journalBody:''}};
  return state('acquired');
 });
 await f.runtime.begin();f.runtime.field('journal','journalBody','Pending save');
 await f.runtime.runCommand('saveJournalEntry',[],'journal');
 assert.deepEqual(f.calls.slice(1).map(call=>call[0]),['heartbeatEditing','beginEditing','executeEditingCommand','refreshProductionPresentation']);
 assert.equal(f.runtime.owns,true);assert.equal(f.runtime.dirty,false);
 f.runtime.destroy();await f.runtime.depart();
});

test('a save checks a reservation first and keeps the draft when another editor or a newer revision prevents renewal', async () => {
 for(const status of ['blocked','stale']) {
  const f=fixture(async method=>state(method==='heartbeatEditing'?status:'acquired'));
  await f.runtime.begin();f.runtime.field('journal','journalBody','Keep my input');
  await f.runtime.runCommand('saveJournalEntry',[],'journal');
  assert.equal(f.calls.some(call=>call[0]==='executeEditingCommand'),false);
  assert.equal(f.calls.filter(call=>call[0]==='beginEditing').length,1);
  assert.equal(f.runtime.canWrite,false);assert.equal(f.runtime.forms.journal.journalBody,'Keep my input');
  f.runtime.destroy();await f.runtime.depart();
 }
});

test('a delayed save preflight never writes after leaving and releases its late renewed reservation', async () => {
 const gate=deferred();const f=fixture(async method=>method==='heartbeatEditing'?gate.promise:state('acquired'));
 await f.runtime.begin();const saving=f.runtime.runCommand('saveJournalEntry',[],'journal');
 await Promise.resolve();await Promise.resolve();await f.runtime.depart();gate.resolve(state('acquired'));await saving;
 assert.equal(f.calls.some(call=>call[0]==='executeEditingCommand'),false);assert.equal(f.releases.length,2);
 f.runtime.destroy();
});

test('a save cannot steal a reservation claimed between its heartbeat and renewal', async () => {
 let begins=0;const f=fixture(async method=>state(method==='heartbeatEditing'?'available':method==='beginEditing'&&++begins>1?'blocked':'acquired'));
 await f.runtime.begin();f.runtime.field('journal','journalBody','Pending save');
 await f.runtime.runCommand('saveJournalEntry',[],'journal');
 assert.equal(f.calls.some(call=>call[0]==='executeEditingCommand'),false);assert.equal(f.runtime.canWrite,false);
 assert.equal(f.runtime.dirty,true);assert.equal(f.runtime.message,'Philippe is editing');
 f.runtime.destroy();await f.runtime.depart();
});

for (const action of ['begin', 'takeover', 'finish', 'reload']) {
 test(`${action} reports a failed request without rejecting`, async () => {
  let failed=false;
  const f=fixture(async method=>{if(failed)throw new Error('Offline');return state('acquired');});
  await f.runtime.begin();failed=true;
  if(['begin','takeover'].includes(action))f.runtime.field('journal','journalBody','Keep this note');
  f.runtime.takeoverOpen=true;
  assert.equal(await f.runtime[action](),null);
  assert.equal(f.runtime.canWrite,false);assert.equal(f.runtime.busy,false);
  assert.equal(f.runtime.message,action==='reload'?'reload failed':'failed');
  assert.equal(f.runtime.takeoverOpen,true);
  if(['begin','takeover'].includes(action)){assert.equal(f.runtime.dirty,true);assert.equal(f.runtime.value('journal','journalBody'),'Keep this note');}
  failed=false;await f.runtime.begin();assert.equal(f.runtime.canWrite,true);
  f.runtime.destroy();await f.runtime.depart();
 });
}
for (const newerInput of [false,true]) {
 test(`a display refresh failure preserves a successful save${newerInput?' and newer input':''}`, async () => {
  const gate=deferred();const started=deferred();
  const f=fixture(async method=>{
   if(method==='executeEditingCommand')return {ok:true,revisions:{1:1},canonical:{journalBody:''},state:state('acquired')};
   if(method==='refreshProductionPresentation'){started.resolve();await gate.promise;throw new Error('Display unavailable');}
   return state('acquired');
  });
  await f.runtime.begin();f.runtime.field('journal','journalBody','submitted');
  const saving=f.runtime.runCommand('saveJournalEntry',[],'journal');await started.promise;
  if(newerInput)f.runtime.field('journal','journalBody','next entry');
  gate.resolve();const reply=await saving;
  assert.equal(reply.ok,true);assert.equal(f.runtime.canWrite,true);assert.equal(f.runtime.owns,true);
  assert.equal(f.runtime.dirty,newerInput);assert.equal(f.runtime.value('journal','journalBody'),newerInput?'next entry':'');
  assert.deepEqual(f.payload.revisions,{1:1});assert.equal(f.runtime.message,'saved; display failed');
  await f.runtime.poll();assert.equal(f.runtime.message,'saved; display failed');
  f.runtime.destroy();await f.runtime.depart();
 });
}

for(const change of ['input','upload']) {
 test(`reload does not accept a snapshot if ${change} arrived while preparing it`, async () => {
  const gate=deferred();const started=deferred();
  const f=fixture(async method=>{if(method==='reloadProductionEditing'){started.resolve();return gate.promise;}return state('acquired');});
  await f.runtime.begin();const reload=f.runtime.reload();await started.promise;
  if(change==='input')f.runtime.field('journal','journalBody','keep');else f.runtime.uploadStarted();
  gate.resolve({id:'receipt',groups:{journal:{journalBody:''}},revisions:{1:2}});await reload;
  assert.equal(f.calls.some(call=>call[0]==='acceptProductionReload'),false);
  assert.deepEqual(f.payload.revisions,{1:0});assert.equal(f.runtime.dirty,true);
  assert.equal(f.runtime.value('journal','journalBody'),change==='input'?'keep':'');
  assert.equal(f.runtime.message,'reload failed');f.runtime.destroy();await f.runtime.depart();
 });
}
test('reload applies the prepared snapshot before accepting it and retains input during acceptance', async () => {
 const gate=deferred();const started=deferred();
 const groups={planning:{scheduleDate:'2026-10-05'}};
 const f=fixture(async (method,id)=>{
  if(method==='reloadProductionEditing')return {id:'receipt',groups,revisions:{1:2}};
  if(method==='acceptProductionReload'){assert.equal(id,'receipt');started.resolve();return gate.promise;}
  return state('acquired');
 },{planning:{scheduleDate:'2026-10-02'}});
 await f.runtime.begin();const reload=f.runtime.reload();await started.promise;
 assert.equal(f.runtime.value('planning','scheduleDate'),'2026-10-05');assert.equal(f.runtime.canWrite,false);
 f.runtime.field('planning','scheduleDate','2026-10-09');gate.resolve({accepted:true,state:state('acquired')});await reload;
 assert.equal(f.runtime.value('planning','scheduleDate'),'2026-10-09');assert.equal(f.runtime.dirty,true);
 assert.deepEqual(f.payload.revisions,{1:2});assert.equal(f.runtime.canWrite,true);
 f.runtime.destroy();await f.runtime.depart();
});
test('an unconfirmed reload stays unable to write through polls and begin until a retry succeeds', async () => {
 let fail=true;
 const f=fixture(async method=>{
  if(method==='reloadProductionEditing')return {id:'receipt',groups:{journal:{journalBody:''}},revisions:{1:2}};
  if(method==='acceptProductionReload'&&fail)throw new Error('Reply lost');
  if(method==='acceptProductionReload')return {accepted:true,state:state('acquired')};
  return state('acquired');
 });
 await f.runtime.begin();assert.equal(await f.runtime.reload(),null);
 assert.equal(f.runtime.message,'reload failed');await f.runtime.poll();await f.runtime.begin();
 assert.equal(f.runtime.canWrite,false);assert.equal(f.runtime.message,'reload failed');
 fail=false;await f.runtime.reload();assert.equal(f.runtime.canWrite,true);assert.equal(f.runtime.message,'');
 f.runtime.destroy();await f.runtime.depart();
});
test('a prepared reload arriving after departure never changes the baseline or gets accepted', async () => {
 const gate=deferred();const started=deferred();
 const f=fixture(async method=>{if(method==='reloadProductionEditing'){started.resolve();return gate.promise;}return state('acquired');});
 await f.runtime.begin();const reload=f.runtime.reload();await started.promise;await f.runtime.depart();
 gate.resolve({id:'receipt',groups:{journal:{journalBody:''}},revisions:{1:2}});assert.equal(await reload,null);
 assert.deepEqual(f.payload.revisions,{1:0});assert.equal(f.calls.some(call=>call[0]==='acceptProductionReload'),false);
 f.runtime.destroy();
});

test('reload renders its accepted snapshot without another presentation read', async () => {
 const f=fixture(async method=>{
  if(method==='reloadProductionEditing')return {id:'receipt',groups:{journal:{journalBody:''}},revisions:{1:2}};
  if(method==='acceptProductionReload')return {accepted:true,state:state('available')};
  if(method==='refreshProductionPresentation')throw new Error('Display unavailable');
  return state('available');
 });
 await f.runtime.reload();assert.equal(f.runtime.message,'');
 assert.equal(f.calls.some(call=>call[0]==='refreshProductionPresentation'),false);
 f.runtime.destroy();await f.runtime.depart();
});

test('a reload response without an acceptance acknowledgment cannot enable writes', async () => {
 const f=fixture(async method=>{
  if(method==='reloadProductionEditing')return {id:'receipt',groups:{journal:{journalBody:''}},revisions:{1:2}};
  if(method==='acceptProductionReload')return {state:state('acquired')};
  return state('acquired');
 });
 await f.runtime.begin();await f.runtime.reload();await f.runtime.poll();
 assert.equal(f.runtime.canWrite,false);assert.equal(f.runtime.message,'reload failed');
 assert.equal(f.calls.some(call=>call[0]==='refreshProductionPresentation'),false);
 f.runtime.destroy();await f.runtime.depart();
});

test('register request failure displays the localized warning without rejecting and allows a later command', async () => {
 const runtime=createProductionRegister();const calls=[];let fail=true;
 runtime.$el={dataset:{failureMessage:'Action non confirmée. Rechargez la page.'}};
 runtime.$wire={$call:async method=>{calls.push(method);if(fail)throw new Error('Connection lost');return 'refreshed';}};
 assert.equal(await runtime.run('toggleTask',[11]),null);
 assert.equal(runtime.busy,false);assert.equal(runtime.message,runtime.$el.dataset.failureMessage);
 assert.deepEqual(calls,['toggleTask']);
 fail=false;assert.equal(await runtime.run('refreshProductionRegister'),'refreshed');
 assert.equal(runtime.busy,false);assert.equal(runtime.message,'');
 assert.deepEqual(calls,['toggleTask','refreshProductionRegister']);
});

test('a definitive unavailable reload clears an earlier unconfirmed receipt without enabling writes', async () => {
 const f=fixture(async method=>method==='reloadProductionEditing'?{state:state('unavailable')}:state('unavailable'));
 f.runtime.reloadUnconfirmed=true;
 await f.runtime.reload();
 assert.equal(f.runtime.reloadUnconfirmed,false);assert.equal(f.runtime.canWrite,false);
 assert.equal(f.runtime.message,'deleted');assert.deepEqual(f.payload.revisions,{1:0});
 f.runtime.destroy();await f.runtime.depart();
});

test('finishing after waiting and resuming returns to ordinary viewing without a resume prompt', async () => {
 let observation='blocked';
 const f=fixture(async method=>state(method==='beginEditing'?'acquired':method==='finishEditing'?'available':observation));
 await f.runtime.poll();assert.equal(f.runtime.waiting,true);
 observation='available';await f.runtime.poll();assert.equal(f.runtime.message,'available to edit');
 await f.runtime.begin();await f.runtime.finish();await f.runtime.poll();
 assert.equal(f.runtime.waiting,false);assert.equal(f.runtime.message,'');assert.equal(f.runtime.owns,false);
 assert.equal(f.calls.filter(call=>call[0]==='beginEditing').length,1);
 f.runtime.destroy();await f.runtime.depart();
});
test('an observer retains the resume prompt until an acquisition succeeds', async () => {
 let observation='blocked';
 const f=fixture(async ()=>state(observation));
 await f.runtime.poll();await f.runtime.begin();assert.equal(f.runtime.waiting,true);
 observation='available';await f.runtime.poll();await f.runtime.poll();
 assert.equal(f.runtime.waiting,true);assert.equal(f.runtime.message,'available to edit');
 assert.equal(f.calls.filter(call=>call[0]==='beginEditing').length,1);
 f.runtime.destroy();await f.runtime.depart();
});
test('a blocked selection shows the group warning while a single production names its holder', async () => {
 for(const size of [1,2]) {
  const f=fixture();f.payload.publicIds=Array.from({length:size},(_,i)=>`production-${i}`);
  const blocked=state('blocked');
  if(size===2)blocked.productions[2]={revision:0,status:'available',holder_name:null};
  f.runtime.apply(blocked);
  assert.equal(f.runtime.message,size===2?'selection cannot all be reserved':'Philippe is editing');
  assert.equal(f.runtime.canWrite,false);assert.equal(f.runtime.state.productions[1].holder_name,'Philippe');
  f.runtime.destroy();await f.runtime.depart();
 }
});
