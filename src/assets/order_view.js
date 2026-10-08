document.addEventListener('DOMContentLoaded',()=>{const picker=document.getElementById('partPicker'),desc=document.getElementById('stockDescription'),price=document.getElementById('stockPrice'),qty=picker?.closest('form')?.querySelector('[name="quantity"]');if(!picker||!desc||!price)return;const sync=()=>{const o=picker.options[picker.selectedIndex];desc.value=o?.dataset.name||'';price.value=o?.dataset.price||'0';if(qty&&o?.dataset.stock)qty.max=o.dataset.stock;};picker.addEventListener('change',sync);sync();});

document.addEventListener('DOMContentLoaded',()=>{
  const identity=document.getElementById('identity');
  if(identity&&identity.dataset.openOnError!=='1')identity.open=false;
  document.querySelectorAll('[data-close-identity]').forEach(button=>button.addEventListener('click',()=>{
    if(!identity)return;
    identity.open=false;
    identity.querySelector('summary')?.focus();
    identity.scrollIntoView({behavior:'smooth',block:'start'});
  }));
});

