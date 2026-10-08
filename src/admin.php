<?php
require_once 'config.php'; require_role('admin'); ensure_schema(); $pdo=db(); $pageTitle='Administración'; $error=null;
try {
 if(is_post()){
  verify_csrf(); $action=(string)post('action');
  if($action==='save_user'){
   $id=(int)post('id'); $name=clean_text(post('name')); $username=clean_text(post('username')); $password=(string)post('password'); $role=(string)post('role');
   if($name===''||$username==='') throw new RuntimeException('Nombre y usuario son obligatorios.');
   if(!in_array($role,['admin','recepcion','mecanico'],true)) throw new RuntimeException('Rol inválido.');
   if($id){ $st=$pdo->prepare('SELECT * FROM users WHERE id=?');$st->execute([$id]);$before=$st->fetch(); if(!$before)throw new RuntimeException('Usuario no encontrado.');
    if($password!==''){ if(strlen($password)<6)throw new RuntimeException('La contraseña debe tener al menos 6 caracteres.'); $st=$pdo->prepare('UPDATE users SET name=?,username=?,password_hash=?,role=? WHERE id=?');$st->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role,$id]); }
    else {$st=$pdo->prepare('UPDATE users SET name=?,username=?,role=? WHERE id=?');$st->execute([$name,$username,$role,$id]);}
    audit('update','user',$id,$before,['name'=>$name,'username'=>$username,'role'=>$role]); flash('success','Usuario actualizado.');
   } else { if(strlen($password)<6)throw new RuntimeException('La contraseña debe tener al menos 6 caracteres.'); $st=$pdo->prepare('INSERT INTO users(name,username,password_hash,role) VALUES(?,?,?,?)');$st->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role]); audit('create','user',(int)$pdo->lastInsertId(),null,['name'=>$name,'username'=>$username,'role'=>$role]); flash('success','Usuario creado.'); }
   redirect('admin.php');
  }
  if($action==='toggle_user'){ $id=(int)post('id'); if($id===(int)auth()['id'])throw new RuntimeException('No podés desactivar tu propio usuario.'); $st=$pdo->prepare('SELECT * FROM users WHERE id=?');$st->execute([$id]);$before=$st->fetch();if(!$before)throw new RuntimeException('Usuario inexistente.');$new=$before['active']?0:1;$pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([$new,$id]);audit('toggle','user',$id,$before,['active'=>$new]);flash('success',$new?'Usuario activado.':'Usuario desactivado.');redirect('admin.php'); }
 }
} catch(Throwable $e){$error=$e->getMessage();}
$editId=(int)($_GET['edit']??0);$edit=null;if($editId){$st=$pdo->prepare('SELECT * FROM users WHERE id=?');$st->execute([$editId]);$edit=$st->fetch();}
$users=$pdo->query('SELECT id,name,username,role,active,last_login_at,created_at FROM users ORDER BY active DESC,name')->fetchAll();
$audit=$pdo->query('SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 30')->fetchAll();
include 'partials/header.php'; ?>
<div class="page-head"><div class="page-head-copy"><h1>Administración</h1><p>Usuarios, permisos y auditoría del taller.</p></div></div>
<?php if($error):?><div class="alert alert-error"><span><?=h($error)?></span></div><?php endif;?>
<div class="grid">
<section class="card span5"><div class="card-header"><div><h2><?=$edit?'Editar usuario':'Nuevo usuario'?></h2><p>Los permisos se aplican por rol.</p></div><?php if($edit):?><a class="btn btn-sm" href="admin.php">Nuevo</a><?php endif;?></div>
<form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="save_user"><input type="hidden" name="id" value="<?=h($edit['id']??0)?>">
<div class="field"><label class="required">Nombre</label><input name="name" required value="<?=h($edit['name']??'')?>"></div><div class="field"><label class="required">Usuario</label><input name="username" required value="<?=h($edit['username']??'')?>"></div>
<div class="field"><label><?= $edit?'Nueva contraseña':'Contraseña'?></label><input type="password" name="password" <?=$edit?'':'required'?> minlength="6"><span class="help"><?=$edit?'Dejá vacío para conservarla.':'Mínimo 6 caracteres.'?></span></div>
<div class="field"><label>Rol</label><select name="role"><option value="admin" <?=($edit['role']??'')==='admin'?'selected':''?>>Administrador</option><option value="recepcion" <?=($edit['role']??'')==='recepcion'?'selected':''?>>Recepción</option><option value="mecanico" <?=($edit['role']??'mecanico')==='mecanico'?'selected':''?>>Mecánico</option></select></div>
<div class="form-actions"><button class="btn btn-primary">Guardar usuario</button></div></form></section>
<section class="card span7"><div class="card-header"><div><h2>Usuarios</h2><p><?=count($users)?> cuentas configuradas.</p></div></div><div class="table-wrap responsive"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th></th></tr></thead><tbody><?php foreach($users as $u):?><tr><td class="primary-cell"><strong><?=h($u['name'])?></strong><br><small class="muted">@<?=h($u['username'])?></small></td><td data-label="Rol"><?=h(ucfirst($u['role']))?></td><td data-label="Estado"><span class="badge <?=$u['active']?'success':'danger'?>"><?=$u['active']?'Activo':'Inactivo'?></span></td><td data-label="Último acceso"><?=h(date_ar($u['last_login_at'],true))?></td><td data-label="Acciones"><div class="table-actions"><a class="btn btn-sm" href="admin.php?edit=<?=(int)$u['id']?>">Editar</a><?php if((int)$u['id']!==(int)auth()['id']):?><form method="post" data-confirm="¿Cambiar estado de este usuario?"><?=csrf_field()?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?=h($u['id'])?>"><button class="btn btn-sm <?=$u['active']?'btn-danger':'btn-success'?>"><?=$u['active']?'Desactivar':'Activar'?></button></form><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card span12"><div class="card-header"><div><h2>Auditoría reciente</h2><p>Últimas acciones administrativas y operativas.</p></div></div><div class="table-wrap responsive"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>ID</th><th>IP</th></tr></thead><tbody><?php foreach($audit as $a):?><tr><td class="primary-cell"><strong><?=h(date_ar($a['created_at'],true))?></strong></td><td data-label="Usuario"><?=h($a['user_name']?:'Sistema')?></td><td data-label="Acción"><?=h($a['action'])?></td><td data-label="Entidad"><?=h($a['entity'])?></td><td data-label="ID"><?=h($a['entity_id']?:'—')?></td><td data-label="IP"><?=h($a['ip']?:'—')?></td></tr><?php endforeach;?></tbody></table></div></section>
</div><?php include 'partials/footer.php'; ?>
