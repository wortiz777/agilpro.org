<?php

declare(strict_types=1);

namespace Drupal\agilpro_org\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bloque de saludo al usuario autenticado en portada ('views:saludo-block_1').
 */
#[Block(
  id: 'agilpro_saludo_block',
  admin_label: new TranslatableMarkup('Saludo Emprendedor (Portada)'),
  category: new TranslatableMarkup('AgilPro.org'),
)]
final class SaludoEmprendedorBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    if (!$this->currentUser->isAuthenticated()) {
      return ['#cache' => ['contexts' => ['user.roles']]];
    }

    $uid = (int) $this->currentUser->id();
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    $nombre = $this->currentUser->getDisplayName();
    if ($account instanceof UserInterface && $account->hasField('field_nombre') && !$account->get('field_nombre')->isEmpty()) {
      $nombre = (string) $account->get('field_nombre')->first()?->value;
    }

    $html = '<div class="view view-saludo view-id-saludo view-display-id-block_1">';
    $html .= '<div class="view-content"><div class="views-row"><div class="views-field views-field-field-nombre"><span class="field-content">¡Hola&nbsp; ' . Html::escape($nombre) . '!</span></div></div></div>';
    $html .= '<div class="view-footer"><p>Te damos la bienvenida a la plataforma AgilPro.org</p></div>';
    $html .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['user:' . $uid],
      ],
    ];
  }
}
