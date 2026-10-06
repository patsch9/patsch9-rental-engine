jQuery(function($){
  const box = $('.clr-rental-box');
  if (!box.length) return;

  const cal = $('#clr-calendar');
  const startInput = $('#clr_start_date');
  const endInput = $('#clr_end_date');
  let view = new Date();
  view = new Date(view.getFullYear(), view.getMonth(), 1);
  let bookings = [];
  let rules = {capacity:1,minDays:1,maxDays:0,advanceDays:0,pickupTime:'12:00',returnTime:'10:00',blockedPickupDays:(RMWCData.blockedPickupDays||[]),blockedReturnDays:(RMWCData.blockedReturnDays||[])};
  let start = null, end = null;
  let deliveryFee = 0;

  const pad = n => String(n).padStart(2,'0');
  const iso = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
  const parseDate = s => {
    if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
    const [y,m,d] = s.split('-').map(Number);
    const x = new Date(y,m-1,d);
    return (x.getFullYear()===y && x.getMonth()===m-1 && x.getDate()===d) ? x : null;
  };
  const addDays = (d,n) => { const x=new Date(d); x.setDate(x.getDate()+n); return x; };
  const dateTimeMs = (dateStr,timeStr) => {
    const d=parseDate(dateStr); if(!d) return NaN;
    const [h,m]=(timeStr||'00:00').split(':').map(Number);
    d.setHours(h||0,m||0,0,0); return d.getTime();
  };
  const bookingMs = s => {
    const m = String(s||'').match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
    return m ? dateTimeMs(m[1],m[2]) : NaN;
  };
  const qty = () => Math.max(1, parseInt($('form.cart input.qty').first().val() || '1',10) || 1);
  const getDayN = d => d.getDay() === 0 ? 7 : d.getDay();
  const dayBlocked = (days,d) => (Array.isArray(days)?days:[]).map(Number).includes(getDayN(d));

  function durationDays(a,b){
    const sm=dateTimeMs(iso(a), rules.pickupTime), em=dateTimeMs(iso(b), rules.returnTime);
    if(!isFinite(sm)||!isFinite(em)||em<=sm) return 0;
    // Charge by configured calendar rental cycles, not fixed 24h blocks. Using
    // UTC dates here keeps the preview stable across daylight-saving changes.
    const ua=Date.UTC(a.getFullYear(),a.getMonth(),a.getDate());
    const ub=Date.UTC(b.getFullYear(),b.getMonth(),b.getDate());
    const calendarDays=Math.round((ub-ua)/86400000);
    return Math.max(1, calendarDays);
  }

  function intervalAvailable(a,b,requestQty){
    const s=dateTimeMs(iso(a), rules.pickupTime), rawEnd=dateTimeMs(iso(b), rules.returnTime);
    if(!isFinite(s)||!isFinite(rawEnd)||rawEnd<=s) return false;
    const e=rawEnd + (parseInt(rules.bufferHours||0,10)*3600000);
    const events=[];
    bookings.forEach(r=>{
      const rs=bookingMs(r.startAt), re=bookingMs(r.endAt);
      if(!isFinite(rs)||!isFinite(re)||rs>=e||re<=s) return;
      events.push({t:rs,d:+parseInt(r.qty||1,10),k:1});
      events.push({t:re,d:-parseInt(r.qty||1,10),k:-1});
    });
    events.push({t:s,d:+requestQty,k:1});
    events.push({t:e,d:-requestQty,k:-1});
    events.sort((a,b)=>a.t===b.t ? a.k-b.k : a.t-b.t);
    let load=0;
    for(const ev of events){ load += ev.d; if(load > rules.capacity) return false; }
    return true;
  }

  function earliestEnd(a){
    const maxSearch=rules.maxDays ? Math.min(parseInt(rules.maxDays,10)+7,400) : 400;
    for(let i=0;i<=maxSearch;i++){
      const candidate=addDays(a,i);
      const days=durationDays(a,candidate);
      if(!days || days<rules.minDays) continue;
      if(rules.maxDays && days>rules.maxDays) break;
      if(dayBlocked(rules.blockedReturnDays,candidate)) continue;
      return candidate;
    }
    return null;
  }

  function allowedStart(d){
    const today=new Date(); today.setHours(0,0,0,0);
    if(d<today) return false;
    if(dayBlocked(rules.blockedPickupDays,d)) return false;
    if(dateTimeMs(iso(d), rules.pickupTime) < Date.now()) return false;
    if(rules.advanceDays && d>addDays(today,rules.advanceDays)) return false;
    const minEnd=earliestEnd(d);
    return !!minEnd && intervalAvailable(d,minEnd,qty());
  }

  function allowedReturn(d,a){
    if(!a || d<a) return false;
    if(dayBlocked(rules.blockedReturnDays,d)) return false;
    const days=durationDays(a,d);
    if(!days) return false;
    if(rules.minDays && days<rules.minDays) return false;
    if(rules.maxDays && days>rules.maxDays) return false;
    return intervalAvailable(a,d,qty());
  }

  function dayHasBooking(d){
    const ds=dateTimeMs(iso(d),'00:00'), de=dateTimeMs(iso(addDays(d,1)),'00:00');
    return bookings.some(r=>bookingMs(r.startAt)<de && bookingMs(r.endAt)>ds);
  }

  function fetchAvailability(){
    cal.addClass('clr-calendar-loading');
    let fromDate=new Date(view.getFullYear(),view.getMonth()-1,1);
    if(start){ const startMonth=new Date(start.getFullYear(),start.getMonth(),1); if(startMonth<fromDate) fromDate=startMonth; }
    const from=iso(fromDate);
    const to=iso(new Date(view.getFullYear(),view.getMonth()+3,0));
    $.get(RMWCData.ajaxUrl,{action:'rmwc_availability',nonce:RMWCData.nonce,product_id:RMWCData.productId,from,to})
      .done(r=>{
        if(r&&r.success){
          bookings=r.data.bookings||[];
          rules={...rules,...r.data};
          render(); updatePrice();
        }
      })
      .always(()=>cal.removeClass('clr-calendar-loading'));
  }

  function renderMonth(monthDate){
    const y=monthDate.getFullYear(), m=monthDate.getMonth();
    const first=new Date(y,m,1), last=new Date(y,m+1,0);
    const names=['Mo','Di','Mi','Do','Fr','Sa','So'];
    let html=`<div class="clr-month"><div class="clr-month-title">${first.toLocaleDateString('de-DE',{month:'long',year:'numeric'})}</div><div class="clr-cal-grid">`;
    names.forEach(n=>html+=`<div class="clr-cal-weekday">${n}</div>`);
    const offset=(first.getDay()+6)%7;
    for(let i=0;i<offset;i++) html+='<div class="clr-cal-empty"></div>';
    for(let day=1; day<=last.getDate(); day++){
      const d=new Date(y,m,day), s=iso(d);
      let cls='clr-cal-day';
      if(dayHasBooking(d)) cls+=' has-booking';
      if(start&&s===iso(start)) cls+=' is-selected';
      if(end&&s===iso(end)) cls+=' is-selected';
      if(start&&end&&d>start&&d<end) cls+=' is-in-range';
      let disabled=false;
      let title='';
      if(start&&!end){
        if(s===iso(start)){
          disabled=false;
        } else if(d<start){
          disabled=!allowedStart(d);
          if(dayBlocked(rules.blockedPickupDays,d)) title='Keine Abholung / kein Mietbeginn an diesem Wochentag';
        } else {
          disabled=!allowedReturn(d,start);
          if(dayBlocked(rules.blockedReturnDays,d)) title='Keine Rückgabe an diesem Wochentag';
        }
      } else {
        disabled=!allowedStart(d);
        if(dayBlocked(rules.blockedPickupDays,d)) title='Keine Abholung / kein Mietbeginn an diesem Wochentag';
      }
      html+=`<button type="button" class="${cls}" data-date="${s}" ${disabled?'disabled':''} ${title?`title="${title}"`:''}>${day}</button>`;
    }
    html+='</div></div>';
    return html;
  }

  function render(){
    cal.html(`<div class="clr-cal-head"><button type="button" class="clr-prev" aria-label="Vorheriger Monat">‹</button><span class="clr-cal-legend"><span class="clr-dot"></span> teilweise/zeitweise belegt</span><button type="button" class="clr-next" aria-label="Nächster Monat">›</button></div><div class="clr-months">${renderMonth(view)}${renderMonth(new Date(view.getFullYear(),view.getMonth()+1,1))}</div>`);
  }

  cal.on('click','.clr-prev',()=>{ view=new Date(view.getFullYear(),view.getMonth()-1,1); fetchAvailability(); });
  cal.on('click','.clr-next',()=>{ view=new Date(view.getFullYear(),view.getMonth()+1,1); fetchAvailability(); });
  cal.on('click','.clr-cal-day',function(){
    const d=parseDate($(this).data('date')); if(!d) return;
    if(!start || end || d<start){
      if(!allowedStart(d)) return;
      start=d; end=null;
    }
    else {
      if(dayBlocked(rules.blockedReturnDays,d)){
        alert('An diesem Wochentag ist keine Rückgabe möglich.');
        return;
      }
      if(!allowedReturn(d,start)){
        alert('Dieser Zeitraum ist nicht buchbar, hat nicht die erlaubte Mietdauer oder es ist nicht genügend Bestand frei.');
        return;
      }
      end=d;
    }
    startInput.val(start?iso(start):''); endInput.val(end?iso(end):''); render(); updatePrice();
  });

  function syncInputs(){
    const s=parseDate(startInput.val()), e=parseDate(endInput.val());
    if(s && !allowedStart(s)){
      start=null; end=null;
      startInput.val(''); endInput.val('');
      render(); updatePrice();
      return;
    }
    if(s && e){
      if(allowedReturn(e,s)){ start=s; end=e; }
      else { start=s; end=null; endInput.val(''); }
    } else { start=s; end=null; }
    render(); updatePrice();
  }
  startInput.on('change',syncInputs); endInput.on('change',syncInputs);
  $(document).on('change input','form.cart input.qty',function(){ render(); updatePrice(); });

  function rentalPrice(days){
    if(!start||!end) return {price:0,units:0,label:''};
    const w=RMWCData.weekend||{};
    const weekendStart=parseInt(w.startDay,10), weekendEnd=parseInt(w.endDay,10);
    const weekendSpan=((weekendEnd-weekendStart+7)%7)||7;
    if(w.enabled && days===weekendSpan && getDayN(start)===weekendStart && getDayN(end)===weekendEnd){
      return {price:parseFloat(w.price||0),units:1,label:'Wochenendtarif'};
    }
    const matches=(RMWCData.pricingRules||[]).filter(r=>{
      const min=Math.max(1,parseInt(r.min_days||1,10)), max=Math.max(0,parseInt(r.max_days||0,10));
      return days>=min && (!max || days<=max);
    }).sort((a,b)=>{
      const amin=parseInt(a.min_days||1,10), bmin=parseInt(b.min_days||1,10);
      if(amin!==bmin) return bmin-amin;
      const amax=parseInt(a.max_days||0,10)||Number.MAX_SAFE_INTEGER;
      const bmax=parseInt(b.max_days||0,10)||Number.MAX_SAFE_INTEGER;
      return amax-bmax;
    });
    if(matches.length){
      const r=matches[0], included=Math.max(1,parseInt(r.included_days||r.min_days||1,10));
      let price=parseFloat(r.package_price||0);
      if(days>included && r.extra_day_price!=='' && r.extra_day_price!=null) price += (days-included)*parseFloat(r.extra_day_price||0);
      return {price,units:1,label:'Preisstaffel'};
    }
    const unit=box.data('unit');
    if(unit==='day'){
      const weekdayPrices=RMWCData.weekdayPrices||{};
      const standard=parseFloat(box.data('unit-price')||0);
      let price=0, usedOverride=false;
      for(let i=0;i<days;i++){
        const rentalDay=addDays(start,i);
        const key=String(getDayN(rentalDay));
        if(Object.prototype.hasOwnProperty.call(weekdayPrices,key) && weekdayPrices[key]!=='' && weekdayPrices[key]!=null){
          price+=parseFloat(weekdayPrices[key]||0);
          usedOverride=true;
        } else {
          price+=standard;
        }
      }
      return {price,units:days,label:usedOverride?'Wochentagstarif':'Standardtarif'};
    }
    let units=days;
    if(unit==='week') units=Math.ceil(days/7);
    if(unit==='month') units=Math.ceil(days/30);
    return {price:parseFloat(box.data('unit-price')||0)*units,units,label:'Standardtarif'};
  }

  function currency(v){
    try{return new Intl.NumberFormat(RMWCData.locale||'de-DE',{style:'currency',currency:RMWCData.currency||'EUR'}).format(v);}catch(e){return v.toFixed(2)+' '+(RMWCData.currencySymbol||'€');}
  }

  function updatePrice(){
    const fulfilment=box.find('input[name="clr_fulfilment"]:checked').val()||'';
    const deliverySelected=fulfilment==='delivery'||fulfilment==='delivery_return';
    const transportLegs=fulfilment==='delivery_return'?2:(fulfilment==='delivery'?1:0);
    box.find('.clr-delivery-fields').prop('hidden',!deliverySelected);
    let baseDeliveryFee=0;
    if(!deliverySelected) deliveryFee=0;
    else if($('#clr_delivery_zone').is('select')) baseDeliveryFee=parseFloat($('#clr_delivery_zone option:selected').data('price')||0);
    else if($('#clr_delivery_zone').is('input')) baseDeliveryFee=parseFloat($('#clr_delivery_zone').data('price')||0);
    else if(!box.find('.clr-delivery-fields').find('#clr_delivery_zone').length) baseDeliveryFee=parseFloat(box.data('fallback-delivery')||0);
    deliveryFee=baseDeliveryFee*transportLegs;

    if(!start||!end){ $('#clr_price_preview').text('–'); $('#clr_duration_preview').text(''); return; }
    const days=durationDays(start,end); if(!days){$('#clr_price_preview').text('–');return;}
    const base=rentalPrice(days); let perItem=base.price;
    box.find('select[name^="clr_addon"]').each(function(){
      const o=$(this).find(':selected'); if(!o.val()) return;
      let p=parseFloat(o.data('price')||0); if(o.data('mode')==='unit') p*=base.units; else if(o.data('mode')==='day') p*=days; perItem+=p;
    });
    const count=qty();
    let accessoryTotal=0;
    $('form.cart .clr-accessory-choice:checked').each(function(){
      const $choice=$(this);
      if(!$choice.val() || $choice.val()==='0') return;
      const $card=$choice.closest('.clr-accessory-card');
      const price=parseFloat($card.data('price')||0);
      const mode=String($card.data('mode')||'once');
      const accessoryQty=Math.max(1,parseInt($card.find('.clr-accessory-qty-input').val()||$card.find('input[type="hidden"][name*="[qty]"]').val()||'1',10)||1);
      accessoryTotal += price*accessoryQty*(mode==='per_device'?count:1);
    });
    const total=(perItem*count)+accessoryTotal+deliveryFee;
    $('#clr_price_preview').text(currency(total));
    $('#clr_duration_preview').text(`${days} Miettag(e), ${base.label}; ${iso(start)} ${rules.pickupTime} – ${iso(end)} ${rules.returnTime}`);
  }

  box.on('change','select[name^="clr_addon"],input[name="clr_fulfilment"],#clr_delivery_zone',updatePrice);
  box.on('change','input[name="clr_fulfilment"]',function(){
    if($(this).val()!=='delivery'&&$(this).val()!=='delivery_return'){
      $('#clr_delivery_result').text(''); $('#clr_delivery_distance').val('');
    }
    updatePrice();
  });

  box.on('input','#clr_delivery_address',function(){
    $('#clr_delivery_zone').val('').data('price',0);
    $('#clr_delivery_distance').val('');
    $('#clr_delivery_result').text('').removeClass('is-error');
    deliveryFee=0; updatePrice();
  });

  box.on('click','.clr-quote-delivery',function(){
    const btn=$(this), address=$('#clr_delivery_address').val().trim();
    if(!address){ alert('Bitte zuerst die Lieferadresse eingeben.'); return; }
    btn.prop('disabled',true).text('Berechne …');
    $('#clr_delivery_result').removeClass('is-error').text('');
    $.post(RMWCData.ajaxUrl,{action:'rmwc_delivery_quote',nonce:RMWCData.nonce,product_id:RMWCData.productId,address})
      .done(r=>{
        if(r&&r.success){
          deliveryFee=parseFloat(r.data.zone.price||0);
          $('#clr_delivery_zone').val(r.data.zone.index).data('price',deliveryFee);
          $('#clr_delivery_distance').val(r.data.distance);
          const mode=box.find('input[name="clr_fulfilment"]:checked').val()||'delivery';
          const legs=mode==='delivery_return'?2:1;
          $('#clr_delivery_result').text(`${r.data.distance} km je Fahrt – ${r.data.zone.label}: ${currency(deliveryFee)} je Fahrt${legs===2?' / '+currency(deliveryFee*2)+' gesamt':''} · Google Maps`);
          updatePrice();
        } else {
          $('#clr_delivery_result').addClass('is-error').text((r&&r.data&&r.data.message)||'Lieferkosten konnten nicht berechnet werden.');
        }
      })
      .fail(xhr=>{
        const m=xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message;
        $('#clr_delivery_result').addClass('is-error').text(m||'Lieferkosten konnten nicht berechnet werden.');
      })
      .always(()=>btn.prop('disabled',false).text('Transportkosten berechnen'));
  });

  function syncAccessoryCards($group){
    $group.find('.clr-accessory-card').each(function(){
      const $card=$(this),$choice=$card.find('.clr-accessory-choice');
      const selected=$choice.is(':checked');
      $card.toggleClass('is-selected',selected);
      let label='Auswählen';
      if(selected){
        label=$choice.val()==='0'?'Ohne Zusatz gewählt':'Ausgewählt';
      }else if($choice.val()==='0'){
        label='Ohne Zusatz';
      }
      $card.find('.clr-accessory-select-label').text(label);
      const $qty=$card.find('.clr-accessory-qty-input');
      if($qty.length) $qty.prop('disabled',!selected);
    });

    if($group.data('required')==='yes' && $group.data('selection')==='multiple'){
      const $first=$group.find('.clr-accessory-choice').first();
      if($first.length){
        const anySelected=$group.find('.clr-accessory-choice:checked').length>0;
        $first.get(0).setCustomValidity(anySelected?'':'Bitte wählen Sie mindestens eine Zubehör-Option aus.');
      }
    }
  }

  $('.clr-accessory-group').each(function(){syncAccessoryCards($(this));});

  $(document).on('change','.clr-accessory-choice',function(){
    const $group=$(this).closest('.clr-accessory-group');
    syncAccessoryCards($group);
    updatePrice();
  });

  $(document).on('click','.clr-accessory-qty-minus,.clr-accessory-qty-plus',function(){
    const $card=$(this).closest('.clr-accessory-card');
    const $choice=$card.find('.clr-accessory-choice');
    if(!$choice.is(':checked')){
      $choice.prop('checked',true).trigger('change');
    }
    const $input=$card.find('.clr-accessory-qty-input');
    if(!$input.length) return;
    const min=Math.max(1,parseInt($input.attr('min')||'1',10)||1);
    const max=Math.max(min,parseInt($input.attr('max')||'10000',10)||10000);
    let value=Math.max(min,parseInt($input.val()||String(min),10)||min);
    value += $(this).hasClass('clr-accessory-qty-plus')?1:-1;
    $input.val(Math.min(max,Math.max(min,value))).trigger('change');
  });

  $(document).on('input change','.clr-accessory-qty-input',function(){
    const $input=$(this);
    const min=Math.max(1,parseInt($input.attr('min')||'1',10)||1);
    const max=Math.max(min,parseInt($input.attr('max')||'10000',10)||10000);
    let value=parseInt($input.val()||String(min),10)||min;
    value=Math.min(max,Math.max(min,value));
    $input.val(value);
    updatePrice();
  });

  $(document).on('click','.clr-accessory-slider-prev,.clr-accessory-slider-next',function(){
    const $track=$(this).closest('.clr-accessory-group').find('.clr-accessory-slider').first();
    if(!$track.length) return;
    const direction=$(this).hasClass('clr-accessory-slider-next')?1:-1;
    const amount=Math.max(260,Math.round($track.get(0).clientWidth*.78))*direction;
    $track.get(0).scrollBy({left:amount,behavior:'smooth'});
  });

  fetchAvailability();
  updatePrice();
});
