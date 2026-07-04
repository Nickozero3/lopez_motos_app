<?php
require_once 'config.php';
require_auth();
ensure_schema();

$pdo = db();
$pageTitle = 'Stock y repuestos';
$error = null;

function part_by_id(PDO $pdo, int $id, bool $lock = false): array
{
    $stmt = $pdo->prepare('SELECT * FROM parts WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $part = $stmt->fetch();
    if (!$part) throw new RuntimeException('El repuesto seleccionado no existe.');
    return $part;
}

function sanitize_part_notes(mixed $value): ?string
{
    $notes = trim((string)$value);
    if ($notes === '') return null;

    // Esta advertencia se muestra para revisión, pero nunca debe guardarse.
    $notes = preg_replace(
        '/(?:^|\R)\s*Revisar(?:\s+datos)?\s+antes\s+de\s+guardar\.?\s*(?=\R|$)/iu',
        '',
        $notes
    ) ?? $notes;

    $notes = preg_replace('/\R{3,}/u', "\n\n", trim($notes)) ?? trim($notes);
    return $notes === '' ? null : $notes;
}

function save_part_photo(string $field = 'photo'): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo subir la foto.');
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('La foto no puede superar los 5 MB.');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('La foto debe ser JPG, PNG o WEBP.');

    $directory = __DIR__ . '/uploads/parts';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear la carpeta de imágenes.');
    }

    $filename = 'part_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('No se pudo guardar la foto.');
    }
    return 'uploads/parts/' . $filename;
}

if (is_post()) {
    try {
        verify_csrf();
        $action = (string)post('action');

        if ($action === 'create') {
            $name = clean_text(post('name'));
            $stock = max(0, decimal_value(post('stock')));
            if ($name === '') throw new RuntimeException('El nombre del repuesto es obligatorio.');
            $photo = save_part_photo();
            $notes = sanitize_part_notes(post('notes'));

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO parts (name,sku,category,stock,min_stock,buy_price,sell_price,supplier,photo_path,notes,active) VALUES (?,?,?,?,?,?,?,?,?,?,1)');
            $stmt->execute([$name, nullable_text(post('sku')), nullable_text(post('category')), $stock, max(0, decimal_value(post('min_stock'))), max(0, decimal_value(post('buy_price'))), max(0, decimal_value(post('sell_price'))), nullable_text(post('supplier')), $photo, $notes]);
            $partId = (int)$pdo->lastInsertId();
            if ($stock > 0) add_stock_movement($partId, null, null, 'entrada', $stock, 0, $stock, 'Stock inicial');
            $pdo->commit();
            flash('success', 'Repuesto agregado al inventario.');
            redirect('parts.php');
        }

        if ($action === 'update') {
            $partId = (int)post('id');
            $name = clean_text(post('name'));
            $newStock = max(0, decimal_value(post('stock')));
            if ($name === '') throw new RuntimeException('El nombre del repuesto es obligatorio.');
            $photo = save_part_photo();
            $notes = sanitize_part_notes(post('notes'));

            $pdo->beginTransaction();
            $part = part_by_id($pdo, $partId, true);
            $fields = 'name=?,sku=?,category=?,stock=?,min_stock=?,buy_price=?,sell_price=?,supplier=?,notes=?';
            $values = [$name, nullable_text(post('sku')), nullable_text(post('category')), $newStock, max(0, decimal_value(post('min_stock'))), max(0, decimal_value(post('buy_price'))), max(0, decimal_value(post('sell_price'))), nullable_text(post('supplier')), $notes];
            if ($photo !== null) { $fields .= ',photo_path=?'; $values[] = $photo; }
            $values[] = $partId;
            $stmt = $pdo->prepare("UPDATE parts SET {$fields} WHERE id=?");
            $stmt->execute($values);

            $before = (float)$part['stock'];
            if (abs($newStock - $before) > 0.0001) {
                add_stock_movement($partId, null, null, 'ajuste', abs($newStock - $before), $before, $newStock, 'Ajuste desde edición del producto');
            }
            $pdo->commit();
            if ($photo && !empty($part['photo_path']) && is_file(__DIR__ . '/' . $part['photo_path'])) @unlink(__DIR__ . '/' . $part['photo_path']);
            flash('success', 'Repuesto actualizado.');
            redirect('parts.php?view=' . $partId);
        }

        if ($action === 'movement') {
            $partId = (int)post('id');
            $type = post('movement_type') === 'salida' ? 'salida' : 'entrada';
            $quantity = decimal_value(post('quantity'));
            if ($quantity <= 0) throw new RuntimeException('La cantidad debe ser mayor a cero.');

            $pdo->beginTransaction();
            $part = part_by_id($pdo, $partId, true);
            $before = (float)$part['stock'];
            $after = $type === 'entrada' ? $before + $quantity : $before - $quantity;
            if ($after < 0) throw new RuntimeException('La salida supera el stock disponible.');
            $stmt = $pdo->prepare('UPDATE parts SET stock=? WHERE id=?');
            $stmt->execute([$after, $partId]);
            add_stock_movement($partId, null, null, $type, $quantity, $before, $after, nullable_text(post('movement_notes')) ?: 'Movimiento manual');
            $pdo->commit();
            flash('success', $type === 'entrada' ? 'Entrada registrada.' : 'Salida registrada.');
            redirect('parts.php?view=' . $partId);
        }

        if (in_array($action, ['archive', 'restore'], true)) {
            $partId = (int)post('id');
            $active = $action === 'restore' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE parts SET active=? WHERE id=?');
            $stmt->execute([$active, $partId]);
            flash('success', $active ? 'Repuesto restaurado.' : 'Repuesto archivado sin perder su historial.');
            redirect('parts.php?status=' . ($active ? 'active' : 'archived'));
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$viewId = (int)($_GET['view'] ?? 0);
$edit = $editId ? part_by_id($pdo, $editId) : null;
$selected = $viewId ? part_by_id($pdo, $viewId) : ($edit ?: null);
$q = trim((string)($_GET['q'] ?? ''));
$rawStatus = (string)($_GET['status'] ?? 'active');
$status = in_array($rawStatus, ['active', 'archived', 'all'], true) ? $rawStatus : 'active';
$stockFilter = in_array($_GET['stock'] ?? '', ['low', 'zero'], true) ? $_GET['stock'] : '';

$where = []; $params = [];
if ($status !== 'all') { $where[] = 'active=?'; $params[] = $status === 'active' ? 1 : 0; }
if ($q !== '') { $where[] = '(name LIKE ? OR sku LIKE ? OR category LIKE ? OR supplier LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like, $like); }
if ($stockFilter === 'low') $where[] = 'stock <= min_stock AND stock > 0';
if ($stockFilter === 'zero') $where[] = 'stock <= 0';
$sql = 'SELECT * FROM parts' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY active DESC, name';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $parts = $stmt->fetchAll();

$movements = [];
if ($selected) {
    $stmt = $pdo->prepare('SELECT sm.*,wo.code,u.name AS user_name FROM stock_movements sm LEFT JOIN work_orders wo ON wo.id=sm.order_id LEFT JOIN users u ON u.id=sm.user_id WHERE sm.part_id=? ORDER BY sm.created_at DESC LIMIT 40');
    $stmt->execute([(int)$selected['id']]); $movements = $stmt->fetchAll();
}
$stats = $pdo->query('SELECT COUNT(*) total,COALESCE(SUM(stock),0) units,SUM(stock<=min_stock AND active=1) low_count,COALESCE(SUM(stock*buy_price),0) inventory_cost FROM parts WHERE active=1')->fetch();

include 'partials/header.php';
?>
<div class="page-head"><div class="page-head-copy"><h1>Stock y repuestos</h1><p>Inventario completo con alta, edición, archivado, stock mínimo y movimientos auditables.</p></div><div class="actions"><a class="btn btn-primary" href="parts.php#part-form">Agregar repuesto</a></div></div>
<?php if ($error): ?><div class="alert alert-error"><span><?=h($error)?></span></div><?php endif; ?>
<div class="grid">
<section class="card span3 kpi-card"><span class="kpi-label">Productos activos</span><div class="kpi"><?=(int)$stats['total']?></div><div class="kpi-foot">Referencias disponibles</div></section>
<section class="card span3 kpi-card"><span class="kpi-label">Unidades en stock</span><div class="kpi"><?=h(number_format((float)$stats['units'],2,',','.'))?></div><div class="kpi-foot">Suma de existencias</div></section>
<section class="card span3 kpi-card"><span class="kpi-label">Stock bajo</span><div class="kpi"><?=(int)$stats['low_count']?></div><div class="kpi-foot">Requieren reposición</div></section>
<section class="card span3 kpi-card"><span class="kpi-label">Costo inventario</span><div class="kpi"><?=h(money($stats['inventory_cost']))?></div><div class="kpi-foot">Según precio de compra</div></section>

<section class="card span5" id="part-form"><div class="card-header"><div><h2><?=$edit?'Modificar producto':'Añadir producto al stock'?></h2><p><?=$edit?'Editá los datos cargados y guardá los cambios.':'Podés completar los datos manualmente o usar OCR sobre una foto.'?></p></div><?php if($edit):?><a class="btn btn-sm" href="parts.php#part-form">Nuevo producto</a><?php endif;?></div>
<div class="notice"><span>La foto puede completar nombre, categoría y SKU como sugerencia. Revisá los datos antes de guardar.</span></div>
<form method="post" enctype="multipart/form-data" id="partForm" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="<?=$edit?'update':'create'?>"><?php if($edit):?><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><?php endif;?>
<div class="field"><label>Foto</label><input type="file" name="photo" id="photoInput" accept="image/jpeg,image/png,image/webp" capture="environment"><?php if(!empty($edit['photo_path'])):?><img id="photoPreview" class="part-preview" src="<?=h($edit['photo_path'])?>" alt="Foto del repuesto"><?php else:?><img id="photoPreview" class="part-preview" alt="Vista previa" hidden><?php endif;?><button type="button" class="btn btn-sm" id="aiFillBtn">Completar con IA/OCR</button><span class="help" id="aiMsg"></span></div>
<div class="field span8"><label class="required">Nombre</label><input name="name" id="name" required value="<?=h($edit['name']??post('name'))?>"></div><div class="field span4"><label>SKU</label><input name="sku" id="sku" value="<?=h($edit['sku']??post('sku'))?>"></div>
<div class="field span6"><label>Categoría</label><input name="category" id="category" value="<?=h($edit['category']??post('category'))?>"></div><div class="field span6"><label>Proveedor</label><input name="supplier" value="<?=h($edit['supplier']??post('supplier'))?>"></div>
<div class="field span4"><label>Stock actual</label><input type="number" min="0" step="0.01" name="stock" value="<?=h($edit['stock']??post('stock','0'))?>"></div><div class="field span4"><label>Stock mínimo</label><input type="number" min="0" step="0.01" name="min_stock" value="<?=h($edit['min_stock']??post('min_stock','0'))?>"></div><div class="field span4"><label>Precio compra</label><input type="number" min="0" step="0.01" name="buy_price" value="<?=h($edit['buy_price']??post('buy_price','0'))?>"></div>
<div class="field span6"><label>Precio venta</label><input type="number" min="0" step="0.01" name="sell_price" value="<?=h($edit['sell_price']??post('sell_price','0'))?>"></div><div class="field span6"><label>Notas</label><textarea name="notes" id="notes"><?=h($edit['notes']??post('notes'))?></textarea></div>
<div class="form-actions"><button class="btn btn-primary"><?=$edit?'Guardar cambios':'Agregar al stock'?></button></div></form></section>

<section class="card span7"><div class="card-header"><div><h2>Inventario</h2><p><?=count($parts)?> resultado<?=count($parts)===1?'':'s'?> · Tocá un producto para modificarlo</p></div></div>
<form method="get" class="toolbar"><input class="search" name="q" placeholder="Buscar por nombre, SKU, categoría o proveedor" value="<?=h($q)?>"><select name="status"><option value="active" <?=$status==='active'?'selected':''?>>Activos</option><option value="archived" <?=$status==='archived'?'selected':''?>>Archivados</option><option value="all" <?=$status==='all'?'selected':''?>>Todos</option></select><select name="stock"><option value="">Todo el stock</option><option value="low" <?=$stockFilter==='low'?'selected':''?>>Stock bajo</option><option value="zero" <?=$stockFilter==='zero'?'selected':''?>>Sin stock</option></select><button class="btn">Filtrar</button></form>
<?php if($parts):?><div class="table-wrap responsive"><table><thead><tr><th>Repuesto</th><th>Stock</th><th>Precios</th><th>Proveedor</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($parts as $part):$stock=(float)$part['stock'];$min=(float)$part['min_stock'];$editUrl='parts.php?'.http_build_query(array_filter(['edit'=>(int)$part['id'],'q'=>$q,'status'=>$status,'stock'=>$stockFilter],static fn($value)=>$value!==''&&$value!==null)).'#part-form';?><tr class="stock-product-row <?=$editId===(int)$part['id']?'is-selected':''?>" tabindex="0" role="link" aria-label="Modificar <?=h($part['name'])?>" data-row-edit-url="<?=h($editUrl)?>"><td class="primary-cell"><div class="table-title"><?php if($part['photo_path']):?><img class="thumb" src="<?=h($part['photo_path'])?>" alt=""><?php else:?><span class="thumb-placeholder">LM</span><?php endif;?><span><strong><?=h($part['name'])?></strong><small><?=h(($part['sku']?:'Sin SKU').($part['category']?' · '.$part['category']:''))?></small></span></div></td><td data-label="Stock"><strong class="<?=$stock<=0?'stock-zero':($stock<=$min?'stock-low':'')?>"><?=h(number_format($stock,2,',','.'))?></strong><br><small class="muted">Mín. <?=h(number_format($min,2,',','.'))?></small></td><td data-label="Precios"><strong><?=h(money($part['sell_price']))?></strong><br><small class="muted">Compra <?=h(money($part['buy_price']))?></small></td><td data-label="Proveedor"><?=h($part['supplier']?:'—')?></td><td data-label="Estado"><span class="badge <?=$part['active']?'success':'danger'?>"><?=$part['active']?'Activo':'Archivado'?></span></td><td data-label="Acciones"><div class="table-actions"><a class="btn btn-sm" href="parts.php?view=<?=(int)$part['id']?>">Movimientos</a><a class="btn btn-sm" href="<?=h($editUrl)?>">Modificar</a><form method="post" data-confirm="<?=$part['active']?'¿Archivar este repuesto?':'¿Restaurar este repuesto?'?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$part['id']?>"><input type="hidden" name="action" value="<?=$part['active']?'archive':'restore'?>"><button class="btn btn-sm <?=$part['active']?'btn-danger':'btn-success'?>"><?=$part['active']?'Archivar':'Restaurar'?></button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="empty-state"><div><div class="empty-icon">0</div><h2>No hay repuestos para mostrar</h2><p class="muted">Modificá los filtros o agregá el primer producto.</p></div></div><?php endif;?></section>

<?php if($selected):?><section class="card span12"><div class="card-header"><div><h2>Movimientos · <?=h($selected['name'])?></h2><p>Stock actual: <strong><?=h(number_format((float)$selected['stock'],2,',','.'))?></strong></p></div><a class="btn btn-sm" href="parts.php">Cerrar detalle</a></div>
<form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="movement"><input type="hidden" name="id" value="<?=(int)$selected['id']?>"><div class="field"><label>Detalle del movimiento</label><input name="movement_notes" placeholder="Ej: compra a proveedor, ajuste de conteo"></div><div class="field"><label>Tipo</label><select name="movement_type"><option value="entrada">Entrada</option><option value="salida">Salida</option></select></div><div class="field"><label>Cantidad</label><input type="number" min="0.01" step="0.01" name="quantity" value="1" required></div><button class="btn btn-primary">Registrar</button></form>
<div style="height:16px"></div><?php if($movements):?><div class="table-wrap responsive"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Stock</th><th>Referencia</th><th>Usuario</th></tr></thead><tbody><?php foreach($movements as $m):?><tr><td class="primary-cell"><strong><?=h(date_ar($m['created_at'],true))?></strong></td><td data-label="Tipo"><span class="badge <?=in_array($m['movement_type'],['entrada','devolucion'],true)?'success':($m['movement_type']==='salida'?'warning':'info')?>"><?=h(ucfirst($m['movement_type']))?></span></td><td data-label="Cantidad"><?=h(number_format((float)$m['quantity'],2,',','.'))?></td><td data-label="Stock"><?=h(number_format((float)$m['stock_before'],2,',','.').' → '.number_format((float)$m['stock_after'],2,',','.'))?></td><td data-label="Referencia"><?=h(($m['code']?'Orden '.$m['code'].' · ':'').($m['notes']?:'Sin detalle'))?></td><td data-label="Usuario"><?=h($m['user_name']?:'Sistema')?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="empty-state"><div><h2>Sin movimientos registrados</h2></div></div><?php endif;?></section><?php endif;?>
</div>
<script src="assets/parts_ai.js"></script>
<?php include 'partials/footer.php'; ?>
