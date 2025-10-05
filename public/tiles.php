<?php
// public/tiles.php — POST-first, duplicate-safe for sizes & tiles
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

$pdo = Database::pdo();

/* ---------- helpers ---------- */
function P($k,$d=null){ return $_POST[$k]??$d; }
function Pn($k){ $v=P($k,0); return is_numeric($v)?(float)$v:0.0; }
function Pid($k){ $v=P($k,0); return is_numeric($v)?(int)$v:0; }

/* ===========================================================
   HANDLE POST (before any HTML to avoid header warnings)
   =========================================================== */

/* Add / Update Size */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_size'])) {
  $id    = Pid('size_id');
  $label = trim((string)P('label',''));
  $spb   = Pn('sqft_per_box');

  if ($label === '') {
    safe_redirect('tiles.php?err='.urlencode('Label is required'));
  }

  if ($id > 0) {
    $dup = $pdo->prepare("SELECT id FROM tile_sizes WHERE label=? AND id<>?");
    $dup->execute([$label, $id]);
    if ($dup->fetchColumn()) {
      safe_redirect('tiles.php?err='.urlencode('Size label already exists. Use that row instead.'));
    }
    $stmt = $pdo->prepare("UPDATE tile_sizes SET label=?, sqft_per_box=? WHERE id=?");
    $stmt->execute([$label, $spb, $id]);
    safe_redirect('tiles.php?msg='.urlencode('Size updated').'#size'.$id);
  } else {
    // Upsert by label
    $stmt = $pdo->prepare("SELECT id FROM tile_sizes WHERE label=?");
    $stmt->execute([$label]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
      $stmt = $pdo->prepare("UPDATE tile_sizes SET sqft_per_box=? WHERE id=?");
      $stmt->execute([$spb, $existingId]);
      safe_redirect('tiles.php?msg='.urlencode('Existing size updated').'#size'.$existingId);
    } else {
      $stmt = $pdo->prepare("INSERT INTO tile_sizes(label, sqft_per_box) VALUES(?,?)");
      $stmt->execute([$label, $spb]);
      $new = (int)$pdo->lastInsertId();
      safe_redirect('tiles.php?msg='.urlencode('Size added').'#size'.$new);
    }
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_size'])) {
  $id = Pid('size_id');
  if ($id > 0) {
    try {
      $pdo->prepare("DELETE FROM tile_sizes WHERE id=?")->execute([$id]);
      safe_redirect('tiles.php?msg='.urlencode('Size deleted'));
    } catch (PDOException $e) {
      safe_redirect('tiles.php?err='.urlencode('Cannot delete: size is in use.'));
    }
  }
  safe_redirect('tiles.php');
}

/* Add / Update Tile — prevent duplicates by (name, size_id) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_tile'])) {
  $id      = Pid('tile_id');
  $name    = trim((string)P('name',''));
  $size_id = Pid('size_id_fk');

  if ($name === '' || $size_id <= 0) {
    safe_redirect('tiles.php?err='.urlencode('Name and Size are required'));
  }

  if ($id > 0) {
    $chk = $pdo->prepare("SELECT id FROM tiles WHERE name=? AND size_id=? AND id<>?");
    $chk->execute([$name, $size_id, $id]);
    if ($chk->fetchColumn()) {
      safe_redirect('tiles.php?err='.urlencode('Tile with same name & size already exists.'));
    }
    $stmt = $pdo->prepare("UPDATE tiles SET name=?, size_id=? WHERE id=?");
    $stmt->execute([$name, $size_id, $id]);
    safe_redirect('tiles.php?msg='.urlencode('Tile updated').'#tile'.$id);
  } else {
    $chk = $pdo->prepare("SELECT id FROM tiles WHERE name=? AND size_id=?");
    $chk->execute([$name, $size_id]);
    if ($chk->fetchColumn()) {
      safe_redirect('tiles.php?err='.urlencode('Tile already exists. No new row created.'));
    }
    $stmt = $pdo->prepare("INSERT INTO tiles(name, size_id) VALUES(?,?)");
    $stmt->execute([$name, $size_id]);
    $new = (int)$pdo->lastInsertId();
    safe_redirect('tiles.php?msg='.urlencode('Tile added').'#tile'.$new);
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del_tile'])) {
  $id = Pid('tile_id');
  if ($id > 0) {
    try {
      $pdo->prepare("DELETE FROM tiles WHERE id=?")->execute([$id]);
      safe_redirect('tiles.php?msg='.urlencode('Tile deleted'));
    } catch (PDOException $e) {
      safe_redirect('tiles.php?err='.urlencode('Cannot delete: tile is referenced.'));
    }
  }
  safe_redirect('tiles.php');
}

/* ============================
   Fetch for rendering
   ============================ */
$sizes = $pdo->query("SELECT * FROM tile_sizes ORDER BY id DESC")
             ->fetchAll(PDO::FETCH_ASSOC);

$tiles = $pdo->query("
  SELECT t.id, t.name, ts.label AS size_label, ts.id AS size_id
  FROM tiles t
  JOIN tile_sizes ts ON ts.id = t.size_id
  ORDER BY t.id DESC LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

/* ============================
   Render (safe to output now)
   ============================ */
$page_title = "Tiles & Sizes";
require_once __DIR__ . '/../includes/header.php';
?>
<?php if (!empty($_GET['msg'])): ?>
  <div class="alert alert-success py-2"><?= h($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['err'])): ?>
  <div class="alert alert-danger  py-2"><?= h($_GET['err']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-5">
    <div class="card p-3">
      <h5>Add / Edit Size</h5>
      <form method="post" class="row g-2">
        <input type="hidden" name="size_id" value="0">
        <div class="col-8">
          <label class="form-label">Label</label>
          <input class="form-control" name="label" placeholder="e.g. 12*18" required>
        </div>
        <div class="col-4">
          <label class="form-label">Sqft/Box</label>
          <input class="form-control" type="number" step="0.01" name="sqft_per_box" value="0" required>
        </div>
        <div class="col-12">
          <button class="btn btn-primary" name="save_size" value="1">Save Size</button>
        </div>
      </form>

      <hr>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead><tr><th>#</th><th>Label</th><th>Sqft/Box</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach($sizes as $s): ?>
            <tr id="size<?= (int)$s['id'] ?>">
              <td>#<?= (int)$s['id'] ?></td>
              <td><?= h($s['label']) ?></td>
              <td><?= n2($s['sqft_per_box']) ?></td>
              <td>
                <form method="post" class="d-inline">
                  <input type="hidden" name="size_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" name="del_size" value="1"
                    onclick="return confirm('Delete this size?')">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-7">
    <div class="card p-3">
      <h5>Add / Edit Tile</h5>
      <form method="post" class="row g-2">
        <input type="hidden" name="tile_id" value="0">
        <div class="col-md-6">
          <label class="form-label">Tile Name</label>
          <input class="form-control" name="name" placeholder="e.g. Ummehabiba" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Size</label>
          <select class="form-select" name="size_id_fk" required>
            <option value="">Choose size…</option>
            <?php foreach($sizes as $s): ?>
              <option value="<?= (int)$s['id'] ?>">
                <?= h($s['label']) ?> (<?= n2($s['sqft_per_box']) ?> sqft/box)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <button class="btn btn-success" name="save_tile" value="1">Save Tile</button>
        </div>
      </form>

      <hr>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead><tr><th>#</th><th>Name</th><th>Size</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach($tiles as $t): ?>
              <tr id="tile<?= (int)$t['id'] ?>">
                <td>#<?= (int)$t['id'] ?></td>
                <td><?= h($t['name']) ?></td>
                <td><?= h($t['size_label']) ?></td>
                <td>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="tile_id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" name="del_tile" value="1"
                      onclick="return confirm('Delete this tile?')">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
