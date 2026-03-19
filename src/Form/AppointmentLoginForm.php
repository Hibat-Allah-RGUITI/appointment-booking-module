<?php

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a login form for appointments using email.
 */
class AppointmentLoginForm extends FormBase {

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a new AppointmentLoginForm.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory) {
    $this->tempStore = $temp_store_factory->get('appointment_booking');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'appointment_login_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // If the user is already "connected" (Drupal user or session), redirect to list.
    $current_user = \Drupal::currentUser();
    $verified_email = $current_user->isAuthenticated() ? $current_user->getEmail() : $this->tempStore->get('verified_email');

    if ($verified_email) {
      return $this->redirect('appointment.my_appointments');
    }

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Enter your email'),
      '#description' => $this->t('Please enter the email address used for your appointments.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('View My Appointments'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $email = (string) $form_state->getValue('email');

    // Check if any appointment exists for this email.
    $query = \Drupal::entityQuery('appointment')
      ->accessCheck(TRUE)
      ->condition('field_customer_email', $email)
      ->range(0, 1);
    $ids = $query->execute();

    if (empty($ids)) {
      $this->messenger()->addError($this->t('No appointments found for this email address.'));
      $form_state->setRebuild();
      return;
    }

    // Store the verified email in the session.
    $this->tempStore->set('verified_email', $email);

    $form_state->setRedirect('appointment.my_appointments');
  }

}
