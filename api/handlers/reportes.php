<?php
/**
 * Handlers para REPORTES con rango de fechas
 */
return [
    'dashboard' => function () {
        $pdo = db();
        $desde = $_GET['desde'] ?? date('Y-m-d');
        $hasta = $_GET['hasta'] ?? date('Y-m-d');

        // Ventas = suma de los totales de las órdenes cobradas (fuente de verdad).
        // NO se suman los pagos, porque el efectivo entregado incluye el cambio.
        $sales = $pdo->prepare("
            SELECT COALESCE(SUM(total),0) AS total
            FROM ordenes
            WHERE estado='cobrada' AND DATE(cerrada_at) BETWEEN ? AND ?
        ");
        $sales->execute([$desde, $hasta]);
        $totalVentas = (float)$sales->fetch()['total'];

        // órdenes cobradas
        $ord = $pdo->prepare("SELECT COUNT(*) FROM ordenes WHERE estado='cobrada' AND DATE(cerrada_at) BETWEEN ? AND ?");
        $ord->execute([$desde, $hasta]);
        $totalOrdenes = (int)$ord->fetchColumn();

        // por método
        $met = $pdo->prepare("
            SELECT m.codigo, m.nombre, m.icono, COALESCE(SUM(p.monto),0) AS total, COUNT(p.id) AS transacciones
            FROM pagos p
            JOIN metodos_pago m ON m.id = p.metodo_id
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            GROUP BY m.id
            ORDER BY total DESC
        ");
        $met->execute([$desde, $hasta]);
        $metodos = $met->fetchAll();
        foreach ($metodos as &$mm) $mm['total'] = (float)$mm['total'];

        // TODO lo vendido: cada producto con unidades NETAS (menos canceladas)
        // e ingresos, con su categoría. De aquí sale también el "top".
        $prodStmt = $pdo->prepare("
            SELECT oi.nombre,
                   COALESCE(c.nombre,'Sin categoría') AS categoria,
                   SUM(oi.cantidad - COALESCE(oi.cantidad_cancelada,0)) AS unidades,
                   SUM((oi.cantidad - COALESCE(oi.cantidad_cancelada,0)) * oi.precio) AS ingresos
            FROM orden_items oi
            JOIN ordenes o ON o.id = oi.orden_id
            LEFT JOIN productos p ON p.id = oi.producto_id
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE o.estado='cobrada' AND DATE(o.cerrada_at) BETWEEN ? AND ?
            GROUP BY oi.nombre, c.nombre
            HAVING unidades > 0
            ORDER BY unidades DESC, ingresos DESC
        ");
        $prodStmt->execute([$desde, $hasta]);
        $productos = array_map(function ($r) {
            $r['unidades'] = (int)$r['unidades'];
            $r['ingresos'] = (float)$r['ingresos'];
            return $r;
        }, $prodStmt->fetchAll());

        // Lo que más SE MUEVE por categoría (unidades e ingresos)
        $catStmt = $pdo->prepare("
            SELECT COALESCE(c.nombre,'Sin categoría') AS categoria,
                   SUM(oi.cantidad - COALESCE(oi.cantidad_cancelada,0)) AS unidades,
                   SUM((oi.cantidad - COALESCE(oi.cantidad_cancelada,0)) * oi.precio) AS ingresos
            FROM orden_items oi
            JOIN ordenes o ON o.id = oi.orden_id
            LEFT JOIN productos p ON p.id = oi.producto_id
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE o.estado='cobrada' AND DATE(o.cerrada_at) BETWEEN ? AND ?
            GROUP BY c.nombre
            HAVING unidades > 0
            ORDER BY ingresos DESC
        ");
        $catStmt->execute([$desde, $hasta]);
        $porCategoria = array_map(function ($r) {
            $r['unidades'] = (int)$r['unidades'];
            $r['ingresos'] = (float)$r['ingresos'];
            return $r;
        }, $catStmt->fetchAll());

        // A qué HORA se vende más (por hora del día 0–23)
        $horaStmt = $pdo->prepare("
            SELECT HOUR(o.cerrada_at) AS hora, COUNT(*) AS ordenes, COALESCE(SUM(o.total),0) AS ventas
            FROM ordenes o
            WHERE o.estado='cobrada' AND DATE(o.cerrada_at) BETWEEN ? AND ?
            GROUP BY HOUR(o.cerrada_at)
            ORDER BY hora
        ");
        $horaStmt->execute([$desde, $hasta]);
        $porHora = array_map(function ($r) {
            $r['hora']    = (int)$r['hora'];
            $r['ordenes'] = (int)$r['ordenes'];
            $r['ventas']  = (float)$r['ventas'];
            return $r;
        }, $horaStmt->fetchAll());

        // ventas por día (para gráfica)
        $byDay = $pdo->prepare("
            SELECT DATE(cerrada_at) AS dia, SUM(total) AS total, COUNT(*) AS ordenes
            FROM ordenes
            WHERE estado='cobrada' AND DATE(cerrada_at) BETWEEN ? AND ?
            GROUP BY DATE(cerrada_at)
            ORDER BY dia
        ");
        $byDay->execute([$desde, $hasta]);

        // Total de unidades vendidas (para porcentajes)
        $totalUnidades = array_sum(array_column($productos, 'unidades'));

        json_response([
            'rango'         => ['desde' => $desde, 'hasta' => $hasta],
            'ventas'        => $totalVentas,
            'ordenes'       => $totalOrdenes,
            'promedio'      => $totalOrdenes ? $totalVentas / $totalOrdenes : 0,
            'mesas'         => $totalOrdenes,
            'unidades'      => $totalUnidades,
            'metodos'       => $metodos,
            'top'           => array_slice($productos, 0, 5),
            'productos'     => $productos,
            'por_categoria' => $porCategoria,
            'por_hora'      => $porHora,
            'por_dia'       => $byDay->fetchAll(),
        ]);
    },

    'metodos_pago' => function () {
        $rows = db()->query("SELECT * FROM metodos_pago WHERE activo=1")->fetchAll();
        json_response($rows);
    },

    /**
     * Cortes consolidados de todos los empleados
     * Filtros: ?desde=&hasta=&rol=&usuario_id=
     */
    'cortes_consolidados' => function () {
        api_require_role('admin','encargado','cajero');
        $desde = $_GET['desde'] ?? date('Y-m-d');
        $hasta = $_GET['hasta'] ?? date('Y-m-d');
        $rol = $_GET['rol'] ?? '';
        $usuario_id = (int)($_GET['usuario_id'] ?? 0);

        $pdo = db();

        // ===== ÓRDENES COBRADAS = fuente de verdad de las ventas =====
        // Se atribuyen al usuario que atendió (o.usuario_id) e incluyen las que
        // se cobraron SIN turno abierto (turno_id NULL) — antes desaparecían.
        $ow = ["o.estado='cobrada'", "DATE(o.cerrada_at) BETWEEN ? AND ?"];
        $op = [$desde, $hasta];
        if ($usuario_id) { $ow[] = "o.usuario_id = ?"; $op[] = $usuario_id; }
        if ($rol)        { $ow[] = "u.rol = ?";        $op[] = $rol; }
        $owSql = implode(' AND ', $ow);

        // Filtro de turnos (reconciliación de caja: esperado/contado/diferencia)
        $tw = ["t.fecha BETWEEN ? AND ?", "t.estado='cerrado'"];
        $tp = [$desde, $hasta];
        if ($usuario_id) { $tw[] = "t.usuario_id = ?"; $tp[] = $usuario_id; }
        if ($rol)        { $tw[] = "u.rol = ?";        $tp[] = $rol; }
        $twSql = implode(' AND ', $tw);

        // ── Resumen: ventas/órdenes/envíos de las órdenes ──
        $stmt = $pdo->prepare("SELECT
              COUNT(*) AS total_ordenes,
              COALESCE(SUM(o.total),0) AS total_ventas,
              COALESCE(SUM(o.costo_envio),0) AS total_envios,
              COUNT(DISTINCT o.usuario_id) AS empleados
            FROM ordenes o LEFT JOIN usuarios u ON u.id=o.usuario_id WHERE $owSql");
        $stmt->execute($op);
        $ov = $stmt->fetch();

        // Métodos de pago (montos aplicados) de esas órdenes
        $byCode = _pagos_por_codigo($pdo, $owSql, $op);

        // Propinas de esas órdenes
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(pr.monto),0)
            FROM propinas pr JOIN ordenes o ON o.id=pr.orden_id
            LEFT JOIN usuarios u ON u.id=o.usuario_id WHERE $owSql");
        $stmt->execute($op);
        $totalPropinas = (float)$stmt->fetchColumn();

        // Reconciliación de caja (turnos cerrados)
        $stmt = $pdo->prepare("SELECT
              COUNT(*) AS num_turnos,
              COALESCE(SUM(t.diferencia),0) AS diferencia_acumulada,
              COALESCE(SUM(CASE WHEN t.diferencia<0 THEN 1 ELSE 0 END),0) AS turnos_faltante,
              COALESCE(SUM(CASE WHEN t.diferencia>0 THEN 1 ELSE 0 END),0) AS turnos_sobrante
            FROM turnos t JOIN usuarios u ON u.id=t.usuario_id WHERE $twSql");
        $stmt->execute($tp);
        $tagg = $stmt->fetch();

        $resumen = [
            'num_turnos'           => (int)$tagg['num_turnos'],
            'empleados'            => (int)$ov['empleados'],
            'total_ventas'         => (float)$ov['total_ventas'],
            'total_ordenes'        => (int)$ov['total_ordenes'],
            'total_efectivo'       => (float)($byCode['cash'] ?? 0),
            'total_tarjeta'        => (float)($byCode['card'] ?? 0),
            'total_transfer'       => (float)($byCode['transfer'] ?? 0),
            'total_otros'          => (float)($byCode['_otros'] ?? 0),
            'total_propinas'       => $totalPropinas,
            'total_envios'         => (float)$ov['total_envios'],
            'diferencia_acumulada' => (float)$tagg['diferencia_acumulada'],
            'turnos_faltante'      => (int)$tagg['turnos_faltante'],
            'turnos_sobrante'      => (int)$tagg['turnos_sobrante'],
        ];

        // ── Por empleado (atribución por quien atendió) ──
        $stmt = $pdo->prepare("SELECT
              COALESCE(o.usuario_id,0) AS usuario_id,
              COALESCE(u.nombre,'(sin asignar)') AS nombre,
              COALESCE(u.rol,'—') AS rol,
              COUNT(*) AS num_ordenes,
              COALESCE(SUM(o.total),0) AS total_ventas,
              COALESCE(SUM(o.costo_envio),0) AS total_envios
            FROM ordenes o LEFT JOIN usuarios u ON u.id=o.usuario_id
            WHERE $owSql GROUP BY COALESCE(o.usuario_id,0), u.nombre, u.rol");
        $stmt->execute($op);
        $emp = [];
        foreach ($stmt->fetchAll() as $r) {
            $emp[(int)$r['usuario_id']] = [
                'usuario_id'=>(int)$r['usuario_id'], 'nombre'=>$r['nombre'], 'rol'=>$r['rol'],
                'num_ordenes'=>(int)$r['num_ordenes'], 'total_ventas'=>(float)$r['total_ventas'],
                'total_envios'=>(float)$r['total_envios'],
                'total_efectivo'=>0.0,'total_tarjeta'=>0.0,'total_transfer'=>0.0,'total_otros'=>0.0,
                'total_propinas'=>0.0,'num_turnos'=>0,'diferencia'=>0.0,
            ];
        }
        // pagos por empleado+método
        $stmt = $pdo->prepare("SELECT COALESCE(o.usuario_id,0) AS uid, mp.codigo, COALESCE(SUM(p.monto),0) AS total
            FROM pagos p JOIN ordenes o ON o.id=p.orden_id
            LEFT JOIN usuarios u ON u.id=o.usuario_id
            JOIN metodos_pago mp ON mp.id=p.metodo_id
            WHERE $owSql GROUP BY uid, mp.codigo");
        $stmt->execute($op);
        foreach ($stmt->fetchAll() as $r) {
            $uid=(int)$r['uid']; if (!isset($emp[$uid])) continue;
            $tot=(float)$r['total'];
            if ($r['codigo']==='cash') $emp[$uid]['total_efectivo']+=$tot;
            elseif ($r['codigo']==='card') $emp[$uid]['total_tarjeta']+=$tot;
            elseif ($r['codigo']==='transfer') $emp[$uid]['total_transfer']+=$tot;
            else $emp[$uid]['total_otros']+=$tot;
        }
        // propinas por empleado
        $stmt = $pdo->prepare("SELECT COALESCE(o.usuario_id,0) AS uid, COALESCE(SUM(pr.monto),0) AS total
            FROM propinas pr JOIN ordenes o ON o.id=pr.orden_id
            LEFT JOIN usuarios u ON u.id=o.usuario_id WHERE $owSql GROUP BY uid");
        $stmt->execute($op);
        foreach ($stmt->fetchAll() as $r) { $uid=(int)$r['uid']; if(isset($emp[$uid])) $emp[$uid]['total_propinas']=(float)$r['total']; }
        // turnos por empleado (reconciliación de su caja)
        $stmt = $pdo->prepare("SELECT t.usuario_id AS uid, u.nombre, u.rol,
              COUNT(*) AS num_turnos, COALESCE(SUM(t.diferencia),0) AS diferencia
            FROM turnos t JOIN usuarios u ON u.id=t.usuario_id WHERE $twSql GROUP BY t.usuario_id");
        $stmt->execute($tp);
        foreach ($stmt->fetchAll() as $r) {
            $uid=(int)$r['uid'];
            if (!isset($emp[$uid])) {
                $emp[$uid] = ['usuario_id'=>$uid,'nombre'=>$r['nombre'],'rol'=>$r['rol'],
                    'num_ordenes'=>0,'total_ventas'=>0.0,'total_envios'=>0.0,
                    'total_efectivo'=>0.0,'total_tarjeta'=>0.0,'total_transfer'=>0.0,'total_otros'=>0.0,
                    'total_propinas'=>0.0,'num_turnos'=>0,'diferencia'=>0.0];
            }
            $emp[$uid]['num_turnos']=(int)$r['num_turnos'];
            $emp[$uid]['diferencia']=(float)$r['diferencia'];
        }
        $por_empleado = array_values($emp);
        usort($por_empleado, fn($a,$b)=> $b['total_ventas'] <=> $a['total_ventas']);

        // ── Por día (por fecha de cobro) ──
        $stmt = $pdo->prepare("SELECT DATE(o.cerrada_at) AS fecha,
              COUNT(*) AS num_ordenes, COUNT(DISTINCT o.usuario_id) AS empleados,
              COALESCE(SUM(o.total),0) AS total_ventas
            FROM ordenes o LEFT JOIN usuarios u ON u.id=o.usuario_id
            WHERE $owSql GROUP BY DATE(o.cerrada_at) ORDER BY fecha");
        $stmt->execute($op);
        $por_dia = [];
        foreach ($stmt->fetchAll() as $r) {
            $por_dia[$r['fecha']] = [
                'fecha'=>$r['fecha'], 'num_ordenes'=>(int)$r['num_ordenes'], 'empleados'=>(int)$r['empleados'],
                'total_ventas'=>(float)$r['total_ventas'], 'total_efectivo'=>0.0, 'total_propinas'=>0.0,
                'num_turnos'=>0, 'diferencia'=>0.0,
            ];
        }
        // efectivo por día
        $stmt = $pdo->prepare("SELECT DATE(o.cerrada_at) AS fecha, COALESCE(SUM(p.monto),0) AS total
            FROM pagos p JOIN ordenes o ON o.id=p.orden_id
            LEFT JOIN usuarios u ON u.id=o.usuario_id
            JOIN metodos_pago mp ON mp.id=p.metodo_id
            WHERE $owSql AND mp.codigo='cash' GROUP BY fecha");
        $stmt->execute($op);
        foreach ($stmt->fetchAll() as $r) if(isset($por_dia[$r['fecha']])) $por_dia[$r['fecha']]['total_efectivo']=(float)$r['total'];
        // propinas por día
        $stmt = $pdo->prepare("SELECT DATE(o.cerrada_at) AS fecha, COALESCE(SUM(pr.monto),0) AS total
            FROM propinas pr JOIN ordenes o ON o.id=pr.orden_id
            LEFT JOIN usuarios u ON u.id=o.usuario_id WHERE $owSql GROUP BY fecha");
        $stmt->execute($op);
        foreach ($stmt->fetchAll() as $r) if(isset($por_dia[$r['fecha']])) $por_dia[$r['fecha']]['total_propinas']=(float)$r['total'];
        // turnos por día
        $stmt = $pdo->prepare("SELECT t.fecha, COUNT(*) AS num_turnos, COALESCE(SUM(t.diferencia),0) AS diferencia
            FROM turnos t JOIN usuarios u ON u.id=t.usuario_id WHERE $twSql GROUP BY t.fecha");
        $stmt->execute($tp);
        foreach ($stmt->fetchAll() as $r) {
            if (!isset($por_dia[$r['fecha']])) continue;
            $por_dia[$r['fecha']]['num_turnos']=(int)$r['num_turnos'];
            $por_dia[$r['fecha']]['diferencia']=(float)$r['diferencia'];
        }
        $por_dia = array_values($por_dia);

        // ── Lista detallada de turnos cerrados ──
        $stmt = $pdo->prepare("
            SELECT
              t.id, t.fecha, t.hora_inicio, t.hora_fin,
              t.fondo_inicial, t.efectivo_esperado, t.efectivo_contado, t.diferencia,
              t.total_ventas, t.total_propinas, t.total_envios, t.num_ordenes,
              t.cerrado_at, t.observaciones_cierre,
              u.id AS usuario_id, u.nombre AS usuario, u.rol
            FROM turnos t
            JOIN usuarios u ON u.id = t.usuario_id
            WHERE $twSql
            ORDER BY t.fecha DESC, t.cerrado_at DESC
        ");
        $stmt->execute($tp);
        $turnos = $stmt->fetchAll();

        // ── Conteo por tipo de pedido en el rango (extra útil) ──
        $stmt = $pdo->prepare("
            SELECT o.tipo, COUNT(*) AS num, COALESCE(SUM(o.total),0) AS total
            FROM ordenes o
            WHERE o.estado='cobrada' AND DATE(o.cerrada_at) BETWEEN ? AND ?
            GROUP BY o.tipo
        ");
        $stmt->execute([$desde, $hasta]);
        $por_tipo = $stmt->fetchAll();

        // ── Por repartidor (entregas, efectivo, envíos, propinas, en camino) ──
        // Dimensión INDEPENDIENTE del filtro de empleado/rol: se filtra solo por
        // fecha. La venta se le acredita a quien TOMÓ la orden (arriba); aquí se
        // ve la actividad de reparto de cada repartidor.
        $rep = [];
        // Entregado y cobrado en el rango
        $stmt = $pdo->prepare("SELECT o.repartidor_id AS rid, r.nombre,
              COUNT(*) AS entregas, COALESCE(SUM(o.total),0) AS monto,
              COALESCE(SUM(o.costo_envio),0) AS envios
            FROM ordenes o JOIN usuarios r ON r.id=o.repartidor_id
            WHERE o.estado='cobrada' AND DATE(o.cerrada_at) BETWEEN ? AND ? AND o.repartidor_id IS NOT NULL
            GROUP BY o.repartidor_id, r.nombre");
        $stmt->execute([$desde, $hasta]);
        foreach ($stmt->fetchAll() as $r) {
            $rep[(int)$r['rid']] = [
                'repartidor_id'=>(int)$r['rid'], 'nombre'=>$r['nombre'],
                'entregas'=>(int)$r['entregas'], 'monto'=>(float)$r['monto'], 'envios'=>(float)$r['envios'],
                'efectivo'=>0.0, 'propinas'=>0.0, 'en_camino'=>0,
            ];
        }
        // Efectivo cobrado por el repartidor (pagos en efectivo de esas órdenes)
        $stmt = $pdo->prepare("SELECT o.repartidor_id AS rid, COALESCE(SUM(p.monto),0) AS efectivo
            FROM pagos p JOIN ordenes o ON o.id=p.orden_id JOIN metodos_pago mp ON mp.id=p.metodo_id
            WHERE o.estado='cobrada' AND DATE(o.cerrada_at) BETWEEN ? AND ? AND o.repartidor_id IS NOT NULL AND mp.codigo='cash'
            GROUP BY o.repartidor_id");
        $stmt->execute([$desde, $hasta]);
        foreach ($stmt->fetchAll() as $r) { $id=(int)$r['rid']; if(isset($rep[$id])) $rep[$id]['efectivo']=(float)$r['efectivo']; }
        // Propinas de esas órdenes de domicilio
        $stmt = $pdo->prepare("SELECT o.repartidor_id AS rid, COALESCE(SUM(pr.monto),0) AS propinas
            FROM propinas pr JOIN ordenes o ON o.id=pr.orden_id
            WHERE o.estado='cobrada' AND DATE(o.cerrada_at) BETWEEN ? AND ? AND o.repartidor_id IS NOT NULL
            GROUP BY o.repartidor_id");
        $stmt->execute([$desde, $hasta]);
        foreach ($stmt->fetchAll() as $r) { $id=(int)$r['rid']; if(isset($rep[$id])) $rep[$id]['propinas']=(float)$r['propinas']; }
        // En camino AHORA (estado vivo, sin filtrar por fecha): toda entrega que
        // sigue en la calle (asignada/en_camino) y no cancelada — incluye las ya
        // cobradas que nunca se marcaron como entregadas.
        $stmt = $pdo->query("SELECT o.repartidor_id AS rid, r.nombre, COUNT(*) AS n
            FROM ordenes o JOIN usuarios r ON r.id=o.repartidor_id
            WHERE o.estado_entrega IN ('asignada','en_camino') AND o.estado<>'cancelada' AND o.repartidor_id IS NOT NULL
            GROUP BY o.repartidor_id, r.nombre");
        foreach ($stmt->fetchAll() as $r) {
            $id=(int)$r['rid'];
            if (!isset($rep[$id])) {
                $rep[$id] = ['repartidor_id'=>$id,'nombre'=>$r['nombre'],'entregas'=>0,'monto'=>0.0,
                    'envios'=>0.0,'efectivo'=>0.0,'propinas'=>0.0,'en_camino'=>0];
            }
            $rep[$id]['en_camino'] = (int)$r['n'];
        }
        $por_repartidor = array_values($rep);
        usort($por_repartidor, fn($a,$b)=> $b['monto'] <=> $a['monto']);

        // Último cierre de día (para el banner del módulo)
        $ultimoCierre = null;
        $uc = cfg('ultimo_cierre_dia');
        if ($uc) { $ultimoCierre = json_decode($uc, true) ?: null; }

        json_response([
            'rango'          => ['desde' => $desde, 'hasta' => $hasta],
            'resumen'        => $resumen,
            'por_empleado'   => $por_empleado,
            'por_dia'        => $por_dia,
            'por_tipo'       => $por_tipo,
            'por_repartidor' => $por_repartidor,
            'turnos'         => $turnos,
            'ultimo_cierre'  => $ultimoCierre,
        ]);
    },

    /**
     * Empleados activos (para el selector de filtro)
     */
    'empleados' => function () {
        api_require_role('admin','encargado','cajero');
        $rows = db()->query("
            SELECT id, nombre, usuario, rol
            FROM usuarios
            WHERE activo=1 AND rol IN ('mesero','repartidor','cajero','cocina')
            ORDER BY rol, nombre
        ")->fetchAll();
        json_response($rows);
    },

    /**
     * Detalle completo de un turno (admin puede ver de cualquier usuario)
     */
    'turno_detalle' => function () {
        api_require_role('admin','encargado','cajero');
        $id = (int)($_GET['id'] ?? 0);
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT t.*, u.nombre AS usuario, u.usuario AS login, u.rol
            FROM turnos t JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) json_response(['error' => 'No encontrado'], 404);

        // Órdenes del turno
        $ord = $pdo->prepare("
            SELECT o.id, o.tipo, o.mesa_id, m.numero AS mesa_numero,
                   o.cliente_nombre, o.total, o.costo_envio, o.propina, o.cerrada_at,
                   GROUP_CONCAT(CONCAT(mp.codigo,':',p.monto) SEPARATOR ' · ') AS pagos
            FROM ordenes o
            LEFT JOIN mesas m ON m.id = o.mesa_id
            LEFT JOIN pagos p ON p.orden_id = o.id
            LEFT JOIN metodos_pago mp ON mp.id = p.metodo_id
            WHERE o.turno_id = ? AND o.estado = 'cobrada'
            GROUP BY o.id
            ORDER BY o.cerrada_at DESC
        ");
        $ord->execute([$id]);
        $t['ordenes'] = $ord->fetchAll();

        // Propinas del turno
        $prop = $pdo->prepare("
            SELECT p.monto, p.porcentaje, p.created_at, o.id AS orden_id
            FROM propinas p
            LEFT JOIN ordenes o ON o.id = p.orden_id
            WHERE p.turno_id = ?
            ORDER BY p.created_at DESC
        ");
        $prop->execute([$id]);
        $t['propinas'] = $prop->fetchAll();

        json_response($t);
    },
];

/**
 * Suma de pagos por código de método para las órdenes que cumplen $owSql.
 * Devuelve ['cash'=>x,'card'=>y,'transfer'=>z,'_otros'=>w].
 */
function _pagos_por_codigo(PDO $pdo, string $owSql, array $op): array {
    $stmt = $pdo->prepare("SELECT mp.codigo, COALESCE(SUM(p.monto),0) AS total
        FROM pagos p
        JOIN ordenes o ON o.id=p.orden_id
        LEFT JOIN usuarios u ON u.id=o.usuario_id
        JOIN metodos_pago mp ON mp.id=p.metodo_id
        WHERE $owSql GROUP BY mp.codigo");
    $stmt->execute($op);
    $out = ['cash'=>0.0,'card'=>0.0,'transfer'=>0.0,'_otros'=>0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $code => $tot) {
        if (isset($out[$code])) $out[$code] = (float)$tot;
        else $out['_otros'] += (float)$tot;
    }
    return $out;
}
