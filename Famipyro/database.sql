USE dbs15576434;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    article_number VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    customer_phone VARCHAR(50) DEFAULT NULL,
    customer_notes TEXT DEFAULT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    article_number VARCHAR(50) NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    paid_quantity INT NOT NULL DEFAULT 0,
    free_quantity INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shop_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    article_number VARCHAR(50) NOT NULL UNIQUE,
    buy_quantity INT NOT NULL,
    free_quantity INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO shop_settings (setting_key, setting_value) VALUES
('client_mode', 'card_or_name')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO products (category, article_number, name, description, price, stock, is_active, image_path) VALUES
('fumigenes-bengales', 'FB-1001', 'Pack Couleurs', 'Fumigènes colorés pour animations et fêtes.', 10.00, 25, 1, NULL),
('batteries-feux', 'BF-2001', 'Spectacle Family', 'Batterie complète pour jardin et cour.', 24.90, 18, 1, NULL),
('batteries-silencieuses', 'BS-2101', 'Douce Nuit', 'Effets visuels sans fortes détonations.', 19.50, 12, 1, NULL),
('fontaines', 'FO-3001', 'Cascade Verte', 'Fontaine élégante pour soirée et réception.', 6.50, 34, 1, NULL),
('fusees', 'FU-4001', 'Rocket Star', 'Set de fusées à effets aériens variés.', 14.90, 20, 1, NULL),
('pochettes-multipacks', 'PM-5001', 'Mix Découverte', 'Pochette variée pour toute la famille.', 12.00, 30, 1, NULL),
('petards', 'PE-6001', 'Classique Rouge', 'Pétards traditionnels pour amateurs.', 5.00, 50, 1, NULL),
('promotions', 'PR-7001', 'Dernières Pièces', 'Sélection en promotion de fin de stock.', 8.90, 10, 1, NULL),
('baby-shower', 'BSH-8001', 'Nuage Bleu', 'Effets doux adaptés aux fêtes baby shower.', 9.50, 16, 1, NULL),
('anniversaire-gateau', 'AG-9001', 'Bougie Fontaine', 'Fontaines pour gâteau et anniversaires.', 4.50, 40, 1, NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);
