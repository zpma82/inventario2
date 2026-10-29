-- =============================================================
-- Migración 15 — varios almacenes (ZAZ y BCN)
--  · Tabla almacenes (ZAZ = 1, BCN = 2)
--  · ubicaciones, equipos, movimientos y sesiones pasan a tener almacen_id
--  · Una ubicación es única DENTRO de su almacén (ZAZ y BCN pueden tener
--    cada uno su "1-Dcha-1-A")
--  · Todo lo que ya existe queda asignado a ZAZ (id 1)
-- Es idempotente: se puede ejecutar varias veces sin problema.
-- =============================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1) Tabla de almacenes
CREATE TABLE IF NOT EXISTS almacenes (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(10) NOT NULL UNIQUE,
    nombre VARCHAR(80) NOT NULL
);
INSERT IGNORE INTO almacenes (id, codigo, nombre) VALUES
    (1, 'ZAZ', 'Almacén ZAZ'),
    (2, 'BCN', 'Almacén BCN');

-- 2) Columna almacen_id (por defecto 1 = ZAZ) en las 4 tablas
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ubicaciones' AND COLUMN_NAME = 'almacen_id');
SET @s := IF(@c = 0, 'ALTER TABLE ubicaciones ADD COLUMN almacen_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipos' AND COLUMN_NAME = 'almacen_id');
SET @s := IF(@c = 0, 'ALTER TABLE equipos ADD COLUMN almacen_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND COLUMN_NAME = 'almacen_id');
SET @s := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN almacen_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesiones' AND COLUMN_NAME = 'almacen_id');
SET @s := IF(@c = 0, 'ALTER TABLE sesiones ADD COLUMN almacen_id INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3) La ubicación es única dentro de cada almacén
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ubicaciones' AND INDEX_NAME = 'uk_ubicacion_almacen');
SET @s := IF(@c = 0,
             'ALTER TABLE ubicaciones ADD UNIQUE KEY uk_ubicacion_almacen (almacen_id, calle, lado, hueco, altura)',
             'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ubicaciones' AND INDEX_NAME = 'uk_ubicacion');
SET @s := IF(@c > 0, 'ALTER TABLE ubicaciones DROP INDEX uk_ubicacion', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4) Claves foráneas hacia almacenes
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ubicaciones' AND CONSTRAINT_NAME = 'fk_ubic_almacen');
SET @s := IF(@c = 0, 'ALTER TABLE ubicaciones ADD CONSTRAINT fk_ubic_almacen FOREIGN KEY (almacen_id) REFERENCES almacenes(id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipos' AND CONSTRAINT_NAME = 'fk_equipo_almacen');
SET @s := IF(@c = 0, 'ALTER TABLE equipos ADD CONSTRAINT fk_equipo_almacen FOREIGN KEY (almacen_id) REFERENCES almacenes(id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND CONSTRAINT_NAME = 'fk_mov_almacen');
SET @s := IF(@c = 0, 'ALTER TABLE movimientos ADD CONSTRAINT fk_mov_almacen FOREIGN KEY (almacen_id) REFERENCES almacenes(id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sesiones' AND CONSTRAINT_NAME = 'fk_ses_almacen');
SET @s := IF(@c = 0, 'ALTER TABLE sesiones ADD CONSTRAINT fk_ses_almacen FOREIGN KEY (almacen_id) REFERENCES almacenes(id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'Migración 15 aplicada: almacenes ZAZ y BCN' AS resultado;
