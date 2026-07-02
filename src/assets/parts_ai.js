const photoInput=document.getElementById('photoInput');
const preview=document.getElementById('photoPreview');
const btn=document.getElementById('aiFillBtn');
const msg=document.getElementById('aiMsg');
if(photoInput){photoInput.addEventListener('change',()=>{const f=photoInput.files[0]; if(!f)return; preview.src=URL.createObjectURL(f); preview.style.display='block';});}
if(btn){btn.addEventListener('click',async()=>{
  const f=photoInput.files[0]; if(!f){msg.textContent=' Subí una foto primero.'; return;}
  btn.disabled=true; msg.textContent=' Leyendo foto con OCR.Space...';
  try{const fd=new FormData(); fd.append('photo',f); const r=await fetch('ai_part.php',{method:'POST',body:fd}); const j=await r.json();
    if(!j.ok){msg.textContent=' '+j.message; return;}
    const d=j.data||{}; if(d.name) document.getElementById('name').value=d.name; if(d.sku) document.getElementById('sku').value=d.sku; if(d.category) document.getElementById('category').value=d.category; if(d.notes) document.getElementById('notes').value=d.notes;
    msg.textContent=' Datos sugeridos. Revisalos antes de guardar.';
  }catch(e){msg.textContent=' No se pudo analizar: '+e.message;} finally{btn.disabled=false;}
});}
