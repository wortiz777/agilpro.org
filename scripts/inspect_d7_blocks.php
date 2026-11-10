<?php

$db7 = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
echo "=== D7 ACTIVE BLOCKS IN SUBTEMA ===\n";
$blocks = $db7->select('block', 'b')
  ->fields('b')
  ->condition('theme', 'subtema')
  ->condition('status', 1)
  ->orderBy('region')
  ->orderBy('weight')
  ->execute()
  ->fetchAll();

foreach ($blocks as $b) {
  $roles = $db7->select('block_role', 'br')
    ->fields('br', ['rid'])
    ->condition('module', $b->module)
    ->condition('delta', $b->delta)
    ->execute()
    ->fetchCol();
  echo sprintf(
    "region=%-15s weight=%-4d module=%-10s delta=%-35s visibility=%d pages=%s roles=%s title='%s'\n",
    $b->region,
    $b->weight,
    $b->module,
    $b->delta,
    $b->visibility,
    json_encode($b->pages),
    json_encode($roles),
    $b->title
  );
}

