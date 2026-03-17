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

class AppointmentBookingForm extends FormBase {

  protected $tempStore;

  private const AGENCY_OPENING_HOURS_FIELD = 'field_agency_opening_hours';
  private const ADVISER_WORKING_HOURS_FIELD = 'field_appointment_working_hours';
  private const DATE_ERROR_NAME = 'appointment_date';

  public function __construct(PrivateTempStoreFactory $temp_store_factory) {
    $this->tempStore = $temp_store_factory->get('appointment_booking');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private')
    );
  }

  public function getFormId() {
    return 'appointment_booking_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    $step = $form_state->get('step') ?? 1;

    // Always define wrappers so AJAX callbacks never return an empty array.
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

    // Actions
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

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
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

      $now = new DrupalDateTime('now', $date->getTimezone());
      if ($date->getTimestamp() < $now->getTimestamp()) {
        $form_state->setErrorByName(self::DATE_ERROR_NAME, $this->t('You cannot book an appointment in the past. Please choose a future date and time.'));
        return;
      }

      $agency_id = (string) ($this->tempStore->get('selected_agency') ?? '');
      $adviser_id = (string) ($this->tempStore->get('selected_adviser') ?? '');

      $this->validateWithinAgencyHours($form_state, $agency_id, $date, TRUE, self::DATE_ERROR_NAME);
      $this->validateWithinAdviserHours($form_state, $adviser_id, $date, TRUE, self::DATE_ERROR_NAME);
      if ($adviser_id !== '') {
        $this->validateNoDoubleBooking($form_state, $agency_id, $adviser_id, $date, TRUE, self::DATE_ERROR_NAME);
      }
    }
  }

  /**
   * Submit handler for the Back button.
   */
  public function submitPrevious(array &$form, FormStateInterface $form_state): void {
    $step = $form_state->get('step') ?? 1;
    $this->tempStore->set('step_' . $step, $form_state->getValues());
    $form_state->set('step', max(1, $step - 1));
    $form_state->setRebuild();
  }

  /**
   * SubmitForm principal
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $step = $form_state->get('step') ?? 1;

    $this->tempStore->set('step_' . $step, $form_state->getValues());

    // Persist selected values for later validations.
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

    // Compilation finale des données.
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

    $now = new DrupalDateTime('now', $date->getTimezone());
    if ($date->getTimestamp() < $now->getTimestamp()) {
      $this->messenger()->addError($this->t('You cannot book an appointment in the past. Please choose a future date and time.'));
      $form_state->set('step', 4);
      $form_state->setRebuild();
      return;
    }

    $agency_id = (string) ($this->tempStore->get('selected_agency') ?? ($data['agency'] ?? ''));
    $adviser_id = (string) ($this->tempStore->get('selected_adviser') ?? ($data['adviser'] ?? ''));

    $ok = TRUE;
    $ok = $this->validateWithinAgencyHours($form_state, $agency_id, $date, FALSE) && $ok;
    $ok = $this->validateWithinAdviserHours($form_state, $adviser_id, $date, FALSE) && $ok;
    $ok = $this->validateNoDoubleBooking($form_state, $agency_id, $adviser_id, $date, FALSE) && $ok;
    if (!$ok) {
      $form_state->set('step', 4);
      $form_state->setRebuild();
      return;
    }

    $formatted_date = $date->format('Y-m-d\\TH:i:s');

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

      for ($i = 1; $i <= 5; $i++) {
        $this->tempStore->delete('step_' . $i);
      }

      $form_state->setRedirect('<front>');

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error saving appointment: @msg', ['@msg' => $e->getMessage()]));
    }
  }

  /**
   * Callback AJAX for updating advisers.
   *
   * Kept for compatibility, but not used by the current selects.
   */
  public function updateAdviserOptions(array &$form, FormStateInterface $form_state) {
    $this->tempStore->set('selected_agency', $form_state->getValue('agency'));
    $this->tempStore->set('selected_type', $form_state->getValue('appointment_type'));

    return $form['adviser_container'];
  }

  /**
   * Options list for agency select.
   */
  protected function getAgencyOptions(): array {
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

  /**
   * Options list for appointment type select.
   */
  protected function getAppointmentTypeOptions(): array {
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

  /**
   * Get the list of available advisers 
   */
  protected function getAvailableAdvisers(): array {
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

  protected function validateNoDoubleBooking(FormStateInterface $form_state, string $agency_id, string $adviser_id, DrupalDateTime $date, bool $set_form_errors = TRUE, string $date_error_name = self::DATE_ERROR_NAME): bool {
    if ($adviser_id === '') {
      $message = $this->t('Please select an adviser to continue.');
      if ($set_form_errors) {
        $form_state->setErrorByName('adviser_container][adviser', $message);
      }
      else {
        $this->messenger()->addError($message);
      }
      return FALSE;
    }

    $formatted = $date->format('Y-m-d\\TH:i:s');
    $query = \Drupal::entityQuery('appointment')
      ->accessCheck(TRUE)
      ->condition('field_appointment_adviser', $adviser_id)
      ->condition('field_appointment_date', $formatted);

    if ($agency_id !== '') {
      $query->condition('field_appointment_agency', $agency_id);
    }
    $query->condition('field_status', 'cancelled', '<>');

    $existing = $query->range(0, 1)->execute();
    if (!empty($existing)) {
      $message = $this->t('This time slot is already booked. Please choose a different time.');
      if ($set_form_errors) {
        $form_state->setErrorByName($date_error_name, $message);
      }
      else {
        $this->messenger()->addError($message);
      }
      return FALSE;
    }
    return TRUE;
  }

  protected function validateWithinAgencyHours(FormStateInterface $form_state, string $agency_id, DrupalDateTime $date, bool $set_form_errors = TRUE, string $date_error_name = self::DATE_ERROR_NAME): bool {
    if ($agency_id === '') {
      return TRUE;
    }
    $agency = AgencyEntity::load($agency_id);
    if (!$agency || !$agency->hasField(self::AGENCY_OPENING_HOURS_FIELD)) {
      return TRUE;
    }
    $hours = (string) $agency->get(self::AGENCY_OPENING_HOURS_FIELD)->value;
    if (trim($hours) === '') {
      return TRUE;
    }

    $minutes = $this->timeToMinutes($date->format('H:i'));
    if ($minutes === NULL) {
      return TRUE;
    }
    if (!$this->isWithinHoursString($minutes, $hours)) {
      $message = $this->t('This time slot is outside the agency opening hours. Please choose a time within the opening hours.');
      if ($set_form_errors) {
        $form_state->setErrorByName($date_error_name, $message);
      }
      else {
        $this->messenger()->addError($message);
      }
      return FALSE;
    }
    return TRUE;
  }

  protected function validateWithinAdviserHours(FormStateInterface $form_state, string $adviser_id, DrupalDateTime $date, bool $set_form_errors = TRUE, string $date_error_name = self::DATE_ERROR_NAME): bool {
    if ($adviser_id === '') {
      return TRUE;
    }
    $adviser = User::load($adviser_id);
    if (!$adviser || !$adviser->hasField(self::ADVISER_WORKING_HOURS_FIELD)) {
      return TRUE;
    }
    $hours = (string) $adviser->get(self::ADVISER_WORKING_HOURS_FIELD)->value;
    if (trim($hours) === '') {
      return TRUE;
    }

    $minutes = $this->timeToMinutes($date->format('H:i'));
    if ($minutes === NULL) {
      return TRUE;
    }
    if (!$this->isWithinHoursString($minutes, $hours)) {
      $message = $this->t('This time slot is outside the selected adviser\'s working hours. Please choose a different time.');
      if ($set_form_errors) {
        $form_state->setErrorByName($date_error_name, $message);
      }
      else {
        $this->messenger()->addError($message);
      }
      return FALSE;
    }
    return TRUE;
  }

  private function timeToMinutes(string $hhmm): ?int {
    if (!preg_match('/^(?<h>\\d{1,2}):(?<m>\\d{2})$/', trim($hhmm), $m)) {
      return NULL;
    }
    $h = (int) $m['h'];
    $min = (int) $m['m'];
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
      return NULL;
    }
    return ($h * 60) + $min;
  }

  private function isWithinHoursString(int $minutes, string $hours): bool {
    $ranges = array_filter(array_map('trim', preg_split('/\\s*,\\s*/', trim($hours)) ?: []));
    foreach ($ranges as $range) {
      $parsed = $this->parseHoursRange($range);
      if ($parsed === NULL) {
        continue;
      }
      [$open_min, $close_min] = $parsed;
      if ($minutes >= $open_min && $minutes <= $close_min) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function parseHoursRange(string $range): ?array {
    if (!preg_match('/^(?<start>\\d{1,2}:\\d{2})\\s*-\\s*(?<end>\\d{1,2}:\\d{2})$/', trim($range), $m)) {
      return NULL;
    }
    $start = $this->timeToMinutes($m['start']);
    $end = $this->timeToMinutes($m['end']);
    if ($start === NULL || $end === NULL || $end < $start) {
      return NULL;
    }
    return [$start, $end];
  }

  private function normalizeDateValue(mixed $raw): ?DrupalDateTime {
    if ($raw instanceof DrupalDateTime) {
      return $raw;
    }

    if (is_array($raw)) {
      if (array_key_exists('value', $raw)) {
        $raw = $raw['value'];
      }
      if (is_array($raw) && (isset($raw['date']) || isset($raw['time']))) {
        $date_part = isset($raw['date']) ? trim((string) $raw['date']) : '';
        $time_part = isset($raw['time']) ? trim((string) $raw['time']) : '';
        if ($date_part !== '' && $time_part !== '') {
          // Time may be HH:MM or HH:MM:SS.
          if (preg_match('/^\\d{1,2}:\\d{2}:\\d{2}$/', $time_part)) {
            $raw = $date_part . 'T' . $time_part;
          }
          else {
            $raw = $date_part . 'T' . $time_part . ':00';
          }
        }
        elseif ($date_part !== '' && $time_part === '') {
          // No time selected yet.
          return NULL;
        }
      }
    }

    if (!is_string($raw) || trim($raw) === '') {
      return NULL;
    }

    try {
      return new DrupalDateTime($raw);
    }
    catch (\Exception) {
      return NULL;
    }
  }

}