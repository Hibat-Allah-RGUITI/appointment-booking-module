<?php

declare(strict_types=1);

namespace Drupal\appointment;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining an agency entity type.
 */
interface AgencyEntityInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
