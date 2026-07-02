<?php
require_once 'config.php'; require_auth(); $pdo=db();
ensure_schema();
$errors=[]; $edit=null;
function save_part_photo($field='photo'){
  if(empty($_FILES[$field]) || ($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
  if($_FILES[$field]['error']!==UPLOAD_ERR_OK) throw new Exception('No se pudo subir la foto.');
  $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
  $mime=mime_content_type($_FILES[$field]['tmp_name']);
  if(!isset($allowed[$mime])) throw new Exception('La foto debe ser JPG, PNG o WEBP.');
  $dir=__DIR__.'/uploads/parts'; if(!is_dir($dir)) mkdir($dir,0775,true);
  $name='part_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$allowed[$mime];
  $dest=$dir.'/'.$name; move_uploaded_file($_FILES[$field]['tmp_name'],$dest);
  return 'uploads/parts/'.$name;
}
if(isset($_GET['edit'])){ $st=$pdo->prepare('SELECT * FROM parts WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit=$st->fetch(); }
try{
if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action']??'create';
  if($action==='create'){
    $photo=save_part_photo();
    $st=$pdo->prepare('INSERT INTO parts(name,sku,category,stock,buy_price,sell_price,supplier,photo_path,notes) VALUES(?,?,?,?,?,?,?,?,?)');
    $st->execute([trim($_POST['name']),$_POST['sku']?:null,$_POST['category']?:null,$_POST['stock']?:0,$_POST['buy_price']?:0,$_POST['sell_price']?:0,$_POST['supplier']?:null,$photo,$_POST['notes']?:null]);
    redirect('parts.php');
  }
  if($action==='update'){
    $id=(int)$_POST['id']; $photo=save_part_photo();
    if($photo){
      $st=$pdo->prepare('UPDATE parts SET name=?,sku=?,category=?,stock=?,buy_price=?,sell_price=?,supplier=?,photo_path=?,notes=? WHERE id=?');
      $st->execute([trim($_POST['name']),$_POST['sku']?:null,$_POST['category']?:null,$_POST['stock']?:0,$_POST['buy_price']?:0,$_POST['sell_price']?:0,$_POST['supplier']?:null,$photo,$_POST['notes']?:null,$id]);
    } else {
      $st=$pdo->prepare('UPDATE parts SET name=?,sku=?,category=?,stock=?,buy_price=?,sell_price=?,supplier=?,notes=? WHERE id=?');
      $st->execute([trim($_POST['name']),$_POST['sku']?:null,$_POST['category']?:null,$_POST['stock']?:0,$_POST['buy_price']?:0,$_POST['sell_price']?:0,$_POST['supplier']?:null,$_POST['notes']?:null,$id]);
    }
    redirect('parts.php');
  }
  if($action==='delete'){
    $st=$pdo->prepare('UPDATE parts SET active=0 WHERE id=?'); $st->execute([(int)$_POST['id']]); redirect('parts.php');
  }
}
}catch(Exception $e){$errors[]=$e->getMessage();}
$rows=$pdo->query('SELECT * FROM parts WHERE active=1 ORDER BY name')->fetchAll(); include 'partials/header.php'; ?>
<div class="grid">
<section class="card span4"><h1><?= $edit?'Editar repuesto':'Repuestos / Stock' ?></h1>
<?php foreach($errors as $er):?><div class="notice bad"><?=h($er)?></div><?php endforeach;?>
<div class="notice">Cargá una foto clara del repuesto o de su etiqueta y tocá <b>Completar con IA/OCR/OCR</b>. Usa OCR.Space para leer texto/códigos y completar nombre, categoría y SKU sugeridos. Revisá antes de guardar.</div>
<form method="post" enctype="multipart/form-data" id="partForm"><input type="hidden" name="action" value="<?= $edit?'update':'create' ?>"><?php if($edit):?><input type="hidden" name="id" value="<?=h($edit['id'])?>"><?php endif;?>
<label>Foto del producto</label><input type="file" name="photo" id="photoInput" accept="image/*" capture="environment"><img id="photoPreview" class="part-preview" src="<?=h($edit['photo_path']??'')?>" style="<?=empty($edit['photo_path'])?'display:none':''?>">
<button type="button" class="btn" id="aiFillBtn">Completar con IA/OCR</button><small class="muted" id="aiMsg"></small>
<label>Nombre</label><input name="name" id="name" required value="<?=h($edit['name']??'')?>"><label>Código / SKU</label><input name="sku" id="sku" value="<?=h($edit['sku']??'')?>"><label>Categoría</label><input name="category" id="category" placeholder="Ej: electricidad, motor, transmisión" value="<?=h($edit['category']??'')?>"><label>Stock</label><input type="number" step="0.01" name="stock" value="<?=h($edit['stock']??0)?>"><label>Precio compra</label><input type="number" step="0.01" name="buy_price" value="<?=h($edit['buy_price']??0)?>"><label>Precio venta</label><input type="number" step="0.01" name="sell_price" value="<?=h($edit['sell_price']??0)?>"><label>Proveedor</label><input name="supplier" value="<?=h($edit['supplier']??'')?>"><label>Notas</label><textarea name="notes" id="notes"><?=h($edit['notes']??'')?></textarea><button class="btn primary"><?= $edit?'Guardar cambios':'Agregar al stock' ?></button><?php if($edit):?><a class="btn" href="parts.php">Cancelar</a><?php endif;?></form></section>
<section class="card span8"><h2>Stock</h2><table><tr><th>Foto</th><th>Repuesto</th><th>Stock</th><th>Venta</th><th>Proveedor</th><th></th></tr><?php foreach($rows as $r):?><tr><td><?php if($r['photo_path']):?><img class="thumb" src="<?=h($r['photo_path'])?>"><?php endif;?></td><td><?=h($r['name'])?><br><small class="muted"><?=h($r['sku'])?> <?= $r['category']?'· '.h($r['category']):'' ?></small></td><td><?=h($r['stock'])?></td><td>$<?=number_format($r['sell_price'],2,',','.')?></td><td><?=h($r['supplier'])?></td><td><a class="btn" href="parts.php?edit=<?=h($r['id'])?>">Editar</a><form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este repuesto del stock?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=h($r['id'])?>"><button class="btn bad">Borrar</button></form></td></tr><?php endforeach;?></table></section></div>
<script src="assets/parts_ai.js"></script>
<?php include 'partials/footer.php'; ?>
