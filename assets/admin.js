jQuery(function($){
  function toggleRentalTab(){
    const on=$('#_clr_rental_enabled').is(':checked');
    const tab=$('a[href="#clr_rental_product_data"]').closest('li');
    tab.toggle(on);
    if(!on && $('#clr_rental_product_data').is(':visible')) $('a[href="#general_product_data"]').trigger('click');
  }
  function keepProductModesExclusive(changed){
    const rental=$('#_clr_rental_enabled');
    const accessory=$('#_clr_accessory_only');
    if(!accessory.length) return;
    if(changed==='rental' && rental.is(':checked')) accessory.prop('checked',false);
    if(changed==='accessory' && accessory.is(':checked')) rental.prop('checked',false);
    toggleRentalTab();
  }
  toggleRentalTab();
  $('#_clr_rental_enabled').on('change',function(){keepProductModesExclusive('rental');});
  $('#_clr_accessory_only').on('change',function(){keepProductModesExclusive('accessory');});

  let addonIdx=$('.clr-addons-table tbody tr').length;
  $('.clr-add-addon').on('click',function(){
    $('.clr-addons-table tbody').append(`<tr><td><input type="text" name="clr_addons[${addonIdx}][group]" placeholder="Material"></td><td><input type="text" name="clr_addons[${addonIdx}][label]" placeholder="für 20 Personen"></td><td><input type="number" step="0.01" min="0" name="clr_addons[${addonIdx}][price]" value="0"></td><td><select name="clr_addons[${addonIdx}][mode]"><option value="once">einmalig</option><option value="unit">pro Mieteinheit</option><option value="day">pro Miettag</option></select></td><td><button type="button" class="button clr-remove-row">×</button></td></tr>`); addonIdx++;
  });

  let priceIdx=$('.clr-pricing-table tbody tr').length;
  $('.clr-add-pricing').on('click',function(){
    $('.clr-pricing-table tbody').append(`<tr><td><input type="number" min="1" step="1" name="clr_pricing[${priceIdx}][min_days]" value="1"></td><td><input type="number" min="0" step="1" name="clr_pricing[${priceIdx}][max_days]" placeholder="∞"></td><td><input type="number" min="0" step="0.01" name="clr_pricing[${priceIdx}][package_price]"></td><td><input type="number" min="1" step="1" name="clr_pricing[${priceIdx}][included_days]" value="1"></td><td><input type="number" min="0" step="0.01" name="clr_pricing[${priceIdx}][extra_day_price]"></td><td><button type="button" class="button clr-remove-row">×</button></td></tr>`); priceIdx++;
  });

  let deliveryIdx=$('.clr-delivery-table tbody tr').length;
  $('.clr-add-delivery').on('click',function(){
    $('.clr-delivery-table tbody').append(`<tr><td><input type="text" name="clr_delivery_zones[${deliveryIdx}][label]" placeholder="bis 10 km"></td><td><input type="number" step="0.1" min="0.1" name="clr_delivery_zones[${deliveryIdx}][max_km]"></td><td><input type="number" step="0.01" min="0" name="clr_delivery_zones[${deliveryIdx}][price]" value="0"></td><td><button type="button" class="button clr-remove-row">×</button></td></tr>`); deliveryIdx++;
  });


  let materialGroupIdx=$('.clr-v2-material-group').length;
  function materialOptionMarkup(groupIdx,optionIdx){
    return `<div class="clr-v2-material-option" data-option-index="${optionIdx}">
      <div class="clr-v2-field clr-v2-field-product"><label>Miet-Zubehörprodukt</label><select class="wc-product-search" name="clr_v2_accessory_groups[${groupIdx}][options][${optionIdx}][product_id]" data-placeholder="Miet-Zubehör suchen…" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select><p class="description">Preis, Steuer, Bestand und „Nur einzeln verkaufen“ stammen direkt aus diesem WooCommerce-Artikel.</p></div>
      <div class="clr-v2-field clr-v2-field-label"><label>Anzeige für Kunden</label><input type="text" name="clr_v2_accessory_groups[${groupIdx}][options][${optionIdx}][label]" placeholder="z. B. für ca. 1 kg süßes Popcorn" maxlength="150"><p class="description">Optional. Leer = Produktname verwenden.</p></div>
      <div class="clr-v2-field clr-v2-field-mode"><label>Berechnung</label><select name="clr_v2_accessory_groups[${groupIdx}][options][${optionIdx}][mode]"><option value="once">1× je Buchung</option><option value="per_device">je Mietgerät</option></select></div>
      <button type="button" class="button-link-delete clr-remove-material-option" aria-label="Miet-Zubehör aus Gruppe entfernen">×</button>
    </div>`;
  }
  function materialGroupMarkup(groupIdx){
    return `<div class="clr-v2-material-group" data-group-index="${groupIdx}" data-next-option="0">
      <div class="clr-v2-material-group-head">
        <div class="clr-v2-material-group-settings">
          <div class="clr-v2-field clr-v2-group-name"><label>Gruppenname</label><input type="text" name="clr_v2_accessory_groups[${groupIdx}][label]" placeholder="Material" maxlength="100" required></div>
          <div class="clr-v2-field clr-v2-group-selection"><label>Auswahlart</label><select name="clr_v2_accessory_groups[${groupIdx}][selection]"><option value="single">Einfachauswahl – maximal eine Option</option><option value="multiple">Mehrfachauswahl – mehrere Optionen möglich</option></select></div>
          <div class="clr-v2-field clr-v2-group-required"><span class="clr-v2-label">Pflichtauswahl</span><label class="clr-v2-checkbox"><input type="checkbox" name="clr_v2_accessory_groups[${groupIdx}][required]" value="1"><span>Mindestens eine Option muss gewählt werden</span></label></div>
        </div>
        <div class="clr-v2-material-group-actions"><button type="button" class="button clr-v2-add-material-option">+ Zubehör hinzufügen</button><button type="button" class="button-link-delete clr-remove-material-group" aria-label="Zubehörgruppe entfernen">×</button></div>
      </div>
      <div class="clr-v2-material-options-list"></div>
      <p class="clr-v2-material-group-empty">Noch kein Zubehör in dieser Gruppe. Füge mindestens einen Artikel hinzu.</p>
    </div>`;
  }
  $(document).on('click','.clr-v2-add-material-group',function(){
    const $group=$(materialGroupMarkup(materialGroupIdx));
    $('.clr-v2-material-group-list').append($group);
    $('.clr-v2-material-empty').hide();
    materialGroupIdx++;
    $group.find('.clr-v2-add-material-option').trigger('click');
  });
  $(document).on('click','.clr-v2-add-material-option',function(){
    const $group=$(this).closest('.clr-v2-material-group');
    const groupIdx=parseInt($group.data('group-index'),10);
    let optionIdx=parseInt($group.attr('data-next-option')||'0',10);
    $group.find('.clr-v2-material-options-list').append(materialOptionMarkup(groupIdx,optionIdx));
    $group.attr('data-next-option',String(optionIdx+1));
    $group.find('.clr-v2-material-group-empty').hide();
    $(document.body).trigger('wc-enhanced-select-init');
  });
  $(document).on('click','.clr-remove-material-option',function(){
    const $group=$(this).closest('.clr-v2-material-group');
    $(this).closest('.clr-v2-material-option').remove();
    if(!$group.find('.clr-v2-material-option').length) $group.find('.clr-v2-material-group-empty').show();
  });
  $(document).on('click','.clr-remove-material-group',function(){
    $(this).closest('.clr-v2-material-group').remove();
    if(!$('.clr-v2-material-group').length) $('.clr-v2-material-empty').show();
  });

  $(document).on('click','.clr-remove-row',function(){ $(this).closest('tr').remove(); });
});

jQuery(function($){
  $(document).on('click','.clr-add-checklist-item',function(){
    const target=$(this).data('target')==='return'?'return':'handover';
    const name=target==='return'?'clr_return_checklist[]':'clr_handover_checklist[]';
    const $list=$(`.clr-checklist-items[data-checklist="${target}"]`);
    $list.append(`<div class="clr-checklist-item"><input type="text" maxlength="220" name="${name}" value=""><button type="button" class="button-link-delete clr-remove-checklist-item" aria-label="Prüfpunkt entfernen">×</button></div>`);
    $list.find('input').last().trigger('focus');
  });
  $(document).on('click','.clr-remove-checklist-item',function(){ $(this).closest('.clr-checklist-item').remove(); });

  $('.clr-signature-pad').each(function(){
    const canvas=this;
    const $canvas=$(canvas);
    const $wrap=$canvas.closest('.clr-signature-wrap');
    const $hidden=$wrap.find('.clr-signature-data');
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
    $wrap.find('.clr-signature-clear').on('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);dirty=false;$hidden.val('');});
    $canvas.closest('form').on('submit',function(){if(dirty){$hidden.val(canvas.toDataURL('image/jpeg',0.82));}});
  });
});

// 2.0.13: return workflow always exposes the deposit settlement and requires
// an explicit disposition when less than the available deposit is repaid.
jQuery(function($){
  function money(v){return (Math.max(0,v)||0).toFixed(2).replace('.',',')+' €';}
  function syncDepositSettlement($box){
    const available=parseFloat(String($box.data('available')||'0').replace(',','.'))||0;
    const $amount=$box.find('input[name="clr_workflow_deposit_refund_amount"]');
    const amount=Math.min(available,Math.max(0,parseFloat(String($amount.val()||'0').replace(',','.'))||0));
    const remaining=Math.max(0,available-amount);
    const $reason=$box.find('select[name="clr_workflow_deposit_retention_reason"]');
    $box.find('.clr-workflow-deposit-retained-preview span').text(money(remaining));
    $box.toggleClass('has-remainder',remaining>0.0001);
    $reason.prop('required',remaining>0.0001);
    if(remaining<=0.0001){$reason.val('none');}
  }
  $(document).on('input change','.clr-workflow-deposit-settlement input[name="clr_workflow_deposit_refund_amount"], .clr-workflow-deposit-settlement select[name="clr_workflow_deposit_retention_reason"]',function(){
    syncDepositSettlement($(this).closest('.clr-workflow-deposit-settlement'));
  });
  $('.clr-workflow-deposit-settlement').each(function(){syncDepositSettlement($(this));});
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
  $(document).on('change','.clr-workflow-return-check-row input[type="radio"]',function(){
    syncReturnChecklistRow($(this).closest('.clr-workflow-return-check-row'));
  });
  $('.clr-workflow-return-check-row').each(function(){syncReturnChecklistRow($(this));});
});
