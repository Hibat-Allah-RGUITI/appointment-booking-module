<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\appointment\Traits\AppointmentValidationTrait;

/**
 * Form controller for the appointment entity edit forms.
 */
final class AppointmentEntityForm extends ContentEntityForm
{

  use AppointmentValidationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form = parent::buildForm($form, $form_state);

    $operation = $this->getOperation();

    // Fields to always hide for these specific forms.
    $common_hidden = [
      'status',
      'field_status',
      'revision_log',
      'revision',
      'uid',
      'created',
      'changed',
      'label',
    ];

    foreach ($common_hidden as $field) {
      if (isset($form[$field])) {
        $form[$field]['#access'] = FALSE;
      }
    }

    // Hide the delete button if present in these specific forms.
    if (in_array($operation, ['personal_info', 'details'])) {
      if (isset($form['actions']['delete'])) {
        $form['actions']['delete']['#access'] = FALSE;
      }
    }

    if ($operation === 'personal_info') {
      // Hide appointment details, only show personal info.
      $fields_to_hide = [
        'field_appointment_agency',
        'field_appointment_adviser',
        'field_appointment_type',
        'field_appointment_date',
        'field_notes',
      ];
      foreach ($fields_to_hide as $field) {
        if (isset($form[$field])) {
          $form[$field]['#access'] = FALSE;
        }
      }
    } elseif ($operation === 'details') {
      // Only allow changing the appointment date.
      $fields_to_hide = [
        'field_customer_name',
        'field_customer_email',
        'field_customer_phone',
        'field_appointment_agency',
        'field_appointment_adviser',
        'field_appointment_type',
        'field_notes',
      ];
      foreach ($fields_to_hide as $field) {
        if (isset($form[$field])) {
          $form[$field]['#access'] = FALSE;
        }
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);

    $operation = $this->getOperation();
    if ($operation === 'details') {
      // Get values from form state.
      // In ContentEntityForm, values are typically in the field_name array.
      $date_raw = $form_state->getValue('field_appointment_date');
      $date = $this->normalizeDateValue($date_raw);

      if (!$date) {
        // If we can't normalize, maybe it's already in the entity.
        $entity = $this->getEntity();
        if ($entity->hasField('field_appointment_date') && !$entity->get('field_appointment_date')->isEmpty()) {
          $date = $this->normalizeDateValue($entity->get('field_appointment_date')->value);
        }
      }

      if (!$date) {
        $form_state->setErrorByName('field_appointment_date', $this->t('Please select a valid appointment date and time.'));
        return;
      }

      // Check if date is in the past.
      if (!$this->validateDateNotInPast($form_state, $date, 'field_appointment_date')) {
        return;
      }

      // Use unchanged entity for reference fields as they are hidden in the form.
      /** @var \Drupal\appointment\Entity\AppointmentEntity $entity */
      $entity = $this->getEntity();
      $unchanged = \Drupal::entityTypeManager()->getStorage('appointment')->loadUnchanged($entity->id());

      $agency_id = '';
      if ($unchanged && $unchanged->hasField('field_appointment_agency') && !$unchanged->get('field_appointment_agency')->isEmpty()) {
        $agency_id = (string) $unchanged->get('field_appointment_agency')->target_id;
      }

      $adviser_id = '';
      if ($unchanged && $unchanged->hasField('field_appointment_adviser') && !$unchanged->get('field_appointment_adviser')->isEmpty()) {
        $adviser_id = (string) $unchanged->get('field_appointment_adviser')->target_id;
      }

      // Business logic validations.
      if ($agency_id !== '') {
        $this->validateWithinAgencyHours($form_state, $agency_id, $date, 'field_appointment_date');
      }
      if ($adviser_id !== '') {
        $this->validateWithinAdviserHours($form_state, $adviser_id, $date, 'field_appointment_date');
        $this->validateNoDoubleBooking($form_state, $agency_id, $adviser_id, $date, 'field_appointment_date', $entity->id() ? (int) $entity->id() : NULL);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int
  {
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
        $this->messenger()->addStatus($this->t('New appointment %label has been created.', $message_args));
        $this->logger('appointment')->notice('New appointment %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The appointment %label has been updated.', $message_args));
        $this->logger('appointment')->notice('The appointment %label has been updated.', $logger_args);
        break;

      default:
        throw new \LogicException('Could not save the entity.');
    }

    $operation = $this->getOperation();
    if (in_array($operation, ['personal_info', 'details'])) {
      $form_state->setRedirect('appointment.summary', ['appointment' => $this->entity->id()]);
    } else {
      $form_state->setRedirectUrl($this->entity->toUrl());
    }

    return $result;
  }
}
