// Runs shipped Alpine + rendered Blade in jsdom; this is DOM regression, not browser layout QA.
// NODE_PATH=<directory containing jsdom> node tests/browser/workflow-dom.cjs
const { JSDOM, VirtualConsole } = require(process.env.JSDOM_MODULE || 'jsdom');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const fixtures = process.env.WF_BROWSER_FIXTURES || '/tmp/wf-browser-fixtures';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let checks = 0;
async function load(name, storage = {}) {
  const source = new JSDOM(fs.readFileSync(path.join(fixtures, name + '.html'), 'utf8'));
  const selector = name === 'bookings' ? '[x-data^="docEntryPage"]' : '[x-data="poPage()"]';
  const console = new VirtualConsole();
  const errors = [];
  console.on('jsdomError', error => errors.push(error.message));
  const dom = new JSDOM(source.window.document.querySelector(selector).outerHTML, {
    url: 'http://localhost/' + name, runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: console,
  });
  const w = dom.window;
  w.confirm = () => true;
  w.fetch = async () => ({ok:true, json:async()=>[]});
  w.AbortController = AbortController;
  w.erpToast = () => {};
  w.erpStopSubmit = (event, problems) => { if (problems.length) event.preventDefault(); };
  Object.entries(storage).forEach(([key,value]) => w.sessionStorage.setItem(key,value));
  w.eval(fs.readFileSync(path.join(root,'public/document-workflow.js'),'utf8'));
  for (const script of source.window.document.scripts) {
    if (/window\.erpProductPicker = function|function docEntryPage\(|function poPage\(/.test(script.textContent)) w.eval(script.textContent);
  }
  w.eval(fs.readFileSync(path.join(root,'public/vendor/alpinejs/alpine.min.js'),'utf8'));
  await sleep(100);
  const state = w.Alpine.$data(w.document.querySelector(selector));
  return {dom,w,state,errors};
}
(async () => {
  for (const name of ['bookings','purchase-orders']) {
    let {dom,w,state,errors} = await load(name);
    state.modalOpen = true;
    state.items = Array.from({length:50},(_,i)=>({product_id:i+1,sku_code:'WF-'+i,name_th:'ทดสอบ',qty:i+1,unit_price:25,unit_name:'กก.'}));
    await sleep(40);
    assert.equal(w.document.querySelectorAll('.doc-modal tbody tr, .po-modal tbody tr').length,50); checks++;
    state.items[0].qty = 123;
    const header = w.document.querySelector('[name=remark], [name=note]');
    header.value = 'ร่าง 50 รายการ'; header.dispatchEvent(new w.Event('input', {bubbles:true}));
    await sleep(40);
    state.openProductPicker(0);
    await sleep(40);
    w.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
    await sleep(40);
    assert.equal(state.modalOpen,true); assert.equal(state.pickerOpen,false); checks+=2;
    const input = w.document.querySelector('[name="items[0][qty]"]');
    assert.equal(input.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true})),false); checks++;
    // Reject closing, then accept; neither path discards the rows.
    w.confirm=()=>false; state.closeModal(); assert.equal(state.modalOpen,true); checks++;
    w.confirm=()=>true; state.closeModal(); assert.equal(state.items.length,50); checks++;
    const storage = Object.fromEntries(Object.keys(w.sessionStorage).map(key=>[key,w.sessionStorage.getItem(key)]));
    assert.ok(Object.keys(storage).some(key=>key.startsWith('erp:draft:v1:'))); checks++;
    assert.deepEqual(errors,[]);
    dom.window.close();
    ({dom,w,state,errors}=await load(name,storage));
    assert.equal(state.items.length,50); assert.equal(state.items[0].qty,123); assert.equal(state.draftDirty,true); checks+=3;
    assert.equal(w.document.querySelector('[name=remark], [name=note]').value, 'ร่าง 50 รายการ'); checks++;
    const event = new w.Event('beforeunload',{cancelable:true}); w.dispatchEvent(event);
    assert.equal(event.defaultPrevented,true); checks++;
    assert.deepEqual(errors,[]);
    dom.window.close();
    // Expired drafts are not restored, and another user key is not loaded.
    const expired = Object.fromEntries(Object.entries(storage).map(([key,value]) => [key, JSON.stringify({...JSON.parse(value), time:0})]));
    let clean = await load(name, expired);
    assert.equal(clean.state.items.length,1); checks++; clean.dom.window.close();
    const otherUser = Object.fromEntries(Object.entries(storage).map(([key,value]) => [key.replace(/v1:[^:]+:/,'v1:another-user:'),value]));
    clean = await load(name, otherUser);
    assert.equal(clean.state.items.length,1); checks++; clean.dom.window.close();
  }
  const {dom,w,state,errors}=await load('bookings');
  const pending=[]; w.fetch=()=>new Promise(resolve=>pending.push(resolve));
  state.partyQuery='old'; const first=state.searchParty();
  state.partyQuery='new'; const second=state.searchParty();
  pending[1]({ok:true,json:async()=>[{id:2,code:'NEW'}]}); await second;
  pending[0]({ok:true,json:async()=>[{id:1,code:'OLD'}]}); await first;
  assert.equal(state.partyResults[0].code,'NEW'); checks++;
  state.partyQuery=''; await state.searchParty(); assert.equal(state.partyId,''); assert.equal(state.partyResults.length,0); checks+=2;
  assert.deepEqual(errors,[]);
  dom.window.close();
  // Execute the actual Executive component with controlled timers/network.
  const execDom = new JSDOM(fs.readFileSync(path.join(fixtures,'executive.html'),'utf8'), {url:'http://localhost/executive',runScripts:'outside-only'});
  const ew = execDom.window;
  const script = [...ew.document.scripts].find(script => script.textContent.includes('function execBoard()')).textContent;
  ew.eval(script);
  let starts=0, cleared=0, resolve;
  ew.AbortController=AbortController;
  ew.setInterval=()=>{starts++; return 7}; ew.clearInterval=()=>cleared++;
  ew.fetch=()=>new Promise(done=>{resolve=done});
  const board=ew.execBoard(); board.start(); board.start();
  assert.equal(starts,1); checks++;
  const refresh=board.refresh(); await board.refresh();
  assert.equal(board.loading,true); checks++;
  board.destroy(); assert.equal(cleared,1); assert.equal(board.refreshController.signal.aborted,true); checks+=2;
  resolve({ok:false}); await refresh; assert.equal(board.loading,false); checks++;
  ew.close();
  const saved = new JSDOM(fs.readFileSync(path.join(fixtures,'purchase-order-saved.html'),'utf8'), {url:'http://localhost/purchase-orders/1',runScripts:'outside-only'});
  const cleanup = [...saved.window.document.scripts].find(script=>script.textContent.includes('erp:draft:pending:')).textContent;
  const sw = saved.window;
  sw.sessionStorage.setItem('erp:draft:pending:1',JSON.stringify({key:'draft',action:'http://localhost/bookings'}));
  sw.sessionStorage.setItem('draft','keep'); sw.eval(cleanup);
  assert.equal(sw.sessionStorage.getItem('draft'),'keep'); checks++;
  sw.sessionStorage.setItem('erp:draft:pending:1',JSON.stringify({key:'draft',action:'http://localhost/purchase-orders'}));
  sw.eval(cleanup); assert.equal(sw.sessionStorage.getItem('draft'),null); checks++; sw.close();

  const posDom = new JSDOM(fs.readFileSync(path.join(fixtures,'pos.html'),'utf8'), {url:'http://localhost/pos',runScripts:'outside-only'});
  const pw = posDom.window;
  pw.eval([...pw.document.scripts].find(script=>script.textContent.includes('function posApp()')).textContent);
  const pos = pw.posApp();
  let timers=0, loads=0, actions=0, stops=0, clears=0;
  pw.setInterval=()=>++timers; pw.clearInterval=()=>clears++;
  ['loadReceiptSettings','loadHeldBills','loadProducts','loadPromotions','loadActiveShift','registerServiceWorker','tickClock','initOffline'].forEach(method=>pos[method]=()=>loads++);
  pos.handleVueAction=()=>actions++; pos.stopScanner=()=>stops++;
  pos.init(); pos.init();
  assert.equal(timers,1); assert.equal(loads,8); checks+=2;
  pw.dispatchEvent(new pw.CustomEvent('pos-vue-action')); assert.equal(actions,1); checks++;
  pos.destroy(); pw.dispatchEvent(new pw.CustomEvent('pos-vue-action'));
  assert.equal(actions,1); assert.equal(stops,1); assert.equal(clears,3); assert.equal(pos.backgroundController.signal.aborted,true); checks+=4;
  // Failed closing keeps the count and active shift; concurrent clicks send one request.
  const shift = pw.posApp(); shift.activeShift={id:7}; shift.countedCash='123.45'; shift.closeShiftModalOpen=true;
  pw.erpPopup=()=>{};
  let requests=0, finish;
  pw.fetch=()=>{requests++; return new Promise(resolve=>finish=resolve)};
  const closing=shift.submitCloseShift(); await shift.submitCloseShift();
  assert.equal(requests,1); checks++;
  finish({ok:false,json:async()=>({message:'Permission denied'})}); await closing;
  assert.equal(shift.activeShift.id,7); assert.equal(shift.countedCash,'123.45'); assert.equal(shift.closeShiftModalOpen,true); assert.equal(shift.shiftProcessing,false); checks+=4;
  shift.countedCash=''; await shift.submitCloseShift(); assert.equal(requests,1); checks++;
  const search = pw.posApp(); search.hostOnline=true;
  const replies=[]; pw.fetch=()=>new Promise(resolve=>replies.push(resolve));
  search.searchQ='old'; const oldSearch=search.loadProducts();
  search.searchQ='new'; const newSearch=search.loadProducts();
  replies[1]({ok:true,json:async()=>[{id:2}]}); await newSearch;
  replies[0]({ok:true,json:async()=>[{id:1}]}); await oldSearch;
  assert.equal(search.products[0].id,2); assert.equal(search.loading,false); checks+=2;
  posDom.window.close();
  console.log(JSON.stringify({passed:checks,engine:'jsdom + shipped Alpine',layoutVerified:false}));
})().catch(error=>{console.error(error);process.exit(1)});
