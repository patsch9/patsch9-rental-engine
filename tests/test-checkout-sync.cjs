/** Run the actual checkout script with a controllable Store API and DOM events. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/checkout-blocks.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function harness() {
  let observe, gateway = 'stripe';
  const requests = [], listeners = {}, select = {
    value: 'online', options: [{value: 'online'}, {value: 'cash'}],
    matches: () => true, dispatchEvent(event) { listeners[event.type]?.({target: this}); }
  };
  const window = {
    RMWCCheckoutBlocks: {offlineGateways: ['bacs']},
    wp: {data: {select: () => ({getActivePaymentMethod: () => gateway}), subscribe() {}}},
    wc: {blocksCheckout: {extensionCartUpdate(args) {
      return new Promise((resolve, reject) => requests.push({data: args.data, resolve, reject}));
    }}}, setTimeout() {}
  };
  vm.runInNewContext(source, {
    window, document: {documentElement: {}, querySelector: () => select, addEventListener: (type, fn) => {listeners[type] = fn;}},
    MutationObserver: class { constructor(fn) { observe = fn; } observe() {} },
    Event: class {constructor(type) {this.type = type;}}
  });
  return {requests, select, reconcile: () => observe(), gateway: value => {gateway = value;}};
}
(async () => {
  let h = harness();
  h.reconcile(); h.select.value = 'cash'; h.reconcile();
  assert.equal(h.requests.length, 1, 'Only one request can be in flight');
  h.requests[0].resolve(); await flush();
  assert.equal(h.requests.length, 2, 'Changed selection is sent after the first request');
  assert.equal(h.requests[1].data.deposit_choice, 'cash');
  h.requests[1].resolve(); await flush(); h.reconcile();
  assert.equal(h.requests.length, 2, 'Unchanged successful state is deduplicated');

  h = harness(); h.reconcile(); h.select.value = 'cash'; h.reconcile(); h.select.value = 'online'; h.reconcile();
  h.requests[0].resolve(); await flush();
  assert.equal(h.requests.length, 1, 'Only the latest choice matters, not intermediate values');

  h = harness(); h.reconcile(); h.gateway('bacs'); h.reconcile();
  assert.equal(h.select.value, '', 'Offline gateway clears online choice');
  assert.equal(h.select.options[0].disabled, true);
  h.requests[0].resolve(); await flush();
  assert.equal(h.requests.length, 2);
  assert.equal(h.requests[1].data.deposit_choice, '');
  assert.equal(h.requests[1].data.payment_method, 'bacs');

  h = harness(); h.reconcile(); h.requests[0].reject(new Error('offline')); await flush();
  assert.equal(h.requests.length, 1, 'A failed unchanged state never automatically loops');
  h.select.value = 'cash'; h.reconcile(); assert.equal(h.requests.length, 2, 'A new choice remains retryable');

  h = harness(); h.reconcile(); h.select.value = 'cash'; h.reconcile(); h.requests[0].reject(new Error('offline')); await flush();
  assert.equal(h.requests.length, 2, 'A newer choice is sent even when the previous request failed');
  assert.equal(h.requests[1].data.deposit_choice, 'cash');
  console.log('PASS: Checkout sync handles queued choices, gateways, deduplication and failures.');
})().catch(error => {console.error(error); process.exitCode = 1;});
