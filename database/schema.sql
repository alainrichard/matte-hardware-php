CREATE TABLE IF NOT EXISTS categories (
 id INT PRIMARY KEY AUTO_INCREMENT, slug VARCHAR(160) NOT NULL UNIQUE, name VARCHAR(200) NOT NULL,
 blurb TEXT NOT NULL DEFAULT '', sort INT NOT NULL DEFAULT 0, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 parent_id INT NULL, image_url TEXT NULL, FOREIGN KEY(parent_id) REFERENCES categories(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS products (
 id INT PRIMARY KEY AUTO_INCREMENT, slug VARCHAR(160) NOT NULL UNIQUE, category_id INT NOT NULL,
 name VARCHAR(200) NOT NULL, summary TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
 specs JSON NOT NULL DEFAULT '{}', price_rwf INT NOT NULL DEFAULT 0 CHECK(price_rwf>=0),
 price_confirmed BOOLEAN NOT NULL DEFAULT FALSE, unit VARCHAR(40) NOT NULL DEFAULT 'piece',
 in_stock BOOLEAN NOT NULL DEFAULT FALSE, stock_quantity INT NOT NULL DEFAULT 0 CHECK(stock_quantity>=0),
 is_featured BOOLEAN NOT NULL DEFAULT FALSE, is_active BOOLEAN NOT NULL DEFAULT TRUE,
 color_group VARCHAR(80), color_name VARCHAR(60) NOT NULL DEFAULT '',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(category_id) REFERENCES categories(id), INDEX(category_id), INDEX(color_group)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS product_images (
 id INT PRIMARY KEY AUTO_INCREMENT, product_id INT NOT NULL, base_name VARCHAR(200) NOT NULL, sort INT NOT NULL DEFAULT 0,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE, INDEX(product_id,sort)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_users (
 id INT PRIMARY KEY AUTO_INCREMENT,email VARCHAR(160) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL DEFAULT 'Admin',password_hash VARCHAR(255) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS customer_users (
 id INT PRIMARY KEY AUTO_INCREMENT,email VARCHAR(160) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,password_hash VARCHAR(255) NOT NULL,
 phone VARCHAR(40) NOT NULL DEFAULT '',address TEXT NOT NULL DEFAULT '',latitude DOUBLE NULL CHECK(latitude BETWEEN -90 AND 90),longitude DOUBLE NULL CHECK(longitude BETWEEN -180 AND 180),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS invoice_counter (id INT PRIMARY KEY, next_value BIGINT NOT NULL) ENGINE=InnoDB;
INSERT IGNORE INTO invoice_counter VALUES(1,13);
CREATE TABLE IF NOT EXISTS orders (
 id INT PRIMARY KEY AUTO_INCREMENT,public_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,proforma_no INT NULL UNIQUE,
 status VARCHAR(30) NOT NULL DEFAULT 'proforma_issued' CHECK(status IN ('submitted','proforma_issued','paid','preparing','delivered','cancelled')),
 customer_name VARCHAR(120) NOT NULL,customer_phone VARCHAR(40) NOT NULL,customer_email VARCHAR(160),customer_company VARCHAR(120),customer_tin VARCHAR(40),purchase_code VARCHAR(80),
 delivery_method VARCHAR(20) NOT NULL DEFAULT 'pickup' CHECK(delivery_method IN ('pickup','delivery')),delivery_address TEXT,note TEXT,
 total_rwf INT NOT NULL CHECK(total_rwf>=0),currency VARCHAR(3) NOT NULL DEFAULT 'RWF' CHECK(currency IN ('RWF','USD')),usd_rwf_rate DECIMAL(14,4) NOT NULL DEFAULT 1 CHECK(usd_rwf_rate>0),
 invoice_requested BOOLEAN NOT NULL DEFAULT TRUE,notify_on_delivery BOOLEAN NOT NULL DEFAULT FALSE,delivery_notified_at DATETIME(6),
 service_request VARCHAR(20) NOT NULL DEFAULT 'none' CHECK(service_request IN ('none','technician','plumber')),service_note TEXT,payment_method VARCHAR(60),payment_ref VARCHAR(100),
 customer_id INT NULL,latitude DOUBLE CHECK(latitude BETWEEN -90 AND 90),longitude DOUBLE CHECK(longitude BETWEEN -180 AND 180),momo_reference_id CHAR(36),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(customer_id) REFERENCES customer_users(id),INDEX(status,created_at),INDEX(customer_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS order_items (
 id INT PRIMARY KEY AUTO_INCREMENT,order_id INT NOT NULL,product_id INT,description TEXT NOT NULL,unit_price_rwf INT NOT NULL CHECK(unit_price_rwf>=0),qty INT NOT NULL CHECK(qty>0),line_total_rwf INT NOT NULL CHECK(line_total_rwf>=0),
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS customer_carts (customer_id INT PRIMARY KEY,items JSON NOT NULL DEFAULT '[]',FOREIGN KEY(customer_id) REFERENCES customer_users(id) ON DELETE CASCADE) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS site_content (id INT PRIMARY KEY CHECK(id=1),content JSON NOT NULL DEFAULT '{}',updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS conversations (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,customer_name VARCHAR(120),customer_phone VARCHAR(40),unread_staff INT NOT NULL DEFAULT 0,
 last_message_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),INDEX(last_message_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS messages (
 id BIGINT PRIMARY KEY AUTO_INCREMENT,conversation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,sender VARCHAR(20) NOT NULL CHECK(sender IN ('customer','staff','system')),body TEXT NOT NULL,product_id INT,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL,INDEX(conversation_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS chat_presence (conversation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,role VARCHAR(20) NOT NULL,typing_until DATETIME(6) NOT NULL,PRIMARY KEY(conversation_id,role),FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS quote_requests (
 id BIGINT PRIMARY KEY AUTO_INCREMENT,customer_name VARCHAR(120) NOT NULL,customer_phone VARCHAR(40) NOT NULL,customer_email VARCHAR(160),product_id INT,quantity INT NOT NULL DEFAULT 1 CHECK(quantity>0),requirement TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'new' CHECK(status IN ('new','quoted','closed')),created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS momo_payments (
 reference CHAR(36) PRIMARY KEY,order_id INT NOT NULL,external_id VARCHAR(200) NOT NULL UNIQUE,amount DECIMAL(14,2) NOT NULL CHECK(amount>0),currency VARCHAR(3) NOT NULL CHECK(currency IN ('EUR','RWF')),
 phone VARCHAR(30) NOT NULL,target VARCHAR(50) NOT NULL,base_url VARCHAR(200) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'CREATED' CHECK(status IN ('CREATED','PENDING','SUCCESSFUL','FAILED','REVIEW')),
 provider_reference VARCHAR(100),failure_code VARCHAR(80),next_check_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 active_order INT GENERATED ALWAYS AS (CASE WHEN status IN ('CREATED','PENDING','REVIEW') THEN order_id ELSE NULL END) STORED UNIQUE,
 FOREIGN KEY(order_id) REFERENCES orders(id),INDEX(status,next_check_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS whatsapp_conversations (
 id BIGINT PRIMARY KEY AUTO_INCREMENT,phone_number_id VARCHAR(100) NOT NULL,customer_phone VARCHAR(30) NOT NULL,customer_name VARCHAR(120) NOT NULL DEFAULT '',last_customer_at DATETIME(6) NOT NULL,last_message_at DATETIME(6) NOT NULL,unread INT NOT NULL DEFAULT 0,UNIQUE(phone_number_id,customer_phone),INDEX(last_message_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS whatsapp_messages (
 id BIGINT PRIMARY KEY AUTO_INCREMENT,conversation_id BIGINT NOT NULL,provider_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,request_id CHAR(36) UNIQUE,request_hash VARCHAR(64),direction VARCHAR(20) NOT NULL CHECK(direction IN ('inbound','outbound')),
 body TEXT NOT NULL DEFAULT '',kind VARCHAR(30) NOT NULL DEFAULT 'text',media_id VARCHAR(100),filename VARCHAR(150),mime_type VARCHAR(100),status VARCHAR(30) NOT NULL DEFAULT 'received',error_code VARCHAR(30),created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(conversation_id) REFERENCES whatsapp_conversations(id),INDEX(conversation_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS catalog_migrations (name VARCHAR(160) PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS rate_limits (bucket CHAR(64) PRIMARY KEY,hits INT NOT NULL,expires_at DATETIME NOT NULL,INDEX(expires_at)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mail_jobs (id BIGINT PRIMARY KEY AUTO_INCREMENT,order_id INT NOT NULL,kind VARCHAR(30) NOT NULL,attempts INT NOT NULL DEFAULT 0,available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at DATETIME NULL,UNIQUE(order_id,kind),FOREIGN KEY(order_id) REFERENCES orders(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS checkout_requests (
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 payload_hash CHAR(64) NOT NULL, order_id INT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB;
