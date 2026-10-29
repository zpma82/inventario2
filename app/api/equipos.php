<?php
// =============================================================
// INVENTARIO IT v3 -- API de equipos
// Cada equipo puede tener múltiples líneas en equipo_ubicaciones,
// cada una con su propio estado. La lista principal devuelve
// una "fila virtual" por cada combinación (equipo × estado).
// =============================================================
require_once 'config.php';
require_once __DIR__ . '/historial_lib.php';
require_once __DIR__ . '/almacen_lib.php';

$pdo    = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$ses    = almacenSesion($pdo);

// Lectura: almacén consultado. Escritura: siempre el almacén de la sesión.
match ($method) {
    'GET'    => $id ? getUno($pdo, $id, almacenLectura($pdo, $ses)) : getTodos($pdo, almacenLectura($pdo, $ses)),
    'POST'   => crear($pdo, almacenEscritura($ses)),
    'PUT'    => actualizar($pdo, $id, almacenEscritura($ses)),
    'DELETE' => eliminar($pdo, $id, almacenEscritura($ses)),
    default  => responder(['error' => 'Método no permitido'], 405),
};

// -------------------------------------------------------------
// Lista todos los equipos. Por cada equipo genera una fila
// por cada estado distinto que tenga en equipo_ubicaciones.
// Si no tiene líneas, genera una única fila sin ubicación.
// -------------------------------------------------------------
function getTodos(PDO $pdo, int $alm): void {
    // Detectar si columna empleado_id existe (puede faltar en instalaciones antiguas)
    try { $pdo->query("SELECT empleado_id FROM equipos LIMIT 1"); $joinEmp = "LEFT JOIN empleados em ON em.id = e.empleado_id"; $colEmp = ", em.nombre AS empleado_nombre"; }
    catch (Exception $ex) { $joinEmp = ""; $colEmp = ", NULL AS empleado_nombre"; }
    $stEq = $pdo->prepare("
        SELECT e.*, c.nombre AS categoria, sc.nombre AS subcategoria,
               p.nombre AS proveedor_nombre $colEmp
        FROM   equipos e
        LEFT JOIN categorias    c  ON c.id  = e.categoria_id
        LEFT JOIN subcategorias sc ON sc.id = e.subcategoria_id
        LEFT JOIN proveedores   p  ON p.id  = e.proveedor_id
        $joinEmp
        WHERE  e.almacen_id = ?
        ORDER  BY e.id DESC
    ");
    $stEq->execute([$alm]);
    $equipos = $stEq->fetchAll();

    // Líneas de ubicación con estado
    $stUb = $pdo->prepare("
        SELECT eu.equipo_id,
               eu.ubicacion_id,
               eu.cantidad,
               eu.estado,
               CONCAT(u.calle,'-',u.lado,'-',u.hueco,'-',u.altura) AS etiqueta
        FROM   equipo_ubicaciones eu
        JOIN   ubicaciones u ON u.id = eu.ubicacion_id
        JOIN   equipos     q ON q.id = eu.equipo_id
        WHERE  q.almacen_id = ?
        ORDER  BY eu.equipo_id, eu.estado, eu.id
    ");
    $stUb->execute([$alm]);
    $ubics = $stUb->fetchAll();

    // Indexar ubicaciones por equipo_id
    $byEquipo = [];
    foreach ($ubics as $row) {
        $byEquipo[$row['equipo_id']][] = [
            'id'       => (int)$row['ubicacion_id'],
            'etiqueta' => $row['etiqueta'],
            'cantidad' => (int)$row['cantidad'],
            'estado'   => $row['estado'],
        ];
    }

    // Cargar notas/specs por (equipo_id, estado)
    $notasPorEstado = [];
    try {
        $rowsInfo = $pdo->query("SELECT equipo_id, estado, notas, especificaciones FROM equipo_estado_info")->fetchAll();
        foreach ($rowsInfo as $r) {
            $notasPorEstado[$r['equipo_id']][$r['estado']] = [
                'notas'           => $r['notas'],
                'especificaciones'=> $r['especificaciones'],
            ];
        }
    } catch (Exception $ex) { /* tabla aun no existe */ }

    $filas = [];
    foreach ($equipos as $e) {
        $lines = $byEquipo[$e['id']] ?? [];

        if (empty($lines)) {
            // Sin ubicaciones: una sola fila con el estado del equipo
            $infoFila = $notasPorEstado[$e['id']][$e['estado']] ?? [];
            $row = array_merge($e, [
                'ubicaciones'     => [],
                'ubicacion_label' => null,
                'ubicacion_id'    => null,
                'fila_estado'     => $e['estado'],
                'fila_cantidad'   => (int)$e['cantidad'],
                'fila_id'         => $e['id'] . '_' . $e['estado'],
                'notas'           => $infoFila['notas']            ?? $e['notas'],
                'especificaciones'=> $infoFila['especificaciones'] ?? $e['especificaciones'],
            ]);
            $filas[] = $row;
            continue;
        }

        // Agrupar líneas por estado
        $porEstado = [];
        foreach ($lines as $l) {
            $porEstado[$l['estado']][] = $l;
        }

        foreach ($porEstado as $estado => $lineasEstado) {
            $cantEstado = array_sum(array_column($lineasEstado, 'cantidad'));
            $etiquetas  = array_unique(array_column($lineasEstado, 'etiqueta'));
            $label      = count($etiquetas) === 1
                ? $etiquetas[0]
                : implode(' · ', array_map(fn($l) => $l['etiqueta'].' ×'.$l['cantidad'], $lineasEstado));

            $infoFila = $notasPorEstado[$e['id']][$estado] ?? [];
            $row = array_merge($e, [
                'ubicaciones'     => $lineasEstado,
                'ubicacion_label' => $label,
                'ubicacion_id'    => (int)$lineasEstado[0]['id'],
                'estado'          => $estado,
                'fila_estado'     => $estado,
                'fila_cantidad'   => $cantEstado,
                'fila_id'         => $e['id'] . '_' . $estado,
                'cantidad'        => $cantEstado,
                'notas'           => $infoFila['notas']            ?? $e['notas'],
                'especificaciones'=> $infoFila['especificaciones'] ?? $e['especificaciones'],
            ]);
            $filas[] = $row;
        }
    }

    responder($filas);
}

// -------------------------------------------------------------
function getUno(PDO $pdo, int $id, int $alm): void {
    $stmt = $pdo->prepare("
        SELECT e.*,
               c.nombre  AS categoria,
               sc.nombre AS subcategoria,
               p.nombre  AS proveedor_nombre
        FROM   equipos e
        LEFT JOIN categorias    c  ON c.id  = e.categoria_id
        LEFT JOIN subcategorias sc ON sc.id = e.subcategoria_id
        LEFT JOIN proveedores   p  ON p.id  = e.proveedor_id
        WHERE  e.id = ? AND e.almacen_id = ?
    ");
    $stmt->execute([$id, $alm]);
    $row = $stmt->fetch();
    if (!$row) { responder(['error' => 'Equipo no encontrado'], 404); return; }

    $ubics = $pdo->prepare("
        SELECT eu.ubicacion_id AS id, eu.cantidad, eu.estado,
               CONCAT(u.calle,'-',u.lado,'-',u.hueco,'-',u.altura) AS etiqueta
        FROM   equipo_ubicaciones eu
        JOIN   ubicaciones u ON u.id = eu.ubicacion_id
        WHERE  eu.equipo_id = ?
        ORDER  BY eu.estado, eu.id
    ");
    $ubics->execute([$id]);
    $lines = $ubics->fetchAll();

    $row['ubicaciones']     = $lines;
    $row['ubicacion_label'] = count($lines) === 1
        ? $lines[0]['etiqueta']
        : (count($lines) > 1 ? implode(' · ', array_column($lines, 'etiqueta')) : null);
    $row['ubicacion_id']    = count($lines) >= 1 ? (int)$lines[0]['id'] : null;

    // Leer notas/specs del estado solicitado (parámetro GET 'estado')
    $estado = $_GET['estado'] ?? $row['estado'] ?? 'Activo';
    try {
        $si = $pdo->prepare("SELECT notas, especificaciones FROM equipo_estado_info WHERE equipo_id=? AND estado=?");
        $si->execute([$id, $estado]);
        $info = $si->fetch();
        if ($info) {
            $row['notas']            = $info['notas'];
            $row['especificaciones'] = $info['especificaciones'];
        }
    } catch (Exception $ex) { /* tabla aun no existe, devolver datos de equipos */ }

    responder($row);
}

// -------------------------------------------------------------
function crear(PDO $pdo, int $alm): void {
    $d = leerBody();
    if (empty($d['nombre'])) { responder(['error' => 'El nombre es obligatorio'], 400); }
    almacenExigirUbicaciones($pdo, idsUbicacionesPeticion($d), $alm);

    $ubicLines = $d['ubicaciones'] ?? null;
    $ubicIdLeg = null;
    if ($ubicLines && count($ubicLines) > 0) {
        $ubicIdLeg = (int)$ubicLines[0]['id'];
    } elseif (!empty($d['ubicacion_id'])) {
        $ubicIdLeg = (int)$d['ubicacion_id'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO equipos
                (almacen_id,nombre,categoria_id,subcategoria_id,numero_serie,estado,ubicacion_id,cantidad,fecha_alta,notas,proveedor_id,especificaciones,empleado_id)
            VALUES
                (:almacen_id,:nombre,:categoria_id,:subcategoria_id,:numero_serie,:estado,:ubicacion_id,:cantidad,:fecha_alta,:notas,:proveedor_id,:especificaciones,:empleado_id)
        ")->execute([
            ':almacen_id'       => $alm,
            ':nombre'           => $d['nombre'],
            ':categoria_id'     => $d['categoria_id']     ?? null,
            ':subcategoria_id'  => $d['subcategoria_id']  ?? null,
            ':numero_serie'     => $d['numero_serie']     ?? null,
            ':estado'           => $d['estado']           ?? 'Activo',
            ':ubicacion_id'     => $ubicIdLeg,
            ':cantidad'         => $d['cantidad']         ?? 1,
            ':fecha_alta'       => $d['fecha_alta']       ?? date('Y-m-d'),
            ':notas'            => $d['notas']            ?? null,
            ':proveedor_id'     => $d['proveedor_id']     ?? null,
            ':especificaciones' => $d['especificaciones'] ?? null,
            ':empleado_id'      => $d['empleado_id']      ?? null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        guardarUbicaciones($pdo, $newId, $ubicLines, $d);
        // Guardar notas/specs por estado
        guardarNotasEstado($pdo, $newId, $d['estado'] ?? 'Activo', $d['notas'] ?? null, $d['especificaciones'] ?? null);
        $pdo->commit();
        responder(['id' => $newId, 'mensaje' => 'Equipo creado'], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        responder(['error' => 'Error al crear: ' . $e->getMessage()], 500);
    }
}

// -------------------------------------------------------------
function actualizar(PDO $pdo, ?int $id, int $alm): void {
    if (!$id) responder(['error' => 'ID requerido'], 400);
    $d = leerBody();
    almacenExigirEquipo($pdo, $id, $alm);
    almacenExigirUbicaciones($pdo, idsUbicacionesPeticion($d), $alm);

    $pdo->beginTransaction();
    try {
        if (!empty($d['_solo_ubicacion'])) {
            $uid  = $d['ubicacion_id'] ?? null;
            $q    = $pdo->prepare("SELECT cantidad, estado FROM equipos WHERE id = ?");
            $q->execute([$id]);
            $eq   = $q->fetch();
            $cant = (int)$eq['cantidad'];
            $est  = $eq['estado'];

            $pdo->prepare("UPDATE equipos SET ubicacion_id = ? WHERE id = ?")
                ->execute([$uid, $id]);
            $pdo->prepare("DELETE FROM equipo_ubicaciones WHERE equipo_id = ?")
                ->execute([$id]);
            if ($uid) {
                $pdo->prepare("INSERT INTO equipo_ubicaciones (equipo_id,ubicacion_id,cantidad,estado) VALUES (?,?,?,?)")
                    ->execute([$id, (int)$uid, $cant, $est]);
            }
            $pdo->commit();
            responder(['mensaje' => 'Ubicación actualizada']);
            return;
        }

        $ubicLines = $d['ubicaciones'] ?? null;
        $ubicIdLeg = null;
        if ($ubicLines && count($ubicLines) > 0) {
            $ubicIdLeg = (int)$ubicLines[0]['id'];
        } elseif (!empty($d['ubicacion_id'])) {
            $ubicIdLeg = (int)$d['ubicacion_id'];
        }

        $pdo->prepare("
            UPDATE equipos SET
                nombre=:nombre, categoria_id=:categoria_id, subcategoria_id=:subcategoria_id,
                numero_serie=:numero_serie, estado=:estado, ubicacion_id=:ubicacion_id,
                cantidad=:cantidad, fecha_alta=:fecha_alta, notas=:notas, proveedor_id=:proveedor_id,
                especificaciones=:especificaciones, empleado_id=:empleado_id
            WHERE id=:id
        ")->execute([
            ':nombre'           => $d['nombre']           ?? '',
            ':categoria_id'     => $d['categoria_id']     ?? null,
            ':subcategoria_id'  => $d['subcategoria_id']  ?? null,
            ':numero_serie'     => $d['numero_serie']     ?? null,
            ':estado'           => $d['estado']           ?? 'Activo',
            ':ubicacion_id'     => $ubicIdLeg,
            ':cantidad'         => $d['cantidad']         ?? 1,
            ':fecha_alta'       => $d['fecha_alta']       ?? null,
            ':notas'            => $d['notas']            ?? null,
            ':proveedor_id'     => $d['proveedor_id']     ?? null,
            ':especificaciones' => $d['especificaciones'] ?? null,
            ':empleado_id'      => $d['empleado_id']      ?? null,
            ':id'               => $id,
        ]);

        guardarUbicaciones($pdo, $id, $ubicLines, $d);
        // Guardar notas/specs por estado
        guardarNotasEstado($pdo, $id, $d['estado'] ?? 'Activo', $d['notas'] ?? null, $d['especificaciones'] ?? null);
        $pdo->commit();
        responder(['mensaje' => 'Equipo actualizado']);
    } catch (Exception $e) {
        $pdo->rollBack();
        responder(['error' => 'Error al actualizar: ' . $e->getMessage()], 500);
    }
}

// -------------------------------------------------------------
function eliminar(PDO $pdo, ?int $id, int $alm): void {
    if (!$id) responder(['error' => 'ID requerido'], 400);
    almacenExigirEquipo($pdo, $id, $alm);

    $st = $pdo->prepare("SELECT nombre, numero_serie, cantidad, ubicacion_id FROM equipos WHERE id = ?");
    $st->execute([$id]);
    $eq = $st->fetch();
    [, , $eqCat, $eqNotas] = historialInstantanea($pdo, $id);

    $movId = null;
    $pdo->beginTransaction();
    try {
        if ($eq) {
            // Unidades que había en el almacén (suma de líneas; si no hay, el total del equipo)
            $q = $pdo->prepare("SELECT COALESCE(SUM(cantidad), 0) FROM equipo_ubicaciones WHERE equipo_id = ?");
            $q->execute([$id]);
            $uds = (int)$q->fetchColumn();
            if ($uds === 0) $uds = (int)$eq['cantidad'];

            $u = historialUsuarioActual($pdo);
            $pdo->prepare("
                INSERT INTO movimientos (equipo_id, equipo_nombre, equipo_serie, equipo_categoria, equipo_notas, empleado_id, tipo, cantidad, ubicacion_origen_id, almacen_id)
                VALUES (?, ?, ?, ?, ?, ?, 'eliminacion', ?, ?, ?)
            ")->execute([$id, $eq['nombre'], $eq['numero_serie'], $eqCat, $eqNotas, $u['empleado_id'] ?? null, $uds, $eq['ubicacion_id'], $alm]);
            $movId = (int)$pdo->lastInsertId();
        }
        // Los movimientos se conservan: la FK es ON DELETE SET NULL
        $pdo->prepare("DELETE FROM equipos WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        responder(['error' => 'Error al eliminar: ' . $e->getMessage()], 500);
    }
    if ($movId) historialCsvAnadir($pdo, [$movId]);
    responder(['mensaje' => 'Equipo eliminado']);
}

// -------------------------------------------------------------
// IDs de ubicación que trae una petición de crear/editar equipo
function idsUbicacionesPeticion(array $d): array {
    $ids = [];
    foreach (($d['ubicaciones'] ?? []) as $l) { $ids[] = $l['id'] ?? 0; }
    if (!empty($d['ubicacion_id'])) $ids[] = $d['ubicacion_id'];
    return $ids;
}

// -------------------------------------------------------------
function guardarNotasEstado(PDO $pdo, int $equipoId, string $estado, ?string $notas, ?string $specs): void {
    try {
        $pdo->prepare("
            INSERT INTO equipo_estado_info (equipo_id, estado, notas, especificaciones)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE notas=VALUES(notas), especificaciones=VALUES(especificaciones)
        ")->execute([$equipoId, $estado, $notas, $specs]);
    } catch (Exception $ex) { /* tabla aun no existe — ignorar */ }
}

// -------------------------------------------------------------
function guardarUbicaciones(PDO $pdo, int $equipoId, ?array $lines, array $d): void {
    $estadoDefault = $d['estado'] ?? 'Activo';

    // Borrar SOLO las ubicaciones del estado que se está editando.
    // Los demás estados del mismo equipo no se tocan.
    $pdo->prepare("DELETE FROM equipo_ubicaciones WHERE equipo_id = ? AND estado = ?")
        ->execute([$equipoId, $estadoDefault]);

    if ($lines && count($lines) > 0) {
        $ins = $pdo->prepare(
            "INSERT INTO equipo_ubicaciones (equipo_id,ubicacion_id,cantidad,estado) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad)"
        );
        foreach ($lines as $l) {
            $uid  = (int)($l['id'] ?? 0);
            $cant = (int)($l['cantidad'] ?? 1);
            $est  = in_array($l['estado'] ?? '', ['Activo','En reparación','Baja'])
                    ? $l['estado'] : $estadoDefault;
            if ($uid > 0 && $cant > 0) $ins->execute([$equipoId, $uid, $cant, $est]);
        }
    } elseif (!empty($d['ubicacion_id'])) {
        $pdo->prepare(
            "INSERT INTO equipo_ubicaciones (equipo_id,ubicacion_id,cantidad,estado) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad)"
        )->execute([$equipoId, (int)$d['ubicacion_id'], (int)($d['cantidad'] ?? 1), $estadoDefault]);
    }
}
