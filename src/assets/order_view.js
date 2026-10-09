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



document.addEventListener('DOMContentLoaded', () => {
  const status = document.getElementById('repairStatus');
  const help = document.getElementById('repairStatusHelp');
  if (!status || !help) return;

  const guidance = {
    'Ingresada': 'La moto ya ingresó al taller. Todavía no se comenzó la revisión.',
    'Pendiente de revisión': 'La moto está esperando que alguien del taller la revise.',
    'En diagnóstico': 'Estás revisando la moto para encontrar la causa del problema.',
    'Diagnóstico cargado': 'Ya registraste qué problema tiene la moto. Podés preparar el presupuesto.',
    'Presupuesto pendiente': 'Falta preparar o completar el presupuesto para el cliente.',
    'Esperando aprobación del cliente': 'El presupuesto fue enviado y se espera la respuesta del cliente.',
    'Aprobado': 'El cliente aceptó el presupuesto. Ya se puede organizar la reparación.',
    'En reparación': 'El trabajo está en curso. Anotá los avances importantes en el diagnóstico.',
    'Esperando repuestos': 'El trabajo está detenido hasta conseguir los repuestos necesarios.',
    'Repuesto solicitado': 'Los repuestos fueron pedidos, pero todavía no llegaron.',
    'Repuesto recibido': 'Los repuestos ya llegaron. Podés continuar con el trabajo.',
    'Con complicaciones': 'Surgió un problema o demora. Explicá el motivo en el diagnóstico o en la nota interna.',
    'Prueba final': 'La reparación terminó y se está comprobando que la moto funcione correctamente.',
    'Lista para retirar': 'La moto está lista. Si corresponde, avisale al cliente.',
    'Entregada': 'La moto ya fue entregada al cliente. Usá este estado solo cuando se haya retirado.',
    'Cancelada': 'La orden se canceló. Elegí este estado solo si el trabajo no continuará.'
  };
  const updateGuidance = () => {
    help.textContent = guidance[status.value] || 'Elegí el estado que mejor describe el avance actual.';
  };
  status.addEventListener('change', updateGuidance);
  updateGuidance();
});
