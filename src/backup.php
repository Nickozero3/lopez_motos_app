<?php
require_once 'config.php'; require_role('admin'); ensure_schema(); $pdo=db(); $tables=['users','clients','vehicles','work_orders','order_updates','parts','budget_items','stock_movements','notification_queue','payments','cash_sessions','cash_movements','audit_logs','service_reminders','app_settings'];
$filename='lopez_motos_'.date('Y-m-d_H-i-s').'.sql';$sql="-- Backup Lopez Motos\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach($tables as $table){$sql.="DROP TABLE IF EXISTS `{$table}`;\n";}
foreach($tables as $table){$rows=$pdo->query("SELECT * FROM `{$table}`")->fetchAll();$ddl=$pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();$sql.="\n-- {$table}\n".$ddl['Create Table'].";\n";foreach($rows as $r){$vals=[];foreach($r as $v)$vals[]=$v===null?'NULL':$pdo->quote((string)$v);$sql.="INSERT INTO `{$table}` VALUES (".implode(',',$vals).");\n";}}
$sql.="\nSET FOREIGN_KEY_CHECKS=1;\n";header('Content-Type: application/sql; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');echo $sql;exit;
