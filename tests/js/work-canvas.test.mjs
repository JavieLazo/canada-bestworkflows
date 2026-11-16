import test from 'node:test';
import assert from 'node:assert/strict';
import {canPan,panPosition} from '../../resources/js/work-pan.js';
import {taskTimeline} from '../../resources/js/task-timeline.js';

test('panning follows both axes without treating cards or controls as blank canvas',()=>{
 assert.deepEqual(panPosition({left:30,top:80},{x:100,y:90},{x:60,y:40}),{left:70,top:130});
 assert.equal(canPan({closest:()=>null}),true);
 assert.equal(canPan({closest:selector=>selector==='[data-pan-open]'?null:{}}),false);
 assert.equal(canPan({closest:()=>({})}),true);
});

test('date movement requires deliberate edit mode and existing per-date authority',()=>{
 const canvas=taskTimeline({}); const bar={dataset:{editStart:'1',editDue:'0',due:'2026-11-08'}};
 assert.equal(canvas.allowed(bar,'start'),false);
 canvas.editing=true;
 assert.equal(canvas.allowed(bar,'start'),true);assert.equal(canvas.allowed(bar,'due'),false);assert.equal(canvas.allowed(bar,'move'),false);
 bar.dataset.editStart='0';assert.equal(canvas.allowed(bar,'start'),false);
 canvas.editing=true;canvas.checkWindow('2026-11-03','28');assert.equal(canvas.editing,false);
});

test('zoom reads the pointed-to date, exits editing and delegates only a calendar change',async()=>{
 const calls=[];const scroller={clientWidth:800,scrollLeft:300,getBoundingClientRect:()=>({left:10})};
 const wire={zoomTimeline:async(...args)=>calls.push(args)};
 const canvas=Object.assign(taskTimeline(wire),{$refs:{scroller},$root:{dataset:{days:'28',from:'2026-11-03'},querySelector:selector=>({getBoundingClientRect:()=>({width:selector==='[data-track]'?784:160})})},$nextTick:()=>{}});
 canvas.scales=[7,14,28,84,168,364];canvas.scaleIndex=2;canvas.editing=true;
 await canvas.changeZoom(-1,410);
 assert.equal(canvas.editing,false);assert.deepEqual(calls,[[-1,'2026-11-22']]);assert.equal(canvas.zooming,false);
 canvas.scaleIndex=0;await canvas.changeZoom(1);assert.equal(calls.length,1);
 canvas.scaleIndex=5;await canvas.changeZoom(-1);assert.equal(calls.length,1);
});
