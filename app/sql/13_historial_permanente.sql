-- =============================================================
-- Migración 13 — historial permanente
--  · Los movimientos sobreviven al borrado del equipo (FK SET NULL)
--  · Copia del nombre y nº de serie en cada movimiento
--  · Nuevo tipo de movimiento 'eliminacion'
--  · Tabla de ajustes (retención del fichero CSV)
-- Es idempotente: se puede ejecutar varias veces sin problema.
-- =============================================================

-- 1) Columnas de copia (nombre y serie del equipo)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND COLUMN_NAME = 'equipo_nombre');
SET @s := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN equipo_nombre VARCHAR(150) NULL AFTER equipo_id', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND COLUMN_NAME = 'equipo_serie');
SET @s := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN equipo_serie VARCHAR(80) NULL AFTER equipo_nombre', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) Rellenar la copia en los movimientos ya existentes
UPDATE movimientos m
JOIN   equipos e ON e.id = m.equipo_id
SET    m.equipo_nombre = e.nombre,
       m.equipo_serie  = e.numero_serie
WHERE  m.equipo_nombre IS NULL;

-- 3) Quitar la FK actual (ON DELETE CASCADE) de equipo_id
SET @fk := (SELECT rc.CONSTRAINT_NAME
            FROM   information_schema.REFERENTIAL_CONSTRAINTS rc
            JOIN   information_schema.KEY_COLUMN_USAGE k
                   ON  k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                   AND k.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
                   AND k.TABLE_NAME        = rc.TABLE_NAME
            WHERE  rc.CONSTRAINT_SCHEMA = DATABASE()
              AND  rc.TABLE_NAME = 'movimientos'
              AND  rc.REFERENCED_TABLE_NAME = 'equipos'
              AND  k.COLUMN_NAME = 'equipo_id'
              AND  rc.DELETE_RULE <> 'SET NULL'
            LIMIT 1);
SET @s := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE movimientos DROP FOREIGN KEY `', @fk, '`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4) equipo_id admite NULL (movimientos de equipos ya eliminados)
ALTER TABLE movimientos MODIFY COLUMN equipo_id INT NULL;

-- 5) Nueva FK con ON DELETE SET NULL
SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos'
             AND CONSTRAINT_NAME = 'fk_mov_equipo');
SET @s := IF(@c = 0,
             'ALTER TABLE movimientos ADD CONSTRAINT fk_mov_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE SET NULL',
             'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 6) Tipos de movimiento (incluye 'separacion' de la migración 12)
ALTER TABLE movimientos
    MODIFY COLUMN tipo ENUM('suma','resta','ubicacion','separacion','eliminacion') NOT NULL;

-- 7) Ajustes de la aplicación (retención del CSV: 0 = permanente, 1 o 2 meses)
CREATE TABLE IF NOT EXISTS ajustes (
    clave VARCHAR(60)  NOT NULL PRIMARY KEY,
    valor VARCHAR(200) NOT NULL
);
INSERT IGNORE INTO ajustes (clave, valor) VALUES ('historial_csv_meses', '0');
