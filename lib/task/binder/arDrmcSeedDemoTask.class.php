<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Seed demo artworks, components, a supporting technology record and fake
 * AIPs directly through Propel (no Archivematica, no TMS), mimicking the
 * object graph built by qtPackageExtractorMETSArchivematicaDIP and
 * arFetchTmsV1. Idempotent: safe to run repeatedly.
 *
 * Run "php symfony search:populate" afterwards to reindex.
 *
 * @package    symfony
 * @subpackage task
 */
class arDrmcSeedDemoTask extends sfBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', 'qubit'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'cli'),
    ));

    $this->namespace = 'binder';
    $this->name = 'seed-demo';

    $this->briefDescription = 'Seed demo artworks, components, tech record and AIPs';
    $this->detailedDescription = <<<EOF
The [binder:seed-demo|INFO] task adds demo artwork records (with components,
one supporting technology record and fake AIPs) so the UI can be exercised
without Archivematica or TMS. Run search:populate afterwards.
EOF;
  }

  public function execute($arguments = array(), $options = array())
  {
    sfContext::createInstance($this->configuration);
    new sfDatabaseManager($this->configuration);

    // Resolve app_drmc_* configuration ids (see arUpdateCollectionTmsTask)
    sfConfig::add(arDrmcBootstrapTask::getDrmcConfigArray());

    foreach ($this->getDataset() as $data)
    {
      $this->seedArtwork($data);
      $this->logSection('binder', 'Seeded artwork: '.$data['title']);
    }

    $this->logSection('binder', 'Done. Run "php symfony search:populate" to reindex.');
  }

  protected function getDataset()
  {
    return array(
      array(
        'title' => 'Lovers',
        'identifier' => '100001',
        'objectNumber' => '1250.2011',
        'artist' => 'Teiji Furuhashi',
        'artistDates' => 'Japanese, 1960-1995',
        'dated' => '1994',
        'startDate' => '1994-01-01',
        'accessionDate' => '2011-10-05',
        'classification' => 'Media',
        'department' => 'Media and Performance Art',
        'medium' => 'Computer controlled five-channel laser disc/sound installation with five projectors and two audio channels',
        'dimensions' => 'Dimensions variable',
        'components' => array(
          array('identifier' => '200001', 'number' => '1250.2011.x1', 'lod' => 'app_drmc_lod_archival_master_id', 'title' => 'Archival master', 'medium' => 'Digital Betacam', 'type' => 'Video'),
          array('identifier' => '200002', 'number' => '1250.2011.x2', 'lod' => 'app_drmc_lod_exhibition_format_id', 'title' => 'Exhibition format', 'medium' => 'Laser disc', 'type' => 'Installation'),
          array('identifier' => '200003', 'number' => '1250.2011.x3', 'lod' => 'app_drmc_lod_duplicating_copy_id', 'title' => 'Duplicating copy', 'medium' => 'DVD', 'type' => 'Video'),
        ),
        'technologyRecord' => array(
          'title' => 'Pioneer LD-V8000 laserdisc player',
          'description' => 'Industrial laserdisc player required for exhibition of Lovers.',
          'relationType' => 'requires',
        ),
        'aips' => array(
          array(
            'uuid' => '6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52',
            'filename' => 'lovers_archival_master',
            'component' => '200001',
            'sizeOnDisk' => 5368709120,
            'digitalObjectCount' => 4,
            'createdAt' => '2014-05-12 10:31:00',
            'files' => array(
              array('title' => 'lovers_ch1.mov', 'path' => 'objects/lovers_ch1.mov'),
              array('title' => 'lovers_ch2.mov', 'path' => 'objects/lovers_ch2.mov'),
            ),
          ),
          array(
            'uuid' => '9559945a-52e6-4c0e-9d54-83e8e548dd1d',
            'filename' => 'lovers_exhibition_format',
            'component' => '200002',
            'sizeOnDisk' => 734003200,
            'digitalObjectCount' => 2,
            'createdAt' => '2014-06-02 15:02:00',
            'files' => array(),
          ),
        ),
      ),
      array(
        'title' => 'Grosse Fatigue',
        'identifier' => '100002',
        'objectNumber' => '232.2014',
        'artist' => 'Camille Henrot',
        'artistDates' => 'French, born 1978',
        'dated' => '2013',
        'startDate' => '2013-01-01',
        'accessionDate' => '2014-03-12',
        'classification' => 'Media',
        'department' => 'Media and Performance Art',
        'medium' => 'Video (color, sound)',
        'dimensions' => '13 min.',
        'components' => array(
          array('identifier' => '200004', 'number' => '232.2014.x1', 'lod' => 'app_drmc_lod_artist_verified_proof_id', 'title' => 'Artist verified proof', 'medium' => 'ProRes 422 file', 'type' => 'Video'),
          array('identifier' => '200005', 'number' => '232.2014.x2', 'lod' => 'app_drmc_lod_exhibition_format_id', 'title' => 'Exhibition format', 'medium' => 'H.264 file', 'type' => 'Video'),
        ),
        'technologyRecord' => null,
        'aips' => array(
          array(
            'uuid' => 'd3a3f8d6-8a5b-4e6c-9c1a-2f4b7c1d9e0f',
            'filename' => 'grossefatigue_transfer_1',
            'component' => '200004',
            'sizeOnDisk' => 21474836480,
            'digitalObjectCount' => 6,
            'createdAt' => '2014-06-20 09:15:00',
            'files' => array(
              array('title' => 'GrosseFatigue_ProRes422.mov', 'path' => 'objects/GrosseFatigue_ProRes422.mov'),
            ),
          ),
        ),
      ),
      array(
        'title' => 'Manifestos 2',
        'identifier' => '100003',
        'objectNumber' => '108.2014',
        'artist' => 'Charles Gaines',
        'artistDates' => 'American, born 1944',
        'dated' => '2013',
        'startDate' => '2013-01-01',
        'accessionDate' => '2014-05-01',
        'classification' => 'Installation',
        'department' => 'Media and Performance Art',
        'medium' => 'Four-channel video (color, sound)',
        'dimensions' => 'Dimensions variable',
        'components' => array(
          array('identifier' => '200006', 'number' => '108.2014.x1', 'lod' => 'app_drmc_lod_archival_master_id', 'title' => 'Archival master', 'medium' => '.mov Animation files', 'type' => 'Video'),
          array('identifier' => '200007', 'number' => '108.2014.x2', 'lod' => 'app_drmc_lod_exhibition_format_id', 'title' => 'Exhibition format', 'medium' => 'DVD', 'type' => 'Installation'),
          array('identifier' => '200008', 'number' => '108.2014.x3', 'lod' => 'app_drmc_lod_documentation_id', 'title' => 'Documentation', 'medium' => 'PDF', 'type' => 'Document'),
          array('identifier' => '200009', 'number' => '108.2014.x4', 'lod' => 'app_drmc_lod_component_id', 'title' => 'Component', 'medium' => 'AIFF audio files', 'type' => 'Video'),
        ),
        'technologyRecord' => null,
        'aips' => array(),
      ),
    );
  }

  protected function seedArtwork($data)
  {
    // Artwork record: lookup by identifier + LOD, mimicking
    // qtPackageExtractorMETSArchivematicaDIP::processArtworkRecord()
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::IDENTIFIER, $data['identifier']);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_artwork_record_id'));

    if (null === $artwork = QubitInformationObject::getOne($criteria))
    {
      $artwork = new QubitInformationObject;
      $artwork->identifier = $data['identifier'];
      $artwork->parentId = QubitInformationObject::ROOT_ID;
      $artwork->levelOfDescriptionId = sfConfig::get('app_drmc_lod_artwork_record_id');
      $artwork->setPublicationStatusByName('Published');
      $artwork->indexOnSave = false;
      $artwork->save();
    }

    $artwork->title = $data['title'];
    $artwork->extentAndMedium = $data['medium'];
    $artwork->physicalCharacteristics = $data['dimensions'];
    $artwork->indexOnSave = false;
    $artwork->save();

    // TMS-ish properties (consumed by arElasticSearchInformationObjectPdo)
    $this->addOrUpdateProperty($artwork, 'ObjectNumber', $data['objectNumber']);
    $this->addOrUpdateProperty($artwork, 'Dated', $data['dated']);
    $this->addOrUpdateProperty($artwork, 'AccessionISODate', $data['accessionDate']);
    $this->addOrUpdateProperty($artwork, 'Thumbnail', '/images/binder-logo.png');
    $this->addOrUpdateProperty($artwork, 'FullImage', '/images/binder-logo.png');
    $this->addOrUpdateProperty($artwork, 'LastModifiedCheckDate', '2014-11-20');

    // Classification and department term relations
    $this->addOrUpdateObjectTermRelation($artwork, 'classification', $data['classification']);
    $this->addOrUpdateObjectTermRelation($artwork, 'department', $data['department']);

    // Creation event + artist. Actor dates are set directly: the
    // addDataToCreationEvent() helper drops them (reads $options instead of
    // $data), see qtSwordPlugin.class.php:46.
    $actor = QubitFlatfileImport::createOrFetchActor($data['artist'], array(
      'repositoryId' => null,
      'datesOfExistence' => $data['artistDates']));

    $criteria = new Criteria;
    $criteria->add(QubitEvent::INFORMATION_OBJECT_ID, $artwork->id);
    $criteria->add(QubitEvent::TYPE_ID, QubitTerm::CREATION_ID);
    if (null === $event = QubitEvent::getOne($criteria))
    {
      $event = new QubitEvent;
      $event->informationObjectId = $artwork->id;
      $event->typeId = QubitTerm::CREATION_ID;
    }
    $event->actorId = $actor->id;
    $event->date = $data['dated'];
    $event->startDate = $data['startDate'];
    $event->endDate = $data['startDate'];
    $event->indexOnSave = false;
    $event->save();

    // Components parent node, see arFetchTmsBase::getOrCreateComponentsParent()
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::PARENT_ID, $artwork->id);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_description_id'));
    if (null === $componentsParent = QubitInformationObject::getOne($criteria))
    {
      $componentsParent = new QubitInformationObject;
      $componentsParent->parentId = $artwork->id;
      $componentsParent->levelOfDescriptionId = sfConfig::get('app_drmc_lod_description_id');
      $componentsParent->setPublicationStatusByName('Published');
      $componentsParent->title = 'Components';
      $componentsParent->indexOnSave = false;
      $componentsParent->save();
    }

    // Components, see arFetchTmsV1::getTmsComponentsData()
    $componentIoIds = array();
    $componentsByIdentifier = array();
    foreach ($data['components'] as $item)
    {
      $criteria = new Criteria;
      $criteria->add(QubitInformationObject::IDENTIFIER, $item['identifier']);
      $criteria->addAnd(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_component_lod_ids'), Criteria::IN);
      if (null === $component = QubitInformationObject::getOne($criteria))
      {
        $component = new QubitInformationObject;
        $component->parentId = $componentsParent->id;
        $component->identifier = $item['identifier'];
        $component->levelOfDescriptionId = sfConfig::get($item['lod']);
        $component->setPublicationStatusByName('Published');
        $component->indexOnSave = false;
      }
      $component->title = $item['title'];
      $component->extentAndMedium = $item['medium'];
      $component->save();

      $this->addOrUpdateProperty($component, 'ComponentNumber', $item['number']);
      $this->addOrUpdateProperty($component, 'CompCount', '1');
      $this->addOrUpdateProperty($component, 'artworkThumbnail', '/images/binder-logo.png');
      $this->addOrUpdateObjectTermRelation($component, 'component_type', $item['type']);

      $componentIoIds[] = $component->id;
      $componentsByIdentifier[$item['identifier']] = $component;
    }

    // Serialized component id list, see arFetchTmsV1.class.php:130
    $this->addOrUpdateProperty($artwork, 'childComponents', serialize($componentIoIds));

    // Supporting technology record + relation, see
    // ApiInformationobjectsRelatedTechnologiesCreateAction
    if (null !== $data['technologyRecord'])
    {
      $tech = $data['technologyRecord'];

      $criteria = new Criteria;
      $criteria->addJoin(QubitInformationObject::ID, QubitInformationObjectI18n::ID);
      $criteria->add(QubitInformationObjectI18n::TITLE, $tech['title']);
      $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_supporting_technology_record_id'));
      if (null === $techRecord = QubitInformationObject::getOne($criteria))
      {
        $techRecord = new QubitInformationObject;
        $techRecord->parentId = QubitInformationObject::ROOT_ID;
        $techRecord->levelOfDescriptionId = sfConfig::get('app_drmc_lod_supporting_technology_record_id');
        $techRecord->setPublicationStatusByName('Published');
        $techRecord->title = $tech['title'];
        $techRecord->scopeAndContent = $tech['description'];
        $techRecord->indexOnSave = false;
        $techRecord->save();
      }

      $typeTerm = QubitFlatfileImport::createOrFetchTerm(
        sfConfig::get('app_drmc_taxonomy_supporting_technologies_relation_types_id'), $tech['relationType']);

      $criteria = new Criteria;
      $criteria->add(QubitRelation::SUBJECT_ID, $artwork->id);
      $criteria->add(QubitRelation::OBJECT_ID, $techRecord->id);
      $criteria->add(QubitRelation::TYPE_ID, $typeTerm->id);
      if (null === QubitRelation::getOne($criteria))
      {
        $relation = new QubitRelation;
        $relation->subjectId = $artwork->id;
        $relation->objectId = $techRecord->id;
        $relation->typeId = $typeTerm->id;
        $relation->indexOnSave = false;
        $relation->save();
      }
    }

    // Fake AIPs, see qtPackageExtractorMETSArchivematicaDIP::addAip()
    foreach ($data['aips'] as $item)
    {
      $component = $componentsByIdentifier[$item['component']];

      // Intermediate 'AIP' information object under the component
      $criteria = new Criteria;
      $criteria->add(QubitInformationObject::PARENT_ID, $component->id);
      $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_aip_id'));
      if (null === $aipIo = QubitInformationObject::getOne($criteria))
      {
        $aipIo = new QubitInformationObject;
        $aipIo->parentId = $component->id;
        $aipIo->levelOfDescriptionId = sfConfig::get('app_drmc_lod_aip_id');
        $aipIo->setPublicationStatusByName('Published');
        $aipIo->title = $item['filename'];
        $aipIo->indexOnSave = false;
        $aipIo->save();
      }

      if (null === QubitAip::getByUuid($item['uuid']))
      {
        $aip = new QubitAip;
        $aip->uuid = $item['uuid'];
        $aip->filename = $item['filename'];
        $aip->digitalObjectCount = $item['digitalObjectCount'];
        $aip->partOf = $artwork->id;
        $aip->sizeOnDisk = $item['sizeOnDisk'];
        $aip->createdAt = $item['createdAt'];
        $aip->typeId = QubitTerm::ARTWORK_COMPONENT_ID;
        $aip->indexOnSave = false;
        $aip->save();

        QubitProperty::addUnique($aip->id, 'ingestionUser', 'demo', array('indexOnSave' => false));
        QubitProperty::addUnique($aip->id, 'attachedTo', $component->getTitle(array('sourceCulture' => true)), array('indexOnSave' => false));

        // AIP <-> component and AIP <-> artwork relations (subject = aip)
        foreach (array($component->id, $artwork->id) as $objectId)
        {
          $relation = new QubitRelation;
          $relation->objectId = $objectId;
          $relation->subjectId = $aip->id;
          $relation->typeId = QubitTerm::AIP_RELATION_ID;
          $relation->indexOnSave = false;
          $relation->save();
        }
      }

      // Optional file objects (fill the AIP files tab)
      foreach ($item['files'] as $file)
      {
        $criteria = new Criteria;
        $criteria->add(QubitInformationObject::PARENT_ID, $aipIo->id);
        $criteria->addJoin(QubitInformationObject::ID, QubitInformationObjectI18n::ID);
        $criteria->add(QubitInformationObjectI18n::TITLE, $file['title']);
        if (null === $fileIo = QubitInformationObject::getOne($criteria))
        {
          $fileIo = new QubitInformationObject;
          $fileIo->parentId = $aipIo->id;
          $fileIo->levelOfDescriptionId = sfConfig::get('app_drmc_lod_digital_object_id');
          $fileIo->setPublicationStatusByName('Published');
          $fileIo->title = $file['title'];
          $fileIo->indexOnSave = false;
          $fileIo->save();
        }

        $this->addOrUpdateProperty($fileIo, 'aipUUID', $item['uuid']);
        $this->addOrUpdateProperty($fileIo, 'original_relative_path_within_aip', $file['path']);
      }
    }
  }

  // See arFetchTmsBase::addOrUpdateProperty()
  protected function addOrUpdateProperty($io, $name, $value)
  {
    if (isset($io->id) && null !== $property = QubitProperty::getOneByObjectIdAndName($io->id, $name))
    {
      $property->value = $value;
      $property->indexOnSave = false;
      $property->save();
    }
    else
    {
      // addProperty only queues the property; it is persisted on the next
      // save() of the information object (QubitInformationObject.php:844)
      $io->addProperty($name, $value);
      $io->indexOnSave = false;
      $io->save();
    }
  }

  // See arFetchTmsBase::addOrUpdateObjectTermRelation()
  protected function addOrUpdateObjectTermRelation($io, $name, $value)
  {
    $taxonomyId = sfConfig::get('app_drmc_taxonomy_'.strtolower($name).'s_id');
    $term = QubitFlatfileImport::createOrFetchTerm($taxonomyId, $value);

    $criteria = new Criteria;
    $criteria->add(QubitObjectTermRelation::OBJECT_ID, $io->id);
    $criteria->addJoin(QubitObjectTermRelation::TERM_ID, QubitTerm::ID);
    $criteria->add(QubitTerm::TAXONOMY_ID, $taxonomyId);
    if (null !== $termRelation = QubitObjectTermRelation::getOne($criteria))
    {
      $termRelation->setTermId($term->id);
      $termRelation->indexOnSave = false;
      $termRelation->save();
    }
    else
    {
      $termRelation = new QubitObjectTermRelation;
      $termRelation->setTermId($term->id);
      $io->objectTermRelationsRelatedByobjectId[] = $termRelation;
      $io->save();
    }
  }
}
