-- =============================================================
-- Migración 14 — historial: categoría y notas del producto
-- Guarda una copia de la categoría y de las notas/observaciones en cada
-- movimiento, para poder filtrar el Historial general incluso cuando el
-- producto ya se ha eliminado.
-- Es idempotente: se puede ejecutar varias veces sin problema.
-- =============================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION group_concat_max_len = 65535;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND COLUMN_NAME = 'equipo_categoria');
SET @s := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN equipo_categoria VARCHAR(100) NULL AFTER equipo_serie', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimientos' AND COLUMN_NAME = 'equipo_notas');
SET @s := IF(@c = 0, 'ALTER TABLE movimientos ADD COLUMN equipo_notas TEXT NULL AFTER equipo_categoria', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Rellenar los movimientos de equipos que aún existen
UPDATE movimientos m
JOIN   equipos e ON e.id = m.equipo_id
LEFT JOIN categorias cat ON cat.id = e.categoria_id
LEFT JOIN (
    SELECT equipo_id, GROUP_CONCAT(notas SEPARATOR ' | ') AS notas
    FROM   equipo_estado_info
    WHERE  notas IS NOT NULL AND notas <> ''
    GROUP  BY equipo_id
) i ON i.equipo_id = e.id
SET m.equipo_categoria = COALESCE(m.equipo_categoria, cat.nombre),
    m.equipo_notas     = COALESCE(m.equipo_notas, i.notas, e.notas)
WHERE m.equipo_categoria IS NULL OR m.equipo_notas IS NULL;
