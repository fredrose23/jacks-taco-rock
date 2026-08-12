<?php
/**
 * Cortes de caja / Arqueo
 */
return [
    /** Estado actual: si hay corte abierto y resumen del turno */
    'actual' => function () {
        $u = current_user();
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM cortes_caja WHERE usuario_id=? AND estado='abierto' LIMIT 1");
        $stmt->execute([$u['id']]);
        $corte = $stmt->fetch();
        if (!$corte) { json_response(['abierto' => false]); }

        // Calcular pagos desde la apertura
        $sum = $pdo->prepare("
            SELECT m.codigo, SUM(p.monto) AS total
            FROM pagos p
            JOIN metodos_pago m ON m.id=p.metodo_id
            JOIN ordenes o ON o.id=p.orden_id
            WHERE o.cerrada_at >= ? AND o.usuario_id=?
            GROUP BY m.codigo
        ");
        $sum->execute([$corte['abierto_at'], $u['id']]);
        $totales = $sum->fetchAll(PDO::FETCH_KEY_PAIR);

        $ord = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM ordenes WHERE usuario_id=? AND estado='cobrada' AND cerrada_at>=?");
        $ord->execute([$u['id'], $corte['abierto_at']]);
        [$nOrd, $tVentas] = $ord->fetch(PDO::FETCH_NUM);

        json_response([
            'abierto'   => true,
            'corte'     => $corte,
            'efectivo'  => (float)($totales['cash'] ?? 0),
            'tarjeta'   => (float)($totales['card'] ?? 0),
            'transfer'  => (float)($totales['transfer'] ?? 0),
            'otros'     => array_sum(array_map(fn($k,$v)=>!in_array($k,['cash','card','transfer'])?(float)$v:0, array_keys($totales), $totales)),
            'ventas'    => (float)$tVentas,
            'ordenes'   => (int)$nOrd,
            'esperado'  => (float)$corte['fondo_inicial'] + (float)($totales['cash'] ?? 0),
        ]);
    },

    /** Abrir corte */
    'abrir' => function () {
        require_method('POST');
        $in = json_input();
        $fondo = (float)($in['fondo_inicial'] ?? 0);
        $u = current_user();
        $pdo = db();
        $existing = $pdo->prepare("SELECT id FROM cortes_caja WHERE usuario_id=? AND estado='abierto'");
        $existing->execute([$u['id']]);
        if ($existing->fetch()) json_response(['error'=>'Ya tienes un corte abierto'], 400);

        $pdo->prepare("INSERT INTO cortes_caja (usuario_id, fondo_inicial) VALUES (?,?)")
            ->execute([$u['id'], $fondo]);
        $id = (int)$pdo->lastInsertId();
        log_action('corte_abrir', 'corte', $id, "Fondo inicial: $fondo");
        json_response(['ok'=>true, 'id'=>$id]);
    },

    /** Cerrar corte */
    'cerrar' => function () {
        require_method('POST');
        $in = json_input();
        $efectivo_contado = (float)($in['efectivo_contado'] ?? 0);
        $observaciones = trim($in['observaciones'] ?? '');
        $u = current_user();
        $pdo = db();

        $stmt = $pdo->prepare("SELECT * FROM cortes_caja WHERE usuario_id=? AND estado='abierto'");
        $stmt->execute([$u['id']]);
        $corte = $stmt->fetch();
        if (!$corte) json_response(['error'=>'No hay corte abierto'], 400);

        // Recalcular totales
        $sum = $pdo->prepare("
            SELECT m.codigo, COALESCE(SUM(p.monto),0) AS total
            FROM pagos p
            JOIN metodos_pago m ON m.id=p.metodo_id
            JOIN ordenes o ON o.id=p.orden_id
            WHERE o.cerrada_at >= ? AND o.usuario_id=?
            GROUP BY m.codigo
        ");
        $sum->execute([$corte['abierto_at'], $u['id']]);
        $tot = $sum->fetchAll(PDO::FETCH_KEY_PAIR);
        $cash = (float)($tot['cash'] ?? 0);
        $card = (float)($tot['card'] ?? 0);
        $tr   = (float)($tot['transfer'] ?? 0);
        $otros= array_sum(array_diff_key($tot, array_flip(['cash','card','transfer'])));
        $esperado = (float)$corte['fondo_inicial'] + $cash;
        $diff = $efectivo_contado - $esperado;

        $ord = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM ordenes WHERE usuario_id=? AND estado='cobrada' AND cerrada_at>=?");
        $ord->execute([$u['id'], $corte['abierto_at']]);
        [$nOrd, $tVentas] = $ord->fetch(PDO::FETCH_NUM);

        $pdo->prepare("
          UPDATE cortes_caja SET
            efectivo_esperado=?, efectivo_contado=?, diferencia=?,
            total_tarjeta=?, total_transfer=?, total_otros=?,
            total_ventas=?, num_ordenes=?, observaciones=?,
            cerrado_at=NOW(), estado='cerrado'
          WHERE id=?
        ")->execute([$esperado, $efectivo_contado, $diff, $card, $tr, $otros, $tVentas, $nOrd, $observaciones, $corte['id']]);

        log_action('corte_cerrar', 'corte', $corte['id'], "Diferencia: $diff");
        json_response(['ok'=>true, 'diferencia'=>$diff, 'esperado'=>$esperado]);
    },

    'historial' => function () {
        $rows = db()->query("
            SELECT c.*, u.nombre AS usuario
            FROM cortes_caja c JOIN usuarios u ON u.id=c.usuario_id
            ORDER BY c.id DESC LIMIT 30
        ")->fetchAll();
        json_response($rows);
    },
];
