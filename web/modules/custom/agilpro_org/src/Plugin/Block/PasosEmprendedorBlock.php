<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Plugin\Block;

use Drupal\agilpro_org\Service\WebformRadarDataProvider;
use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bloque de pasos del emprendedor en portada (paso_1a, paso_2a, paso_2).
 */
#[Block(
  id: 'agilpro_pasos_emprendedor_block',
  admin_label: new TranslatableMarkup('Pasos Emprendedor 1A / 2A / 2 (Portada)'),
  category: new TranslatableMarkup('AgilPro.org'),
)]
final class PasosEmprendedorBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly WebformRadarDataProvider $radarDataProvider,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('agilpro_org.webform_radar_data_provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (!$this->currentUser->isAuthenticated()) {
      return ['#cache' => ['contexts' => ['user.roles']]];
    }

    $uid = (int) $this->currentUser->id();
    $roles = $this->currentUser->getRoles();
    $isAdmin = in_array('administrator', $roles, TRUE);
    $html = '';

    // Paso 1A: roles administrator o colaborador.
    if ($isAdmin || in_array('colaborador', $roles, TRUE) || in_array('emprendedor_registrado', $roles, TRUE)) {
      $capData = $this->radarDataProvider->getCapacidadesSubmissionByUid($uid);
      if ($capData !== []) {
        $html .= '<div class="view view-paso-1a"><div class="view-header"><div class="pasos"><p><span aria-hidden="true" class="glyphicon glyphicon-ok">&nbsp;</span> Excelente, ya realizaste tu Autodiagnóstico. Vea sus resultados aquí: <a href="/360-capacidades-emprendedor"><b>Autodiagnóstico 360° como emprendedor.</b></a></p></div><br></div></div>';
      }
      else {
        $html .= '<div class="view view-paso-1a"><div class="view-empty"><div class="c-callout-card card-content"><div class="c-mediaImage--centered margin-left--lv0"><span aria-hidden="true" class="glyphicon glyphicon-bell">&nbsp;</span></div><div class="freddicon light-bulb-color margin--lv1 margin-top-bottom--lv0"><a href="/360-capacidades-emprendedor"><p class="margin-bottom--lv0 small-meta link-underline"><strong><h3>Primer Paso</h3></strong></p></a><p>Realiza el <b><a href="/360-capacidades-emprendedor">Autodiagnóstico 360° de Capacidades Emprendedoras.</a></b> Obtendrás un completo diagnóstico de tus habilidades como emprendedor y podrás acceder a la fase <b>"Conectar"</b> del programa ¡empréndelo!.</p> <a class="btn btn-warning" href="/360-capacidades-emprendedor"><i class="fa fa-user-circle-o fa-lg">&nbsp;</i> Realizar Autodiagnóstico </a></div></div><br></div></div>';
      }
    }

    // Paso 2A: roles administrator o emprendedor_paso_2a.
    if ($isAdmin || in_array('emprendedor_paso_2a', $roles, TRUE)) {
      $finNids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'componente_financiero_adicional')
        ->condition('status', 1)
        ->condition('uid', $uid)
        ->range(0, 1)
        ->execute();
      if ($finNids !== []) {
        $html .= '<div class="view view-paso-2a"><div class="view-header"><div class="pasos"><p><span aria-hidden="true" class="glyphicon glyphicon-ok">&nbsp;</span> Buen trabajo, ya realizaste el Autodiagnóstico de tu emprendimiento. Vea sus resultados aquí: <a href="/360-emprendimientos"><b>Autodiagnóstico 360° </b></a></p></div><br></div></div>';
      }
      else {
        $html .= '<div class="view view-paso-2a"><div class="view-empty"><div class="c-callout-card card-content"><div class="c-mediaImage--centered margin-left--lv0"><span aria-hidden="true" class="glyphicon glyphicon-bell">&nbsp;</span></div><div class="freddicon light-bulb-color margin--lv1 margin-top-bottom--lv0"><a href="/360-emprendimientos"><p class="margin-bottom--lv0 small-meta link-underline"><strong><h3>Segundo Paso</h3></strong></p></a><p>Ahora realiza el <b><a href="/360-emprendimientos">Autodiagnóstico 360° de tu Emprendimiento.</a></b> Un análisis integral de las cinco áreas clave de tu negocio que te permitirán conocer sus fortalezas y aspectos a mejorar.</p> <a class="btn btn-danger" href="/360-emprendimientos"><i class="fa fa-lightbulb-o fa-lg">&nbsp;</i>Realizar Autodiagnóstico</a></div></div><br></div></div>';
      }
    }

    // Paso 2 (Tercer Paso): roles administrator o emprendedor_paso_2.
    if ($isAdmin || in_array('emprendedor_paso_2', $roles, TRUE)) {
      $empNids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'inscripci_n_fase_empoderar')
        ->condition('status', 1)
        ->condition('uid', $uid)
        ->range(0, 1)
        ->execute();
      if ($empNids !== []) {
        $nid = (int) reset($empNids);
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        $linkUrl = $node instanceof NodeInterface ? $node->toUrl()->toString() : '/node/' . $nid;
        $html .= '<div class="view view-paso-2"><div class="view-header"><div class="pasos"><p><span aria-hidden="true" class="glyphicon glyphicon-ok">&nbsp;</span> <b><a href="' . Html::escape($linkUrl) . '">Ver inscripción</a></b>. Te contactaremos para brindarte más información. </p></div><br></div></div>';
      }
      else {
        $html .= '<div class="view view-paso-2"><div class="view-empty"><div class="c-callout-card card-content"><div class="c-mediaImage--centered margin-left--lv0"><span aria-hidden="true" class="glyphicon glyphicon-hand-right">&nbsp;</span></div><div class="freddicon light-bulb-color margin--lv1 margin-top-bottom--lv0"><a href="/node/add/inscripci_n_fase_empoderar"><p class="margin-bottom--lv0 small-meta link-underline"><strong><h3>Tercer Paso</h3></strong></p></a><p><b>¿Desea fortalecer sus capacidades como emprendedor?</b> Acepte el desafío <b>¡empréndelo!</b> y avance en su Ruta de Fortalecimiento Emprendedor:</p> <a class="btn btn-success" href="/node/add/inscripci_n_fase_empoderar"><i class="fa fa-thumbs-up fa-lg">&nbsp;</i> Acepto el Reto</a></div></div><br></div></div>';
      }
    }

    if ($html === '') {
      return ['#cache' => ['contexts' => ['user.roles']]];
    }

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
      '#cache' => [
        'contexts' => ['user', 'user.roles'],
        'tags' => ['user:' . $uid, 'node_list', 'webform_submission_list'],
      ],
    ];
  }

}

