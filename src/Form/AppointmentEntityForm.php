<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\appointment\Traits\AppointmentValidationTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the appointment entity edit forms.
 */
final class AppointmentEntityForm extends ContentEntityForm
{

  use AppointmentValidationTrait;

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a new AppointmentEntityForm.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(
    $entity_repository,
    $entity_type_bundle_info,
    $time,
    PrivateTempStoreFactory $temp_store_factory
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->tempStore = $temp_store_factory->get('appointment_booking');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $operation = $this->getOperation();

    if (in_array($operation, ['personal_info', 'details'])) {
      // Security: verify the phone number in session matches the appointment.
      $verified_phone = (string) $this->tempStore->get('verified_phone_' . $this->entity->id());
      $appointment_phone = (string) $this->entity->get('field_customer_phone')->value;

      if ($verified_phone !== $appointment_phone) {
        $this->messenger()->addError($this->t('Please verify your phone number to modify this appointment.'));
        $form_state->setRedirect('appointment.lookup', ['appointment' => $this->entity->id()], ['query' => ['action' => $operation]]);
        return [];
      }
    }

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

      // Update the entity with the normalized date (UTC and rounded to minute).
      if (!$form_state->hasAnyErrors()) {
        $storage_date = clone $date;
        $storage_date->setTimezone(new \DateTimeZone('UTC'));
        $entity->set('field_appointment_date', $storage_date->format('Y-m-d\\TH:i:s'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int
  {
    $entity = $this->entity;
    $operation = $this->getOperation();

    
    
    
    if (in_array($operation, ['personal_info', 'details'])) {
      $original_entity = \Drupal::entityTypeManager()
        ->getStorage('appointment')
        ->loadUnchanged($entity->id());

      if ($original_entity) {
        if ($operation === 'personal_info') {
          $entity->set('field_appointment_date', $original_entity->get('field_appointment_date')->value);
          $entity->set('field_appointment_agency', $original_entity->get('field_appointment_agency')->target_id);
          $entity->set('field_appointment_adviser', $original_entity->get('field_appointment_adviser')->target_id);
          $entity->set('field_appointment_type', $original_entity->get('field_appointment_type')->target_id);
        }
        elseif ($operation === 'details') {
          $entity->set('field_customer_name', $original_entity->get('field_customer_name')->value);
          $entity->set('field_customer_email', $original_entity->get('field_customer_email')->value);
          $entity->set('field_customer_phone', $original_entity->get('field_customer_phone')->value);
        }
      }
    }

    $result = parent::save($form, $form_state);

    $label = $entity->label() ?: $this->t('(no label)');
    $message_args = ['%label' => $entity->toLink($label)->toString()];
    $logger_args = [
      '%label' => $label,
      'link' => $entity->toUrl('canonical')->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New appointment %label has been created.', $message_args));
        $this->logger('appointment')->notice('New appointment %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The appointment %label has been updated.', $message_args));
        $this->logger('appointment')->notice('The appointment %label has been updated.', $logger_args);

        $email = $entity->get('field_customer_email')->value;
        if ($email) {
          $mail_manager = \Drupal::service('plugin.manager.mail');
          $langcode = $entity->language()->getId();
          
          $params = [
            'title' => (string) $entity->label(),
            'name'  => (string) $entity->get('field_customer_name')->value,
            'date'  => (string) $entity->get('field_appointment_date')->value,
            'agency'=> $entity->get('field_appointment_agency')->entity ? $entity->get('field_appointment_agency')->entity->label() : '',
          ];
          
          $mail_manager->mail('appointment', 'appointment_modification', $email, $langcode, $params, NULL, TRUE);
        }
        break;

      default:
        throw new \LogicException('Could not save the entity.');
    }

    if (in_array($operation, ['personal_info', 'details'])) {
      $form_state->setRedirect('appointment.summary', ['appointment' => $entity->id()]);
    } else {
      $form_state->setRedirectUrl($entity->toUrl());
    }

    return $result;
  }
}