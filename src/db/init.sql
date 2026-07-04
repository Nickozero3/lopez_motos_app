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
  total_estimated DECIMAL(12,2) DEFAULT 0,
  total_final DECIMAL(12,2) DEFAULT 0,
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
  stock DECIMAL(10,2) NOT NULL DEFAULT 0,
  min_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
  buy_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
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
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_applied DECIMAL(10,2) NOT NULL DEFAULT 0,
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
  quantity DECIMAL(10,2) NOT NULL,
  stock_before DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock_after DECIMAL(10,2) NOT NULL DEFAULT 0,
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
