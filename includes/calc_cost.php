<?php
// includes/calc_cost.php
// Robust, SQLite-safe cost helpers.

/* ---------------- common: SQLite date parsing ---------------- */
/*
   Returns an expression that yields a normalized ISO date (YYYY-MM-DD) or NULL.
   Works for values stored as YYYY-MM-DD or DD-MM-YYYY (and ignores others).
*/
function _sqlite_norm_date_expr(string $col): string {
    $safe = preg_replace('/[^A-Za-z0-9_\.]/', '', $col);
    return "CASE
              WHEN $safe GLOB '____-__-__' THEN $safe
              WHEN $safe GLOB '__-__-____'
                THEN substr($safe,7,4)||'-'||substr($safe,4,2)||'-'||substr($safe,1,2)
              ELSE NULL
            END";
}

/* ---------------- inventory snapshot for stock card ---------------- */
/**
 * tile_availability_and_cost
 * Returns [avail_boxes, weighted_cost_per_box_incl, sqft_per_box]
 */
function tile_availability_and_cost(PDO $pdo, int $tile_id): array {
    // sqft/box
    $st = $pdo->prepare("
        SELECT ts.sqft_per_box
        FROM tiles t
        JOIN tile_sizes ts ON ts.id=t.size_id
        WHERE t.id=?
    ");
    $st->execute([$tile_id]);
    $spb = (float)($st->fetchColumn() ?: 0);

    // good received
    $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_in - COALESCE(damage_boxes,0)),0) FROM inventory_items WHERE tile_id=?");
    $st->execute([$tile_id]);
    $good = (float)$st->fetchColumn();

    // sold
    $st = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_items WHERE tile_id=?");
    $st->execute([$tile_id]);
    $sold = (float)$st->fetchColumn();

    $avail = $good - $sold;

    // weighted avg over all receipts
    $st = $pdo->prepare("
        SELECT per_box_value, per_sqft_value,
               transport_pct, transport_per_box, transport_total,
               MAX(boxes_in - COALESCE(damage_boxes,0), 0) AS qty
        FROM inventory_items
        WHERE tile_id=?
    ");
    $st->execute([$tile_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $csum=0.0; $qty=0.0;
    foreach ($rows as $r) {
        $base = (float)($r['per_box_value'] ?? 0);
        if ($base <= 0 && $spb > 0 && (float)($r['per_sqft_value'] ?? 0) > 0) {
            $base = (float)$r['per_sqft_value'] * $spb;
        }
        $trans = 0.0;
        if ((float)($r['transport_pct'] ?? 0) > 0)     $trans += $base * ((float)$r['transport_pct']/100.0);
        if ((float)($r['transport_per_box'] ?? 0) > 0) $trans += (float)$r['transport_per_box'];
        $goodQty = max(0.0,(float)($r['qty'] ?? 0));
        if ((float)($r['transport_total'] ?? 0) > 0 && $goodQty > 0) {
            $trans += (float)$r['transport_total'] / $goodQty;
        }
        $eff = $base + $trans;
        $csum += $eff * $goodQty;
        $qty  += $goodQty;
    }
    $cpb = $qty > 0 ? $csum / $qty : 0.0;
    return [$avail, $cpb, $spb];
}

/* ---------------- internal selectors that SKIP zero-cost rows ---------------- */
function _select_tile_cost_row(PDO $pdo, int $tile_id, string $asof): array {
    $norm = _sqlite_norm_date_expr('ii.purchase_dt');

    // First: as-of (take several rows; pick first base>0)
    $sql = "
      SELECT
        CASE
          WHEN COALESCE(ii.per_box_value, 0) > 0
            THEN ii.per_box_value
          WHEN ii.per_sqft_value > 0 AND ts.sqft_per_box > 0
            THEN ii.per_sqft_value * ts.sqft_per_box
          ELSE 0
        END AS base,
        COALESCE(ii.transport_pct,0)     AS pct,
        COALESCE(ii.transport_per_box,0) AS adder,
        COALESCE(ii.transport_total,0)   AS ttotal,
        MAX(ii.boxes_in - COALESCE(ii.damage_boxes,0), 0) AS good_qty
      FROM inventory_items ii
      JOIN tiles t       ON t.id=ii.tile_id
      JOIN tile_sizes ts ON ts.id=t.size_id
      WHERE ii.tile_id=:id
        AND (
              $norm IS NULL
           OR date($norm) <= date(:asof)
        )
      ORDER BY ($norm IS NULL) DESC, date($norm) DESC, ii.id DESC
      LIMIT 40
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':id'=>$tile_id, ':asof'=>$asof]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $base = (float)$r['base'];
        if ($base > 0) {
            return ['base'=>$base,'pct'=>(float)$r['pct'],'adder'=>(float)$r['adder'],
                    'ttotal'=>(float)$r['ttotal'],'good_qty'=>(float)$r['good_qty'],'reason'=>'asof-hit'];
        }
    }

    // Fallback: latest regardless of date
    $sql2 = "
      SELECT
        CASE
          WHEN COALESCE(ii.per_box_value, 0) > 0
            THEN ii.per_box_value
          WHEN ii.per_sqft_value > 0 AND ts.sqft_per_box > 0
            THEN ii.per_sqft_value * ts.sqft_per_box
          ELSE 0
        END AS base,
        COALESCE(ii.transport_pct,0)     AS pct,
        COALESCE(ii.transport_per_box,0) AS adder,
        COALESCE(ii.transport_total,0)   AS ttotal,
        MAX(ii.boxes_in - COALESCE(ii.damage_boxes,0), 0) AS good_qty
      FROM inventory_items ii
      JOIN tiles t       ON t.id=ii.tile_id
      JOIN tile_sizes ts ON ts.id=t.size_id
      WHERE ii.tile_id=:id
      ORDER BY ($norm IS NULL) DESC, date($norm) DESC, ii.id DESC
      LIMIT 60
    ";
    $st = $pdo->prepare($sql2);
    $st->execute([':id'=>$tile_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $base = (float)$r['base'];
        if ($base > 0) {
            return ['base'=>$base,'pct'=>(float)$r['pct'],'adder'=>(float)$r['adder'],
                    'ttotal'=>(float)$r['ttotal'],'good_qty'=>(float)$r['good_qty'],'reason'=>'fallback-latest'];
        }
    }

    return ['base'=>0,'pct'=>0,'adder'=>0,'ttotal'=>0,'good_qty'=>0,'reason'=>'no-cost-rows'];
}

function _select_misc_cost_row(PDO $pdo, int $misc_item_id, string $asof): array {
    $norm = _sqlite_norm_date_expr('r.recvd_dt');

    $sql = "
      SELECT
        COALESCE(r.cost_per_unit,0)      AS base,
        COALESCE(r.transport_pct,0)      AS pct,
        COALESCE(r.transport_per_unit,0) AS adder,
        COALESCE(r.transport_total,0)    AS ttotal,
        MAX(r.qty_in - COALESCE(r.damage_units,0), 0) AS good_qty
      FROM misc_inventory_items r
      WHERE r.misc_item_id=:id
        AND ( $norm IS NULL OR date($norm) <= date(:asof) )
      ORDER BY ($norm IS NULL) DESC, date($norm) DESC, r.id DESC
      LIMIT 40
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':id'=>$misc_item_id, ':asof'=>$asof]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ((float)$r['base'] > 0) {
            return ['base'=>(float)$r['base'],'pct'=>(float)$r['pct'],'adder'=>(float)$r['adder'],
                    'ttotal'=>(float)$r['ttotal'],'good_qty'=>(float)$r['good_qty'],'reason'=>'asof-hit'];
        }
    }

    $sql2 = "
      SELECT
        COALESCE(r.cost_per_unit,0)      AS base,
        COALESCE(r.transport_pct,0)      AS pct,
        COALESCE(r.transport_per_unit,0) AS adder,
        COALESCE(r.transport_total,0)    AS ttotal,
        MAX(r.qty_in - COALESCE(r.damage_units,0), 0) AS good_qty
      FROM misc_inventory_items r
      WHERE r.misc_item_id=:id
      ORDER BY ($norm IS NULL) DESC, date($norm) DESC, r.id DESC
      LIMIT 60
    ";
    $st = $pdo->prepare($sql2);
    $st->execute([':id'=>$misc_item_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ((float)$r['base'] > 0) {
            return ['base'=>(float)$r['base'],'pct'=>(float)$r['pct'],'adder'=>(float)$r['adder'],
                    'ttotal'=>(float)$r['ttotal'],'good_qty'=>(float)$r['good_qty'],'reason'=>'fallback-latest'];
        }
    }

    return ['base'=>0,'pct'=>0,'adder'=>0,'ttotal'=>0,'good_qty'=>0,'reason'=>'no-cost-rows'];
}

/* ---------------- public API used by reports ---------------- */
function cost_tile_per_box_asof(PDO $pdo, int $tile_id, string $asof, string $mode='simple'): array {
    $hit = _select_tile_cost_row($pdo, $tile_id, $asof);
    $base     = (float)$hit['base'];
    $pct      = (float)$hit['pct'];
    $adder    = (float)$hit['adder'];
    $ttotal   = (float)$hit['ttotal'];
    $good_qty = (float)$hit['good_qty'];

    $pct_amt = round($base * $pct / 100.0, 2);
    $alloc   = ($good_qty > 0 && $ttotal > 0) ? round($ttotal / $good_qty, 2) : 0.0;
    $cp_simple   = round($base + $pct_amt + $adder, 2);
    $cp_detailed = round($cp_simple + $alloc, 2);

    return [
        'base'   => $base,
        'pct'    => $pct,
        'pct_amt'=> $pct_amt,
        'adder'  => $adder,
        'alloc'  => $alloc,
        'cp'     => ($mode==='detailed') ? $cp_detailed : $cp_simple,
        'why'    => $hit['reason'],
    ];
}

function cost_misc_per_unit_asof(PDO $pdo, int $misc_item_id, string $asof, string $mode='simple'): array {
    $hit = _select_misc_cost_row($pdo, $misc_item_id, $asof);
    $base     = (float)$hit['base'];
    $pct      = (float)$hit['pct'];
    $adder    = (float)$hit['adder'];
    $ttotal   = (float)$hit['ttotal'];
    $good_qty = (float)$hit['good_qty'];

    $pct_amt = round($base * $pct / 100.0, 2);
    $alloc   = ($good_qty > 0 && $ttotal > 0) ? round($ttotal / $good_qty, 4) : 0.0;
    $cp_simple   = round($base + $pct_amt + $adder, 4);
    $cp_detailed = round($cp_simple + $alloc, 4);

    return [
        'base'=>$base,'pct'=>$pct,'pct_amt'=>$pct_amt,'adder'=>$adder,'alloc'=>$alloc,
        'cp'=> ($mode==='detailed') ? $cp_detailed : $cp_simple,
        'why'=>$hit['reason'],
    ];
}
