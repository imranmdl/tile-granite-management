<?php
// public/_calc_tile_cost.php
// Provides availability & weighted average cost for a tile.
// Returns both numeric and associative keys to be compatible with existing code.

if (!function_exists('tile_availability_and_cost')) {
  function tile_availability_and_cost(PDO $pdo, int $tile_id): array {
    // 1) sqft per box
    $st = $pdo->prepare("
      SELECT s.sqft_per_box
      FROM tiles t
      JOIN tile_sizes s ON s.id = t.size_id
      WHERE t.id = :tile_id
    ");
    $st->execute([':tile_id' => $tile_id]);
    $sqft_per_box = (float)($st->fetchColumn() ?: 0);
    if ($sqft_per_box <= 0) { $sqft_per_box = 1.0; } // safety

    // 2) Lifetime inventory: good boxes + total amount paid
    $invSql = "
      SELECT
        COALESCE(SUM(
          boxes_in
          - COALESCE(damage_boxes,0)
          - (COALESCE(damage_sqft,0) / :sq)
        ),0) AS good_boxes,

        COALESCE(SUM(
          -- base per box
          (CASE
             WHEN per_box_value  > 0 THEN per_box_value
             WHEN per_sqft_value > 0 THEN per_sqft_value * :sq
             ELSE 0
           END) * boxes_in
          -- percent transport on base
          + (CASE
               WHEN per_box_value  > 0 THEN per_box_value
               WHEN per_sqft_value > 0 THEN per_sqft_value * :sq
               ELSE 0
             END) * (COALESCE(transport_pct,0)/100.0) * boxes_in
          -- per-box transport
          + COALESCE(transport_per_box,0) * boxes_in
          -- lump-sum transport
          + COALESCE(transport_total,0)
        ),0) AS total_amount_paid
      FROM inventory_items
      WHERE tile_id = :tile_id
    ";
    $invSt = $pdo->prepare($invSql);
    $invSt->execute([':tile_id' => $tile_id, ':sq' => $sqft_per_box]);
    $inv = $invSt->fetch(PDO::FETCH_ASSOC) ?: ['good_boxes'=>0,'total_amount_paid'=>0];
    $good_boxes = (float)$inv['good_boxes'];
    $total_amount_paid = (float)$inv['total_amount_paid'];

    // 3) Lifetime sold & returned
    $soldSt = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_items WHERE tile_id = :tile_id");
    $soldSt->execute([':tile_id' => $tile_id]);
    $boxes_sold = (float)$soldSt->fetchColumn();

    $retSt = $pdo->prepare("SELECT COALESCE(SUM(boxes_decimal),0) FROM invoice_return_items WHERE tile_id = :tile_id");
    $retSt->execute([':tile_id' => $tile_id]);
    $boxes_returned = (float)$retSt->fetchColumn();

    // 4) Availability & average cost
    $available_boxes   = round($good_boxes - $boxes_sold + $boxes_returned, 3);
    $avg_cost_per_box  = ($good_boxes > 0) ? ($total_amount_paid / $good_boxes) : 0.0;
    $avg_cost_per_sqft = ($sqft_per_box > 0) ? ($avg_cost_per_box / $sqft_per_box) : 0.0;

    // Return both numeric and associative keys for maximum compatibility
    return [
      // numeric (for list(...) destructuring)
      0 => $available_boxes,
      1 => $avg_cost_per_box,
      2 => $sqft_per_box,

      // associative (for named access)
      'sqft_per_box'      => $sqft_per_box,
      'good_boxes'        => $good_boxes,
      'boxes_sold'        => $boxes_sold,
      'boxes_returned'    => $boxes_returned,
      'available_boxes'   => $available_boxes,
      'total_amount_paid' => $total_amount_paid,

      // aliases
      'avg_cost_box'      => $avg_cost_per_box,
      'avg_cost_per_box'  => $avg_cost_per_box,
      'avg_cost_sqft'     => $avg_cost_per_sqft,
    ];
  }
}
