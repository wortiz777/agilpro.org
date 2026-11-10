<?php

$db7 = \Drupal\Core\Database\Database::getConnection('default', 'migrate');
$mods = $db7->select('system', 's')
  ->fields('s', ['name', 'status'])
  ->condition('type', 'module')
  ->condition('status', 1)
  ->execute()
  ->fetchAllKeyed(0, 1);
foreach ($mods as $name => $st) {
  if (stripos($name, 'menu') !== false || stripos($name, 'icon') !== false || stripos($name, 'role') !== false) {
    echo "Enabled D7 module: $name\n";
  }
}

$links = $db7->select('menu_links', 'ml')
  ->fields('ml', ['mlid', 'plid', 'menu_name', 'link_path', 'link_title', 'options', 'hidden'])
  ->condition('menu_name', ['management', 'navigation', 'devel', 'shortcut-set-1'], 'NOT IN')
  ->orderBy('menu_name')
  ->orderBy('weight')
  ->execute()
  ->fetchAll();

foreach ($links as $l) {
  $opt = @unserialize($l->options);
  echo sprintf("[%s] mlid=%d title='%s' options=%s\n", $l->menu_name, $l->mlid, $l->link_title, json_encode($opt));
}

