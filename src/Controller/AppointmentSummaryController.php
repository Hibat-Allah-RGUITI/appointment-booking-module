<?php

namespace Drupal\appointment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\appointment\AppointmentEntityInterface;
use Drupal\Core\Url;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for the appointment summary page.
 */
class AppointmentSummaryController extends ControllerBase
{

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a new AppointmentSummaryController.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory)
  {
    $this->tempStore = $temp_store_factory->get('appointment_booking');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('tempstore.private')
    );
  }

  /**
   * Shows the appointment summary with edit options.
   *
   * @param \Drupal\appointment\AppointmentEntityInterface $appointment
   *   The appointment entity.
   *
   * @return array
   *   The render array.
   */
  public function show(AppointmentEntityInterface $appointment)
  {
    // 1. Security: verify the email in session matches the appointment.
    $current_user = \Drupal::currentUser();
    $verified_email = $current_user->isAuthenticated() ? $current_user->getEmail() : $this->tempStore->get('verified_email');
    $appointment_email = (string) $appointment->get('field_customer_email')->value;

    if ($verified_email !== $appointment_email) {
      $this->messenger()->addError($this->t('Please login with your email to access this appointment.'));
      return $this->redirect('appointment.login');
    }

    // 2. Navigation Security: check if the user comes from the list page.
    // We check either the HTTP Referer or a session flag.
    $request = \Drupal::request();
    $referer = $request->headers->get('referer');
    $from_list_session = $this->tempStore->get('allowed_from_list');

    // We allow access if:
    // - The referer contains '/my-appointments'
    // - OR the session flag is set (for page refreshes)
    $is_from_list = ($referer && str_contains($referer, '/my-appointments')) || $from_list_session;

    if (!$is_from_list) {
      $this->messenger()->addWarning($this->t('Please access appointment details from your list.'));
      return $this->redirect('appointment.my_appointments');
    }

    return [
      '#theme' => 'appointment_summary',
      '#appointment' => $appointment,
      '#personal_info_edit_url' => Url::fromRoute('appointment.lookup', ['appointment' => $appointment->id()], ['query' => ['action' => 'personal_info']])->toString(),
      '#appointment_details_edit_url' => Url::fromRoute('appointment.lookup', ['appointment' => $appointment->id()], ['query' => ['action' => 'details']])->toString(),
      '#appointment_cancel_url' => Url::fromRoute('appointment.lookup', ['appointment' => $appointment->id()], ['query' => ['action' => 'cancel']])->toString(),
      '#my_appointments_url' => Url::fromRoute('appointment.my_appointments')->toString(),
    ];
  }

  /**
   * Lists all appointments for the logged-in user.
   */
  public function listMy()
  {
    $current_user = \Drupal::currentUser();
    $verified_email = '';

    // 1. Try to get email from logged-in Drupal user.
    if ($current_user->isAuthenticated()) {
      $verified_email = $current_user->getEmail();
    }
    // 2. Fallback to email stored in session (for anonymous users who just booked or logged in).
    else {
      $verified_email = (string) $this->tempStore->get('verified_email');
    }

    if (!$verified_email) {
      $this->messenger()->addError($this->t('Please login to see your appointments.'));
      return $this->redirect('appointment.login');
    }

    // Set a session flag to allow viewing details from this list.
    $this->tempStore->set('allowed_from_list', TRUE);

    $query = \Drupal::entityQuery('appointment')
      ->accessCheck(TRUE)
      ->condition('field_customer_email', $verified_email)
      ->sort('field_appointment_date', 'DESC');
    $ids = $query->execute();

    /** @var \Drupal\appointment\AppointmentEntityInterface[] $appointments */
    $appointments = \Drupal::entityTypeManager()->getStorage('appointment')->loadMultiple($ids);

    $rows = [];
    foreach ($appointments as $appointment) {
      $agency_label = '';
      if ($appointment->hasField('field_appointment_agency') && !$appointment->get('field_appointment_agency')->isEmpty()) {
        /** @var \Drupal\Core\Entity\EntityInterface $agency */
        $agency = $appointment->get('field_appointment_agency')->entity;
        $agency_label = $agency ? $agency->label() : '';
      }

      $type_label = '';
      if ($appointment->hasField('field_appointment_type') && !$appointment->get('field_appointment_type')->isEmpty()) {
        /** @var \Drupal\Core\Entity\EntityInterface $type */
        $type = $appointment->get('field_appointment_type')->entity;
        $type_label = $type ? $type->label() : '';
      }

      $rows[] = [
        'date' => $appointment->get('field_appointment_date')->value,
        'type' => $type_label,
        'agency' => $agency_label,
        'status' => (string) $appointment->get('field_status')->value,
        'operations' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('View Details'),
            '#url' => Url::fromRoute('appointment.summary', ['appointment' => $appointment->id()]),
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('Type'),
        $this->t('Agency'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No appointments found.'),
    ];
  }

  /**
   * Cancels the appointment (sets status to cancelled).
   *
   * @param \Drupal\appointment\AppointmentEntityInterface $appointment
   *   The appointment entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirects back to the summary page.
   */
  public function cancel(AppointmentEntityInterface $appointment)
  {
    // Security: verify the phone number in session matches the appointment.
    $verified_phone = (string) $this->tempStore->get('verified_phone_' . $appointment->id());
    $appointment_phone = (string) $appointment->get('field_customer_phone')->value;

    if ($verified_phone !== $appointment_phone) {
      $this->messenger()->addError($this->t('Please verify your phone number to cancel this appointment.'));
      return $this->redirect('appointment.lookup', ['appointment' => $appointment->id()], ['query' => ['action' => 'cancel']]);
    }

    $appointment->set('field_status', 'cancelled');
    $appointment->save();

    $this->messenger()->addStatus($this->t('Appointment has been cancelled.'));

    return $this->redirect('appointment.summary', ['appointment' => $appointment->id()]);
  }
}
