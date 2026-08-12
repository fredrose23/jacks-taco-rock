<?php
/**
 * Handler unificado de CRUD para administración (mesas, productos, categorías,
 * modificadores, combos, impresoras, promociones, etc.)
 * Esto evita tener 10 handlers casi idénticos.
 */

function _crud_table(string $table, array $fields, ?array $where = null): array {
    $sql = "SELECT * FROM $table";
    if ($where) $sql .= " WHERE " . implode(' AND ', array_map(fn($k)=>"$k=?", array_keys($where)));
    $sql .= " ORDER BY id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($where ? array_values($where) : []);
    return $stmt->fetchAll();
}

function _crud_create(string $table, array $allowed, array $data, string $logEntity = null): int {
    $data = array_intersect_key($data, array_flip($allowed));
    if (!$data) throw new Exception('Sin datos');
    $cols = implode(',', array_keys($data));
    $ph   = implode(',', array_fill(0, count($data), '?'));
    db()->prepare("INSERT INTO $table ($cols) VALUES ($ph)")->execute(array_values($data));
    $id = (int)db()->lastInsertId();
    if ($logEntity) log_action("{$logEntity}_create", $logEntity, $id, "Creado");
    return $id;
}

function _crud_update(string $table, array $allowed, array $data, int $id, string $logEntity = null): void {
    $data = array_intersect_key($data, array_flip($allowed));
    if (!$data) return;
    $sets = implode(',', array_map(fn($k)=>"$k=?", array_keys($data)));
    $vals = array_values($data);
    $vals[] = $id;
    db()->prepare("UPDATE $table SET $sets WHERE id=?")->execute($vals);
    if ($logEntity) log_action("{$logEntity}_update", $logEntity, $id, "Editado");
}

function _crud_delete(string $table, int $id, string $logEntity = null, bool $soft = false): void {
    if ($soft) {
        db()->prepare("UPDATE $table SET activa=0 WHERE id=?")->execute([$id]);
    } else {
        db()->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
    }
    if ($logEntity) log_action("{$logEntity}_delete", $logEntity, $id, "Eliminado");
}

return [
    // ===== MESAS =====
    'mesas_list' => function () {
        json_response(db()->query("SELECT * FROM mesas ORDER BY numero")->fetchAll());
    },
    'mesas_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['numero','nombre','capacidad','zona','descripcion','estado','activa'];
        $id = (int)($in['id'] ?? 0);
        if ($id) { _crud_update('mesas', $fields, $in, $id, 'mesa'); json_response(['ok'=>true]); }
        else     { json_response(['ok'=>true, 'id'=>_crud_create('mesas', $fields, $in, 'mesa')]); }
    },
    'mesas_delete' => function () {
        api_require_role('admin'); require_method('POST');
        $id = (int)(json_input()['id'] ?? 0);
        _crud_delete('mesas', $id, 'mesa');
        json_response(['ok'=>true]);
    },

    // ===== CATEGORÍAS =====
    'categorias_list' => function () {
        json_response(db()->query("SELECT c.*, i.nombre AS impresora FROM categorias c LEFT JOIN impresoras i ON i.id=c.impresora_id ORDER BY orden")->fetchAll());
    },
    'categorias_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['nombre','orden','impresora_id','activa'];
        $id = (int)($in['id'] ?? 0);
        if ($id) { _crud_update('categorias', $fields, $in, $id, 'categoria'); json_response(['ok'=>true]); }
        else     { json_response(['ok'=>true, 'id'=>_crud_create('categorias', $fields, $in, 'categoria')]); }
    },
    /**
     * Elimina una categoría. Solo se permite si no tiene productos
     * (la FK productos.categoria_id lo impediría de todas formas).
     */
    'categorias_delete' => function () {
        api_require_role('admin'); require_method('POST');
        $id = (int)(json_input()['id'] ?? 0);
        if (!$id) json_response(['error' => 'ID requerido'], 400);
        $st = db()->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id=?");
        $st->execute([$id]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            json_response(['error' => "No se puede eliminar: la categoría tiene $n producto(s). Muévelos a otra categoría o elimínalos primero."], 409);
        }
        _crud_delete('categorias', $id, 'categoria');
        json_response(['ok'=>true]);
    },

    // ===== PRODUCTOS (CRUD admin) =====
    'productos_list' => function () {
        json_response(db()->query("
            SELECT p.*, c.nombre AS categoria, i.nombre AS impresora
            FROM productos p
            JOIN categorias c ON c.id=p.categoria_id
            LEFT JOIN impresoras i ON i.id=p.impresora_id
            ORDER BY c.orden, p.nombre
        ")->fetchAll());
    },
    'productos_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['categoria_id','nombre','descripcion','precio','emoji','destacado','disponible','stock','stock_minimo','unidad','impresora_id','cocina_2','maneja_stock'];
        $id = (int)($in['id'] ?? 0);
        // detectar cambio de precio para bitácora
        if ($id && isset($in['precio'])) {
            $old = db()->query("SELECT precio FROM productos WHERE id=$id")->fetchColumn();
            if ($old !== null && (float)$old !== (float)$in['precio']) {
                log_action('price_change', 'producto', $id, "Precio $old → {$in['precio']}");
            }
        }
        if ($id) { _crud_update('productos', $fields, $in, $id, 'producto'); json_response(['ok'=>true]); }
        else     { json_response(['ok'=>true, 'id'=>_crud_create('productos', $fields, $in, 'producto')]); }
    },
    /**
     * Elimina un producto. Si tiene historial (ventas, movimientos de stock
     * o pertenece a un combo) NO se puede borrar el registro sin romper
     * reportes, así que solo se desactiva (disponible=0) y se informa.
     */
    'productos_delete' => function () {
        api_require_role('admin'); require_method('POST');
        $id = (int)(json_input()['id'] ?? 0);
        if (!$id) json_response(['error' => 'ID requerido'], 400);
        $pdo = db();

        $refs = [
            'orden_items'       => 'ventas registradas',
            'movimientos_stock' => 'movimientos de inventario',
            'combo_items'       => 'combos que lo incluyen',
        ];
        foreach ($refs as $tabla => $motivo) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE producto_id=?");
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                $pdo->prepare("UPDATE productos SET disponible=0 WHERE id=?")->execute([$id]);
                log_action('producto_delete', 'producto', $id, "Desactivado (tiene $motivo)");
                json_response(['ok'=>true, 'deleted'=>false, 'motivo'=>$motivo]);
            }
        }

        // Sin historial → borrar imagen, vínculos y registro definitivamente
        $st = $pdo->prepare("SELECT imagen FROM productos WHERE id=?");
        $st->execute([$id]);
        $imagen = $st->fetchColumn();
        if ($imagen) {
            $imgDir = __DIR__ . '/../../assets/img/products';
            $base = preg_replace('/\.png$/', '', $imagen);
            @unlink("$imgDir/$imagen");
            @unlink("$imgDir/{$base}-thumb.png");
        }
        $pdo->prepare("DELETE FROM producto_modificador_grupo WHERE producto_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM productos WHERE id=?")->execute([$id]);
        log_action('producto_delete', 'producto', $id, "Eliminado definitivamente");
        json_response(['ok'=>true, 'deleted'=>true]);
    },
    /** Asignar grupos de modificadores a un producto */
    'productos_mods' => function () {
        $id = (int)($_GET['id'] ?? 0);
        $rows = db()->prepare("
            SELECT g.id, g.nombre, g.tipo, g.obligatorio
            FROM producto_modificador_grupo pmg
            JOIN modificador_grupos g ON g.id = pmg.grupo_id
            WHERE pmg.producto_id=?
        ");
        $rows->execute([$id]);
        $grupos = $rows->fetchAll();
        $mods = db()->prepare("SELECT * FROM modificadores WHERE grupo_id=? AND activo=1");
        foreach ($grupos as &$g) {
            $mods->execute([$g['id']]);
            $g['opciones'] = $mods->fetchAll();
        }
        json_response($grupos);
    },
    'productos_set_mods' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $pid = (int)($in['producto_id'] ?? 0);
        $grupos = $in['grupos'] ?? [];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM producto_modificador_grupo WHERE producto_id=?")->execute([$pid]);
            $ins = $pdo->prepare("INSERT INTO producto_modificador_grupo (producto_id, grupo_id) VALUES (?,?)");
            foreach ($grupos as $gid) $ins->execute([$pid, (int)$gid]);
            $pdo->commit();
            log_action('producto_mods', 'producto', $pid, "Asignados " . count($grupos) . " grupos");
            json_response(['ok'=>true]);
        } catch (Throwable $e) { $pdo->rollBack(); json_response(['error'=>$e->getMessage()], 500); }
    },

    // ===== IMPRESORAS =====
    'impresoras_list' => function () {
        json_response(db()->query("SELECT * FROM impresoras ORDER BY ubicacion, nombre")->fetchAll());
    },
    'impresoras_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['nombre','ubicacion','tipo','driver','destino','ancho_mm','copias','activa'];
        $id = (int)($in['id'] ?? 0);
        if ($id) { _crud_update('impresoras', $fields, $in, $id, 'impresora'); json_response(['ok'=>true]); }
        else     { json_response(['ok'=>true, 'id'=>_crud_create('impresoras', $fields, $in, 'impresora')]); }
    },
    'impresoras_delete' => function () {
        api_require_role('admin'); require_method('POST');
        _crud_delete('impresoras', (int)(json_input()['id'] ?? 0), 'impresora');
        json_response(['ok'=>true]);
    },

    // ===== CONFIGURACIÓN =====
    'config_list' => function () {
        json_response(db()->query("SELECT * FROM configuracion")->fetchAll());
    },
    'config_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $upd = db()->prepare("UPDATE configuracion SET valor=? WHERE clave=?");
        foreach (($in['cambios'] ?? []) as $clave => $valor) $upd->execute([(string)$valor, $clave]);
        log_action('config_update', 'configuracion', null, "Actualizada configuración");
        json_response(['ok'=>true]);
    },

    /** Guarda el horario de atención por día + el switch de pedidos web */
    'horario_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        // Switch general
        if (isset($in['web_activo'])) {
            db()->prepare("UPDATE configuracion SET valor=? WHERE clave='web_pedidos_activo'")
                ->execute([(int)$in['web_activo']]);
        }
        // Horarios por día
        $upd = db()->prepare("UPDATE horarios_atencion SET abierto=?, hora_inicio=?, hora_fin=? WHERE dia=?");
        foreach (($in['horarios'] ?? []) as $h) {
            $dia = (int)($h['dia'] ?? 0);
            if ($dia < 1 || $dia > 7) continue;
            $upd->execute([
                (int)($h['abierto'] ?? 0),
                ($h['hora_inicio'] ?? '18:00') ?: '18:00',
                ($h['hora_fin'] ?? '22:30') ?: '22:30',
                $dia,
            ]);
        }
        log_action('horario_save', 'configuracion', null, 'Horario actualizado');
        json_response(['ok' => true]);
    },

    /** Guarda la ubicación del restaurante (lat/lng) desde el mini-mapa */
    'ubicacion_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $lat = (float)($in['lat'] ?? 0);
        $lng = (float)($in['lng'] ?? 0);
        if (!$lat || !$lng) json_response(['error' => 'Coordenadas inválidas'], 400);
        $upd = db()->prepare("UPDATE configuracion SET valor=? WHERE clave=?");
        $upd->execute([(string)$lat, 'restaurante_lat']);
        $upd->execute([(string)$lng, 'restaurante_lng']);
        log_action('ubicacion_update', 'configuracion', null, "Ubicación: $lat, $lng");
        json_response(['ok' => true, 'lat' => $lat, 'lng' => $lng]);
    },

    // ===== PROMOCIONES =====
    'promos_list' => function () {
        json_response(db()->query("SELECT * FROM promociones ORDER BY activa DESC, created_at DESC")->fetchAll());
    },
    'promos_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['nombre','descripcion','tipo','valor','aplicable_a','categoria_id','producto_id','desde','hasta','hora_desde','hora_hasta','codigo','uso_max','activa'];
        $id = (int)($in['id'] ?? 0);
        // limpia campos vacíos a null
        foreach ($fields as $f) if (isset($in[$f]) && $in[$f] === '') $in[$f] = null;
        if ($id) { _crud_update('promociones', $fields, $in, $id, 'promocion'); json_response(['ok'=>true]); }
        else     { json_response(['ok'=>true, 'id'=>_crud_create('promociones', $fields, $in, 'promocion')]); }
    },
    'promos_delete' => function () {
        api_require_role('admin'); require_method('POST');
        _crud_delete('promociones', (int)(json_input()['id'] ?? 0), 'promocion');
        json_response(['ok'=>true]);
    },
    /** Aplica una promoción a una orden */
    'promo_apply' => function () {
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $codigo   = trim($in['codigo'] ?? '');
        $promo_id = (int)($in['promo_id'] ?? 0);
        $pdo = db();
        if ($codigo) {
            $p = $pdo->prepare("SELECT * FROM promociones WHERE codigo=? AND activa=1");
            $p->execute([$codigo]); $promo = $p->fetch();
        } else {
            $p = $pdo->prepare("SELECT * FROM promociones WHERE id=? AND activa=1");
            $p->execute([$promo_id]); $promo = $p->fetch();
        }
        if (!$promo) json_response(['error'=>'Promoción no encontrada'], 404);

        // Validaciones de fecha/hora
        $hoy = date('Y-m-d'); $ahora = date('H:i:s');
        if ($promo['desde'] && $hoy < $promo['desde']) json_response(['error'=>'Promo no inicia aún'], 400);
        if ($promo['hasta'] && $hoy > $promo['hasta']) json_response(['error'=>'Promo vencida'], 400);
        if ($promo['hora_desde'] && $ahora < $promo['hora_desde']) json_response(['error'=>'Fuera de horario'], 400);
        if ($promo['hora_hasta'] && $ahora > $promo['hora_hasta']) json_response(['error'=>'Fuera de horario'], 400);

        // Si ya tenía promo, revertir uso anterior
        $orden = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $orden->execute([$orden_id]); $o = $orden->fetch();
        if ($o['promocion_id'] && $o['promocion_id'] != $promo['id']) {
            $pdo->prepare("UPDATE promociones SET uso_actual = GREATEST(0, uso_actual - 1) WHERE id=?")
                ->execute([$o['promocion_id']]);
        }

        // Calcular descuento sobre subtotal (siempre se recalcula desde 0)
        // El subtotal del momento es: SUM((precio + precio_extra) * cantidad)
        $sub = (float)$o['subtotal'] + (float)$o['descuento']; // sin contar descuento previo
        $desc = match($promo['tipo']) {
            'porcentaje' => round($sub * (float)$promo['valor'] / 100, 2),
            'monto_fijo' => min($sub, (float)$promo['valor']),
            default => 0
        };
        $iva = max(0, $sub - $desc) * TAX_RATE;
        $nuevoTotal = max(0, $sub - $desc) + $iva;

        $pdo->prepare("UPDATE ordenes SET subtotal=?, descuento=?, iva=?, promocion_id=?, total=? WHERE id=?")
            ->execute([$sub, $desc, $iva, $promo['id'], $nuevoTotal, $orden_id]);
        $pdo->prepare("UPDATE promociones SET uso_actual=uso_actual+1 WHERE id=?")->execute([$promo['id']]);
        log_action('promo_apply', 'orden', $orden_id, "{$promo['nombre']} (-{$desc})");
        json_response(['ok'=>true, 'descuento'=>$desc, 'total'=>$nuevoTotal, 'promo'=>$promo['nombre']]);
    },

    // ===== MODIFICADORES =====
    'mods_grupos_list' => function () {
        $grupos = db()->query("SELECT * FROM modificador_grupos ORDER BY id")->fetchAll();
        $mods = db()->prepare("SELECT * FROM modificadores WHERE grupo_id=? ORDER BY orden, nombre");
        foreach ($grupos as &$g) { $mods->execute([$g['id']]); $g['opciones'] = $mods->fetchAll(); }
        json_response($grupos);
    },
    'mods_grupo_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['nombre','tipo','obligatorio','max_selecciones'];
        $id = (int)($in['id'] ?? 0);
        if ($id) { _crud_update('modificador_grupos', $fields, $in, $id, 'modgrupo'); json_response(['ok'=>true]); }
        else     { json_response(['ok'=>true, 'id'=>_crud_create('modificador_grupos', $fields, $in, 'modgrupo')]); }
    },
    'mod_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['grupo_id','nombre','precio_extra','orden','activo'];
        $id = (int)($in['id'] ?? 0);
        if ($id) { _crud_update('modificadores', $fields, $in, $id, 'mod'); json_response(['ok'=>true]); }
        else     { json_response(['ok'=>true, 'id'=>_crud_create('modificadores', $fields, $in, 'mod')]); }
    },
    'mod_delete' => function () {
        api_require_role('admin'); require_method('POST');
        _crud_delete('modificadores', (int)(json_input()['id'] ?? 0), 'mod');
        json_response(['ok'=>true]);
    },

    // ===== COMBOS =====
    'combos_list' => function () {
        $rows = db()->query("SELECT * FROM combos ORDER BY activo DESC, nombre")->fetchAll();
        $items = db()->prepare("SELECT ci.cantidad, p.id, p.nombre, p.emoji, p.precio FROM combo_items ci JOIN productos p ON p.id=ci.producto_id WHERE ci.combo_id=?");
        foreach ($rows as &$c) { $items->execute([$c['id']]); $c['items'] = $items->fetchAll(); }
        json_response($rows);
    },
    'combos_save' => function () {
        api_require_role('admin'); require_method('POST');
        $in = json_input();
        $fields = ['nombre','descripcion','precio','emoji','activo'];
        $items  = $in['items'] ?? [];
        $id = (int)($in['id'] ?? 0);
        $pdo = db(); $pdo->beginTransaction();
        try {
            if ($id) _crud_update('combos', $fields, $in, $id, 'combo');
            else     $id = _crud_create('combos', $fields, $in, 'combo');
            $pdo->prepare("DELETE FROM combo_items WHERE combo_id=?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO combo_items (combo_id, producto_id, cantidad) VALUES (?,?,?)");
            foreach ($items as $it) $ins->execute([$id, (int)$it['producto_id'], (int)$it['cantidad']]);
            $pdo->commit();
            json_response(['ok'=>true, 'id'=>$id]);
        } catch (Throwable $e) { $pdo->rollBack(); json_response(['error'=>$e->getMessage()], 500); }
    },
    'combos_delete' => function () {
        api_require_role('admin'); require_method('POST');
        _crud_delete('combos', (int)(json_input()['id'] ?? 0), 'combo');
        json_response(['ok'=>true]);
    },

    // ===== IMÁGENES DE PRODUCTOS =====
    /**
     * Sube la imagen de un producto. Acepta multipart/form-data con:
     *   producto_id  → ID del producto
     *   imagen       → archivo PNG/JPG/WebP, max 5 MB
     *
     * Procesa: recorta cuadrada centrada y guarda dos versiones:
     *   - {id}-{ts}.png         (600x600, vista normal)
     *   - {id}-{ts}-thumb.png   (200x200, para grids)
     * Borra las imágenes anteriores del producto.
     */
    'producto_imagen_upload' => function () {
        api_require_role('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['error' => 'Método no permitido'], 405);
        }
        $producto_id = (int)($_POST['producto_id'] ?? 0);
        if (!$producto_id) json_response(['error' => 'producto_id requerido'], 400);

        if (empty($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => 'Sin archivo o error en subida'], 400);
        }

        $tmp = $_FILES['imagen']['tmp_name'];
        $size = (int)$_FILES['imagen']['size'];
        if ($size > 5 * 1024 * 1024) json_response(['error' => 'Máximo 5 MB'], 400);

        $info = @getimagesize($tmp);
        if (!$info) json_response(['error' => 'Archivo no es imagen válida'], 400);
        $mime = $info['mime'];
        if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) {
            json_response(['error' => 'Solo PNG, JPG o WebP'], 400);
        }
        if (!extension_loaded('gd')) json_response(['error' => 'GD no instalado'], 500);

        $src = match($mime) {
            'image/png'  => imagecreatefrompng($tmp),
            'image/jpeg' => imagecreatefromjpeg($tmp),
            'image/webp' => imagecreatefromwebp($tmp),
        };
        if (!$src) json_response(['error' => 'No se pudo leer la imagen'], 500);

        $w = imagesx($src); $h = imagesy($src);
        $side = min($w, $h);
        $sx = (int)(($w - $side) / 2);
        $sy = (int)(($h - $side) / 2);

        $imgDir = __DIR__ . '/../../assets/img/products';
        if (!is_dir($imgDir)) @mkdir($imgDir, 0775, true);

        // Borrar imágenes anteriores del producto (con o sin -thumb)
        $stmt = db()->prepare("SELECT imagen FROM productos WHERE id=?");
        $stmt->execute([$producto_id]);
        $imagenAnterior = $stmt->fetchColumn();
        if ($imagenAnterior) {
            $base = preg_replace('/\.png$/', '', $imagenAnterior);
            @unlink("$imgDir/$imagenAnterior");
            @unlink("$imgDir/{$base}-thumb.png");
        }

        $ts = time();
        $filename = "{$producto_id}-{$ts}.png";
        $thumbname = "{$producto_id}-{$ts}-thumb.png";

        // Generar versión principal (600x600)
        $main = imagecreatetruecolor(600, 600);
        imagealphablending($main, false);
        imagesavealpha($main, true);
        imagecopyresampled($main, $src, 0, 0, $sx, $sy, 600, 600, $side, $side);
        imagepng($main, "$imgDir/$filename", 8);
        imagedestroy($main);

        // Thumb (200x200)
        $thumb = imagecreatetruecolor(200, 200);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $src, 0, 0, $sx, $sy, 200, 200, $side, $side);
        imagepng($thumb, "$imgDir/$thumbname", 8);
        imagedestroy($thumb);

        imagedestroy($src);

        db()->prepare("UPDATE productos SET imagen=? WHERE id=?")
            ->execute([$filename, $producto_id]);
        log_action('producto_imagen', 'producto', $producto_id, "Imagen actualizada");

        json_response([
            'ok' => true,
            'imagen' => $filename,
            'url' => APP_URL . '/assets/img/products/' . $filename,
            'thumb' => APP_URL . '/assets/img/products/' . $thumbname,
        ]);
    },

    /**
     * Elimina la imagen del producto y vuelve a usar emoji
     */
    'producto_imagen_delete' => function () {
        api_require_role('admin');
        require_method('POST');
        $producto_id = (int)(json_input()['producto_id'] ?? 0);
        if (!$producto_id) json_response(['error' => 'ID requerido'], 400);

        $stmt = db()->prepare("SELECT imagen FROM productos WHERE id=?");
        $stmt->execute([$producto_id]);
        $imagen = $stmt->fetchColumn();
        if ($imagen) {
            $imgDir = __DIR__ . '/../../assets/img/products';
            $base = preg_replace('/\.png$/', '', $imagen);
            @unlink("$imgDir/$imagen");
            @unlink("$imgDir/{$base}-thumb.png");
        }
        db()->prepare("UPDATE productos SET imagen=NULL WHERE id=?")->execute([$producto_id]);
        log_action('producto_imagen_del', 'producto', $producto_id, "Imagen eliminada");
        json_response(['ok' => true]);
    },

    // ===== LOGO DE TICKET (escala de grises) =====
    /**
     * Genera el logo para tickets (logo-ticket.png) en escala de grises con
     * contraste reforzado, óptimo para impresoras térmicas B/N.
     *
     * Dos modos:
     *   - source=principal  → toma assets/img/logo.png y lo convierte a grises
     *   - archivo subido     → convierte la imagen recibida a grises
     */
    'logo_ticket' => function () {
        api_require_role('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['error' => 'Método no permitido'], 405);
        }
        if (!extension_loaded('gd')) json_response(['error' => 'GD no instalado'], 500);

        $imgDir = __DIR__ . '/../../assets/img';
        $source = $_POST['source'] ?? '';

        // Resolver imagen origen
        if ($source === 'principal') {
            $path = "$imgDir/logo.png";
            if (!file_exists($path)) json_response(['error' => 'Primero sube el logo principal del negocio'], 400);
            $src = imagecreatefrompng($path);
            $mime = 'image/png';
        } else {
            if (empty($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
                json_response(['error' => 'Sin archivo o error en subida'], 400);
            }
            $tmp = $_FILES['imagen']['tmp_name'];
            if ((int)$_FILES['imagen']['size'] > 5 * 1024 * 1024) json_response(['error' => 'Máximo 5 MB'], 400);
            $info = @getimagesize($tmp);
            if (!$info) json_response(['error' => 'Archivo no es imagen válida'], 400);
            $mime = $info['mime'];
            if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) {
                json_response(['error' => 'Solo PNG, JPG o WebP'], 400);
            }
            $src = match($mime) {
                'image/png'  => imagecreatefrompng($tmp),
                'image/jpeg' => imagecreatefromjpeg($tmp),
                'image/webp' => imagecreatefromwebp($tmp),
            };
        }
        if (!$src) json_response(['error' => 'No se pudo leer la imagen'], 500);

        // Recorte cuadrado centrado
        $w = imagesx($src); $h = imagesy($src);
        $side = min($w, $h);
        $sx = (int)(($w - $side) / 2);
        $sy = (int)(($h - $side) / 2);

        $size = 600; // mayor resolución para que el logo se vea nítido al imprimirlo grande
        $out = imagecreatetruecolor($size, $size);
        // Fondo BLANCO (los tickets son sobre papel blanco)
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $size, $size, $white);
        imagecopyresampled($out, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

        // Convertir a escala de grises
        imagefilter($out, IMG_FILTER_GRAYSCALE);
        // Reforzar contraste para que destaque en térmica
        imagefilter($out, IMG_FILTER_CONTRAST, -25);   // negativo = más contraste en GD
        imagefilter($out, IMG_FILTER_BRIGHTNESS, 10);

        imagepng($out, "$imgDir/logo-ticket.png", 8);
        imagedestroy($out);
        imagedestroy($src);

        log_action('logo_ticket', 'configuracion', null, "Logo de ticket generado ($source)");
        json_response([
            'ok' => true,
            'url' => APP_URL . '/assets/img/logo-ticket.png?v=' . time(),
        ]);
    },

    'logo_ticket_delete' => function () {
        api_require_role('admin');
        require_method('POST');
        @unlink(__DIR__ . '/../../assets/img/logo-ticket.png');
        log_action('logo_ticket_del', 'configuracion', null, "Logo de ticket eliminado");
        json_response(['ok' => true]);
    },

    // ===== LOGO =====
    /**
     * Sube el logo del negocio. Acepta multipart/form-data con un archivo "logo".
     * Procesa la imagen con GD: la recorta cuadrada (manteniendo el centro), la
     * redimensiona a 512x512 y genera además las versiones 192x192 (PWA) y
     * el logo principal en assets/img/logo.png.
     */
    'logo_upload' => function () {
        api_require_role('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            json_response(['error' => 'Método no permitido'], 405);
        }
        if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo excede el límite del servidor (php.ini)',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo es muy grande',
                UPLOAD_ERR_PARTIAL    => 'Subida incompleta',
                UPLOAD_ERR_NO_FILE    => 'No se recibió archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'Sin carpeta temporal en el servidor',
                UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo',
            ];
            $code = $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE;
            json_response(['error' => $errMap[$code] ?? 'Error en la subida'], 400);
        }

        $tmp = $_FILES['logo']['tmp_name'];
        $size = (int)$_FILES['logo']['size'];

        // Validación de tamaño máximo: 5 MB
        if ($size > 5 * 1024 * 1024) {
            json_response(['error' => 'El archivo es muy grande (máx 5 MB)'], 400);
        }

        // Validar que sea imagen real (no solo extensión)
        $info = @getimagesize($tmp);
        if (!$info) json_response(['error' => 'El archivo no es una imagen válida'], 400);
        $mime = $info['mime'];
        $allowed = ['image/png', 'image/jpeg', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            json_response(['error' => 'Formato no soportado. Usa PNG, JPG o WebP'], 400);
        }
        if (!extension_loaded('gd')) {
            json_response(['error' => 'El servidor no tiene GD instalado'], 500);
        }

        // Cargar imagen según el formato
        $src = match($mime) {
            'image/png'  => imagecreatefrompng($tmp),
            'image/jpeg' => imagecreatefromjpeg($tmp),
            'image/webp' => imagecreatefromwebp($tmp),
        };
        if (!$src) json_response(['error' => 'No se pudo leer la imagen'], 500);

        // Recortar cuadrado centrado (para que el logo redondo se vea bien)
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $sx = ($w - $side) / 2;
        $sy = ($h - $side) / 2;

        // Función para generar una versión redimensionada
        $genSize = function($outPath, $targetSize) use ($src, $sx, $sy, $side) {
            $out = imagecreatetruecolor($targetSize, $targetSize);
            // Preservar transparencia para PNG
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
            imagefilledrectangle($out, 0, 0, $targetSize, $targetSize, $transparent);

            imagecopyresampled($out, $src, 0, 0, $sx, $sy, $targetSize, $targetSize, $side, $side);
            imagepng($out, $outPath, 9);
            imagedestroy($out);
        };

        $imgDir = __DIR__ . '/../../assets/img';
        if (!is_dir($imgDir)) @mkdir($imgDir, 0775, true);

        // Backup del logo anterior por si el cliente quiere recuperarlo
        $logoPath = "$imgDir/logo.png";
        if (file_exists($logoPath)) {
            @copy($logoPath, "$imgDir/logo-backup-" . date('Ymd-His') . '.png');
        }

        try {
            $genSize("$imgDir/logo.png",     512);
            $genSize("$imgDir/icon-512.png", 512);
            $genSize("$imgDir/icon-192.png", 192);
            // Favicon adicional
            $genSize("$imgDir/favicon.png",   64);
        } finally {
            imagedestroy($src);
        }

        log_action('logo_update', 'configuracion', null, "Logo actualizado ($size bytes, $mime)");
        json_response([
            'ok' => true,
            'logo_url' => APP_URL . '/assets/img/logo.png?v=' . time(),
            'size' => $size,
            'mime' => $mime,
        ]);
    },

    // ===== BITÁCORA =====
    'bitacora' => function () {
        $limit = min(500, max(20, (int)($_GET['limit'] ?? 100)));
        $sql = "SELECT b.*, u.nombre AS usuario, u.usuario AS login
                FROM bitacora b LEFT JOIN usuarios u ON u.id=b.usuario_id
                ORDER BY b.created_at DESC LIMIT $limit";
        json_response(db()->query($sql)->fetchAll());
    },
];
