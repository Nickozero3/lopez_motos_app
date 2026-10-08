CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','recepcion','mecanico') NOT NULL DEFAULT 'mecanico',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(60) NOT NULL,
  email VARCHAR(160) NULL,
  dni VARCHAR(40) NULL,
  address VARCHAR(200) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_clients_name (name), INDEX idx_clients_phone (phone)
);

CREATE TABLE IF NOT EXISTS vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  type VARCHAR(60) NOT NULL DEFAULT 'Moto',
  brand VARCHAR(100) NOT NULL,
  model VARCHAR(100) NOT NULL,
  plate VARCHAR(40) NOT NULL,
  year VARCHAR(10) NULL,
  cc VARCHAR(30) NULL,
  color VARCHAR(60) NULL,
  engine_number VARCHAR(100) NOT NULL,
  chassis_number VARCHAR(100) NOT NULL,
  km INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_vehicles_plate (plate), INDEX idx_vehicles_engine (engine_number), INDEX idx_vehicles_chassis (chassis_number)
);

CREATE TABLE IF NOT EXISTS work_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  mechanic_id INT NULL,
  problem_reported TEXT NOT NULL,
  current_status VARCHAR(80) NOT NULL DEFAULT 'Ingresada',
  priority ENUM('baja','normal','alta','urgente') NOT NULL DEFAULT 'normal',
  estimated_delivery DATE NULL,
  diagnosis TEXT NULL,
  total_estimated BIGINT UNSIGNED DEFAULT 0,
  budget_approved_at DATETIME NULL,
  budget_approved_total BIGINT UNSIGNED NULL,
  budget_approved_ip VARCHAR(45) NULL,
  total_final BIGINT UNSIGNED DEFAULT 0,
  public_token VARCHAR(80) NOT NULL UNIQUE,
  delivered_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (mechanic_id) REFERENCES users(id),
  INDEX idx_orders_status (current_status), INDEX idx_orders_updated (updated_at)
);

CREATE TABLE IF NOT EXISTS order_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NULL,
  status VARCHAR(80) NOT NULL,
  internal_message TEXT NULL,
  client_message TEXT NULL,
  visible_client TINYINT(1) NOT NULL DEFAULT 1,
  notify_client TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS parts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  sku VARCHAR(80) NULL,
  category VARCHAR(120) NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  min_stock INT UNSIGNED NOT NULL DEFAULT 0,
  buy_price BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sell_price BIGINT UNSIGNED NOT NULL DEFAULT 0,
  supplier VARCHAR(160) NULL,
  photo_path VARCHAR(255) NULL,
  notes TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_parts_name (name), INDEX idx_parts_sku (sku), INDEX idx_parts_active (active)
);

CREATE TABLE IF NOT EXISTS budget_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  item_type ENUM('mano_obra','repuesto','otro') NOT NULL DEFAULT 'repuesto',
  description VARCHAR(220) NOT NULL,
  part_id INT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price BIGINT UNSIGNED NOT NULL DEFAULT 0,
  stock_applied INT UNSIGNED NOT NULL DEFAULT 0,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_id INT NOT NULL,
  order_id INT NULL,
  budget_item_id INT NULL,
  user_id INT NULL,
  movement_type ENUM('entrada','salida','ajuste','devolucion') NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  stock_before INT UNSIGNED NOT NULL DEFAULT 0,
  stock_after INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stock_part (part_id), INDEX idx_stock_order (order_id)
);

CREATE TABLE IF NOT EXISTS notification_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  order_update_id INT NULL,
  client_id INT NOT NULL,
  channel VARCHAR(40) NOT NULL DEFAULT 'webhook',
  destination VARCHAR(180) NULL,
  subject VARCHAR(180) NULL,
  message TEXT NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  provider_response TEXT NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_order (order_id), INDEX idx_notifications_status (status)
);

INSERT INTO users (name, username, password_hash, role)
SELECT 'Fabricio', 'fabricio', '$2y$12$4wNphd2iBsQBZ5DLn5nmwO7tgFls5dy/HJ.MtfDiSIgdJbqVwzyR2', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='fabricio');


ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER active;

CREATE TABLE IF NOT EXISTS payments (
 id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, user_id INT NULL, amount BIGINT UNSIGNED NOT NULL,
 method ENUM('efectivo','transferencia','tarjeta','mercadopago','otro') NOT NULL DEFAULT 'efectivo', reference VARCHAR(160) NULL,
 notes VARCHAR(255) NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES work_orders(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id), INDEX(order_id), INDEX(paid_at)
);
CREATE TABLE IF NOT EXISTS cash_sessions (
 id INT AUTO_INCREMENT PRIMARY KEY, opened_by INT NOT NULL, opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, opening_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
 closed_by INT NULL, closed_at DATETIME NULL, closing_amount BIGINT UNSIGNED NULL, notes TEXT NULL, status ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
 FOREIGN KEY(opened_by) REFERENCES users(id), FOREIGN KEY(closed_by) REFERENCES users(id), INDEX(status), INDEX(opened_at)
);
CREATE TABLE IF NOT EXISTS cash_movements (
 id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, user_id INT NULL, order_id INT NULL, payment_id INT NULL, type ENUM('ingreso','egreso') NOT NULL,
 amount BIGINT UNSIGNED NOT NULL, method ENUM('efectivo','transferencia','tarjeta','mercadopago','otro') NOT NULL DEFAULT 'efectivo', description VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(session_id) REFERENCES cash_sessions(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(order_id) REFERENCES work_orders(id), INDEX(session_id), INDEX(created_at)
);
CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, action VARCHAR(80) NOT NULL, entity VARCHAR(80) NOT NULL, entity_id INT NULL,
 before_json LONGTEXT NULL, after_json LONGTEXT NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL, INDEX(entity,entity_id), INDEX(created_at)
);
CREATE TABLE IF NOT EXISTS service_reminders (
 id INT AUTO_INCREMENT PRIMARY KEY, vehicle_id INT NOT NULL, order_id INT NULL, reminder_km INT NULL, reminder_date DATE NULL,
 status ENUM('pendiente','cumplido','cancelado') NOT NULL DEFAULT 'pendiente', notes VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE, FOREIGN KEY(order_id) REFERENCES work_orders(id) ON DELETE SET NULL, INDEX(status), INDEX(reminder_date), INDEX(reminder_km)
);
CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS order_reception (
 order_id INT PRIMARY KEY, fuel_level ENUM('reserva','1/4','1/2','3/4','lleno','no_indicado') NOT NULL DEFAULT 'no_indicado', keys_count INT UNSIGNED NOT NULL DEFAULT 1,
 accessories TEXT NULL, reception_notes TEXT NULL, photo1 VARCHAR(255) NULL, photo2 VARCHAR(255) NULL, photo3 VARCHAR(255) NULL, photo4 VARCHAR(255) NULL,
 updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES work_orders(id) ON DELETE CASCADE, FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
);
