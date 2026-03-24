<?php

declare(strict_types=1);

namespace Drupal\appointment\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\appointment\AppointmentEntityInterface;
use Drupal\appointment\AppointmentEntityListBuilder;
use Drupal\appointment\Form\AppointmentEntityForm;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the appointment entity class.
 */
#[ContentEntityType(
  id: 'appointment',
  label: new TranslatableMarkup('Appointment'),
  label_collection: new TranslatableMarkup('Appointments'),
  label_singular: new TranslatableMarkup('appointment'),
  label_plural: new TranslatableMarkup('appointments'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'langcode' => 'langcode',
    'label' => 'label',
    'owner' => 'uid',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => AppointmentEntityListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'add' => AppointmentEntityForm::class,
      'edit' => AppointmentEntityForm::class,
      'personal_info' => AppointmentEntityForm::class,
      'details' => AppointmentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
      'revision-delete' => RevisionDeleteForm::class,
      'revision-revert' => RevisionRevertForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/appointment',
    'add-form' => '/admin/content/appointment/add',
    'canonical' => '/admin/content/appointment/{appointment}',
    'edit-form' => '/admin/content/appointment/{appointment}/edit',
    'delete-form' => '/admin/content/appointment/{appointment}/delete',
    'delete-multiple-form' => '/admin/content/appointment/delete-multiple',
    'revision' => '/admin/content/appointment/{appointment}/revision/{appointment_revision}/view',
    'revision-delete-form' => '/admin/content/appointment/{appointment}/revision/{appointment_revision}/delete',
    'revision-revert-form' => '/admin/content/appointment/{appointment}/revision/{appointment_revision}/revert',
    'version-history' => '/admin/content/appointment/{appointment}/revisions',
  ],
  admin_permission: 'administer appointment',
  base_table: 'appointment',
  data_table: 'appointment_field_data',
  revision_table: 'appointment_revision',
  revision_data_table: 'appointment_field_revision',
  translatable: TRUE,
  show_revision_ui: TRUE,
  label_count: [
    'singular' => '@count appointments',
    'plural' => '@count appointments',
  ],
  field_ui_base_route: 'entity.appointment.settings',
  revision_metadata_keys: [
    'revision_user' => 'revision_uid',
    'revision_created' => 'revision_timestamp',
    'revision_log_message' => 'revision_log',
  ],
)]
class AppointmentEntity extends EditorialContentEntityBase implements AppointmentEntityInterface
{

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function label()
  {
    $label = parent::label();
    if ($label === NULL || $label === '') {
      $id = $this->id();
      if ($id !== NULL && $id !== '') {
        return (string) new TranslatableMarkup('Appointment @id', ['@id' => $id]);
      }
      return (string) new TranslatableMarkup('(no label)');
    }
    return $label;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void
  {
    parent::preSave($storage);
    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array
  {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setRevisionable(TRUE)
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(new TranslatableMarkup('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Authored on'))
      ->setTranslatable(TRUE)
      ->setDescription(new TranslatableMarkup('The time that the appointment was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setTranslatable(TRUE)
      ->setDescription(new TranslatableMarkup('The time that the appointment was last edited.'));

    return $fields;
  }
}
