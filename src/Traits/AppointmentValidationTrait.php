<?php

namespace Drupal\appointment\Traits;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\appointment\Entity\AgencyEntity;
use Drupal\user\Entity\User;

/**
 * Trait for appointment date validation logic.
 */
trait AppointmentValidationTrait
{

  private const AGENCY_OPENING_HOURS_FIELD = 'field_agency_opening_hours';
  private const ADVISER_WORKING_HOURS_FIELD = 'field_appointment_working_hours';

  /**
   * Validates that the appointment date is not in the past.
   */
  protected function validateDateNotInPast(FormStateInterface $form_state, DrupalDateTime $date, string $element_name): bool
  {
    $now = new DrupalDateTime('now', $date->getTimezone());
    if ($date->getTimestamp() < $now->getTimestamp()) {
      $form_state->setErrorByName($element_name, $this->t('You cannot book an appointment in the past. Please choose a future date and time.'));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Validates that there is no double booking for the adviser.
   */
  protected function validateNoDoubleBooking(FormStateInterface $form_state, string $agency_id, string $adviser_id, DrupalDateTime $date, string $element_name, ?int $exclude_id = NULL): bool
  {
    if ($adviser_id === '') {
      return TRUE;
    }

    $date_utc = clone $date;
    $date_utc->setTimezone(new \DateTimeZone('UTC'));
    $formatted = $date_utc->format('Y-m-d\\TH:i:s');

    $query = \Drupal::entityQuery('appointment')
      ->accessCheck(TRUE)
      ->condition('field_appointment_adviser', $adviser_id)
      ->condition('field_appointment_date', $formatted)
      ->condition('field_status', 'cancelled', '<>');

    if ($agency_id !== '') {
      $query->condition('field_appointment_agency', $agency_id);
    }

    if ($exclude_id) {
      $query->condition('id', $exclude_id, '<>');
    }

    $existing = $query->range(0, 1)->execute();
    if (!empty($existing)) {
      $form_state->setErrorByName($element_name, $this->t('This time slot is already booked. Please choose a different time.'));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Validates that the date is within agency opening hours.
   */
  protected function validateWithinAgencyHours(FormStateInterface $form_state, string $agency_id, DrupalDateTime $date, string $element_name): bool
  {
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

    // Ensure we check time in site/user timezone, not necessarily UTC.
    $local_date = clone $date;
    $local_date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $minutes = $this->timeToMinutes($local_date->format('H:i'));

    if ($minutes === NULL) {
      return TRUE;
    }
    if (!$this->isWithinHoursString($minutes, $hours)) {
      $form_state->setErrorByName($element_name, $this->t('This time slot is outside the agency opening hours. Please choose a time within the opening hours.'));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Validates that the date is within adviser working hours.
   */
  protected function validateWithinAdviserHours(FormStateInterface $form_state, string $adviser_id, DrupalDateTime $date, string $element_name): bool
  {
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

    // Ensure we check time in site/user timezone, not necessarily UTC.
    $local_date = clone $date;
    $local_date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $minutes = $this->timeToMinutes($local_date->format('H:i'));

    if ($minutes === NULL) {
      return TRUE;
    }
    if (!$this->isWithinHoursString($minutes, $hours)) {
      $form_state->setErrorByName($element_name, $this->t('This time slot is outside the selected adviser\'s working hours. Please choose a different time.'));
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Helper: convert HH:MM to minutes.
   */
  private function timeToMinutes(string $hhmm): ?int
  {
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

  /**
   * Helper: check if minutes is within comma-separated ranges.
   */
  private function isWithinHoursString(int $minutes, string $hours): bool
  {
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

  /**
   * Helper: parse a "HH:MM-HH:MM" range.
   */
  private function parseHoursRange(string $range): ?array
  {
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

  /**
   * Normalizes various date inputs into a DrupalDateTime object.
   */
  protected function normalizeDateValue(mixed $raw): ?DrupalDateTime
  {
    if ($raw instanceof DrupalDateTime) {
      $date = $raw;
    } else {
      // Unpack from common Drupal structures.
      if (is_array($raw)) {
        // Structure like [0 => ['value' => object|string]]
        if (isset($raw[0]['value'])) {
          return $this->normalizeDateValue($raw[0]['value']);
        }
        // Structure like ['value' => object|string]
        if (isset($raw['value'])) {
          return $this->normalizeDateValue($raw['value']);
        }

        // Handle custom form datetime structure: date/time parts
        if (isset($raw['date']) || isset($raw['time'])) {
          $date_part = isset($raw['date']) ? trim((string) $raw['date']) : '';
          $time_part = isset($raw['time']) ? trim((string) $raw['time']) : '';
          if ($date_part !== '' && $time_part !== '') {
            $raw_str = $date_part . 'T' . $time_part;
            if (!preg_match('/^\\d{1,2}:\\d{2}:\\d{2}$/', $time_part)) {
              $raw_str .= ':00';
            }
            try {
              $date = new DrupalDateTime($raw_str);
            } catch (\Exception) {
              return NULL;
            }
          } else {
            return NULL;
          }
        } else {
          return NULL;
        }
      } elseif (is_string($raw) && trim($raw) !== '') {
        try {
          $date = new DrupalDateTime($raw);
        } catch (\Exception) {
          return NULL;
        }
      } else {
        return NULL;
      }
    }

    // Always round to the minute for consistent comparison.
    if ($date) {
      $date->setTime((int) $date->format('H'), (int) $date->format('i'), 0);
    }

    return $date;
  }
}
