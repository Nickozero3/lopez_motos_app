<?php require_once 'config.php'; require_auth(); $pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
  $pdo->beginTransaction();
  try{
    $st=$pdo->prepare('INSERT INTO clients(name,phone,email,dni,address,notes) VALUES(?,?,?,?,?,?)');
    $st->execute([$_POST['client_name'],$_POST['phone'],$_POST['email']?:null,$_POST['dni']?:null,$_POST['address']?:null,$_POST['client_notes']?:null]); $client=$pdo->lastInsertId();
    $st=$pdo->prepare('INSERT INTO vehicles(client_id,type,brand,model,plate,year,cc,color,engine_number,chassis_number,km) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$client,$_POST['type']?:'Moto',$_POST['brand'],$_POST['model'],$_POST['plate']?:null,$_POST['year']?:null,$_POST['cc']?:null,$_POST['color']?:null,$_POST['engine_number']?:null,$_POST['chassis_number']?:null,$_POST['km']?:null]); $veh=$pdo->lastInsertId();
    $code=order_code(); $tok=token();
    $st=$pdo->prepare('INSERT INTO work_orders(code,client_id,vehicle_id,mechanic_id,problem_reported,priority,estimated_delivery,public_token) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([$code,$client,$veh,auth()['id'],$_POST['problem_reported'],$_POST['priority'],$_POST['estimated_delivery']?:null,$tok]); $oid=$pdo->lastInsertId();
    $msg='Tu moto ingresó a Lopez Motos con orden '.$code.'. Ya podés consultar el seguimiento desde tu link.';
    $st=$pdo->prepare('INSERT INTO order_updates(order_id,user_id,status,internal_message,client_message,visible_client,notify_client) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$oid,auth()['id'],'Ingresada','Orden creada en recepción',$msg,1,1]);
    $pdo->commit(); redirect('order_view.php?id='.$oid);
  }catch(Exception $e){$pdo->rollBack(); $err=$e->getMessage();}
}
include 'partials/header.php'; ?>
<section class="card"><h1>Nueva orden de trabajo</h1><?php if(isset($err)):?><div class="notice"><?=h($err)?></div><?php endif;?>
<form method="post" class="grid">
<div class="span6"><h2>Cliente</h2><label>Nombre *</label><input name="client_name" required><label>Teléfono WhatsApp *</label><input name="phone" required placeholder="351..."><label>Email</label><input name="email"><label>DNI</label><input name="dni"><label>Dirección</label><input name="address"><label>Notas</label><textarea name="client_notes"></textarea></div>
<div class="span6"><h2>Vehículo</h2><label>Tipo</label><input name="type" value="Moto"><label>Marca *</label><input name="brand" required><label>Modelo *</label><input name="model" required><label>Patente</label><input name="plate"><label>Año</label><input name="year"><label>Cilindrada</label><input name="cc"><label>Color</label><input name="color"><label>N° motor</label><input name="engine_number"><label>N° chasis</label><input name="chassis_number"><label>Kilómetros</label><input type="number" name="km"></div>
<div class="span12"><h2>Trabajo</h2><label>Problema declarado por el cliente *</label><textarea name="problem_reported" required></textarea><label>Prioridad</label><select name="priority"><option>normal</option><option>baja</option><option>alta</option><option>urgente</option></select><label>Fecha estimada</label><input type="date" name="estimated_delivery"><button class="btn primary">Crear orden</button></div>
</form></section><?php include 'partials/footer.php'; ?>
