(() => {
  const body=document.body, toggle=document.querySelector('[data-menu-toggle]'), close=document.querySelector('[data-menu-close]');
  const setMenu=open=>{body.classList.toggle('menu-open',open);toggle?.setAttribute('aria-expanded',String(open));};
  toggle?.addEventListener('click',()=>setMenu(!body.classList.contains('menu-open'))); close?.addEventListener('click',()=>setMenu(false));
  document.querySelectorAll('.sidebar a').forEach(a=>a.addEventListener('click',()=>setMenu(false)));
  document.querySelectorAll('[data-uppercase]').forEach(input=>{const n=()=>input.value=input.value.toLocaleUpperCase('es-AR').replace(/\s+/g,'');input.addEventListener('input',n);input.addEventListener('blur',n);});
  document.querySelectorAll('[data-copy]').forEach(btn=>btn.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(btn.dataset.copy||'');const old=btn.innerHTML;btn.textContent='Copiado';setTimeout(()=>btn.innerHTML=old,1400);}catch{window.prompt('Copiá este enlace:',btn.dataset.copy||'');}}));
  document.querySelectorAll('[data-alert-close]').forEach(btn=>btn.addEventListener('click',()=>btn.closest('[data-alert]')?.remove()));
  document.querySelectorAll('[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{if(!confirm(form.dataset.confirm||'¿Confirmar esta acción?'))e.preventDefault();}));


  document.querySelectorAll('[data-row-edit-url]').forEach(row=>{
    const openEditor=event=>{
      if(event.target.closest('a,button,input,select,textarea,label,form'))return;
      const url=row.dataset.rowEditUrl;
      if(url)window.location.href=url;
    };
    row.addEventListener('click',openEditor);
    row.addEventListener('keydown',event=>{
      if(event.key!=='Enter'&&event.key!==' ')return;
      event.preventDefault();
      openEditor(event);
    });
  });

  const clientModes=document.querySelectorAll('[name="client_mode"]');
  const syncClient=()=>{const mode=document.querySelector('[name="client_mode"]:checked')?.value||'new';document.querySelectorAll('[data-client-mode-panel]').forEach(p=>{p.hidden=p.dataset.clientModePanel!==mode;p.querySelectorAll('[data-mode-required="true"]').forEach(f=>f.required=!p.hidden);});syncVehicle();};
  clientModes.forEach(i=>i.addEventListener('change',syncClient));

  const vehicleModes=document.querySelectorAll('[name="vehicle_mode"]');
  const syncVehicle=()=>{const cm=document.querySelector('[name="client_mode"]:checked')?.value||'new';if(cm==='new'){const n=document.querySelector('[name="vehicle_mode"][value="new"]');if(n)n.checked=true;}const mode=document.querySelector('[name="vehicle_mode"]:checked')?.value||'new';document.querySelectorAll('[data-vehicle-mode-panel]').forEach(p=>{p.hidden=p.dataset.vehicleModePanel!==mode;p.querySelectorAll('[data-vehicle-required="true"]').forEach(f=>f.required=!p.hidden);});const ex=document.querySelector('[name="vehicle_mode"][value="existing"]');if(ex)ex.disabled=cm==='new';};
  vehicleModes.forEach(i=>i.addEventListener('change',syncVehicle));

  const client=document.getElementById('existingClient'), vehicle=document.getElementById('existingVehicle');
  const syncVehicles=()=>{if(!client||!vehicle)return;let first='';[...vehicle.options].forEach(o=>{if(!o.value)return;const ok=o.dataset.clientId===client.value;o.hidden=!ok;o.disabled=!ok;if(ok&&!first)first=o.value;});if(vehicle.selectedOptions[0]?.disabled)vehicle.value=first;};
  client?.addEventListener('change',syncVehicles); if(clientModes.length)syncClient(); syncVehicle(); syncVehicles();
})();
