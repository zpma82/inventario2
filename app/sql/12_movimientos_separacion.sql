-- =============================================================
-- Migración 12 — nuevo tipo de movimiento 'separacion'
-- El historial distingue "Separar ubicaciones" de "Cambiar ubicación".
-- Es idempotente: se puede ejecutar varias veces sin problema.
-- =============================================================
ALTER TABLE movimientos
    MODIFY COLUMN tipo ENUM('suma','resta','ubicacion','separacion') NOT NULL;
