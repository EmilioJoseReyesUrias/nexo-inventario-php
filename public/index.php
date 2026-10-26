<?php
declare(strict_types=1);
session_start();
require_once '/var/www/src/database.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function go(string $kind, string $message): never
{
    $_SESSION['flash'] = [$kind, $message];
    header('Location: /', true, 303);
    exit;
}

function validProduct(array $data): array
{
    $name = trim((string) ($data['nombre'] ?? ''));
    $category = trim((string) ($data['categoria'] ?? ''));
    $price = trim((string) ($data['precio'] ?? ''));
    $stock = trim((string) ($data['existencia'] ?? ''));

    if ($name === '' || mbLength($name) > 120 || $category === '' || mbLength($category) > 80) {
        throw new InvalidArgumentException('Escribe nombre y categoria validos.');
    }
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price) || (float) $price > 99999999.99) {
        throw new InvalidArgumentException('El precio debe ser positivo y tener hasta dos decimales.');
    }
    if (!preg_match('/^\d{1,9}$/', $stock)) {
        throw new InvalidArgumentException('La existencia debe ser un numero entero positivo.');
    }

    return [$name, $category, $price, (int) $stock];
}

function mbLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$isHealth = isset($_GET['health']);
try {
    $db = inventarioDb();
    if ($isHealth) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'app' => 'inventario-php', 'database' => 'mysql']);
        exit;
    }
} catch (Throwable $error) {
    error_log('Error de base de datos: ' . $error->getMessage());
    http_response_code(503);
    if ($isHealth) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'database' => 'unavailable']);
    } else {
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Servicio no disponible</title>';
        echo '<style>body{font:16px system-ui;background:#f1f5f9;padding:4rem;color:#122235}</style>';
        echo '<h1>Conexion a MySQL pendiente</h1><p>Revisa las variables DB_* en Railway y los registros del servicio.</p>';
    }
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        go('error', 'La sesion vencio. Recarga la pagina e intenta de nuevo.');
    }
    try {
        $action = (string) ($_POST['accion'] ?? '');
        if ($action === 'crear' || $action === 'actualizar') {
            [$name, $category, $price, $stock] = validProduct($_POST);
            if ($action === 'crear') {
                $stmt = $db->prepare('INSERT INTO productos (nombre, categoria, precio, existencia) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $category, $price, $stock]);
                go('ok', 'Producto registrado correctamente.');
            }
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                throw new InvalidArgumentException('Identificador no valido.');
            }
            $stmt = $db->prepare('UPDATE productos SET nombre=?, categoria=?, precio=?, existencia=? WHERE id=?');
            $stmt->execute([$name, $category, $price, $stock, $id]);
            go('ok', 'Producto actualizado correctamente.');
        }
        if ($action === 'eliminar') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) {
                throw new InvalidArgumentException('Identificador no valido.');
            }
            $stmt = $db->prepare('DELETE FROM productos WHERE id=?');
            $stmt->execute([$id]);
            go('ok', 'Producto eliminado correctamente.');
        }
        throw new InvalidArgumentException('Accion no reconocida.');
    } catch (InvalidArgumentException $error) {
        go('error', $error->getMessage());
    } catch (Throwable $error) {
        error_log('Operacion CRUD fallida: ' . $error->getMessage());
        go('error', 'No se pudo guardar el cambio. Consulta los registros.');
    }
}

$search = trim((string) ($_GET['buscar'] ?? ''));
if ($search !== '') {
    $stmt = $db->prepare('SELECT * FROM productos WHERE nombre LIKE ? OR categoria LIKE ? ORDER BY id DESC');
    $term = '%' . $search . '%';
    $stmt->execute([$term, $term]);
    $products = $stmt->fetchAll();
} else {
    $products = $db->query('SELECT * FROM productos ORDER BY id DESC')->fetchAll();
}
$stats = $db->query('SELECT COUNT(*) AS cantidad, COALESCE(SUM(existencia),0) AS unidades, COALESCE(SUM(precio * existencia),0) AS valor FROM productos')->fetch();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$editingId = filter_var($_GET['editar'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editing = null;
if ($editingId) {
    $stmt = $db->prepare('SELECT * FROM productos WHERE id=?');
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch() ?: null;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Nexo Inventario | PHP + MySQL</title>
  <link rel="stylesheet" href="/estilos.css">
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="logo"><span class="logo-mark">N</span><span>NEXO <small>GESTION CLOUD</small></span></div>
      <div class="nav-label">ESPACIO DE TRABAJO</div>
      <a class="nav active" href="/">▦ <span>Inventario</span></a>
      <a class="nav" href="/?health=1">◈ <span>Estado de servicio</span></a>
      <div class="sidebar-bottom"><span class="status-dot"></span> Servicio PHP conectado<br><small>Railway · MySQL</small></div>
    </aside>
    <main class="main">
      <header class="topbar"><div><div class="eyebrow">PANEL DE ADMINISTRACION</div><h1>Control de inventario</h1><p class="subtitle">Administra productos, existencias y precios desde la nube.</p></div><div class="badge">PHP 8.3 <span>·</span> MySQL</div></header>
      <?php if ($flash): ?><div class="alert <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>
      <div class="stats">
        <section class="stat"><div class="stat-label">Productos registrados</div><strong><?= e($stats['cantidad']) ?></strong><div class="stat-bottom">Items en catalogo</div></section>
        <section class="stat"><div class="stat-label">Unidades disponibles</div><strong><?= e($stats['unidades']) ?></strong><div class="stat-bottom">Stock total</div></section>
        <section class="stat"><div class="stat-label">Valor del inventario</div><strong>$<?= number_format((float) $stats['valor'], 2) ?></strong><div class="stat-bottom">Valor estimado en USD</div></section>
      </div>
      <div class="workspace">
        <section class="panel form-panel"><div class="panel-head"><div><span class="kicker">GESTION RAPIDA</span><h2><?= $editing ? 'Editar producto' : 'Nuevo producto' ?></h2></div><span class="step">01</span></div>
          <p class="support">Completa los campos para <?= $editing ? 'guardar los cambios' : 'incorporar un articulo' ?>.</p>
          <form method="post" action="/">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="accion" value="<?= $editing ? 'actualizar' : 'crear' ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= e($editing['id']) ?>"><?php endif; ?>
            <label>Nombre del producto<input required maxlength="120" name="nombre" placeholder="Ej. Teclado mecanico" value="<?= e($editing['nombre'] ?? '') ?>"></label>
            <label>Categoria<input required maxlength="80" name="categoria" placeholder="Ej. Accesorios" value="<?= e($editing['categoria'] ?? '') ?>"></label>
            <div class="form-grid"><label>Precio (USD)<input required type="number" min="0" max="99999999.99" step="0.01" name="precio" placeholder="0.00" value="<?= e($editing['precio'] ?? '') ?>"></label><label>Unidades<input required type="number" min="0" max="999999999" step="1" name="existencia" placeholder="0" value="<?= e($editing['existencia'] ?? '') ?>"></label></div>
            <button class="primary" type="submit"><?= $editing ? 'Guardar cambios' : '+ Registrar producto' ?></button>
            <?php if ($editing): ?><a class="cancel" href="/">Cancelar edicion</a><?php endif; ?>
          </form>
        </section>
        <section class="panel table-panel"><div class="panel-head"><div><span class="kicker">CATALOGO EN TIEMPO REAL</span><h2>Productos</h2></div><span class="step">02</span></div>
          <form class="search" method="get" action="/"><input name="buscar" placeholder="Buscar por nombre o categoria" value="<?= e($search) ?>"><button type="submit">Buscar</button><?php if ($search): ?><a href="/">Limpiar</a><?php endif; ?></form>
          <div class="table-wrap"><table><thead><tr><th>PRODUCTO</th><th>PRECIO</th><th>STOCK</th><th>ACCIONES</th></tr></thead><tbody>
            <?php foreach ($products as $product): ?><tr><td><strong><?= e($product['nombre']) ?></strong><small><?= e($product['categoria']) ?> · #<?= e($product['id']) ?></small></td><td>$<?= number_format((float) $product['precio'], 2) ?></td><td><span class="stock <?= (int) $product['existencia'] === 0 ? 'empty' : '' ?>"><?= e($product['existencia']) ?> un.</span></td><td><div class="actions"><a href="/?editar=<?= e($product['id']) ?>">Editar</a><form method="post" action="/" onsubmit="return confirm('¿Eliminar este producto?');"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= e($product['id']) ?>"><button type="submit">Eliminar</button></form></div></td></tr><?php endforeach; ?>
            <?php if (!$products): ?><tr><td colspan="4" class="empty-message"><?= $search ? 'No se encontraron productos.' : 'Tu inventario esta vacio. Registra tu primer producto desde el formulario.' ?></td></tr><?php endif; ?>
          </tbody></table></div>
        </section>
      </div>
      <footer>PARCIAL 2 · INFORMATICA EN LA NUBE <span>Aplicacion A — PHP / MySQL / Railway</span></footer>
    </main>
  </div>
</body>
</html>
