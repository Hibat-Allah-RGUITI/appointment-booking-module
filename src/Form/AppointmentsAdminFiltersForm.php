<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\appointment\Entity\AgencyEntity;
use Drupal\user\Entity\User;

/**
 * Admin filters form for the Appointments view.
 */
final class AppointmentsAdminFiltersForm extends FormBase {

  public function getFormId(): string {
    return 'appointment_appointments_admin_filters_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $query = $request->query;

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-admin-filters', 'container-inline']],
    ];

    $form['filters']['agency'] = [
      '#type' => 'select',
      '#title' => $this->t('Agency'),
      '#empty_option' => $this->t('- Any -'),
      '#options' => $this->getAgencyOptions(),
      '#default_value' => $query->get('agency') ?? '',
    ];

    $form['filters']['adviser'] = [
      '#type' => 'select',
      '#title' => $this->t('Adviser'),
      '#empty_option' => $this->t('- Any -'),
      '#options' => $this->getAdviserOptions(),
      '#default_value' => $query->get('adviser') ?? '',
    ];

    $form['filters']['appointment_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#empty_option' => $this->t('- Any -'),
      '#options' => $this->getStatusOptions(),
      '#default_value' => $query->get('appointment_status') ?? '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
    ];

    $form['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#submit' => ['::resetForm'],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $params = [];
    foreach (['agency', 'adviser', 'appointment_status'] as $key) {
      $value = (string) ($form_state->getValue($key) ?? '');
      if ($value !== '') {
        $params[$key] = $value;
      }
    }

    $form_state->setRedirect('<current>', [], ['query' => $params]);
  }

  public function resetForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('<current>');
  }

  private function getAgencyOptions(): array {
    $ids = \Drupal::entityQuery('agency')
      ->accessCheck(TRUE)
      ->sort('label')
      ->execute();
    if (!$ids) {
      return [];
    }
    $options = [];
    foreach (AgencyEntity::loadMultiple($ids) as $agency) {
      $options[(string) $agency->id()] = $agency->label();
    }
    return $options;
  }

  private function getAdviserOptions(): array {
    $uids = \Drupal::entityQuery('user')
      ->accessCheck(TRUE)
      ->condition('roles', 'adviser')
      ->sort('name')
      ->execute();
    if (!$uids) {
      return [];
    }
    $options = [];
    foreach (User::loadMultiple($uids) as $user) {
      $options[(string) $user->id()] = $user->getDisplayName();
    }
    return $options;
  }

  private function getStatusOptions(): array {
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('appointment', 'appointment');
    if (!isset($definitions['field_status'])) {
      return [];
    }
    $storage = $definitions['field_status']->getFieldStorageDefinition();
    $allowed = $storage->getSetting('allowed_values') ?? [];
    return is_array($allowed) ? $allowed : [];
  }

}

