<?php

$db7 = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
echo "=== ACCESS FILTERS ===\n";
if ($db7->schema()->tableExists('access_filter')) {
  $filters = $db7->select('access_filter', 'af')->fields('af')->execute()->fetchAll();
  print_r($filters);
}

echo "\n=== D7 ROLES ===\n";
$roles = $db7->select('role', 'r')->fields('r')->execute()->fetchAllKeyed(0, 1);
print_r($roles);

echo "\n=== D7 MENU LINKS WITH ROLES_FOR_MENU OR ROUTER_PATH ===\n";
$links = $db7->select('menu_links', 'ml')
  ->fields('ml', ['mlid', 'plid', 'menu_name', 'link_path', 'router_path', 'link_title', 'options', 'hidden', 'weight'])
  ->condition('menu_name', ['management', 'navigation', 'devel'], 'NOT IN')
  ->orderBy('menu_name')
  ->orderBy('weight')
  ->execute()
  ->fetchAll();

foreach ($links as $l) {
  $opt = @unserialize($l->options);
  $rfm = $opt['roles_for_menu'] ?? null;
  // Also check menu_router access_callback / access_arguments
  $router = $db7->select('menu_router', 'mr')
    ->fields('mr', ['path', 'access_callback', 'access_arguments'])
    ->condition('path', $l->router_path)
    ->execute()
    ->fetchObject();
  $acc_args = $router ? @unserialize($router->access_arguments) : null;
  echo sprintf(
    "[%s] mlid=%d plid=%d hidden=%d title='%s' path='%s' rfm=%s access_cb=%s\n",
    $l->menu_name,
    $l->mlid,
    $l->plid,
    $l->hidden,
    $l->link_title,
    $l->link_path,
    json_encode($rfm),
    $router ? $router->access_callback . ':' . json_encode($acc_args) : 'NONE'
  );
}

