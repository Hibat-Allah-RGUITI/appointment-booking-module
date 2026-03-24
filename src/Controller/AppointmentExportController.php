<?php

namespace Drupal\appointment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Drupal\Core\Url;

/**
 * Controller for exporting appointments to CSV using Batch API.
 */
class AppointmentExportController extends ControllerBase
{

  /**
   * Starts the batch process for CSV export.
   */
  public function start(Request $request)
  {
    $filters = [
      'agency' => $request->query->get('agency'),
      'adviser' => $request->query->get('adviser'),
      'status' => $request->query->get('appointment_status'),
    ];

    $batch = [
      'title' => $this->t('Exporting Appointments to CSV...'),
      'operations' => [
        [[get_class($this), 'processBatch'], [$filters]],
      ],
      'finished' => [get_class($this), 'finishedBatch'],
    ];

    \batch_set($batch);
    return \batch_process(Url::fromRoute('entity.appointment.settings')->toString());
  }

  /**
   * Batch operation: processes appointments in chunks.
   */
  public static function processBatch($filters, &$context)
  {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = self::getCount($filters);

      // Create temporary file.
      $uri = 'temporary://appointments_export_' . time() . '.csv';
      $handle = fopen($uri, 'w');

      // Add CSV Header.
      fputcsv($handle, [
        'ID',
        'Title',
        'Customer Name',
        'Customer Email',
        'Date',
        'Agency',
        'Adviser',
        'Status'
      ]);

      fclose($handle);
      $context['results']['file_uri'] = $uri;
    }

    $limit = 50; // Process 50 rows per batch run.
    $ids = self::getAppointmentIds($filters, $context['sandbox']['progress'], $limit);

    if ($ids) {
      $handle = fopen($context['results']['file_uri'], 'a');
      $entity_type_manager = \Drupal::entityTypeManager();
      $appointments = $entity_type_manager->getStorage('appointment')->loadMultiple($ids);

      foreach ($appointments as $appointment) {
        /** @var \Drupal\appointment\Entity\AppointmentEntity $appointment */
        $agency = $appointment->get('field_appointment_agency')->entity;
        $adviser = $appointment->get('field_appointment_adviser')->entity;

        fputcsv($handle, [
          $appointment->id(),
          $appointment->label(),
          $appointment->get('field_customer_name')->value,
          $appointment->get('field_customer_email')->value,
          $appointment->get('field_appointment_date')->value,
          $agency ? $agency->label() : '',
          $adviser ? $adviser->getDisplayName() : '',
          $appointment->get('field_status')->value,
        ]);

        $context['sandbox']['progress']++;
      }

      fclose($handle);
    }

    $context['message'] = \Drupal::translation()->translate('Exporting @current of @total appointments...', [
      '@current' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['max'],
    ]);

    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    } else {
      $context['finished'] = 1;
    }
  }

  /**
   * Batch finished callback.
   */
  public static function finishedBatch($success, $results, $operations)
  {
    if ($success && !empty($results['file_uri'])) {
      $url = Url::fromRoute('appointment.export_download', ['uri' => base64_encode($results['file_uri'])]);
      \Drupal::messenger()->addStatus(\Drupal::translation()->translate('Export complete. <a href=":url">Click here to download the CSV file</a>.', [
        ':url' => $url->toString(),
      ]));
    } else {
      \Drupal::messenger()->addError(\Drupal::translation()->translate('An error occurred during the export.'));
    }
  }

  /**
   * Helper to get filtered appointment count.
   */
  protected static function getCount($filters)
  {
    $query = \Drupal::entityQuery('appointment')->accessCheck(TRUE);
    self::applyFilters($query, $filters);
    return (int) $query->count()->execute();
  }

  /**
   * Helper to get a chunk of appointment IDs.
   */
  protected static function getAppointmentIds($filters, $offset, $limit)
  {
    $query = \Drupal::entityQuery('appointment')
      ->accessCheck(TRUE)
      ->range($offset, $limit)
      ->sort('id', 'ASC');
    self::applyFilters($query, $filters);
    return $query->execute();
  }

  /**
   * Apply filters to the entity query.
   */
  protected static function applyFilters($query, $filters)
  {
    if (!empty($filters['agency'])) {
      $query->condition('field_appointment_agency', $filters['agency']);
    }
    if (!empty($filters['adviser'])) {
      $query->condition('field_appointment_adviser', $filters['adviser']);
    }
    if (!empty($filters['status'])) {
      $query->condition('field_status', $filters['status']);
    }
  }

  /**
   * Route callback to download the generated file.
   */
  public function download($uri)
  {
    $file_uri = base64_decode($uri);
    if (!file_exists($file_uri)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $response = new BinaryFileResponse($file_uri);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      'appointments_export_' . date('Y-m-d_His') . '.csv'
    );
    $response->deleteFileAfterSend(TRUE);

    return $response;
  }
}
