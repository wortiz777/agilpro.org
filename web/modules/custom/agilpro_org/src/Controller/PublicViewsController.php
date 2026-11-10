<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Controller;

use Drupal\agilpro_org\Service\RadarScoreCalculator;
use Drupal\agilpro_org\Service\WebformRadarDataProvider;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador de las vistas públicas y de navegación de usuarios en D11.
 *
 * Reproduce de forma fiel la estructura HTML, filtros expuestos, grillas
 * Bootstrap 3 y tablas de resumen de Drupal 7.
 */
final class PublicViewsController extends ControllerBase {

  /**
   * Las 10 Cámaras de Comercio participantes del proyecto en Mapeo y Canvas.
   *
   * @var array<int, string>
   */
  private const PROJECT_CAMARAS = [
    56 => 'Cámara de Comercio del Aburrá Sur',
    15 => 'Cámara de Comercio de Armenia por el Quindío',
    19 => 'Cámara de Comercio de Bucaramanga',
    21 => 'Cámara de Comercio de Cali',
    58 => 'Cámara de Comercio del Cauca',
    26 => 'Cámara de Comercio de Cúcuta',
    36 => 'Cámara de Comercio de Manizales por Caldas',
    39 => 'Cámara de Comercio de Neiva',
    43 => 'Cámara de Comercio de Pereira',
    46 => 'Cámara de Comercio de Santa Marta para el Magdalena',
  ];

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    AccountProxyInterface $currentUser,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
    protected readonly WebformRadarDataProvider $radarDataProvider,
    protected readonly RadarScoreCalculator $scoreCalculator,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('current_user'),
      $container->get('file_url_generator'),
      $container->get('agilpro_org.webform_radar_data_provider'),
      $container->get('agilpro_org.radar_score_calculator'),
    );
  }

  /**
   * Vista /emprendimientos-impulsados (Emprendimientos Impulsados).
   */
  public function emprendimientosImpulsados(Request $request): array {
    $selectedCamara = trim((string) $request->query->get('field_camara_tid', 'All'));
    $searchName = trim((string) $request->query->get('field_nombre_del_emprendimiento_value', ''));
    $page = max(0, (int) $request->query->get('page', 0));
    $perPage = 100;

    // Cargar todas las Cámaras de Comercio para el select.
    $camaraOptions = $this->loadAllCamarasOptions();

    $query = $this->database->select('users_field_data', 'u');
    $query->fields('u', ['uid']);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid AND ur.roles_target_id = :role', [
      ':role' => 'emprendedor_registrado',
    ]);
    $query->condition('u.status', 1);

    if ($selectedCamara !== '' && $selectedCamara !== 'All' && is_numeric($selectedCamara)) {
      $query->innerJoin('user__field_camara', 'fc', 'fc.entity_id = u.uid');
      $query->condition('fc.field_camara_target_id', (int) $selectedCamara);
    }

    if ($searchName !== '') {
      $query->innerJoin('user__field_nombre_del_emprendimiento', 'fn', 'fn.entity_id = u.uid');
      $query->condition('fn.field_nombre_del_emprendimiento_value', '%' . $this->database->escapeLike($searchName) . '%', 'LIKE');
    }

    $countQuery = clone $query;
    $total = (int) $countQuery->countQuery()->execute()->fetchField();

    $query->orderBy('u.created', 'DESC');
    $query->range($page * $perPage, $perPage);
    $uids = $query->execute()?->fetchCol() ?? [];

    /** @var array<int, UserInterface> $users */
    $users = $uids !== [] ? $this->entityTypeManager->getStorage('user')->loadMultiple($uids) : [];

    $start = $total > 0 ? ($page * $perPage) + 1 : 0;
    $end = min($total, ($page + 1) * $perPage);

    $optionsHtml = '<option value="All"' . ($selectedCamara === 'All' || $selectedCamara === '' ? ' selected="selected"' : '') . '>- Cualquiera -</option>';
    foreach ($camaraOptions as $tid => $name) {
      $sel = ((string) $tid === $selectedCamara) ? ' selected="selected"' : '';
      $optionsHtml .= '<option value="' . (int) $tid . '"' . $sel . '>' . Html::escape($name) . '</option>';
    }

    $html = '<div class="view view-emprendimientos-impulsados view-id-emprendimientos_impulsados view-display-id-page">';
    if ($total > 0) {
      $html .= '<div class="view-header"><h4>Mostrando ' . $start . ' - ' . $end . ' de ' . $total . '</h4></div>';
    }
    $html .= '<div class="view-filters"><form action="/emprendimientos-impulsados" method="get" id="views-exposed-form-emprendimientos-impulsados-page" accept-charset="UTF-8"><div><div class="views-exposed-form"><div class="views-exposed-widgets clearfix">';
    $html .= '<div id="edit-field-camara-tid-wrapper" class="views-exposed-widget views-widget-filter-field_camara_tid"><label for="edit-field-camara-tid">Buscar por Cámara de Comercio</label><div class="views-widget"><div class="form-item form-item-field-camara-tid form-type-select form-group"><select class="form-control form-select" id="edit-field-camara-tid" name="field_camara_tid">' . $optionsHtml . '</select></div></div></div>';
    $html .= '<div id="edit-field-nombre-del-emprendimiento-value-wrapper" class="views-exposed-widget views-widget-filter-field_nombre_del_emprendimiento_value"><label for="edit-field-nombre-del-emprendimiento-value">Buscar por Emprendimiento</label><div class="views-widget"><div class="form-item form-item-field-nombre-del-emprendimiento-value form-type-textfield form-group"><input class="form-control form-text" type="text" id="edit-field-nombre-del-emprendimiento-value" name="field_nombre_del_emprendimiento_value" value="' . Html::escape($searchName) . '" size="30" maxlength="128" /></div></div></div>';
    $html .= '<div class="views-exposed-widget views-submit-button"><button type="submit" id="edit-submit-emprendimientos-impulsados" value="Aplicar" class="btn btn-info form-submit">Aplicar</button></div>';
    $html .= '</div></div></div></form></div>';

    $html .= '<div class="view-content"><div id="views-bootstrap-thumbnail-1" class="views-bootstrap-thumbnail-plugin-style"><div class="row">';
    $index = 0;
    $mediumStyle = ImageStyle::load('medium');

    foreach ($users as $user) {
      $index++;
      $empName = $this->getFieldString($user, 'field_nombre_del_emprendimiento');
      $pres = $this->getFieldString($user, 'field_presentacion_emprendimient');
      if (mb_strlen($pres) > 400) {
        $pres = Unicode::truncate($pres, 400, TRUE, TRUE);
      }
      $ciudad = $this->getFieldString($user, 'field_ciudad_municipio');
      $email = $this->getFieldString($user, 'field_e_mail_contacto');
      $sectorName = $this->getTermLabel($user, 'field_sector_economico');

      $logoUri = 'public://default_images/emprendimiento.png';
      if ($user->hasField('field_logo_emprendimiento') && !$user->get('field_logo_emprendimiento')->isEmpty()) {
        $file = $user->get('field_logo_emprendimiento')->entity;
        if ($file instanceof FileInterface) {
          $candidateUri = $file->getFileUri();
          if (is_file($candidateUri)) {
            $logoUri = $candidateUri;
          }
        }
      }
      $logoHtml = '';
      if (is_file($logoUri)) {
        $url = $mediumStyle ? $mediumStyle->buildUrl($logoUri) : $this->fileUrlGenerator->generateString($logoUri);
        $logoHtml = '<img class="img-responsive" src="' . Html::escape($url) . '" width="220" alt="' . Html::escape($empName) . '" loading="lazy" />';
      }

      $html .= '<div class=" col-xs-12 col-sm-6 col-md-4 col-lg-3"><div class="thumbnail">';
      $html .= '<div class="views-field views-field-field-nombre-del-emprendimiento"><h1 class="field-content">' . Html::escape($empName) . '</h1></div>';
      $html .= '<div class="views-field views-field-field-logo-emprendimiento"><div class="field-content">' . $logoHtml . '</div></div>';
      $html .= '<div class="views-field views-field-field-presentacion-emprendimient"><div class="field-content">' . Html::escape($pres) . '</div></div>';
      $html .= '<div class="views-field views-field-field-sector-economico"><strong class="views-label views-label-field-sector-economico">Sector Económico: </strong><div class="field-content">' . Html::escape($sectorName) . '</div></div>';
      $html .= '<span class="views-field views-field-field-ciudad-municipio"><strong class="views-label views-label-field-ciudad-municipio">Ciudad: </strong><span class="field-content">' . Html::escape($ciudad) . '</span></span>';
      if ($email !== '') {
        $html .= '<div class="views-field views-field-field-e-mail-contacto"><div class="field-content"><a href="mailto:' . Html::escape($email) . '">' . Html::escape($email) . '</a></div></div>';
      }
      $html .= '</div></div>';

      if ($index % 2 === 0) {
        $html .= '<div class="clearfix visible-sm-block"></div>';
      }
      if ($index % 3 === 0) {
        $html .= '<div class="clearfix visible-md-block"></div>';
      }
      if ($index % 4 === 0) {
        $html .= '<div class="clearfix visible-lg-block"></div>';
      }
    }

    $html .= '</div></div></div>';
    $html .= $this->buildSimplePager('/emprendimientos-impulsados', $page, $perPage, $total, [
      'field_camara_tid' => $selectedCamara,
      'field_nombre_del_emprendimiento_value' => $searchName,
    ]);
    $html .= '</div>';

    $build = [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
    ];
    $cache = new CacheableMetadata();
    $cache->addCacheContexts(['url.query_args', 'user.roles']);
    $cache->addCacheTags(['user_list']);
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Vista /biblioteca-abierta y subpáginas /biblioteca/*.
   */
  public function bibliotecaAbierta(string $ubicacion = '1'): array {
    $ubicacionLabels = [
      '1' => ' Recursos Abiertos (todos los usuarios)',
      '2' => 'Fase Conectar',
      '3' => 'Fase Empoderar',
      '4' => 'Fase Fortalecer',
      '5' => 'Fase Acceder',
    ];

    $userRoles = $this->currentUser->getRoles();
    $isAnon = !$this->currentUser->isAuthenticated();

    // Si es una fase restringida (2..5) y el usuario es anónimo, mostrar candado.
    if ($ubicacion !== '1' && $isAnon) {
      return $this->buildBibliotecaEmptyMarkup();
    }

    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'biblioteca_abierta')
      ->condition('status', 1)
      ->condition('field_ubicaci_n_del_recurso', $ubicacion)
      ->sort('created', 'DESC')
      ->execute();

    if ($nids === []) {
      return $this->buildBibliotecaEmptyMarkup();
    }

    /** @var array<int, NodeInterface> $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $total = count($nodes);
    $ubicacionLabel = $ubicacionLabels[$ubicacion] ?? '';

    $html = '<div class="view view-biblioteca-abierta view-id-biblioteca_abierta view-display-id-page">';
    $html .= '<div class="view-header">Mostrando 1 - ' . $total . ' de ' . $total . '</div>';
    $html .= '<div class="view-content"><div id="views-bootstrap-thumbnail-1" class="views-bootstrap-thumbnail-plugin-style"><div class="row">';

    $index = 0;
    foreach ($nodes as $node) {
      $index++;
      $nodeUrl = $node->toUrl()->toString();
      $title = $node->label();
      $autor = $this->getFieldString($node, 'field_autor_es_');

      $fileHtml = '';
      if ($node->hasField('field_subir_archivo') && !$node->get('field_subir_archivo')->isEmpty()) {
        $file = $node->get('field_subir_archivo')->entity;
        if ($file instanceof FileInterface) {
          $fileUrl = $this->fileUrlGenerator->generateString($file->getFileUri());
          $fileName = $file->getFilename();
          $mime = $file->getMimeType();
          $htmlFileLink = '<a href="' . Html::escape($fileUrl) . '" type="' . Html::escape($mime) . '">' . Html::escape($fileName) . '</a>';
          $fileHtml = '<div class="field field-name-field-subir-archivo field-type-file field-label-hidden"><div class="field-items"><div class="field-item even"><span class="file"><i class="fa fa-file-o" aria-hidden="true"></i> ' . $htmlFileLink . '</span></div></div></div>';
        }
      }

      $html .= '<div class=" col-xs-12 col-sm-6 col-md-4 col-lg-4"><div class="thumbnail">';
      $html .= '<article id="node-' . (int) $node->id() . '" class="node node-biblioteca-abierta clearfix">';
      $html .= '<header><h2><a href="' . Html::escape($nodeUrl) . '">' . Html::escape((string) $title) . '</a></h2></header>';
      $html .= $fileHtml;
      if ($autor !== '') {
        $html .= '<div class="field field-name-field-autor-es- field-type-text field-label-inline clearfix"><div class="field-label">Autor (es):&nbsp;</div><div class="field-items"><div class="field-item even">' . Html::escape($autor) . '</div></div></div>';
      }
      if ($ubicacionLabel !== '') {
        $html .= '<div class="field field-name-field-ubicaci-n-del-recurso field-type-list-text field-label-above"><div class="field-label">Ubicación del recurso:&nbsp;</div><div class="field-items"><div class="field-item even">' . Html::escape($ubicacionLabel) . '</div></div></div>';
      }
      $html .= '</article></div></div>';

      if ($index % 2 === 0) {
        $html .= '<div class="clearfix visible-sm-block"></div>';
      }
      if ($index % 3 === 0) {
        $html .= '<div class="clearfix visible-md-block"></div><div class="clearfix visible-lg-block"></div>';
      }
    }

    $html .= '</div></div></div></div>';

    $build = [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
    ];
    $cache = new CacheableMetadata();
    $cache->addCacheContexts(['user.roles', 'url.path']);
    $cache->addCacheTags(['node_list:biblioteca_abierta']);
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Construye el estado vacío con el candado de Biblioteca Abierta.
   */
  private function buildBibliotecaEmptyMarkup(): array {
    $html = '<div class="view view-biblioteca-abierta view-id-biblioteca_abierta"><div class="view-empty"><div class="rteleft"><a href="/conectar"><img alt="" class="img-responsive" height="120" src="/sites/default/files/public/candado.jpg" width="120" /></a></div><br></div></div>';
    return [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
      '#cache' => [
        'contexts' => ['user.roles', 'url.path'],
      ],
    ];
  }

  /**
   * Vista /mapeo-institucional (Entidades Participantes).
   */
  public function mapeoInstitucional(Request $request): array {
    $selectedCamara = trim((string) $request->query->get('field_camara_tid', 'All'));

    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.type', 'gestion_componentes_mapeo');
    $query->condition('n.status', 1);

    if ($selectedCamara !== '' && $selectedCamara !== 'All' && is_numeric($selectedCamara)) {
      $query->innerJoin('user__field_camara', 'ufc', 'ufc.entity_id = n.uid');
      $query->condition('ufc.field_camara_target_id', (int) $selectedCamara);
    }

    $query->orderBy('n.created', 'DESC');
    $nids = $query->execute()?->fetchCol() ?? [];
    $total = count($nids);

    /** @var array<int, NodeInterface> $nodes */
    $nodes = $nids !== [] ? array_values($this->entityTypeManager->getStorage('node')->loadMultiple($nids)) : [];

    $optionsHtml = '<option value="All"' . ($selectedCamara === 'All' || $selectedCamara === '' ? ' selected="selected"' : '') . '>- Cualquiera -</option>';
    foreach (self::PROJECT_CAMARAS as $tid => $label) {
      $sel = ((string) $tid === $selectedCamara) ? ' selected="selected"' : '';
      $optionsHtml .= '<option value="' . $tid . '"' . $sel . '>' . Html::escape($label) . '</option>';
    }

    $html = '<div class="view view-users-mapeo-institucional view-id-users_mapeo_institucional view-display-id-page">';
    $html .= '<div class="view-header"><H4 class="rteright"><a href="/node/add/gestion-componentes-mapeo"><i class="fa fa-cloud-upload fa-lg">&nbsp;</i>Crear Nueva Entidad del Ecosistema</a></H4><h3> ' . $total . ' Instituciones Mapeadas</h3></div>';
    $html .= '<div class="view-filters"><form action="/mapeo-institucional" method="get" id="views-exposed-form-users-mapeo-institucional-page" accept-charset="UTF-8"><div><div class="views-exposed-form"><div class="views-exposed-widgets clearfix">';
    $html .= '<div id="edit-field-camara-tid-wrapper" class="views-exposed-widget views-widget-filter-field_camara_tid"><label for="edit-field-camara-tid">Seleccione una Cámara de Comercio</label><div class="views-widget"><div class="form-item form-item-field-camara-tid form-type-select form-group"><select class="form-control form-select" id="edit-field-camara-tid" name="field_camara_tid" onchange="this.form.submit()">' . $optionsHtml . '</select></div></div></div>';
    $html .= '<div class="views-exposed-widget views-submit-button"><button class="btn btn-info form-submit" type="submit" id="edit-submit-users-mapeo-institucional" value="Aplicar">Aplicar</button></div>';
    $html .= '</div></div></div></form></div>';

    $html .= '<div class="view-content"><div id="views-bootstrap-grid-1" class="views-bootstrap-grid-plugin-style"><div class="row agp-responsive-grid">';
    foreach ($nodes as $idx => $node) {
      $html .= '<div class="col-xs-12 col-sm-6 col-md-4 col-lg-4 agp-grid-item">';
      $html .= $this->renderMapeoNode($node, $idx + 1);
      $html .= '</div>';
    }
    $html .= '</div></div></div></div>';

    $build = [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
    ];
    $cache = new CacheableMetadata();
    $cache->addCacheContexts(['url.query_args', 'user.roles']);
    $cache->addCacheTags(['node_list:gestion_componentes_mapeo']);
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Renderiza un nodo gestion_componentes_mapeo con el mismo HTML de D7.
   */
  private function renderMapeoNode(NodeInterface $node, int $panelIndex): string {
    $nid = (int) $node->id();
    $nodeUrl = $node->toUrl()->toString();
    $title = (string) $node->label();
    $panelId = $panelIndex === 1 ? 'bootstrap-panel' : 'bootstrap-panel--' . $panelIndex;
    $bodyId = $panelIndex === 1 ? 'bootstrap-panel-body' : 'bootstrap-panel-' . $panelIndex . '-body';
    $contactId = 'mapeo-contact-' . $nid;

    $categoriaMap = [
      '1' => 'Apoyo',
      '2' => 'Financiación',
      '3' => 'Educación',
      '4' => 'Gobierno',
      '5' => 'Investigación y Desarrollo',
      '6' => 'Red / Gremio',
    ];
    $naturalezaMap = [
      '1' => 'Pública',
      '2' => 'Privada',
      '3' => 'Mixta',
    ];
    $generoMap = [
      '1' => 'Femenino',
      '2' => 'Masculino',
    ];

    $html = '<article id="node-' . $nid . '" class="node node-gestion-componentes-mapeo clearfix">';
    $html .= '<header><h2><a href="' . Html::escape($nodeUrl) . '">' . Html::escape($title) . '</a></h2></header>';
    $html .= '<fieldset class="collapsible group-datos field-group-fieldset panel panel-default form-wrapper" id="' . $panelId . '">';
    $html .= '<legend class="panel-heading"><a href="#' . $bodyId . '" class="panel-title fieldset-legend" data-toggle="collapse">Datos de la Entidad</a></legend>';
    $html .= '<div class="panel-body panel-collapse collapse fade in" id="' . $bodyId . '">';

    $categorias = $this->getFieldListLabels($node, 'field_categoria_entidad', $categoriaMap);
    if ($categorias !== []) {
      $html .= '<div class="field field-name-field-categoria-entidad field-type-list-text field-label-above"><div class="field-label">Categoría de la Entidad:&nbsp;</div><div class="field-items">';
      foreach ($categorias as $i => $cat) {
        $html .= '<div class="field-item ' . ($i % 2 === 0 ? 'even' : 'odd') . '">' . Html::escape($cat) . '</div>';
      }
      $html .= '</div></div>';
    }

    $naturalezas = $this->getFieldListLabels($node, 'field_naturaleza_juridica', $naturalezaMap);
    if ($naturalezas !== []) {
      $html .= $this->renderFieldAbove('field-naturaleza-juridica', 'Naturaleza Jurídica', implode(', ', $naturalezas));
    }

    $html .= $this->renderFieldAbove('field-dependencia', 'Dependencia', $this->getFieldString($node, 'field_dependencia'));
    $html .= $this->renderFieldAbove('field-direccion-entidad-ecosis', 'Dirección', $this->getFieldString($node, 'field_direccion_entidad_ecosis'));

    $mailEntidad = $this->getFieldString($node, 'field_mail_entidad_ecosis');
    if ($mailEntidad !== '') {
      $html .= '<div class="field field-name-field-mail-entidad-ecosis field-type-email field-label-above"><div class="field-label">E-mail de Contacto:&nbsp;</div><div class="field-items"><div class="field-item even"><a href="mailto:' . Html::escape($mailEntidad) . '">' . Html::escape($mailEntidad) . '</a></div></div></div>';
    }

    if ($node->hasField('field_web_entidad_ecosis') && !$node->get('field_web_entidad_ecosis')->isEmpty()) {
      $uri = (string) ($node->get('field_web_entidad_ecosis')->first()?->get('uri')?->getValue() ?? '');
      if ($uri !== '') {
        $html .= '<div class="field field-name-field-web-entidad-ecosis field-type-link-field field-label-above"><div class="field-label">Página Web:&nbsp;</div><div class="field-items"><div class="field-item even"><a href="' . Html::escape($uri) . '" target="_blank">' . Html::escape($uri) . '</a></div></div></div>';
      }
    }

    $html .= $this->renderFieldAbove('field-telefonos-entidad-ecosis', 'Teléfonos de Contacto', $this->getFieldString($node, 'field_telefonos_entidad_ecosis'));
    $html .= $this->renderFieldAbove('field-horario-de-atencion-entida', 'Horario de Atención', $this->getFieldString($node, 'field_horario_de_atencion_entida'));
    $html .= $this->renderFieldAbove('field-tipo-de-emprendimiento-pob', 'Tipo de Emprendimiento/Poblacion a la cual se dirige el servicio', $this->getFieldString($node, 'field_tipo_de_emprendimiento_pob'));

    // Tabla de servicios (tablefield).
    if ($node->hasField('field_servicios_de_la_entidad') && !$node->get('field_servicios_de_la_entidad')->isEmpty()) {
      $tableVal = $node->get('field_servicios_de_la_entidad')->first()?->getValue()['value'] ?? NULL;
      if (is_array($tableVal) && !empty($tableVal)) {
        $html .= '<div class="field field-name-field-servicios-de-la-entidad field-type-tablefield field-label-hidden"><div class="field-items"><div class="field-item even"><div class="tablefield-wrapper table-responsive"><table class="tablefield sticky-enabled table table-striped">';
        $rowIndex = 0;
        foreach ($tableVal as $rKey => $row) {
          if (!is_array($row)) {
            continue;
          }
          $cells = array_values(array_filter($row, static fn($k) => is_int($k) || str_starts_with((string) $k, 'col_') || is_numeric((string) $k), ARRAY_FILTER_USE_KEY));
          $nonEmpty = array_filter($cells, static fn($v) => trim((string) $v) !== '');
          if ($rowIndex > 0 && $nonEmpty === []) {
            continue;
          }
          if ($rowIndex === 0) {
            $html .= '<thead><tr>';
            foreach ($cells as $cIdx => $cell) {
              $html .= '<th class="row_0 col_' . $cIdx . '" scope="col">' . Html::escape((string) $cell) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
          }
          else {
            $parity = ($rowIndex % 2 === 1) ? 'odd' : 'even';
            $html .= '<tr class="' . $parity . '">';
            foreach ($cells as $cIdx => $cell) {
              $html .= '<td class="row_' . $rowIndex . ' col_' . $cIdx . '">' . Html::escape((string) $cell) . '</td>';
            }
            $html .= '</tr>';
          }
          $rowIndex++;
        }
        if ($rowIndex > 0) {
          $html .= '</tbody>';
        }
        $html .= '</table></div></div></div></div>';
      }
    }

    // Sección plegable Ver datos de contacto.
    $nombreContacto = $this->getFieldString($node, 'field_nombre_persona_de_contacto');
    $generos = $this->getFieldListLabels($node, 'field_genero', $generoMap);
    $cargo = $this->getFieldString($node, 'field_cargo_o_rol');
    $telContacto = $this->getFieldString($node, 'field_telefonos_contacto');
    $mailContacto = $this->getFieldString($node, 'field_e_mail_contacto');

    if ($nombreContacto !== '' || $telContacto !== '' || $mailContacto !== '') {
      $html .= '<div class="collapsible collapsed strong speed-fast effect-none">';
      $html .= '<h3><a href="#' . $contactId . '" data-toggle="collapse" class="field-group-format-toggler">Ver datos de contacto</a></h3>';
      $html .= '<div id="' . $contactId . '" class="collapse field-group-format-wrapper">';
      $html .= $this->renderFieldAbove('field-nombre-persona-de-contacto', 'Nombre y Apellidos', $nombreContacto);
      if ($generos !== []) {
        $html .= $this->renderFieldAbove('field-genero', 'Genero', implode(', ', $generos));
      }
      $html .= $this->renderFieldAbove('field-cargo-o-rol', 'Cargo o Rol', $cargo);
      $html .= $this->renderFieldAbove('field-telefonos-contacto', 'Teléfonos de Contacto', $telContacto);
      if ($mailContacto !== '') {
        $html .= '<div class="field field-name-field-e-mail-contacto field-type-email field-label-above"><div class="field-label">E-mail de Contacto:&nbsp;</div><div class="field-items"><div class="field-item even"><a href="mailto:' . Html::escape($mailContacto) . '">' . Html::escape($mailContacto) . '</a></div></div></div>';
      }
      $html .= '</div></div>';
    }

    $html .= '</div></fieldset></article>';
    return $html;
  }

  /**
   * Vista /canvas (Canvas de la Red).
   */
  public function canvas(Request $request): array {
    $selectedCamara = trim((string) $request->query->get('field_camara_tid', '56'));
    if (!isset(self::PROJECT_CAMARAS[(int) $selectedCamara])) {
      $selectedCamara = '56';
    }
    $camaraTid = (int) $selectedCamara;

    $optionsHtml = '';
    foreach (self::PROJECT_CAMARAS as $tid => $label) {
      $sel = ($tid === $camaraTid) ? ' selected="selected"' : '';
      $optionsHtml .= '<option value="' . $tid . '"' . $sel . '>' . Html::escape($label) . '</option>';
    }

    $stages = [
      'attachment_3' => [
        'title_html' => '<div><h2><i class="fa fa-lightbulb-o fa-2x" aria-hidden="true"> </i> 1. Idea </h2></div><p>Emprendedores en etapa temprana son inspirados, aprenden las mejores y básicas prácticas para desarrollar compañías, desarrollar sus habilidades, validar sus ideas con expertos y proceder a conformar sus equipos y desarrollar sus primeras versiones de producto. </p>',
        'bundles' => [
          'canvas_construir_el_primer_produ',
          'evangelistas',
          'eventos_inspiradores',
          'canvas_formacion',
          'canvas_medios',
          'canvas_mejores_practicas',
          'canvas_practica_y_retroalimentac',
        ],
        'fields' => [
          'field_ingresar_nueva_pr_ctica',
          'field_ingresar_nuevo_primer_prod',
          'field_ingresar_nuevo_registro',
          'field_nuevo_registro_mejores_p',
          'field_registro_formacion_equipo',
          'field_ingresar_nuevo_evento_insp',
          'field_ingresar_nuevo_evangelista',
          'field_ingresar_nuevo_establecer',
        ],
      ],
      'attachment_1' => [
        'title_html' => '<div><h2><i class="fa fa-rocket fa-2x" aria-hidden="true"> </i> 2. Lanzamiento</h2></div><p>En esta etapa, el emprendedor establece y formaliza la compañía, desarrolla sus productos, obtiene una retroalimentación de sus clientes y se prepara para el siguiente paso. </p>',
        'bundles' => [
          'canvas_dinamizadores_de_semilla',
          'canvas_espacios_de_trabajo_',
          'canvas_establecer',
          'canvas_formalizar',
          'canvas_gobierno',
          'canvas_persuadir_y_demostrar',
          'canvas_prepare_la_semilla',
        ],
        'fields' => [
          'field_ingresar_nuevo_establecer',
          'field_ingresar_nueva_referencia_',
          'field_nueva_referencia_dinamizad',
          'field_nueva_referencia_gobierno',
          'field_nueva_referencia_espacio_t',
          'field_nueva_referencia_semilla',
          'field_nueva_referencia_persuadir',
        ],
      ],
      'attachment_2' => [
        'title_html' => '<div><h2><i class="fa fa-line-chart fa-2x" aria-hidden="true"> </i> 3. Crecimiento</h2></div><p>En esta etapa comprueba su utilidad, recibe reconocimiento y escala su posición.  Esto usualmente requiere financiación y otros recursos.</p>',
        'bundles' => [
          'canvas_capitalistas_de_riesgo',
          'canvas_expansi_n',
          'canvas_infraestructura',
          'canvas_medios_principales',
          'canvas_red_de_inversionistas',
          'canvas_talento',
          'canvas_ngeles',
        ],
        'fields' => [
          'field_nueva_referencia_red_inver',
          'field_nueva_referencia_angeles',
          'field_nueva_referencia_infraestr',
          'field_nueva_referencia_talento',
          'field_nueva_referencia_medios_pr',
          'field_nueva_referencia_capitalis',
          'field_nueva_referencia_expansion',
        ],
      ],
    ];

    $html = '<div class="view view-canvas view-id-canvas view-display-id-page_1">';
    $html .= '<div class="view-filters"><form action="/canvas" method="get" id="views-exposed-form-canvas-page-1" accept-charset="UTF-8"><div><div class="views-exposed-form"><div class="views-exposed-widgets clearfix">';
    $html .= '<div id="edit-field-camara-tid-wrapper" class="views-exposed-widget views-widget-filter-field_camara_tid"><label for="edit-field-camara-tid">Seleccione una Cámara de Comercio</label><div class="views-widget"><div class="form-item form-item-field-camara-tid form-type-select form-group"><select class="form-control form-select required" id="edit-field-camara-tid" name="field_camara_tid" onchange="this.form.submit()">' . $optionsHtml . '</select></div></div></div>';
    $html .= '<div class="views-exposed-widget views-submit-button"><button class="btn btn-info form-submit" type="submit" id="edit-submit-canvas" value="Aplicar">Aplicar</button></div>';
    $html .= '</div></div></div></form></div>';

    foreach ($stages as $attachmentId => $stage) {
      $query = $this->database->select('node_field_data', 'n');
      $query->fields('n', ['nid']);
      $query->condition('n.status', 1);
      $query->condition('n.type', $stage['bundles'], 'IN');
      $query->innerJoin('user__field_camara', 'ufc', 'ufc.entity_id = n.uid');
      $query->condition('ufc.field_camara_target_id', $camaraTid);
      $query->orderBy('n.created', 'ASC');
      $nids = $query->execute()?->fetchCol() ?? [];

      /** @var array<int, NodeInterface> $nodes */
      $nodes = $nids !== [] ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];

      $html .= '<div class="attachment attachment-after"><div class="view view-canvas view-id-canvas view-display-id-' . $attachmentId . '">';
      $html .= '<div class="view-header">' . $stage['title_html'] . '</div>';
      $html .= '<div class="view-content"><div class="views-bootstrap-thumbnail-plugin-style"><div class="row">';

      $index = 0;
      foreach ($nodes as $node) {
        $index++;
        $html .= '<div class=" col-xs-12 col-sm-6 col-md-4 col-lg-3"><div class="thumbnail">';
        $html .= '<div class="views-field views-field-title"><h4 class="field-content">' . Html::escape((string) $node->label()) . '</h4></div>';
        foreach ($stage['fields'] as $fieldName) {
          $itemsHtml = '';
          if ($node->hasField($fieldName) && !$node->get($fieldName)->isEmpty()) {
            $li = [];
            foreach ($node->get($fieldName) as $item) {
              $val = trim((string) ($item->value ?? ''));
              if ($val !== '') {
                $li[] = '<li>' . Html::escape($val) . '</li>';
              }
            }
            if ($li !== []) {
              $itemsHtml = '<ol>' . implode("\n", $li) . '</ol>';
            }
          }
          $cssField = str_replace('_', '-', $fieldName);
          $html .= '<div class="views-field views-field-' . $cssField . '"><div class="field-content">' . $itemsHtml . '</div></div>';
        }
        $html .= '</div></div>';

        if ($index % 2 === 0) {
          $html .= '<div class="clearfix visible-sm-block"></div>';
        }
        if ($index % 3 === 0) {
          $html .= '<div class="clearfix visible-md-block"></div>';
        }
        if ($index % 4 === 0) {
          $html .= '<div class="clearfix visible-lg-block"></div>';
        }
      }

      $html .= '</div></div></div></div></div>';
    }

    $html .= '</div>';

    $build = [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
    ];
    $cache = new CacheableMetadata();
    $cache->addCacheContexts(['url.query_args', 'user.roles']);
    $cache->addCacheTags(['node_list']);
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Vista /360-emprendimientos (Autodiagnóstico 360° Emprendimiento).
   */
  public function autodiagnosticoEmprendimientos(): array {
    $uid = (int) $this->currentUser->id();
    $sectionsData = $uid > 0 ? $this->radarDataProvider->getEmprendimientoFiveByUid($uid) : [];
    $scores = $this->scoreCalculator->calculateEmprendimientoValues($sectionsData);

    // Si es anónimo y no hay datos propios, mostrar el resumen general como en D7 o botón comenzar.
    if ($scores === [] && $uid === 0) {
      // En D7 para usuario anónimo muestra la fila resumen de ejemplo o tabla.
      $scores = [0.0, 150.0, 0.0, 252.0, 270.67, 134.53];
      $empName = '';
    }
    elseif ($scores === []) {
      $html = '<div class="view view-360-emprendimientos view-id-360_emprendimientos view-display-id-page"><div class="view-empty"><br><br><div class="jumbotron"><div class="container">';
      $html .= '<p>Aún no has realizado el diagnóstico de tu emprendimiento.</p>';
      $html .= '<p>Conoce qué tan consolidadas tienes las áreas de tu emprendimiento haciendo clic en el botón "Comenzar"</p>';
      $html .= '<p><a class="btn btn-primary btn-lg" href="/content/360°-direccionamiento" role="button">Comenzar</a></p>';
      $html .= '</div></div></div></div>';
      return [
        '#type' => 'markup',
        '#markup' => Markup::create($html),
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['webform_submission_list', 'user:' . $uid],
        ],
      ];
    }
    else {
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      $empName = $account instanceof UserInterface ? $this->getFieldString($account, 'field_nombre_del_emprendimiento') : '';
    }

    $html = '<div class="view view-360-emprendimientos view-id-360_emprendimientos view-display-id-page"><div class="view-content table-responsive agp-table-card">';
    $html .= '<table class="views-table cols-7 table table-striped" id="views-aggregator-datatable"><thead><tr>';
    $html .= '<th class="views-field views-field-field-nombre-del-emprendimiento">Emprendimiento</th>';
    $html .= '<th class="views-field views-field-expression views-align-center">Direccionamiento</th>';
    $html .= '<th class="views-field views-field-expression-1 views-align-center">Administración</th>';
    $html .= '<th class="views-field views-field-expression-2 views-align-center">Mercadeo</th>';
    $html .= '<th class="views-field views-field-expression-3 views-align-center">Gestión Técnica y Producción</th>';
    $html .= '<th class="views-field views-field-expression-4 views-align-center">Financiero</th>';
    $html .= '<th class="views-field views-field-expression-5 views-align-center">Promedio General Emprendimiento</th>';
    $html .= '</tr></thead><tbody><tr class="odd views-row-first views-row-last">';
    $html .= '<td class="views-field views-field-field-nombre-del-emprendimiento">' . Html::escape($empName) . '</td>';
    for ($i = 0; $i < 5; $i++) {
      $val = number_format((float) ($scores[$i] ?? 0.0), 2, '.', '');
      $html .= '<td class="views-field views-field-expression views-align-center">' . $val . '</td>';
    }
    $avg = number_format((float) ($scores[5] ?? 0.0), 2, '.', '');
    $html .= '<td class="views-field views-field-expression-5 views-align-center"><strong>' . $avg . '</strong></td>';
    $html .= '</tr></tbody></table></div></div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list', 'user:' . $uid],
      ],
    ];
  }

  /**
   * Vista /360-capacidades-emprendedor (Autodiagnóstico 360° Emprendedor).
   */
  public function autodiagnosticoCapacidades(): array {
    $uid = (int) $this->currentUser->id();
    $submissionData = $uid > 0 ? $this->radarDataProvider->getCapacidadesSubmissionByUid($uid) : [];
    $scores = $this->scoreCalculator->calculateCapacidadesValues($submissionData);

    if ($scores === []) {
      $html = '<div class="view view-360-capacidades-emprendedoras-emprendedor"><div class="view-empty"><br><br><div class="jumbotron"><div class="container">';
      $html .= '<p>Aún no has realizado el diagnóstico de tus capacidades emprendedoras.</p>';
      $html .= '<p>Descubre cómo están tus capacidades como emprendedor haciendo clic en el botón "Comenzar"</p>';
      $html .= '<p><a class="btn btn-primary btn-lg" href="/content/autodiagnóstico-de-capacidades-emprendedoras" role="button">Comenzar</a></p>';
      $html .= '</div></div></div></div>';
      return [
        '#type' => 'markup',
        '#markup' => Markup::create($html),
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['webform_submission_list', 'user:' . $uid],
        ],
      ];
    }

    $headers = [
      'Ser',
      'Creatividad e Innovación',
      'Amplitud Perceptual',
      'Pensamiento Sistémico',
      'Liderazgo',
      'Orientación al Logro',
      'Orientación al Mercado',
      'Promedio General',
    ];

    $html = '<div class="view view-360-capacidades-emprendedoras-emprendedor"><div class="view-content table-responsive agp-table-card">';
    $html .= '<table class="views-table cols-8 table table-striped" id="views-aggregator-datatable"><thead><tr>';
    foreach ($headers as $h) {
      $html .= '<th class="views-field views-align-center">' . Html::escape($h) . '</th>';
    }
    $html .= '</tr></thead><tbody><tr class="odd views-row-first views-row-last">';
    for ($i = 0; $i < 7; $i++) {
      $val = number_format((float) ($scores[$i] ?? 0.0), 2, '.', '') . '%';
      $html .= '<td class="views-field views-align-center">' . $val . '</td>';
    }
    $avg = number_format((float) ($scores[7] ?? 0.0), 2, '.', '') . '%';
    $html .= '<td class="views-field views-align-center"><strong>' . $avg . '</strong></td>';
    $html .= '</tr></tbody></table></div></div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['webform_submission_list', 'user:' . $uid],
      ],
    ];
  }

  /**
   * Obtiene todas las opciones del vocabulario c_maras_de_comercio.
   *
   * @return array<int, string>
   */
  private function loadAllCamarasOptions(): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'c_maras_de_comercio',
    ]);
    $options = [];
    foreach ($terms as $term) {
      $options[(int) $term->id()] = (string) $term->label();
    }
    asort($options);
    return $options;
  }

  /**
   * Obtiene el valor string de un campo en una entidad.
   */
  private function getFieldString(object $entity, string $fieldName): string {
    if (!method_exists($entity, 'hasField') || !$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim((string) ($entity->get($fieldName)->first()?->value ?? ''));
  }

  /**
   * Obtiene el nombre del término referenciado en un campo.
   */
  private function getTermLabel(object $entity, string $fieldName): string {
    if (!method_exists($entity, 'hasField') || !$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return '';
    }
    $term = $entity->get($fieldName)->entity;
    return $term ? (string) $term->label() : '';
  }

  /**
   * Obtiene las etiquetas legibles de un campo list_string / list_integer.
   *
   * @param array<string, string> $map
   *
   * @return list<string>
   */
  private function getFieldListLabels(NodeInterface $node, string $fieldName, array $map): array {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return [];
    }
    $allowedValues = $node->getFieldDefinition($fieldName)?->getSetting('allowed_values') ?? [];
    $labels = [];
    foreach ($node->get($fieldName) as $item) {
      $val = (string) ($item->value ?? '');
      if ($val !== '') {
        $labels[] = (string) ($allowedValues[$val] ?? $map[$val] ?? $val);
      }
    }
    return $labels;
  }

  /**
   * Renderiza un campo con etiqueta superior al estilo D7.
   */
  private function renderFieldAbove(string $cssClass, string $label, string $value): string {
    if ($value === '') {
      return '';
    }
    return '<div class="field field-name-' . $cssClass . ' field-type-text field-label-above"><div class="field-label">' . Html::escape($label) . ':&nbsp;</div><div class="field-items"><div class="field-item even">' . Html::escape($value) . '</div></div></div>';
  }

  /**
   * Construye un paginador Bootstrap 3 sencillo.
   *
   * @param array<string, string> $queryParams
   */
  private function buildSimplePager(string $basePath, int $currentPage, int $perPage, int $total, array $queryParams): string {
    $totalPages = (int) ceil($total / $perPage);
    if ($totalPages <= 1) {
      return '';
    }
    $filteredParams = array_filter($queryParams, static fn($v) => $v !== '' && $v !== 'All');
    $html = '<div class="text-center"><ul class="pagination">';
    for ($i = 0; $i < min($totalPages, 15); $i++) {
      $params = $filteredParams;
      if ($i > 0) {
        $params['page'] = (string) $i;
      }
      $qs = $params !== [] ? '?' . http_build_query($params) : '';
      $active = ($i === $currentPage) ? ' class="active"' : '';
      $html .= '<li' . $active . '><a href="' . $basePath . $qs . '">' . ($i + 1) . '</a></li>';
    }
    $html .= '</ul></div>';
    return $html;
  }

}

