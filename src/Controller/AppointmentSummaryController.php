<?php

namespace Drupal\appointment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\appointment\AppointmentEntityInterface;
use Drupal\Core\Url;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use Psr\Log\LoggerInterface;

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new AppointmentSummaryController.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, LoggerInterface $logger)
  {
    $this->tempStore = $temp_store_factory->get('appointment_booking');
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('tempstore.private'),
      $container->get('logger.factory')->get('appointment')
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
  public function list()
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

    // Send cancellation email.
    $email = $appointment->get('field_customer_email')->value;
    if ($email) {
      $mail_manager = \Drupal::service('plugin.manager.mail');
      $langcode = 'en';
      $params = [
        'title' => $appointment->label(),
        'name' => $appointment->get('field_customer_name')->value,
        'date' => $appointment->get('field_appointment_date')->value,
      ];
      $mail_manager->mail('appointment', 'appointment_cancellation', $email, $langcode, $params, NULL, TRUE);
    }

    $this->messenger()->addStatus($this->t('Appointment has been cancelled.'));

    return $this->redirect('appointment.summary', ['appointment' => $appointment->id()]);
  }

  /**
   * JSON response for existing appointments (FullCalendar events).
   */
  public function getEvents(Request $request): JsonResponse
  {
    $adviser_id = $request->query->get('adviser');
    $agency_id = $request->query->get('agency');

    $this->logger->info('getEvents called with adviser: @adviser, agency: @agency', [
      '@adviser' => $adviser_id,
      '@agency' => $agency_id,
    ]);

    if (!$adviser_id) {
      $this->logger->warning('getEvents: Missing adviser ID.');
      return new JsonResponse([]);
    }

    $query = \Drupal::entityQuery('appointment')
      ->accessCheck(TRUE)
      ->condition('field_appointment_adviser', $adviser_id)
      ->condition('field_status', 'cancelled', '<>')
      ->sort('field_appointment_date', 'ASC');

    if ($agency_id) {
      $query->condition('field_appointment_agency', $agency_id);
    }

    $ids = $query->execute();

    $this->logger->info('getEvents: Found @count appointments.', ['@count' => count($ids)]);

    /** @var \Drupal\appointment\AppointmentEntityInterface[] $appointments */
    $appointments = \Drupal::entityTypeManager()->getStorage('appointment')->loadMultiple($ids);

    $events = [];
    foreach ($appointments as $appointment) {
      $date_val = $appointment->get('field_appointment_date')->value;
      if ($date_val) {
        // Drupal stores dates in UTC.
        $start = new \DateTime($date_val, new \DateTimeZone('UTC'));
        $end = clone $start;
        $end->modify('+30 minutes'); // Fixed duration for visualization

        $events[] = [
          'title' => $this->t('Busy')->render(),
          'start' => $start->format('Y-m-d\TH:i:s\Z'),
          'end' => $end->format('Y-m-d\TH:i:s\Z'),
          'color' => '#ff0000',
          'display' => 'background', // Or 'block' to see the title
        ];
      }
    }

    return new JsonResponse($events);
  }
}
