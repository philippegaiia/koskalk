<?php

use Symfony\Component\Process\Process;

it('keeps help state isolated and disposes listeners when its scope changes', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createContextualHelp } from './resources/js/contextual-help.js';
const listeners = new Map();
const document = { querySelector: () => null, addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name) };
const window = { matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }), addEventListener() {}, removeEventListener() {}, requestAnimationFrame: fn => fn() };
const help = createContextualHelp({ document, window });
const formula = { weight: 100, dirty: false };
const before = JSON.stringify(formula);
const topic = { key:'soap.water_mode', locale:'en', title:'Water mode', summary:'An answer', body_html:null, revision:'revision-id' };
help.mount(); help.mount();
help.register({ topics:{ [topic.key]:topic }, tabs:{ formula:[topic.key] } });
help.openIndex('formula', null);
assert.equal(help.isOpen, true); assert.equal(help.view, 'index');
help.openTopic(topic.key, null);
assert.equal(help.currentTopic.title, 'Water mode');
help.openTopic('missing', null); assert.equal(help.currentTopic.key, topic.key);
help.replaceScope({ topics:{}, tabs:{} });
assert.equal(help.isOpen, false); assert.deepEqual(help.currentKeys, []);
assert.equal(JSON.stringify(formula), before);
help.register({ topics:{ [topic.key]:topic }, tabs:{ inventory:[topic.key] } });
help.openTopic(topic.key, null);
assert.deepEqual(help.currentKeys, [topic.key]);
help.destroy(); assert.equal(listeners.size, 0);
JS;
    $process = Process::fromShellCommandline('node --input-type=module -e '.escapeshellarg($script), base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('traps focus only on mobile and restores background state on close navigation and other modals', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createContextualHelp } from './resources/js/contextual-help.js';
const handlers = new Map();
const windowHandlers = new Map();
let resize;
let document;
function node(tagName = 'DIV') {
    return { tagName, inert:false, hidden:false, isConnected:true, dataset:{}, children:[], attrs:{},
        append(child) { this.children.push(child); }, replaceChildren() { this.children = []; },
        setAttribute(k,v) { this.attrs[k]=v; }, removeAttribute(k) { delete this.attrs[k]; },
        getClientRects() { return this.hidden ? [] : [1]; }, focus() { document.activeElement = this; },
        hasAttribute(k) { return Object.hasOwn(this.attrs,k); }, closest() { return null; } };
}
const heading=node('H2'), content=node(), back=node('BUTTON'), close=node('BUTTON'), panel=node('ASIDE'), main=node('MAIN'), existingInert=node();
existingInert.inert=true;
panel.dataset.indexTitle='Help';
panel.querySelector = s => ({ '[data-help-heading]':heading, '[data-help-content]':content, '[data-help-back]':back })[s];
panel.querySelectorAll = () => [back,close];
panel.contains = item => [back,close].includes(item);
const origin=node('A'); origin.closest=()=>({id:'panel-output'});
document={ activeElement:null, body:{style:{overflow:'auto'}, children:[main,existingInert,panel]},
    querySelector:s=>s==='[data-contextual-help-panel]'?panel:null, createElement:node,
    addEventListener:(n,f)=>handlers.set(n,f), removeEventListener:n=>handlers.delete(n) };
const media={matches:false,addEventListener:(n,f)=>resize=f,removeEventListener(){}};
const window={ matchMedia:()=>media,requestAnimationFrame:f=>f(),addEventListener:(n,f)=>windowHandlers.set(n,f),removeEventListener:n=>windowHandlers.delete(n)};
const help=createContextualHelp({document,window}); help.mount();
help.register({topics:{one:{title:'First',summary:'Summary'},two:{title:'Output',summary:'Output summary'}},tabs:{formula:['one'],output:['two']}});
help.openTopic('two',origin);
assert.equal(panel.attrs.role,'dialog'); assert.equal(panel.attrs['aria-modal'],'true');
assert.equal(main.inert,true); assert.equal(document.body.style.overflow,'hidden'); assert.equal(document.activeElement,heading);
assert.deepEqual(help.currentKeys,['two']);
let prevented=false;
handlers.get('keydown')({target:heading,key:'Tab',preventDefault(){prevented=true;}});
assert.equal(prevented,true); assert.equal(document.activeElement,back);
media.matches=true; resize();
assert.equal(panel.attrs.role,'complementary'); assert.equal(main.inert,false); assert.equal(existingInert.inert,true); assert.equal(document.body.style.overflow,'auto');
handlers.get('keydown')({target:heading,key:'Escape',preventDefault(){},stopPropagation(){}});
assert.equal(help.isOpen,false); assert.equal(document.activeElement,origin);
help.openTopic('two',origin); windowHandlers.get('open-modal')(); assert.equal(help.isOpen,false);
help.openTopic('two',origin); handlers.get('contextual-help:modal')(); assert.equal(help.isOpen,false);
media.matches=false; help.openTopic('two',origin); handlers.get('livewire:navigating')();
assert.equal(help.isOpen,false); assert.equal(main.inert,false); assert.deepEqual(help.currentKeys,[]);
help.destroy(); assert.equal(handlers.size,0); assert.equal(windowHandlers.size,0);
JS;
    $process = Process::fromShellCommandline('node --input-type=module -e '.escapeshellarg($script), base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('adds contextual links to existing quality states without creating new warning thresholds', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createPresentationSection } from './resources/js/recipe-workbench/sections/presentation-section.js';
const presentation = createPresentationSection();
const context = { qualityFlags:presentation.qualityFlags, backendCalculationWarningFlags:presentation.backendCalculationWarningFlags, qualityMetrics:()=>({cure_speed:34,dos_risk:35}), isQualityApplicable:k=>['cure_speed','dos_risk'].includes(k),isQualityScored:()=>false };
assert.deepEqual(context.qualityFlags().map(f=>f.helpKey), ['soap.qualities.cure','soap.qualities.dos']);
context.qualityMetrics=()=>({cure_speed:35,dos_risk:34}); assert.deepEqual(context.qualityFlags(),[]);
context.backendCalculation={properties:{warnings:['high_koh_context_process_dependent','high_polyunsaturated_dos_risk']}};
assert.deepEqual(context.qualityFlags().map(f=>f.helpKey), ['soap.qualities.liquid','soap.qualities.dos']);
JS;
    $process = Process::fromShellCommandline('node --input-type=module -e '.escapeshellarg($script), base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
