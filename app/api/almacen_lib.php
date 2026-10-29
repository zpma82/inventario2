<?php
// =============================================================
// INVENTARIO TALLER -- Varios almacenes (ZAZ, BCN)
// Requiere que config.php ya esté cargado.
//
// · La sesión guarda el almacén en el que se identificó el usuario
//   (sesiones.almacen_id). En él puede leer y escribir.
// · Cualquier rol salvo admin puede CONSULTAR otro almacén enviando
//   la cabecera X-Almacen (o ?almacen=ID). Es solo lectura: toda
//   escritura con un almacén distinto al de la sesión se rechaza.
// · El admin trabaja siempre en su almacén de sesión.
// =============================================================
require_once __DIR__ . '/historial_lib.php';

// Sesión obligatoria. Devuelve usuario + almacen_id.
function almacenSesion(PDO $pdo): array {
    $u = historialUsuarioActual($pdo);
    if (!$u) responder(['error' => 'No autenticado'], 401);
    $u['almacen_id'] = (int)($u['almacen_id'] ?? 1);
    return $u;
}

// Almacén que el cliente dice estar consultando (null si no lo indica)
function almacenSolicitado(): ?int {
    $v = $_SERVER['HTTP_X_ALMACEN'] ?? ($_GET['almacen'] ?? null);
    if ($v === null || $v === '') return null;
    return (int)$v;
}

// ¿Puede este usuario consultar almacenes distintos al de su sesión?
function almacenPuedeVerOtros(array $ses): bool {
    return ($ses['rol'] ?? '') !== 'admin';
}

function almacenExiste(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM almacenes WHERE id = ?");
    $st->execute([$id]);
    return (int)$st->fetchColumn() > 0;
}

// Almacén sobre el que se LEE
function almacenLectura(PDO $pdo, array $ses): int {
    $req = almacenSolicitado();
    if ($req === null || $req === (int)$ses['almacen_id']) return (int)$ses['almacen_id'];
    if (!almacenPuedeVerOtros($ses)) {
        responder(['error' => 'Tu usuario solo puede consultar el almacén en el que ha iniciado sesión'], 403);
    }
    if (!almacenExiste($pdo, $req)) responder(['error' => 'Almacén no válido'], 400);
    return $req;
}

// Almacén sobre el que se ESCRIBE: siempre el de la sesión
function almacenEscritura(array $ses): int {
    $req = almacenSolicitado();
    if ($req !== null && $req !== (int)$ses['almacen_id']) {
        responder(['error' => 'Estás consultando otro almacén: es de solo lectura'], 403);
    }
    return (int)$ses['almacen_id'];
}

// El equipo debe existir y pertenecer al almacén $alm
function almacenExigirEquipo(PDO $pdo, int $equipoId, int $alm): void {
    $st = $pdo->prepare("SELECT almacen_id FROM equipos WHERE id = ?");
    $st->execute([$equipoId]);
    $a = $st->fetchColumn();
    if ($a === false) responder(['error' => 'Equipo no encontrado'], 404);
    if ((int)$a !== $alm) responder(['error' => 'Ese equipo pertenece a otro almacén'], 403);
}

// Todas las ubicaciones indicadas deben pertenecer al almacén $alm
function almacenExigirUbicaciones(PDO $pdo, array $ids, int $alm): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM ubicaciones WHERE almacen_id = ? AND id IN ($in)");
    $st->execute(array_merge([$alm], $ids));
    if ((int)$st->fetchColumn() !== count($ids)) {
        responder(['error' => 'Alguna ubicación no pertenece a este almacén'], 400);
    }
}
