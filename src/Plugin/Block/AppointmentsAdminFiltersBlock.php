<?php

declare(strict_types=1);

namespace Drupal\appointment\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides an admin filters block for the appointments view.
 */
#[Block(
  id: 'appointment_appointments_admin_filters',
  admin_label: new TranslatableMarkup('Appointments admin filters'),
)]
final class AppointmentsAdminFiltersBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Admin-only: matches the view access enforcement.
    if (!\Drupal::currentUser()->hasPermission('administer appointment')) {
      return [];
    }

    return \Drupal::formBuilder()->getForm(\Drupal\appointment\Form\AppointmentsAdminFiltersForm::class);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    // This block output depends on the current query string.
    return 0;
  }

}

