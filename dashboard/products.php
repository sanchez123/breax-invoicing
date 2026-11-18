<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_auth();
$pdo = db();
$error = '';
$cycles = ['One-time', 'Monthly', 'Quarterly', 'Yearly'];
$categories = ['Domain', 'Google Workspace', 'Hosting', 'SSL', 'Website Design', 'Graphics Design', 'Online Branding'];
foreach ($pdo->query("SELECT DISTINCT category FROM products WHERE category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN) as $existingCategory) {
    $existingCategory = trim((string) $existingCategory);
    if ($existingCategory !== '' && !in_array($existingCategory, ['Design', 'Other'], true) && !in_array($existingCategory, $categories, true)) {
        $categories[] = $existingCategory;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save';
        if ($action === 'delete') {
            $statement = $pdo->prepare("UPDATE products SET status='archived' WHERE id=?");
            $statement->execute([filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0]);
            flash('Product or service deleted.');
            redirect('/dashboard/products.php');
        }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $name = post_string('name', 190, true);
        $category = post_string('category', 80, true);
        $cycle = post_string('billing_cycle', 40, true);
        if (!in_array($category, $categories, true) || !in_array($cycle, $cycles, true)) {
            throw new InvalidArgumentException('Choose a valid category and billing cycle.');
        }
        $restoredProduct = false;
        if ($id > 0) {
            $statement = $pdo->prepare('UPDATE products SET name=?,category=?,billing_cycle=? WHERE id=?');
            $statement->execute([$name, $category, $cycle, $id]);
        } else {
            $pdo->beginTransaction();
            try {
                $existingProduct = $pdo->prepare('SELECT id,status FROM products WHERE name=? FOR UPDATE');
                $existingProduct->execute([$name]);
                $existing = $existingProduct->fetch();
                if ($existing && $existing['status'] === 'active') {
                    throw new InvalidArgumentException('A product or service with this name already exists.');
                }
                if ($existing) {
                    $statement = $pdo->prepare("UPDATE products SET category=?,billing_cycle=?,status='active' WHERE id=?");
                    $statement->execute([$category, $cycle, $existing['id']]);
                    $restoredProduct = true;
                } else {
                    $statement = $pdo->prepare('INSERT INTO products (name,category,billing_cycle) VALUES (?,?,?)');
                    $statement->execute([$name, $category, $cycle]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
        flash($id > 0 ? 'Product updated.' : ($restoredProduct ? 'Product or service restored.' : 'Product added.'));
        redirect('/dashboard/products.php');
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Product save failed: ' . $exception->getMessage());
        $error = (int) ($exception->errorInfo[1] ?? 0) === 1062
            ? 'A product or service with this name already exists.'
            : 'The product or service could not be saved.';
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT id,name,category,billing_cycle FROM products WHERE id=?');
    $statement->execute([filter_var($_GET['edit'], FILTER_VALIDATE_INT) ?: 0]);
    $editing = $statement->fetch() ?: null;
} elseif ($error !== '' && (filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0) > 0) {
    $statement = $pdo->prepare('SELECT id,name,category,billing_cycle FROM products WHERE id=?');
    $statement->execute([filter_var($_POST['id'], FILTER_VALIDATE_INT)]);
    $editing = $statement->fetch() ?: null;
}
$showCreateForm = isset($_GET['new'])
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && (filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0) === 0);
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$products = [];
if (!$showCreateForm) {
    $statement = $pdo->prepare('SELECT id,name,category,billing_cycle FROM products WHERE status="active" AND (name LIKE ? OR category LIKE ?) ORDER BY category,name LIMIT 200');
    $term = '%' . $search . '%';
    $statement->execute([$term, $term]);
    $products = $statement->fetchAll();
}
$newProductCycle = in_array($_POST['billing_cycle'] ?? null, $cycles, true)
    ? (string) $_POST['billing_cycle']
    : 'One-time';
$editingCycle = in_array($editing['billing_cycle'] ?? null, $cycles, true)
    ? (string) $editing['billing_cycle']
    : 'One-time';
$pageTitle = 'Products & Services';
$activePage = 'products';
$pageSubtitle = 'Manage your service catalogue';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg">Products &amp; services</h1><p class="page-head__sub">Set the names, categories, and billing cycles used on invoices.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="customer-page">
  <?php if ($showCreateForm): ?>
  <section class="card card__pad form-card">
    <div class="customer-editor__heading"><div><span class="eyebrow">NEW ITEM</span><h2 class="heading-lg">Add a product or service</h2><p class="card__sub">Configure the catalogue name, category, and billing cycle.</p></div></div>
    <form class="data-form data-form--grid products-form-grid" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="">
      <label class="field"><span class="field__label">Name *</span><input class="input" name="name" maxlength="190" data-validation-required="true" required value="<?= e($error !== '' && (int) ($_POST['id'] ?? 0) === 0 ? ($_POST['name'] ?? '') : '') ?>"></label>
      <label class="field"><span class="field__label">Category *</span><select class="input" name="category" data-validation-required="true" required><option value="">Choose a category</option><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"<?= $error !== '' && (int) ($_POST['id'] ?? 0) === 0 && (string) ($_POST['category'] ?? '') === $category ? ' selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="field__label">Billing cycle *</span><select class="input" name="billing_cycle" data-validation-required="true" required><?php foreach ($cycles as $cycle): ?><option<?= $newProductCycle === $cycle ? ' selected' : '' ?>><?= e($cycle) ?></option><?php endforeach; ?></select></label>
      <div class="form-actions field--wide"><button class="btn btn--primary btn--danger">Add item</button></div>
    </form>
  </section>
  <?php else: ?>
  <section class="card card__pad"><div class="card__head card__head--row"><div><h2 class="heading-lg">Service catalogue</h2><p class="card__sub"><?= count($products) ?> active items shown</p></div><a class="btn btn--danger" href="<?= e(app_url('/dashboard/products.php?new=1')) ?>">Create a Product or Service</a></div>
    <form class="filter-form filter-panel filter-panel--search" method="get" role="search" aria-label="Search products and services">
      <label class="field"><span class="field__label">Search catalogue</span><input class="input" type="search" name="q" data-table-search-for="service-catalogue" placeholder="e.g. hosting, domain, or SSL" value="<?= e($search) ?>"></label>
      <div class="filter-panel__actions"><button class="btn btn--primary" type="submit"><svg class="icon"><use href="#i-search"/></svg>Search catalogue</button><?php if ($search !== ''): ?><a class="btn btn--quiet" href="<?= e(app_url('/dashboard/products.php')) ?>">Clear</a><?php endif; ?></div>
    </form>
    <?php if ($products): ?><div class="table-wrap"><table id="service-catalogue" data-enhanced-table data-filter-columns="1,2" data-filter-labels="Category,Billing Cycle"><thead><tr><th>Item</th><th>Category</th><th>Billing Cycle</th><th>Actions</th></tr></thead><tbody><?php foreach ($products as $product): ?><tr><td><strong><?= e($product['name']) ?></strong></td><td><?= e($product['category']) ?></td><td><?= e($product['billing_cycle']) ?></td><td><div class="row-actions"><button class="action-pill action-pill--edit" type="button" data-product-edit data-id="<?= e($product['id']) ?>" data-name="<?= e($product['name']) ?>" data-category="<?= e($product['category']) ?>" data-cycle="<?= e($product['billing_cycle']) ?>">Edit</button><form method="post" data-confirm="Are you sure you want to delete this product or service? It will no longer be available for new invoices, but existing invoices will remain unchanged." data-confirm-title="Delete product or service" data-confirm-danger="true"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($product['id']) ?>"><button class="action-pill action-pill--delete" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty">No active products or services found.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php if (!$showCreateForm): ?>
<dialog class="invoice-payment-dialog" id="product-edit-dialog" aria-labelledby="product-edit-title" data-open="<?= $editing ? 'true' : 'false' ?>">
  <div class="invoice-payment-dialog__panel">
    <h2 id="product-edit-title">Edit product or service</h2>
    <form class="data-form" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
      <label class="field"><span class="field__label">Name *</span><input class="input" name="name" maxlength="190" required value="<?= e($error !== '' && $editing ? ($_POST['name'] ?? '') : ($editing['name'] ?? '')) ?>"></label>
      <label class="field"><span class="field__label">Category *</span><select class="input" name="category" required><option value="">Choose a category</option><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"<?= (string) ($error !== '' && $editing ? ($_POST['category'] ?? '') : ($editing['category'] ?? '')) === $category ? ' selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="field__label">Billing cycle *</span><select class="input" name="billing_cycle" required><?php foreach ($cycles as $cycle): ?><option<?= ($error !== '' && $editing ? (string) ($_POST['billing_cycle'] ?? $editingCycle) : $editingCycle) === $cycle ? ' selected' : '' ?>><?= e($cycle) ?></option><?php endforeach; ?></select></label>
      <div class="invoice-payment-dialog__actions"><button class="btn btn--quiet btn--accent" type="button" data-close-product-edit>Cancel</button><button class="btn btn--danger product-edit-save" type="submit">Save changes</button></div>
    </form>
  </div>
</dialog>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>