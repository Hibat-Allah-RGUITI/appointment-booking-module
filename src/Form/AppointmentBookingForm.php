<?php

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\appointment\Entity\AppointmentEntity;
use Drupal\user\Entity\User;
use Drupal\taxonomy\Entity\Term;
use Drupal\appointment\Entity\AgencyEntity;
use Drupal\appointment\Traits\AppointmentValidationTrait;

/**
 * Multi-step booking form for appointments.
 */
class AppointmentBookingForm extends FormBase
{

  use AppointmentValidationTrait;

  protected $tempStore;

  private const DATE_ERROR_NAME = 'appointment_date';

  public function __construct(PrivateTempStoreFactory $temp_store_factory)
  {
    $this->tempStore = $temp_store_factory->get('appointment_booking');
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('tempstore.private')
    );
  }

  public function getFormId()
  {
    return 'appointment_booking_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {

    $step = $form_state->get('step') ?? 1;

    $form['adviser_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'adviser-wrapper'],
      '#attached' => [],
    ];

    switch ($step) {

      case 1:
        $form['agency'] = [
          '#type' => 'select',
          '#title' => $this->t('Select agency'),
          '#options' => $this->getAgencyOptions(),
          '#empty_option' => $this->t('- Select -'),
          '#required' => TRUE,
          '#ajax' => [
            'callback' => '::updateAdviserOptions',
            'wrapper' => 'adviser-wrapper',
            'event' => 'change',
          ],
        ];
        break;

      case 2:
        $form['appointment_type'] = [
          '#type' => 'select',
          '#title' => $this->t('Appointment type'),
          '#options' => $this->getAppointmentTypeOptions(),
          '#empty_option' => $this->t('- Select -'),
          '#required' => TRUE,
        ];
        break;

      case 3:
        $form['adviser_container']['adviser'] = [
          '#type' => 'select',
          '#title' => $this->t('Select adviser'),
          '#options' => $this->getAvailableAdvisers(),
          '#required' => TRUE,
        ];
        break;

      case 4:
        $form['appointment_date'] = [
          '#type' => 'datetime',
          '#title' => $this->t('Appointment date'),
          '#required' => TRUE,
        ];
        break;

      case 5:
        $form['customer_name'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Your name'),
          '#required' => TRUE,
        ];

        $form['customer_email'] = [
          '#type' => 'email',
          '#title' => $this->t('Your email'),
          '#required' => TRUE,
        ];

        $form['customer_phone'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Phone'),
        ];
        break;

      case 6:
        $form['summary'] = [
          '#markup' => $this->t('Confirm your appointment and submit.'),
        ];
        break;
    }

    $form['actions'] = ['#type' => 'actions'];

    if ($step > 1) {
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::submitPrevious'],
        '#limit_validation_errors' => [],
      ];
    }

    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $step == 6 ? $this->t('Confirm booking') : $this->t('Next'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $step = (int) ($form_state->get('step') ?? 1);

    if ($step === 3) {
      $adviser_id = (string) ($form_state->getValue(['adviser_container', 'adviser']) ?? '');
      if ($adviser_id !== '') {
        $this->tempStore->set('selected_adviser', $adviser_id);
      }
    }

    if ($step === 4) {
      $date = $this->normalizeDateValue($form_state->getValue('appointment_date'));
      if (!$date) {
        $form_state->setErrorByName(self::DATE_ERROR_NAME, $this->t('Please select a valid appointment date and time.'));
        return;
      }

      if (!$this->validateDateNotInPast($form_state, $date, self::DATE_ERROR_NAME)) {
        return;
      }

      $agency_id = (string) ($this->tempStore->get('selected_agency') ?? '');
      $adviser_id = (string) ($this->tempStore->get('selected_adviser') ?? '');

      $this->validateWithinAgencyHours($form_state, $agency_id, $date, self::DATE_ERROR_NAME);
      $this->validateWithinAdviserHours($form_state, $adviser_id, $date, self::DATE_ERROR_NAME);
      if ($adviser_id !== '') {
        $this->validateNoDoubleBooking($form_state, $agency_id, $adviser_id, $date, self::DATE_ERROR_NAME);
      }
    }
  }

  public function submitPrevious(array &$form, FormStateInterface $form_state): void
  {
    $step = $form_state->get('step') ?? 1;
    $this->tempStore->set('step_' . $step, $form_state->getValues());
    $form_state->set('step', max(1, $step - 1));
    $form_state->setRebuild();
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {

    $step = $form_state->get('step') ?? 1;

    $this->tempStore->set('step_' . $step, $form_state->getValues());

    if ($step === 1) {
      $this->tempStore->set('selected_agency', $form_state->getValue('agency'));
    }
    if ($step === 2) {
      $this->tempStore->set('selected_type', $form_state->getValue('appointment_type'));
    }
    if ($step === 3) {
      $this->tempStore->set('selected_adviser', $form_state->getValue(['adviser_container', 'adviser']));
    }

    if ($step < 6) {
      $form_state->set('step', $step + 1);
      $form_state->setRebuild();
      return;
    }

    $data = [];
    for ($i = 1; $i <= 5; $i++) {
      $step_data = $this->tempStore->get('step_' . $i);
      if ($step_data) {
        $data = array_merge($data, $step_data);
      }
    }

    $date = $this->normalizeDateValue($data['appointment_date'] ?? NULL);
    if (!$date) {
      $this->messenger()->addError($this->t('Please select a valid appointment date and time.'));
      $form_state->set('step', 4);
      $form_state->setRebuild();
      return;
    }

    $agency_id = (string) ($this->tempStore->get('selected_agency') ?? ($data['agency'] ?? ''));
    $adviser_id = (string) ($this->tempStore->get('selected_adviser') ?? ($data['adviser'] ?? ''));

    if (
      !$this->validateDateNotInPast($form_state, $date, self::DATE_ERROR_NAME) ||
      !$this->validateWithinAgencyHours($form_state, $agency_id, $date, self::DATE_ERROR_NAME) ||
      !$this->validateWithinAdviserHours($form_state, $adviser_id, $date, self::DATE_ERROR_NAME) ||
      ($adviser_id !== '' && !$this->validateNoDoubleBooking($form_state, $agency_id, $adviser_id, $date, self::DATE_ERROR_NAME))
    ) {
      $form_state->set('step', 4);
      $form_state->setRebuild();
      return;
    }

    // Prepare the date in UTC for storage.
    $storage_date = clone $date;
    $storage_date->setTimezone(new \DateTimeZone('UTC'));
    $formatted_date = $storage_date->format('Y-m-d\\TH:i:s');

    try {
      $appointment = AppointmentEntity::create([
        'title' => 'Rdv ' . (($data['customer_name'] ?? '') ?: 'Anonyme'),
        'field_appointment_agency' => $data['agency'] ?? NULL,
        'field_appointment_type' => $data['appointment_type'] ?? NULL,
        'field_appointment_adviser' => $data['adviser'] ?? ($this->tempStore->get('selected_adviser') ?? NULL),
        'field_appointment_date' => $formatted_date,
        'field_customer_name' => $data['customer_name'] ?? NULL,
        'field_customer_email' => $data['customer_email'] ?? NULL,
        'field_customer_phone' => $data['customer_phone'] ?? NULL,
        'field_status' => 'pending',
      ]);

      $appointment->save();
      $this->messenger()->addMessage($this->t('Appointment successfully booked. ID: @id', ['@id' => $appointment->id()]));

      $email = $data['customer_email'] ?? '';
      $phone = $data['customer_phone'] ?? '';

      // Send confirmation email.
      if ($email !== '') {
        $mail_manager = \Drupal::service('plugin.manager.mail');
        $langcode = 'en';
        $params = [
          'title' => $appointment->label(),
          'name' => $appointment->get('field_customer_name')->value,
          'date' => $appointment->get('field_appointment_date')->value,
          'agency' => $appointment->get('field_appointment_agency')->entity ? $appointment->get('field_appointment_agency')->entity->label() : '',
          'type' => $appointment->get('field_appointment_type')->entity ? $appointment->get('field_appointment_type')->entity->label() : '',
        ];
        $mail_manager->mail('appointment', 'appointment_confirmation', $email, $langcode, $params, NULL, TRUE);
      }

      for ($i = 1; $i <= 5; $i++) {
        $this->tempStore->delete('step_' . $i);
      }

      // Store the verified email in session so the user can access their list.
      if ($email !== '') {
        $this->tempStore->set('verified_email', (string) $email);
      }

      $form_state->setRedirect('appointment.my_appointments');
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error saving appointment: @msg', ['@msg' => $e->getMessage()]));
    }
  }

  public function updateAdviserOptions(array &$form, FormStateInterface $form_state)
  {
    $this->tempStore->set('selected_agency', $form_state->getValue('agency'));
    $this->tempStore->set('selected_type', $form_state->getValue('appointment_type'));

    return $form['adviser_container'];
  }

  protected function getAgencyOptions(): array
  {
    $ids = \Drupal::entityQuery('agency')
      ->accessCheck(TRUE)
      ->sort('label')
      ->execute();
    if (!$ids) {
      return [];
    }
    $options = [];
    foreach (AgencyEntity::loadMultiple($ids) as $agency) {
      $options[$agency->id()] = $agency->label();
    }
    return $options;
  }

  protected function getAppointmentTypeOptions(): array
  {
    $tids = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(TRUE)
      ->condition('vid', 'appointment_type')
      ->sort('name')
      ->execute();
    if (!$tids) {
      return [];
    }
    $options = [];
    foreach (Term::loadMultiple($tids) as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  protected function getAvailableAdvisers(): array
  {
    $agency = $this->tempStore->get('selected_agency') ?? NULL;
    $type = $this->tempStore->get('selected_type') ?? NULL;

    if (!$agency || !$type) {
      return [];
    }

    $field_manager = \Drupal::service('entity_field.manager');
    $user_fields = $field_manager->getFieldDefinitions('user', 'user');

    $query = \Drupal::entityQuery('user')
      ->accessCheck(TRUE)
      ->condition('roles', 'adviser');

    $agency_field_candidates = [
      'field_appointment_agency_reference',
      'field_appointment_agency',
      'appointment_agency_reference',
      'appointment_agency',
    ];
    foreach ($agency_field_candidates as $field_name) {
      if (isset($user_fields[$field_name])) {
        $key = $user_fields[$field_name]->getType() === 'entity_reference' ? ($field_name . '.target_id') : $field_name;
        $query->condition($key, $agency);
        break;
      }
    }

    $type_field_candidates = [
      'field_appointment_specializations',
      'field_appointment_specialization',
      'appointment_specializations',
      'appointment_specialization',
    ];
    foreach ($type_field_candidates as $field_name) {
      if (isset($user_fields[$field_name])) {
        $key = $user_fields[$field_name]->getType() === 'entity_reference' ? ($field_name . '.target_id') : $field_name;
        $query->condition($key, $type);
        break;
      }
    }

    $uids = $query->execute();
    $options = [];

    foreach (User::loadMultiple($uids) as $user) {
      $options[$user->id()] = $user->getDisplayName();
    }

    return $options;
  }
}
