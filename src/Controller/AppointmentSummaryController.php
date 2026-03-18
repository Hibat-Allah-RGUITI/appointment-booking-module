<?php

namespace Drupal\appointment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\appointment\AppointmentEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Controller for the appointment summary page.
 */
class AppointmentSummaryController extends ControllerBase
{

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
    return [
      '#theme' => 'appointment_summary',
      '#appointment' => $appointment,
      '#personal_info_edit_url' => Url::fromRoute('appointment.edit_personal', ['appointment' => $appointment->id()])->toString(),
      '#appointment_details_edit_url' => Url::fromRoute('appointment.edit_details', ['appointment' => $appointment->id()])->toString(),
      '#appointment_cancel_url' => Url::fromRoute('appointment.cancel', ['appointment' => $appointment->id()])->toString(),
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
    $appointment->set('field_status', 'cancelled');
    $appointment->save();

    $this->messenger()->addStatus($this->t('Appointment has been cancelled.'));

    return $this->redirect('appointment.summary', ['appointment' => $appointment->id()]);
  }
}
