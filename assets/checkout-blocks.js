(function () {
  'use strict';

  const settings = window.RMWCCheckoutBlocks || {};
  const offline = Array.isArray(settings.offlineGateways) ? settings.offlineGateways : [];
  let lastSyncKey = null;
  let syncing = false;

  function field() {
    return document.querySelector('select[id*="patsch9-rental-engine"][id*="deposit-choice"], select[name*="patsch9-rental-engine/deposit-choice"]');
  }

  function activeGateway() {
    try {
      if (window.wp && window.wp.data) {
        const store = window.wp.data.select('wc/store/payment');
        if (store && typeof store.getActivePaymentMethod === 'function') {
          return String(store.getActivePaymentMethod() || '');
        }
      }
    } catch (e) {}
    return '';
  }

  function isOfflineGateway() {
    const gateway = activeGateway();
    return gateway !== '' && offline.indexOf(gateway) !== -1;
  }

  function updateServer(choice, gateway) {
    if (!window.wc || !window.wc.blocksCheckout || typeof window.wc.blocksCheckout.extensionCartUpdate !== 'function') {
      return;
    }
    const syncKey = choice + '|' + gateway;
    if (syncing || syncKey === lastSyncKey) return;
    syncing = true;
    window.wc.blocksCheckout.extensionCartUpdate({
      namespace: 'patsch9-rental-engine',
      data: { deposit_choice: choice, payment_method: gateway }
    }).then(function () {
      lastSyncKey = syncKey;
    }).catch(function (error) {
      if (window.wc.wcBlocksData && typeof window.wc.wcBlocksData.processErrorResponse === 'function') {
        window.wc.wcBlocksData.processErrorResponse(error);
      }
    }).finally(function () {
      syncing = false;
    });
  }

  function reconcile() {
    const select = field();
    if (!select) return;

    const offlineSelected = isOfflineGateway();
    const onlineOption = Array.from(select.options).find(function (option) { return option.value === 'online'; });
    if (onlineOption) onlineOption.disabled = offlineSelected;

    if (offlineSelected && select.value === 'online') {
      select.value = '';
      select.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    const choice = (select.value === 'online' && !offlineSelected) || select.value === 'cash' ? select.value : '';
    updateServer(choice, activeGateway());
  }

  document.addEventListener('change', function (event) {
    if (event.target && event.target.matches('select[id*="patsch9-rental-engine"][id*="deposit-choice"], select[name*="patsch9-rental-engine/deposit-choice"]')) {
      reconcile();
    }
  });

  if (window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function') {
    let previousGateway = '';
    window.wp.data.subscribe(function () {
      const gateway = activeGateway();
      if (gateway !== previousGateway) {
        previousGateway = gateway;
        reconcile();
      }
    });
  }

  const observer = new MutationObserver(reconcile);
  observer.observe(document.documentElement, { childList: true, subtree: true });
  document.addEventListener('DOMContentLoaded', reconcile);
  window.setTimeout(reconcile, 500);
}());
