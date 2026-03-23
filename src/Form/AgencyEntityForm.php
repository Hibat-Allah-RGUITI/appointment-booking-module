<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the agency entity edit forms.
 */
final class AgencyEntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $label = $this->entity->label();
    if ($label === NULL || $label === '') {
      $label = $this->t('(no label)');
    }
    $message_args = ['%label' => $this->entity->toLink($label)->toString()];
    $logger_args = [
      '%label' => $label,
      'link' => $this->entity->toLink($this->t('View'))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New agency %label has been created.', $message_args));
        $this->logger('appointment')->notice('New agency %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The agency %label has been updated.', $message_args));
        $this->logger('appointment')->notice('The agency %label has been updated.', $logger_args);
        break;

      default:
        throw new \LogicException('Could not save the entity.');
    }

    $form_state->setRedirectUrl($this->entity->toUrl());

    return $result;
  }

}
