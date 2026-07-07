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
 * You should have received a copy of the GNU Affero General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Vocabulary alignment (RiC-O / Linked Art / schema.org / OWL) for the
 * legacy qubit data model, implemented as a property layer (scope
 * 'vocabulary') since the model itself is fixed:
 *
 *  Artwork records:
 *   - owl:sameAs                    external URIs (MoMA collection, Wikidata)
 *   - schema:creator                creation event actor name
 *   - schema:dateCreated            creation event date
 *   - rico:hasOrHadInstantiation    component count summary
 *
 *  Components:
 *   - rico:instantiation            component number + title
 *   - rico:carrierType              medium / equipment (extentAndMedium)
 *
 * Idempotent: a property is skipped when one with the same name already
 * exists on the object (curator edits are never overwritten).
 *
 * Run: php symfony binder:align-vocabulary
 * Then: php symfony search:populate
 *
 * @package    symfony
 * @subpackage task
 */
class arDrmcAlignVocabularyTask extends sfBaseTask
{
  /**
   * External URIs per artwork identifier. MoMA URLs mirror
   * binder-next real-assets/manifest.json (objectId differs for the two
   * aliased stubs); Wikidata only where the mapping is unambiguous.
   */
  protected static $sameAs = array(
    '175258' => array('https://www.moma.org/collection/works/175258'),
    '81362'  => array('https://www.moma.org/collection/works/81362'),
    '175938' => array('https://www.moma.org/collection/works/175938'),
    '152403' => array('https://www.moma.org/collection/works/152403', 'http://www.wikidata.org/entity/Q170572'),
    '102345' => array('https://www.moma.org/collection/works/169996', 'http://www.wikidata.org/entity/Q165918'),
    '103456' => array('https://www.moma.org/collection/works/143751'),
  );

  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', 'qubit'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'cli'),
    ));

    $this->namespace = 'binder';
    $this->name = 'align-vocabulary';

    $this->briefDescription = 'Add RiC-O/schema.org/OWL vocabulary alignment properties to works and components';
    $this->detailedDescription = <<<EOF
Adds vocabulary alignment properties (scope 'vocabulary') to artwork records
and their components. Idempotent — existing property names are not touched.
Run search:populate afterwards to reindex.
EOF;
  }

  public function execute($arguments = array(), $options = array())
  {
    sfContext::createInstance($this->configuration);
    new sfDatabaseManager($this->configuration);

    sfConfig::add(arDrmcBootstrapTask::getDrmcConfigArray());

    $added = 0;

    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_artwork_record_id'));
    foreach (QubitInformationObject::get($criteria) as $artwork)
    {
      $added += $this->alignArtwork($artwork);
    }

    $this->logSection('binder', 'Done: '.$added.' vocabulary propert(ies) added.');
    if (0 < $added)
    {
      $this->logSection('binder', 'Run "php symfony search:populate" to reindex.');
    }
  }

  protected function alignArtwork($artwork)
  {
    $added = 0;
    $title = $artwork->getTitle(array('sourceCulture' => true));

    // owl:sameAs
    if (isset(self::$sameAs[$artwork->identifier]))
    {
      $added += $this->addPropertyIfMissing($artwork, 'owl:sameAs', implode('; ', self::$sameAs[$artwork->identifier]));
    }

    // schema:creator / schema:dateCreated from the creation event
    if (0 < count($creationEvents = $artwork->getCreationEvents()))
    {
      $event = $creationEvents[0];
      if (null !== $event->actor)
      {
        $added += $this->addPropertyIfMissing($artwork, 'schema:creator', $event->actor->getAuthorizedFormOfName(array('sourceCulture' => true)));
      }
      if (null !== $date = $event->getDate(array('sourceCulture' => true)))
      {
        $added += $this->addPropertyIfMissing($artwork, 'schema:dateCreated', $date);
      }
    }

    // rico:hasOrHadInstantiation — component count summary
    $componentIds = array();
    $rows = QubitPdo::fetchAll(
      'SELECT COUNT(*) AS hits FROM information_object
        WHERE lft > ? AND rgt < ? AND level_of_description_id IN ('.implode(',', array_map('intval', sfConfig::get('app_drmc_component_lod_ids'))).')',
      array($artwork->lft, $artwork->rgt));
    $componentCount = (int)$rows[0]->hits;
    if (0 < $componentCount)
    {
      $added += $this->addPropertyIfMissing($artwork, 'rico:hasOrHadInstantiation', $componentCount.' component instantiation(s)');
    }

    if (0 < $added)
    {
      $this->logSection('binder', $title.' ('.$artwork->identifier.'): '.$added.' propert(ies)');
    }

    // Components: rico:instantiation + rico:carrierType
    $componentRows = QubitPdo::fetchAll(
      'SELECT id FROM information_object
        WHERE lft > ? AND rgt < ? AND level_of_description_id IN ('.implode(',', array_map('intval', sfConfig::get('app_drmc_component_lod_ids'))).')
        ORDER BY lft',
      array($artwork->lft, $artwork->rgt));
    foreach ($componentRows as $row)
    {
      $component = QubitInformationObject::getById($row->id);

      $designation = $component->getTitle(array('sourceCulture' => true));
      if (null !== $number = $this->getPropertyValue($component, 'ComponentNumber'))
      {
        $designation = $number.' — '.$designation;
      }
      $added += $this->addPropertyIfMissing($component, 'rico:instantiation', $designation);

      $medium = $component->getExtentAndMedium(array('sourceCulture' => true));
      if (null !== $medium && 0 < strlen($medium))
      {
        $added += $this->addPropertyIfMissing($component, 'rico:carrierType', $medium);
      }
    }

    return $added;
  }

  /**
   * Add a scope-'vocabulary' property unless one with the same name already
   * exists on the object (never overwrite existing values).
   *
   * @return int 1 if added, 0 if skipped
   */
  protected function addPropertyIfMissing($io, $name, $value)
  {
    if (null === $value || 0 == strlen($value))
    {
      return 0;
    }

    if (0 < count($io->getProperties($name, 'vocabulary')))
    {
      return 0;
    }

    QubitProperty::addUnique($io->id, $name, $value, array(
      'scope' => 'vocabulary',
      'indexOnSave' => false,
    ));

    return 1;
  }

  protected function getPropertyValue($io, $name)
  {
    if (null !== $property = QubitProperty::getOneByObjectIdAndName($io->id, $name))
    {
      return $property->getValue(array('sourceCulture' => true));
    }
  }
}
