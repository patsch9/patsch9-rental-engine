function rmwcAdminString(key,fallback){
  const strings=(window.RMWCAdminData&&RMWCAdminData.strings)||{};
  return Object.prototype.hasOwnProperty.call(strings,key)?strings[key]:fallback;
}

jQuery(function($){
  function toggleRentalTab(){
    const on=$('#_rmwc_rental_enabled').is(':checked');
    const tab=$('a[href="#rmwc_rental_product_data"]').closest('li');
    tab.toggle(on);
    if(!on && $('#rmwc_rental_product_data').is(':visible')) $('a[href="#general_product_data"]').trigger('click');
  }
  function keepProductModesExclusive(changed){
    const rental=$('#_rmwc_rental_enabled');
    const accessory=$('#_rmwc_accessory_only');
    if(!accessory.length) return;
    if(changed==='rental' && rental.is(':checked')) accessory.prop('checked',false);
    if(changed==='accessory' && accessory.is(':checked')) rental.prop('checked',false);
    toggleRentalTab();
  }
  toggleRentalTab();
  $('#_rmwc_rental_enabled').on('change',function(){keepProductModesExclusive('rental');});
  $('#_rmwc_accessory_only').on('change',function(){keepProductModesExclusive('accessory');});

  let addonIdx=$('.rmwc-addons-table tbody tr').length;
  $('.rmwc-add-addon').on('click',function(){
    $('.rmwc-addons-table tbody').append(`<tr><td><input type="text" name="rmwc_addons[${addonIdx}][group]" placeholder="${rmwcAdminString('addonMaterial','Material')}"></td><td><input type="text" name="rmwc_addons[${addonIdx}][label]" placeholder="${rmwcAdminString('addonFor20People','for 20 people')}"></td><td><input type="number" step="0.01" min="0" name="rmwc_addons[${addonIdx}][price]" value="0"></td><td><select name="rmwc_addons[${addonIdx}][mode]"><option value="once">${rmwcAdminString('once','once')}</option><option value="unit">${rmwcAdminString('perRentalUnit','per rental unit')}</option><option value="day">${rmwcAdminString('perRentalDay','per rental day')}</option></select></td><td><button type="button" class="button rmwc-remove-row">×</button></td></tr>`); addonIdx++;
  });

  let priceIdx=$('.rmwc-pricing-table tbody tr').length;
  $('.rmwc-add-pricing').on('click',function(){
    $('.rmwc-pricing-table tbody').append(`<tr><td><input type="number" min="1" step="1" name="rmwc_pricing[${priceIdx}][min_days]" value="1"></td><td><input type="number" min="0" step="1" name="rmwc_pricing[${priceIdx}][max_days]" placeholder="∞"></td><td><input type="number" min="0" step="0.01" name="rmwc_pricing[${priceIdx}][package_price]"></td><td><input type="number" min="1" step="1" name="rmwc_pricing[${priceIdx}][included_days]" value="1"></td><td><input type="number" min="0" step="0.01" name="rmwc_pricing[${priceIdx}][extra_day_price]"></td><td><button type="button" class="button rmwc-remove-row">×</button></td></tr>`); priceIdx++;
  });

  let deliveryIdx=$('.rmwc-delivery-table tbody tr').length;
  $('.rmwc-add-delivery').on('click',function(){
    $('.rmwc-delivery-table tbody').append(`<tr><td><input type="text" name="rmwc_delivery_zones[${deliveryIdx}][label]" placeholder="${rmwcAdminString('upTo10Km','up to 10 km')}"></td><td><input type="number" step="0.1" min="0.1" name="rmwc_delivery_zones[${deliveryIdx}][max_km]"></td><td><input type="number" step="0.01" min="0" name="rmwc_delivery_zones[${deliveryIdx}][price]" value="0"></td><td><button type="button" class="button rmwc-remove-row">×</button></td></tr>`); deliveryIdx++;
  });


  let materialGroupIdx=$('.rmwc-v2-material-group').length;
  function materialOptionMarkup(groupIdx,optionIdx){
    return `<div class="rmwc-v2-material-option" data-option-index="${optionIdx}">
      <div class="rmwc-v2-field rmwc-v2-field-product"><label>${rmwcAdminString('rentalAccessoryProduct','Rental accessory product')}</label><select class="wc-product-search" name="rmwc_v2_accessory_groups[${groupIdx}][options][${optionIdx}][product_id]" data-placeholder="${rmwcAdminString('searchRentalAccessory','Search rental accessories…')}" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select><p class="description">${rmwcAdminString('productSourceNote','Price, tax, stock, and the “Sold individually” setting are taken directly from this WooCommerce product.')}</p></div>
      <div class="rmwc-v2-field rmwc-v2-field-label"><label>${rmwcAdminString('customerDisplay','Customer display')}</label><input type="text" name="rmwc_v2_accessory_groups[${groupIdx}][options][${optionIdx}][label]" placeholder="${rmwcAdminString('sweetPopcornExample','e.g. for approx. 1 kg sweet popcorn')}" maxlength="150"><p class="description">${rmwcAdminString('optionalProductName','Optional. Empty = use product name.')}</p></div>
      <div class="rmwc-v2-field rmwc-v2-field-mode"><label>${rmwcAdminString('calculation','Calculation')}</label><select name="rmwc_v2_accessory_groups[${groupIdx}][options][${optionIdx}][mode]"><option value="once">${rmwcAdminString('oncePerBooking','1× per booking')}</option><option value="per_device">${rmwcAdminString('perRentalUnit','per rental unit')}</option></select></div>
      <button type="button" class="button-link-delete rmwc-remove-material-option" aria-label="${rmwcAdminString('removeAccessory','Remove rental accessory from group')}">×</button>
    </div>`;
  }
  function materialGroupMarkup(groupIdx){
    return `<div class="rmwc-v2-material-group" data-group-index="${groupIdx}" data-next-option="0">
      <div class="rmwc-v2-material-group-head">
        <div class="rmwc-v2-material-group-settings">
          <div class="rmwc-v2-field rmwc-v2-group-name"><label>${rmwcAdminString('groupName','Group name')}</label><input type="text" name="rmwc_v2_accessory_groups[${groupIdx}][label]" placeholder="${rmwcAdminString('addonMaterial','Material')}" maxlength="100" required></div>
          <div class="rmwc-v2-field rmwc-v2-group-selection"><label>${rmwcAdminString('selectionType','Selection type')}</label><select name="rmwc_v2_accessory_groups[${groupIdx}][selection]"><option value="single">${rmwcAdminString('singleSelection','Single selection – at most one option')}</option><option value="multiple">${rmwcAdminString('multipleSelection','Multiple selection – multiple options allowed')}</option></select></div>
          <div class="rmwc-v2-field rmwc-v2-group-required"><span class="rmwc-v2-label">${rmwcAdminString('requiredSelection','Required selection')}</span><label class="rmwc-v2-checkbox"><input type="checkbox" name="rmwc_v2_accessory_groups[${groupIdx}][required]" value="1"><span>${rmwcAdminString('atLeastOneOption','At least one option must be selected')}</span></label></div>
        </div>
        <div class="rmwc-v2-material-group-actions"><button type="button" class="button rmwc-v2-add-material-option">${rmwcAdminString('addAccessory','+ Add accessory')}</button><button type="button" class="button-link-delete rmwc-remove-material-group" aria-label="${rmwcAdminString('removeAccessoryGroup','Remove accessory group')}">×</button></div>
      </div>
      <div class="rmwc-v2-material-options-list"></div>
      <p class="rmwc-v2-material-group-empty">${rmwcAdminString('emptyAccessoryGroup','No accessories in this group yet. Add at least one product.')}</p>
    </div>`;
  }
  $(document).on('click','.rmwc-v2-add-material-group',function(){
    const $group=$(materialGroupMarkup(materialGroupIdx));
    $('.rmwc-v2-material-group-list').append($group);
    $('.rmwc-v2-material-empty').hide();
    materialGroupIdx++;
    $group.find('.rmwc-v2-add-material-option').trigger('click');
  });
  $(document).on('click','.rmwc-v2-add-material-option',function(){
    const $group=$(this).closest('.rmwc-v2-material-group');
    const groupIdx=parseInt($group.data('group-index'),10);
    let optionIdx=parseInt($group.attr('data-next-option')||'0',10);
    $group.find('.rmwc-v2-material-options-list').append(materialOptionMarkup(groupIdx,optionIdx));
    $group.attr('data-next-option',String(optionIdx+1));
    $group.find('.rmwc-v2-material-group-empty').hide();
    $(document.body).trigger('wc-enhanced-select-init');
  });
  $(document).on('click','.rmwc-remove-material-option',function(){
    const $group=$(this).closest('.rmwc-v2-material-group');
    $(this).closest('.rmwc-v2-material-option').remove();
    if(!$group.find('.rmwc-v2-material-option').length) $group.find('.rmwc-v2-material-group-empty').show();
  });
  $(document).on('click','.rmwc-remove-material-group',function(){
    $(this).closest('.rmwc-v2-material-group').remove();
    if(!$('.rmwc-v2-material-group').length) $('.rmwc-v2-material-empty').show();
  });

  $(document).on('click','.rmwc-remove-row',function(){ $(this).closest('tr').remove(); });
});

jQuery(function($){
  $(document).on('click','.rmwc-add-checklist-item',function(){
    const target=$(this).data('target')==='return'?'return':'handover';
    const name=target==='return'?'rmwc_return_checklist[]':'rmwc_handover_checklist[]';
    const $list=$(`.rmwc-checklist-items[data-checklist="${target}"]`);
    $list.append(`<div class="rmwc-checklist-item"><input type="text" maxlength="220" name="${name}" value=""><button type="button" class="button-link-delete rmwc-remove-checklist-item" aria-label="${rmwcAdminString('removeChecklistItem','Remove checklist item')}">×</button></div>`);
    $list.find('input').last().trigger('focus');
  });
  $(document).on('click','.rmwc-remove-checklist-item',function(){ $(this).closest('.rmwc-checklist-item').remove(); });

  $('.rmwc-signature-pad').each(function(){
    const canvas=this;
    const $canvas=$(canvas);
    const $wrap=$canvas.closest('.rmwc-signature-wrap');
    const $hidden=$wrap.find('.rmwc-signature-data');
    const ctx=canvas.getContext('2d');
    let drawing=false,dirty=false;
    function point(ev){
      const r=canvas.getBoundingClientRect();
      const e=ev.originalEvent||ev;
      const p=e.touches&&e.touches[0]?e.touches[0]:e;
      return {x:(p.clientX-r.left)*(canvas.width/r.width),y:(p.clientY-r.top)*(canvas.height/r.height)};
    }
    $canvas.on('pointerdown',function(ev){ev.preventDefault();drawing=true;const p=point(ev);ctx.beginPath();ctx.moveTo(p.x,p.y);try{canvas.setPointerCapture(ev.originalEvent.pointerId);}catch(e){}});
    $canvas.on('pointermove',function(ev){if(!drawing)return;ev.preventDefault();const p=point(ev);ctx.lineWidth=2.2;ctx.lineCap='round';ctx.lineJoin='round';ctx.lineTo(p.x,p.y);ctx.stroke();dirty=true;});
    $canvas.on('pointerup pointercancel pointerleave',function(){drawing=false;});
    $wrap.find('.rmwc-signature-clear').on('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);dirty=false;$hidden.val('');});
    $canvas.closest('form').on('submit',function(){if(dirty){$hidden.val(canvas.toDataURL('image/jpeg',0.82));}});
  });
});

// 2.0.13: return workflow always exposes the deposit settlement and requires
// an explicit disposition when less than the available deposit is repaid.
jQuery(function($){
  function money(v){return (Math.max(0,v)||0).toFixed(2).replace('.',',')+' €';}
  function syncDepositSettlement($box){
    const available=parseFloat(String($box.data('available')||'0').replace(',','.'))||0;
    const $amount=$box.find('input[name="rmwc_workflow_deposit_refund_amount"]');
    const amount=Math.min(available,Math.max(0,parseFloat(String($amount.val()||'0').replace(',','.'))||0));
    const remaining=Math.max(0,available-amount);
    const $reason=$box.find('select[name="rmwc_workflow_deposit_retention_reason"]');
    $box.find('.rmwc-workflow-deposit-retained-preview span').text(money(remaining));
    $box.toggleClass('has-remainder',remaining>0.0001);
    $reason.prop('required',remaining>0.0001);
    if(remaining<=0.0001){$reason.val('none');}
  }
  $(document).on('input change','.rmwc-workflow-deposit-settlement input[name="rmwc_workflow_deposit_refund_amount"], .rmwc-workflow-deposit-settlement select[name="rmwc_workflow_deposit_retention_reason"]',function(){
    syncDepositSettlement($(this).closest('.rmwc-workflow-deposit-settlement'));
  });
  $('.rmwc-workflow-deposit-settlement').each(function(){syncDepositSettlement($(this));});
});


// 2.0.12: return checklist uses explicit states; issue descriptions are mandatory.
jQuery(function($){
  function syncReturnChecklistRow($row){
    const status=$row.find('input[type="radio"]:checked').val()||'';
    const $note=$row.find('textarea[name^="checklist_note["]');
    const issue=status==='issue';
    $row.toggleClass('has-issue',issue);
    $note.prop('required',issue).attr('aria-required',issue?'true':'false');
  }
  $(document).on('change','.rmwc-workflow-return-check-row input[type="radio"]',function(){
    syncReturnChecklistRow($(this).closest('.rmwc-workflow-return-check-row'));
  });
  $('.rmwc-workflow-return-check-row').each(function(){syncReturnChecklistRow($(this));});
});
