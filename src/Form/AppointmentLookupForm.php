<?php

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to lookup an appointment by phone number.
 */
class AppointmentLookupForm extends FormBase
{

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a new AppointmentLookupForm.
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
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'appointment_lookup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $appointment = NULL)
  {
    if (!$appointment) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $form_state->set('appointment_id', $appointment->id());
    // Persist the action from query string.
    $action = \Drupal::request()->query->get('action');
    if ($action) {
      $form_state->set('redirect_action', $action);
    }

    $form['phone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Verify your phone number'),
      '#description' => $this->t('Please enter the phone number associated with this specific appointment to continue.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify and Continue'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $phone = (string) $form_state->getValue('phone');
    $id = $form_state->get('appointment_id');

    if ($id) {
      /** @var \Drupal\appointment\Entity\AppointmentEntity $appointment */
      $appointment = \Drupal::entityTypeManager()->getStorage('appointment')->load($id);

      if (!$appointment || (string) $appointment->get('field_customer_phone')->value !== $phone) {
        $this->messenger()->addError($this->t('The phone number does not match our records for this appointment.'));
        $form_state->setRebuild();
        return;
      }
    }

    // Store the verified phone number for THIS specific appointment in the session.
    $this->tempStore->set('verified_phone_' . $id, $phone);

    // Redirect to the intended action or the summary.
    $action = $form_state->get('redirect_action');
    if ($id) {
      if ($action) {
        switch ($action) {
          case 'personal_info':
            $form_state->setRedirect('appointment.edit_personal', ['appointment' => $id]);
            break;
          case 'details':
            $form_state->setRedirect('appointment.edit_details', ['appointment' => $id]);
            break;
          case 'cancel':
            $form_state->setRedirect('appointment.cancel', ['appointment' => $id]);
            break;
          default:
            $form_state->setRedirect('appointment.summary', ['appointment' => $id]);
            break;
        }
      } else {
        // If an ID is present but no specific action, go to summary (the 2 sections page).
        $form_state->setRedirect('appointment.summary', ['appointment' => $id]);
      }
    } else {
      $form_state->setRedirect('appointment.my_appointments');
    }
  }
}
