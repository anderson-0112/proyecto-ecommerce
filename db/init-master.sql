-- =============================================================
-- init-master.sql — Esquema y datos semilla de la plataforma.
-- Lo ejecuta MariaDB Master la primera vez que arranca (volumen vacío).
-- Una base de datos por microservicio (aisladas); los usuarios de BD
-- de cada servicio se crean en db/crear-usuarios.sh.
-- Referencias entre servicios (p. ej. pagos.id_pedido -> pedidos.id)
-- son LÓGICAS: no hay FOREIGN KEY entre bases de datos distintas.
-- =============================================================
SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- auth_db (auth-service)
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS auth_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE auth_db;

CREATE TABLE usuarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,          -- bcrypt
    rol           ENUM('cliente','admin') NOT NULL DEFAULT 'cliente',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Usuarios de prueba exigidos por el enunciado (contraseñas cifradas con bcrypt):
--   admin@mitienda.com / Admin2026!     cliente@test.com / Test2026!
INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES
('Administrador',   'admin@mitienda.com', '$2y$10$7uWbUAo8.O8Hj.GVlEYDm.RUBDXba/qOqyhhuI4fltmYbna1JD4q.', 'admin'),
('Cliente de Prueba','cliente@test.com',  '$2y$10$rRObWpcDF7IRDdx3RJl00.tV6QfOgJ6jNIAJMuRn1.itpofQ6IIOq', 'cliente');

-- -------------------------------------------------------------
-- catalogo_db (catalogo-service)
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS catalogo_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE catalogo_db;

CREATE TABLE categorias (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(80) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE productos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(150) NOT NULL,
    descripcion  TEXT NULL,
    precio       DECIMAL(10,2) NOT NULL,           -- en USD (D21)
    stock        INT UNSIGNED NOT NULL DEFAULT 0,
    imagen_url   VARCHAR(500) NULL,
    destacado    TINYINT(1) NOT NULL DEFAULT 0,
    activo       TINYINT(1) NOT NULL DEFAULT 1,    -- borrado lógico
    categoria_id INT UNSIGNED NULL,
    CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    INDEX idx_productos_filtros (activo, categoria_id, precio)
) ENGINE=InnoDB;

INSERT INTO categorias (id, nombre, descripcion) VALUES
(1, 'Smartphones',          'Teléfonos inteligentes'),
(2, 'Laptops',              'Computadoras portátiles'),
(3, 'Tablets y lectores',   'Tabletas y lectores de libros electrónicos'),
(4, 'Audio',                'Audífonos y bocinas'),
(5, 'Wearables',            'Relojes inteligentes y deportivos'),
(6, 'Consolas',             'Consolas de videojuegos'),
(7, 'Accesorios y cámaras', 'Periféricos, cámaras de acción y placas de desarrollo');

-- 20 productos de marcas reales; fotos con licencia libre de Wikimedia Commons
-- (autores y licencias en docs/creditos-imagenes.md). Se migran a Cloudinary en el Bloque 4.
INSERT INTO productos (nombre, descripcion, precio, stock, imagen_url, destacado, categoria_id) VALUES
('Apple iPhone 15 (128 GB)', 'Pantalla Super Retina XDR de 6.1", chip A16 Bionic, cámara principal de 48 MP y puerto USB-C.', 799.00, 15, 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/48/Apple_iPhone_15.jpg/500px-Apple_iPhone_15.jpg', 1, 1),
('Samsung Galaxy S24 (256 GB)', 'Pantalla Dynamic AMOLED 2X de 6.2", 8 GB de RAM y funciones Galaxy AI.', 859.00, 12, 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/46/Samsung_Galaxy_S24_%28webtekno%29_008.png/500px-Samsung_Galaxy_S24_%28webtekno%29_008.png', 0, 1),
('Google Pixel 8 (128 GB)', 'Chip Google Tensor G3, pantalla Actua de 6.2" y cámara de 50 MP con edición por IA.', 699.00, 10, 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/4b/Google_Pixel_8_Rose_rear.jpg/500px-Google_Pixel_8_Rose_rear.jpg', 0, 1),
('Apple MacBook Air 13" M2', 'Chip Apple M2, 8 GB de memoria unificada, SSD de 256 GB y pantalla Liquid Retina.', 999.00, 8, 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/25/M2_Macbook_Air_Midnight_model_-_1.jpg/500px-M2_Macbook_Air_Midnight_model_-_1.jpg', 1, 2),
('Dell XPS 13', 'Ultraportátil con pantalla InfinityEdge de 13.4", Intel Core i7, 16 GB de RAM y SSD de 512 GB.', 1099.00, 6, 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/8f/Dell_XPS_13_%282018%29.png/500px-Dell_XPS_13_%282018%29.png', 0, 2),
('Lenovo ThinkPad X1 Carbon', 'Portátil empresarial de 14", Intel Core i7, 16 GB de RAM, chasis de fibra de carbono.', 1429.00, 5, 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e9/ThinkPad_X1_Carbon_gen7_%280%29.jpg/500px-ThinkPad_X1_Carbon_gen7_%280%29.jpg', 0, 2),
('Apple iPad (10.ª generación, 64 GB)', 'Pantalla Liquid Retina de 10.9", chip A14 Bionic y USB-C.', 349.00, 14, 'https://upload.wikimedia.org/wikipedia/commons/thumb/5/53/IPad_10th_Generation_2024.jpg/500px-IPad_10th_Generation_2024.jpg', 0, 3),
('Samsung Galaxy Tab S9 (128 GB)', 'Pantalla Dynamic AMOLED 2X de 11", S Pen incluido y resistencia IP68.', 799.00, 7, 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/05/Samsung_Galaxy_Tab_S9.png/500px-Samsung_Galaxy_Tab_S9.png', 0, 3),
('Amazon Kindle Paperwhite (16 GB)', 'Lector de libros electrónicos con pantalla de 6.8" sin reflejos y luz cálida ajustable.', 149.00, 20, 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/37/2023_Amazon_Kindle_Paperwhite_%281%29.jpg/500px-2023_Amazon_Kindle_Paperwhite_%281%29.jpg', 0, 3),
('Sony WH-1000XM3', 'Audífonos inalámbricos over-ear con cancelación de ruido y hasta 30 h de batería.', 279.00, 10, 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/21/Sony-WH-1000XM3-kabellose-Bluetooth-Noise-Cancelling-Kopfhoerer.5.jpg/500px-Sony-WH-1000XM3-kabellose-Bluetooth-Noise-Cancelling-Kopfhoerer.5.jpg', 1, 4),
('Apple AirPods Pro (2.ª generación)', 'Audífonos in-ear con cancelación activa de ruido, audio espacial y estuche MagSafe.', 249.00, 18, 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/2f/AirPods_Pro_%282nd_generation%29.jpg/500px-AirPods_Pro_%282nd_generation%29.jpg', 1, 4),
('JBL Flip 4', 'Bocina Bluetooth portátil resistente al agua (IPX7) con 12 h de reproducción.', 99.00, 25, 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a2/JBL_Flip_4.jpg/500px-JBL_Flip_4.jpg', 0, 4),
('Apple Watch Series 9 (GPS, 41 mm)', 'Reloj inteligente con chip S9, pantalla siempre activa y sensores de salud.', 399.00, 9, 'https://upload.wikimedia.org/wikipedia/commons/thumb/5/54/Apple_Watch_Series_9_%28cropped%29.jpg/500px-Apple_Watch_Series_9_%28cropped%29.jpg', 0, 5),
('Garmin Forerunner 265', 'Reloj GPS para correr con pantalla AMOLED y métricas de entrenamiento.', 449.00, 6, 'https://upload.wikimedia.org/wikipedia/commons/thumb/f/f4/Forerunner_265.jpg/500px-Forerunner_265.jpg', 0, 5),
('Nintendo Switch', 'Consola híbrida: juega en el televisor o en modo portátil con Joy-Con.', 299.00, 12, 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/76/Nintendo-Switch-Console-Docked-wJoyConRB.jpg/500px-Nintendo-Switch-Console-Docked-wJoyConRB.jpg', 1, 6),
('Sony PlayStation 5', 'Consola con SSD ultrarrápido, control DualSense y juegos en 4K.', 499.00, 6, 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1b/PlayStation_5_and_DualSense_with_transparent_background.png/500px-PlayStation_5_and_DualSense_with_transparent_background.png', 1, 6),
('Microsoft Xbox Series X', 'Consola 4K de 12 teraflops con SSD de 1 TB y compatibilidad con Game Pass.', 499.00, 5, 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7d/Xbox_series_X_%2850648118708%29.jpg/500px-Xbox_series_X_%2850648118708%29.jpg', 0, 6),
('Logitech MX Master 3S', 'Mouse inalámbrico ergonómico de 8000 DPI con clics silenciosos y USB-C.', 99.00, 30, 'https://upload.wikimedia.org/wikipedia/commons/thumb/b/bc/Logitech_MX_Master_3S_HS11.jpg/500px-Logitech_MX_Master_3S_HS11.jpg', 0, 7),
('GoPro HERO9 Black', 'Cámara de acción 5K resistente al agua con estabilización HyperSmooth 3.0.', 349.00, 8, 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7e/GoPro_Hero_9_Black_-_Front.jpg/500px-GoPro_Hero_9_Black_-_Front.jpg', 0, 7),
('Raspberry Pi 5 (8 GB)', 'Computadora de placa única con procesador de 4 núcleos a 2.4 GHz, ideal para proyectos.', 80.00, 40, 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e7/Raspberry_Pi_5.jpg/500px-Raspberry_Pi_5.jpg', 0, 7);

-- -------------------------------------------------------------
-- pedidos_db (pedidos-service)
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS pedidos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pedidos_db;

CREATE TABLE clientes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT UNSIGNED NOT NULL UNIQUE,       -- = auth_db.usuarios.id (referencia lógica, D6)
    nombre     VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL,
    telefono   VARCHAR(30) NULL
) ENGINE=InnoDB;

CREATE TABLE direcciones (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT UNSIGNED NOT NULL,
    direccion  VARCHAR(255) NOT NULL,
    ciudad     VARCHAR(100) NOT NULL,
    telefono   VARCHAR(30) NULL,
    CONSTRAINT fk_direcciones_cliente FOREIGN KEY (id_cliente) REFERENCES clientes(id)
) ENGINE=InnoDB;

CREATE TABLE pedidos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT UNSIGNED NOT NULL,
    total      DECIMAL(10,2) NOT NULL,
    estado     ENUM('pendiente','pagado','preparando','enviado','entregado') NOT NULL DEFAULT 'pendiente',
    fecha      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pedidos_cliente FOREIGN KEY (id_cliente) REFERENCES clientes(id),
    INDEX idx_pedidos_estado_fecha (estado, fecha)
) ENGINE=InnoDB;

CREATE TABLE detalle_pedidos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_pedido       INT UNSIGNED NOT NULL,
    id_producto     INT UNSIGNED NOT NULL,         -- = catalogo_db.productos.id (referencia lógica)
    nombre_producto VARCHAR(150) NOT NULL,         -- copia al momento de la compra
    cantidad        INT UNSIGNED NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,        -- precio al momento de la compra
    subtotal        DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_detalle_pedido FOREIGN KEY (id_pedido) REFERENCES pedidos(id)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- pagos_db (pagos-service)
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS pagos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pagos_db;

CREATE TABLE pagos (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_pedido         INT UNSIGNED NOT NULL,        -- = pedidos_db.pedidos.id (referencia lógica)
    monto             DECIMAL(10,2) NOT NULL,
    metodo            ENUM('stripe','paypal') NOT NULL,
    referencia_stripe VARCHAR(100) NULL UNIQUE,     -- id del PaymentIntent (pi_...)
    referencia_paypal VARCHAR(100) NULL UNIQUE,     -- id de la orden PayPal (D4, Bloque 4)
    estado            ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    fecha_pago        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pagos_pedido (id_pedido)
) ENGINE=InnoDB;
