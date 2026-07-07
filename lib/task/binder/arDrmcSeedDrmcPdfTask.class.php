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
 * Seed PDF-accurate DRMC artworks (p.11-23 of Binder(DRMC).pdf):
 *  - Manifestos 2 (Charles Gaines, 2013, id 175258) — 4-channel video with
 *    full component tree and derivation chains
 *  - Lovers (Teiji Furuhashi, 1994, id 81362) — ~30 flat components
 *  - Grosse Fatigue (Camille Henrot, 2013, id 175938) — nested Blu-Ray/DVD chains
 *  - Search-result works from p.12 (Space Invaders, Official Welcome,
 *    Sher-e-Bangla Nagar, Acorde, As You See) — artwork records only
 *
 * Idempotent (safe to re-run). Creates isDerivativeOf / isCopyOf terms in
 * the Associative relationship types taxonomy if absent.
 *
 * Run: php symfony binder:seed-drmc-pdf
 * Then: php symfony search:populate
 *
 * @package    symfony
 * @subpackage task
 */
class arDrmcSeedDrmcPdfTask extends sfBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', 'qubit'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'cli'),
    ));

    $this->namespace = 'binder';
    $this->name = 'seed-drmc-pdf';

    $this->briefDescription = 'Seed PDF-accurate DRMC artworks with full component trees and thumbnails';
    $this->detailedDescription = <<<EOF
Seeds artworks matching the Binder(DRMC).pdf demo screenshots (p.11-23):
Manifestos 2, Lovers, Grosse Fatigue with full component trees and
derivative-chain relations, plus search-result stub works.
Run search:populate afterwards to reindex Elasticsearch.
EOF;
  }

  public function execute($arguments = array(), $options = array())
  {
    sfContext::createInstance($this->configuration);
    new sfDatabaseManager($this->configuration);

    // Required: resolve app_drmc_* ids (see arUpdateCollectionTmsTask.class.php:51)
    sfConfig::add(arDrmcBootstrapTask::getDrmcConfigArray());

    // Ensure relation type terms exist
    $this->ensureRelationTerms();

    // Main 3 artworks with full component trees
    $artworks = array(
      $this->seedManifestos2(),
      $this->seedLovers(),
      $this->seedGrosseFatigue(),
    );

    // p.12 search-result stub works
    foreach ($this->getSearchResultStubs() as $stub)
    {
      $this->seedStubArtwork($stub);
      $this->logSection('binder', 'Seeded stub: '.$stub['title']);
    }

    $this->logSection('binder', 'Done. Run "php symfony search:populate" to reindex.');
  }

  // -------------------------------------------------------------------------
  // Relation terms

  protected function ensureRelationTerms()
  {
    $taxId = sfConfig::get('app_drmc_taxonomy_associative_relationship_types_id');
    foreach (array('isDerivativeOf', 'isCopyOf') as $name)
    {
      QubitFlatfileImport::createOrFetchTerm($taxId, $name);
    }
    $this->logSection('binder', 'Relation terms ensured (isDerivativeOf, isCopyOf)');
  }

  // -------------------------------------------------------------------------
  // Manifestos 2

  protected function seedManifestos2()
  {
    $artwork = $this->getOrCreateArtwork(array(
      'identifier' => '175258',
      'objectNumber' => '108.2014',
      'title' => 'Manifestos 2',
      'artist' => 'Charles Gaines',
      'artistDates' => 'American, born 1944',
      'dated' => '2013',
      'startDate' => '2013-01-01',
      'accessionDate' => '2014-05-01',
      'classification' => 'Installation',
      'department' => 'Media and Performance Art',
      'medium' => 'Four-channel video (color, sound)',
      'dimensions' => 'Dimensions variable',
      'thumbnail' => '/uploads/r/drmc-thumbs/manifestos2_thumb.png',
    ));

    $compParent = $this->getOrCreateComponentsParent($artwork);

    // Channel 1-4 with sub-components and derivative chains
    foreach (array(1, 2, 3, 4) as $ch)
    {
      $chId = sprintf('175258-ch%d', $ch);
      $channel = $this->getOrCreateComponent($compParent, array(
        'identifier' => $chId,
        'number' => sprintf('175258.c%d', $ch),
        'title' => sprintf('Channel %d', $ch),
        'lod' => 'app_drmc_lod_component_id',
        'medium' => 'Four-channel video component',
        'type' => 'Video',
      ));

      // .mov master (artist supplied master)
      $movId = sprintf('175258-ch%d-mov', $ch);
      $mov = $this->getOrCreateComponent($channel, array(
        'identifier' => $movId,
        'number' => sprintf('175258.c%d.m', $ch),
        'title' => sprintf('.mov Milestone digital files Ch %d', $ch),
        'lod' => 'app_drmc_lod_artist_supplied_master_id',
        'medium' => 'QuickTime .mov',
        'type' => 'Video',
      ));

      // .mov -> isDerivativeOf -> DVD digital files (artist verified proof)
      $dvdId = sprintf('175258-ch%d-dvd', $ch);
      $dvd = $this->getOrCreateComponent($channel, array(
        'identifier' => $dvdId,
        'number' => sprintf('175258.c%d.d', $ch),
        'title' => sprintf('DVD digital files Ch %d', $ch),
        'lod' => 'app_drmc_lod_artist_verified_proof_id',
        'medium' => 'DVD',
        'type' => 'Video',
      ));
      $this->addAssociativeRelation($mov, $dvd, 'isDerivativeOf');

      // DVD digital files -> isCopyOf -> DVD (exhibition format)
      $dvdCopyId = sprintf('175258-ch%d-dvdcopy', $ch);
      $dvdCopy = $this->getOrCreateComponent($channel, array(
        'identifier' => $dvdCopyId,
        'number' => sprintf('175258.c%d.dc', $ch),
        'title' => sprintf('DVD Ch %d', $ch),
        'lod' => 'app_drmc_lod_exhibition_format_id',
        'medium' => 'DVD disc',
        'type' => 'Video',
      ));
      $this->addAssociativeRelation($dvd, $dvdCopy, 'isCopyOf');

      // .mov -> isDerivativeOf -> mp4 H.264 (exhibition format)
      $mp4Id = sprintf('175258-ch%d-mp4', $ch);
      $mp4 = $this->getOrCreateComponent($channel, array(
        'identifier' => $mp4Id,
        'number' => sprintf('175258.c%d.h', $ch),
        'title' => sprintf('mp4 H.264 digital files Ch %d', $ch),
        'lod' => 'app_drmc_lod_exhibition_format_id',
        'medium' => 'H.264 mp4',
        'type' => 'Video',
      ));
      $this->addAssociativeRelation($mov, $mp4, 'isDerivativeOf');

      // AIFF audio (archival master)
      $aiffId = sprintf('175258-ch%d-aiff', $ch);
      $this->getOrCreateComponent($channel, array(
        'identifier' => $aiffId,
        'number' => sprintf('175258.c%d.a', $ch),
        'title' => sprintf('AIFF audio digital files Ch %d', $ch),
        'lod' => 'app_drmc_lod_archival_master_id',
        'medium' => 'AIFF audio',
        'type' => 'Video',
      ));
    }

    // Additional flat equipment/package components
    $extraComponents = array(
      array('identifier' => '175258-sync',  'number' => '175258.eq1',  'title' => 'Synchronizer',             'lod' => 'app_drmc_lod_component_id', 'medium' => 'Hardware', 'type' => 'Video'),
      array('identifier' => '175258-ped',   'number' => '175258.eq2',  'title' => '4 pedestals',              'lod' => 'app_drmc_lod_component_id', 'medium' => 'Furniture', 'type' => 'Video'),
      array('identifier' => '175258-tv1',   'number' => '175258.eq3',  'title' => '1 Samsung LED HDTV (CH1)', 'lod' => 'app_drmc_lod_component_id', 'medium' => 'Display', 'type' => 'Video'),
      array('identifier' => '175258-tv2',   'number' => '175258.eq4',  'title' => '1 Samsung LED HDTV (CH2)', 'lod' => 'app_drmc_lod_component_id', 'medium' => 'Display', 'type' => 'Video'),
      array('identifier' => '175258-tv3',   'number' => '175258.eq5',  'title' => '1 Samsung LED HDTV (CH3)', 'lod' => 'app_drmc_lod_component_id', 'medium' => 'Display', 'type' => 'Video'),
      array('identifier' => '175258-tv4',   'number' => '175258.eq6',  'title' => '1 Samsung LED HDTV (CH4)', 'lod' => 'app_drmc_lod_component_id', 'medium' => 'Display', 'type' => 'Video'),
      array('identifier' => '175258-dvdp',  'number' => '175258.eq7',  'title' => 'DVD players',              'lod' => 'app_drmc_lod_component_id', 'medium' => 'Hardware', 'type' => 'Video'),
      array('identifier' => '175258-spkr',  'number' => '175258.eq8',  'title' => 'Speakers',                 'lod' => 'app_drmc_lod_component_id', 'medium' => 'Hardware', 'type' => 'Video'),
      array('identifier' => '175258-opman', 'number' => '175258.doc1', 'title' => 'Operations Manual CD',     'lod' => 'app_drmc_lod_documentation_id', 'medium' => 'CD-ROM', 'type' => 'Document'),
      array('identifier' => '175258-misc',  'number' => '175258.doc2', 'title' => 'Miscellaneous production files', 'lod' => 'app_drmc_lod_documentation_id', 'medium' => 'Digital files', 'type' => 'Document'),
    );
    foreach ($extraComponents as $item)
    {
      $this->getOrCreateComponent($compParent, $item);
    }

    // AIP
    $this->seedAip($artwork, array(
      'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567800',
      'filename' => 'manifestos2_transfer_1',
      'component' => '175258-ch1-mov',
      'sizeOnDisk' => 53687091200,
      'digitalObjectCount' => 38,
      'createdAt' => '2014-10-15 11:00:00',
      'files' => array(
        array('title' => 'Indigenous.mov',    'path' => 'objects/Indigenous.mov'),
        array('title' => 'M1_DVD_CH.ncor',   'path' => 'objects/M1_DVD_CH.ncor'),
        array('title' => 'M3.aif',           'path' => 'objects/M3.aif'),
        array('title' => 'manifest-md5.txt', 'path' => 'metadata/manifest-md5.txt'),
      ),
    ));

    $this->logSection('binder', 'Seeded Manifestos 2 (id 175258)');
    return $artwork;
  }

  // -------------------------------------------------------------------------
  // Lovers

  protected function seedLovers()
  {
    $artwork = $this->getOrCreateArtwork(array(
      'identifier' => '81362',
      'objectNumber' => '331.1994',
      'title' => 'Lovers',
      'artist' => 'Teiji Furuhashi',
      'artistDates' => 'Japanese, 1960-1995',
      'dated' => '1994',
      'startDate' => '1994-01-01',
      'accessionDate' => '1994-01-01',
      'classification' => 'Media',
      'department' => 'Media and Performance Art',
      'medium' => 'Computer controlled, five-channel laser disc/sound installation with five projectors, two sound systems, two slide projectors, and slides (color, sound)',
      'dimensions' => 'Dimensions variable',
      'thumbnail' => '/uploads/r/drmc-thumbs/lovers_thumb.png',
    ));

    $compParent = $this->getOrCreateComponentsParent($artwork);

    // ~30 flat components from PDF p.19, 21-23
    $components = array(
      array('identifier' => '81362-c01',  'number' => '81362.c01',  'title' => '1/2" broken NTSC video version',           'lod' => 'app_drmc_lod_component_id',       'medium' => '1/2" video',     'type' => 'Video'),
      array('identifier' => '81362-c02',  'number' => '81362.c02',  'title' => '1/2" VHS NTSC',                            'lod' => 'app_drmc_lod_component_id',       'medium' => 'VHS',            'type' => 'Video'),
      array('identifier' => '81362-c03',  'number' => '81362.c03',  'title' => 'Laser Disk 1 - Lovers #1 New',             'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Laserdisc',      'type' => 'Video'),
      array('identifier' => '81362-c04',  'number' => '81362.c04',  'title' => 'Laser Disk 2 - Lovers #2 New',             'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Laserdisc',      'type' => 'Video'),
      array('identifier' => '81362-c05',  'number' => '81362.c05',  'title' => 'Laser Disk 3 - Lovers #3 New',             'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Laserdisc',      'type' => 'Video'),
      array('identifier' => '81362-c06',  'number' => '81362.c06',  'title' => 'Laser Disk 4 - Lovers #4 New',             'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Laserdisc',      'type' => 'Video'),
      array('identifier' => '81362-c07',  'number' => '81362.c07',  'title' => 'Laser Disk 5 - Lovers',                   'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Laserdisc',      'type' => 'Video'),
      array('identifier' => '81362-c08',  'number' => '81362.c08',  'title' => 'Slide for Projector 2-Blank white',        'lod' => 'app_drmc_lod_component_id',       'medium' => '35mm slide',     'type' => 'Installation'),
      array('identifier' => '81362-c09',  'number' => '81362.c09',  'title' => 'Slide for Projector 3 "Real"',            'lod' => 'app_drmc_lod_component_id',       'medium' => '35mm slide',     'type' => 'Installation'),
      array('identifier' => '81362-c10',  'number' => '81362.c10',  'title' => 'Slide for Projector 4-Blank white',        'lod' => 'app_drmc_lod_component_id',       'medium' => '35mm slide',     'type' => 'Installation'),
      array('identifier' => '81362-c11',  'number' => '81362.c11',  'title' => 'Slide for Projector 5 "Lovers, Teiji Furuhashi, dead 1995"', 'lod' => 'app_drmc_lod_component_id', 'medium' => '35mm slide', 'type' => 'Installation'),
      array('identifier' => '81362-c12',  'number' => '81362.c12',  'title' => 'Slide for Projector "+Love"',             'lod' => 'app_drmc_lod_component_id',       'medium' => '35mm slide',     'type' => 'Installation'),
      array('identifier' => '81362-c13',  'number' => '81362.c13',  'title' => 'Stainless Steel Stand',                   'lod' => 'app_drmc_lod_component_id',       'medium' => 'Steel',          'type' => 'Installation'),
      array('identifier' => '81362-c14',  'number' => '81362.c14',  'title' => 'Extacro Slide Projector #1',              'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c15',  'number' => '81362.c15',  'title' => 'Extacro Slide Projector #2',              'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c16',  'number' => '81362.c16',  'title' => 'Sharp Liquid Crystal Projector #1',       'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c17',  'number' => '81362.c17',  'title' => 'Sharp Liquid Crystal Projector #2',       'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c18',  'number' => '81362.c18',  'title' => 'Sharp Liquid Crystal Projector #3',       'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c19',  'number' => '81362.c19',  'title' => 'Sharp Liquid Crystal Projector #4',       'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c20',  'number' => '81362.c20',  'title' => 'Sharp Liquid Crystal Projector #5',       'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c21',  'number' => '81362.c21',  'title' => '80 Slide Tray and Cover/Extactors',       'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c22',  'number' => '81362.c22',  'title' => '80 Slide Tray and Cover D-ALDS',          'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c23',  'number' => '81362.c23',  'title' => 'Steel Stand with 2 Projectors',           'lod' => 'app_drmc_lod_component_id',       'medium' => 'Steel',          'type' => 'Installation'),
      array('identifier' => '81362-c24',  'number' => '81362.c24',  'title' => 'Various pieces of Electronic Equipment',  'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c25',  'number' => '81362.c25',  'title' => 'ATA Case 2/4',                            'lod' => 'app_drmc_lod_component_id',       'medium' => 'Case',           'type' => 'Installation'),
      array('identifier' => '81362-c26',  'number' => '81362.c26',  'title' => 'ATA Case 3/3',                            'lod' => 'app_drmc_lod_component_id',       'medium' => 'Case',           'type' => 'Installation'),
      array('identifier' => '81362-c27',  'number' => '81362.c27',  'title' => 'Coaxial Cables',                          'lod' => 'app_drmc_lod_component_id',       'medium' => 'Cable',          'type' => 'Installation'),
      array('identifier' => '81362-c28',  'number' => '81362.c28',  'title' => 'Remotes and Keyboards',                   'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c29',  'number' => '81362.c29',  'title' => 'Misc Instructions',                       'lod' => 'app_drmc_lod_documentation_id',   'medium' => 'Paper',          'type' => 'Document'),
      array('identifier' => '81362-c30',  'number' => '81362.c30',  'title' => 'Computer 1/2',                            'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c31',  'number' => '81362.c31',  'title' => 'Computer 2/2',                            'lod' => 'app_drmc_lod_component_id',       'medium' => 'Hardware',       'type' => 'Installation'),
      array('identifier' => '81362-c32',  'number' => '81362.c32',  'title' => 'CD-R for PC 1/2',                         'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'CD-R',           'type' => 'Video'),
      array('identifier' => '81362-c33',  'number' => '81362.c33',  'title' => 'CD-R M PC2 L1',                           'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'CD-R',           'type' => 'Video'),
      array('identifier' => '81362-c34',  'number' => '81362.c34',  'title' => 'CD-R tech requirements',                  'lod' => 'app_drmc_lod_documentation_id',   'medium' => 'CD-R',           'type' => 'Document'),
      array('identifier' => '81362-c35',  'number' => '81362.c35',  'title' => 'Disc image digital file 1',               'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Disc image',     'type' => 'Video'),
      array('identifier' => '81362-c36',  'number' => '81362.c36',  'title' => 'Disc image digital file 2',               'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Disc image',     'type' => 'Video'),
      array('identifier' => '81362-c37',  'number' => '81362.c37',  'title' => 'Disc image digital file 3',               'lod' => 'app_drmc_lod_archival_master_id', 'medium' => 'Disc image',     'type' => 'Video'),
    );

    foreach ($components as $item)
    {
      $this->getOrCreateComponent($compParent, $item);
    }

    // AIP (real UUID from PDF p.21 AIP browser)
    $this->seedAip($artwork, array(
      'uuid' => 'a504d67c-b73b-49c6-b76d-e33cb5cf5abc',
      'filename' => 'Furuhashi---2009_inspection---2014-11-20',
      'component' => '81362-c03',
      'sizeOnDisk' => 517325980,
      'digitalObjectCount' => 31,
      'createdAt' => '2014-11-20 00:00:00',
      'files' => array(
        array('title' => 'furuhashi_331.1998(1).JPG',          'path' => 'objects/furuhashi_331.1998(1).JPG'),
        array('title' => 'furuhashi_331.1998(installation).jpg', 'path' => 'objects/furuhashi_331.1998(installation).jpg'),
        array('title' => 'lovers_manual_v1.pdf',               'path' => 'objects/lovers_manual_v1.pdf'),
        array('title' => 'METS.a504d67c-b73b-49c6-b76d-e33cb5cf5abc.xml', 'path' => 'metadata/METS.a504d67c-b73b-49c6-b76d-e33cb5cf5abc.xml'),
      ),
    ));

    $this->logSection('binder', 'Seeded Lovers (id 81362)');
    return $artwork;
  }

  // -------------------------------------------------------------------------
  // Grosse Fatigue

  protected function seedGrosseFatigue()
  {
    $artwork = $this->getOrCreateArtwork(array(
      'identifier' => '175938',
      'objectNumber' => '1207.2013',
      'title' => 'Grosse Fatigue',
      'artist' => 'Camille Henrot',
      'artistDates' => 'French, born 1978',
      'dated' => '2013',
      'startDate' => '2013-01-01',
      'accessionDate' => '2013-01-01',
      'classification' => 'Video',
      'department' => 'Media and Performance Art',
      'medium' => 'Video (color, sound)',
      'dimensions' => '13 min.',
      'thumbnail' => '/uploads/r/drmc-thumbs/grossefatigue_thumb.png',
    ));

    $compParent = $this->getOrCreateComponentsParent($artwork);

    // Blue presentation box (flat component)
    $this->getOrCreateComponent($compParent, array(
      'identifier' => '175938-box',
      'number' => '175938.c1',
      'title' => 'Blue presentation box',
      'lod' => 'app_drmc_lod_component_id',
      'medium' => 'Box',
      'type' => 'Installation',
    ));

    // Optical media parent node (Description LOD, hierarchical group)
    $optMedia = $this->getOrCreateSubDescription($compParent, '175938-optical', 'Optical media');

    // Mac.mini (artist supplied master, under Optical media)
    $this->getOrCreateComponent($optMedia, array(
      'identifier' => '175938-macmini',
      'number' => '175938.c2',
      'title' => 'Mac.mini',
      'lod' => 'app_drmc_lod_artist_supplied_master_id',
      'medium' => 'Mac mini computer',
      'type' => 'Installation',
    ));

    // Blu-Rays group
    $bluRays = $this->getOrCreateSubDescription($optMedia, '175938-blurays', 'Blu-Rays');

    // ProRes 422.mov master (artist supplied master)
    $proresBlId = '175938-prores-bl';
    $proresBl = $this->getOrCreateComponent($bluRays, array(
      'identifier' => $proresBlId,
      'number' => '175938.c3',
      'title' => 'ProRes 422.mov (Blu-Ray master)',
      'lod' => 'app_drmc_lod_artist_supplied_master_id',
      'medium' => 'ProRes 422 QuickTime',
      'type' => 'Video',
    ));

    // Blu-Ray 1 (archival master)
    $br1 = $this->getOrCreateComponent($bluRays, array(
      'identifier' => '175938-br1',
      'number' => '175938.c4',
      'title' => 'Blu-Ray 1',
      'lod' => 'app_drmc_lod_archival_master_id',
      'medium' => 'Blu-Ray disc',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($proresBl, $br1, 'isDerivativeOf');

    // Blu-Ray 1 digital file (exhibition format)
    $br1df = $this->getOrCreateComponent($bluRays, array(
      'identifier' => '175938-br1df',
      'number' => '175938.c5',
      'title' => 'Blu-Ray (digital file) 1',
      'lod' => 'app_drmc_lod_exhibition_format_id',
      'medium' => 'Blu-Ray digital file',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($br1, $br1df, 'isCopyOf');

    // Blu-Ray 2 (archival master)
    $br2 = $this->getOrCreateComponent($bluRays, array(
      'identifier' => '175938-br2',
      'number' => '175938.c6',
      'title' => 'Blu-Ray 2',
      'lod' => 'app_drmc_lod_archival_master_id',
      'medium' => 'Blu-Ray disc',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($proresBl, $br2, 'isDerivativeOf');

    // Blu-Ray 2 digital file (exhibition format)
    $br2df = $this->getOrCreateComponent($bluRays, array(
      'identifier' => '175938-br2df',
      'number' => '175938.c7',
      'title' => 'Blu-Ray (digital file) 2',
      'lod' => 'app_drmc_lod_exhibition_format_id',
      'medium' => 'Blu-Ray digital file',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($br2, $br2df, 'isCopyOf');

    // DVDs group
    $dvds = $this->getOrCreateSubDescription($optMedia, '175938-dvds', 'DVDs');

    // ProRes 422.mov master (artist supplied master) for DVD chain
    $proresDvId = '175938-prores-dv';
    $proresDv = $this->getOrCreateComponent($dvds, array(
      'identifier' => $proresDvId,
      'number' => '175938.c8',
      'title' => 'ProRes 422.mov (DVD master)',
      'lod' => 'app_drmc_lod_artist_supplied_master_id',
      'medium' => 'ProRes 422 QuickTime',
      'type' => 'Video',
    ));

    // DVD 1 (archival master)
    $dv1 = $this->getOrCreateComponent($dvds, array(
      'identifier' => '175938-dv1',
      'number' => '175938.c9',
      'title' => 'DVD 1',
      'lod' => 'app_drmc_lod_archival_master_id',
      'medium' => 'DVD disc',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($proresDv, $dv1, 'isDerivativeOf');

    // DVD 1 digital file (exhibition format)
    $dv1df = $this->getOrCreateComponent($dvds, array(
      'identifier' => '175938-dv1df',
      'number' => '175938.c10',
      'title' => 'DVD (digital file) 1',
      'lod' => 'app_drmc_lod_exhibition_format_id',
      'medium' => 'DVD digital file',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($dv1, $dv1df, 'isCopyOf');

    // DVD 2 (archival master)
    $dv2 = $this->getOrCreateComponent($dvds, array(
      'identifier' => '175938-dv2',
      'number' => '175938.c11',
      'title' => 'DVD 2',
      'lod' => 'app_drmc_lod_archival_master_id',
      'medium' => 'DVD disc',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($proresDv, $dv2, 'isDerivativeOf');

    // DVD 2 digital file (exhibition format)
    $dv2df = $this->getOrCreateComponent($dvds, array(
      'identifier' => '175938-dv2df',
      'number' => '175938.c12',
      'title' => 'DVD (digital file) 2',
      'lod' => 'app_drmc_lod_exhibition_format_id',
      'medium' => 'DVD digital file',
      'type' => 'Video',
    ));
    $this->addAssociativeRelation($dv2, $dv2df, 'isCopyOf');

    // AIP with UUIDs referenced in PDF p.20 file list
    $this->seedAip($artwork, array(
      'uuid' => '8dcbb052-aaaa-bbbb-cccc-dd1234567890',
      'filename' => 'grossefatigue_bluray_ingest',
      'component' => '175938-prores-bl',
      'sizeOnDisk' => 32212254720,
      'digitalObjectCount' => 48,
      'createdAt' => '2013-11-10 10:00:00',
      'files' => array(
        array('title' => 'MovieObject.bdmv',  'path' => 'objects/MovieObject.bdmv'),
        array('title' => '00001.mpls',        'path' => 'objects/00001.mpls'),
        array('title' => 'index.bdmv',        'path' => 'objects/index.bdmv'),
        array('title' => 'bagit.txt',         'path' => 'metadata/bagit.txt'),
        array('title' => 'METS.8dcbb052-aaaa-bbbb-cccc-dd1234567890.xml', 'path' => 'metadata/METS.8dcbb052-aaaa-bbbb-cccc-dd1234567890.xml'),
      ),
    ));

    $this->logSection('binder', 'Seeded Grosse Fatigue (id 175938)');
    return $artwork;
  }

  // -------------------------------------------------------------------------
  // Search-result stub artworks (p.12)

  protected function getSearchResultStubs()
  {
    return array(
      array(
        'identifier' => '102345',
        'objectNumber' => '124.2001',
        'title' => 'Space Invaders',
        'artist' => 'Cory Arcangel',
        'artistDates' => 'American, born 1978',
        'dated' => '2002',
        'startDate' => '2002-01-01',
        'accessionDate' => '2001-05-01',
        'classification' => 'Media',
        'department' => 'Media and Performance Art',
        'medium' => 'Video game modification',
        'dimensions' => 'Dimensions variable',
        'thumbnail' => '/uploads/r/drmc-thumbs/spaceinvaders_thumb.png',
      ),
      array(
        'identifier' => '103456',
        'objectNumber' => '212.2005',
        'title' => 'Official Welcome',
        'artist' => 'Andrea Fraser',
        'artistDates' => 'American, born 1965',
        'dated' => '2001',
        'startDate' => '2001-01-01',
        'accessionDate' => '2005-09-12',
        'classification' => 'Media',
        'department' => 'Media and Performance Art',
        'medium' => 'Single-channel video (color, sound)',
        'dimensions' => '29 min.',
        'thumbnail' => '/uploads/r/drmc-thumbs/officialwelcome_thumb.png',
      ),
      array(
        'identifier' => '104567',
        'objectNumber' => '315.2008',
        'title' => 'Sher-e-Bangla Nagar',
        'artist' => 'Louis I. Kahn',
        'artistDates' => 'American, 1901-1974',
        'dated' => '1962',
        'startDate' => '1962-01-01',
        'accessionDate' => '2008-03-20',
        'classification' => 'Architecture',
        'department' => 'Architecture and Design',
        'medium' => 'Architectural drawings',
        'dimensions' => 'Dimensions variable',
        'thumbnail' => '/uploads/r/drmc-thumbs/sherebanglanagar_thumb.png',
      ),
      array(
        'identifier' => '105678',
        'objectNumber' => '428.2010',
        'title' => 'Acorde',
        'artist' => 'Pedro Reyes',
        'artistDates' => 'Mexican, born 1972',
        'dated' => '2004',
        'startDate' => '2004-01-01',
        'accessionDate' => '2010-07-15',
        'classification' => 'Media',
        'department' => 'Media and Performance Art',
        'medium' => 'Video (color, sound)',
        'dimensions' => '10 min.',
        'thumbnail' => '/uploads/r/drmc-thumbs/acorde_thumb.png',
      ),
      array(
        'identifier' => '106789',
        'objectNumber' => '537.2009',
        'title' => 'As You See',
        'artist' => 'Fischli and Weiss',
        'artistDates' => 'Swiss, 1952-2012 and born 1946',
        'dated' => '1986',
        'startDate' => '1986-01-01',
        'accessionDate' => '2009-11-01',
        'classification' => 'Media',
        'department' => 'Media and Performance Art',
        'medium' => 'Single-channel video (color, sound)',
        'dimensions' => '55 min.',
        'thumbnail' => '/uploads/r/drmc-thumbs/asyousee_thumb.png',
      ),
    );
  }

  protected function seedStubArtwork($data)
  {
    $this->getOrCreateArtwork($data);
  }

  // -------------------------------------------------------------------------
  // Shared helpers

  protected function getOrCreateArtwork($data)
  {
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

    $this->addOrUpdateProperty($artwork, 'ObjectNumber', $data['objectNumber']);
    $this->addOrUpdateProperty($artwork, 'Dated', $data['dated']);
    $this->addOrUpdateProperty($artwork, 'AccessionISODate', $data['accessionDate']);
    $this->addOrUpdateProperty($artwork, 'Thumbnail', $data['thumbnail']);
    $this->addOrUpdateProperty($artwork, 'FullImage', $data['thumbnail']);
    $this->addOrUpdateProperty($artwork, 'LastModifiedCheckDate', '2014-11-20');

    $this->addOrUpdateObjectTermRelation($artwork, 'classification', $data['classification']);
    $this->addOrUpdateObjectTermRelation($artwork, 'department', $data['department']);

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

    return $artwork;
  }

  protected function getOrCreateComponentsParent($artwork)
  {
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::PARENT_ID, $artwork->id);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_description_id'));
    if (null === $parent = QubitInformationObject::getOne($criteria))
    {
      $parent = new QubitInformationObject;
      $parent->parentId = $artwork->id;
      $parent->levelOfDescriptionId = sfConfig::get('app_drmc_lod_description_id');
      $parent->setPublicationStatusByName('Published');
      $parent->title = 'Components';
      $parent->indexOnSave = false;
      $parent->save();
    }
    return $parent;
  }

  /**
   * Create a Description-LOD grouping node (e.g. "Optical media", "Blu-Rays")
   * under any IO parent. Idempotent by title+parent+LOD.
   */
  protected function getOrCreateSubDescription($parent, $identifier, $title)
  {
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::PARENT_ID, $parent->id);
    $criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_description_id'));
    $criteria->addJoin(QubitInformationObject::ID, QubitInformationObjectI18n::ID);
    $criteria->add(QubitInformationObjectI18n::TITLE, $title);
    if (null === $node = QubitInformationObject::getOne($criteria))
    {
      $node = new QubitInformationObject;
      $node->parentId = $parent->id;
      $node->levelOfDescriptionId = sfConfig::get('app_drmc_lod_description_id');
      $node->setPublicationStatusByName('Published');
      $node->title = $title;
      $node->identifier = $identifier;
      $node->indexOnSave = false;
      $node->save();
    }
    return $node;
  }

  /**
   * Create or update a component IO under $parent.
   * Idempotent by identifier within component LOD set.
   */
  protected function getOrCreateComponent($parent, $item)
  {
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::IDENTIFIER, $item['identifier']);
    $criteria->addAnd(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_component_lod_ids'), Criteria::IN);
    if (null === $component = QubitInformationObject::getOne($criteria))
    {
      $component = new QubitInformationObject;
      $component->parentId = $parent->id;
      $component->identifier = $item['identifier'];
      $component->levelOfDescriptionId = sfConfig::get($item['lod']);
      $component->setPublicationStatusByName('Published');
      $component->indexOnSave = false;
    }
    $component->title = $item['title'];
    $component->extentAndMedium = $item['medium'];
    $component->indexOnSave = false;
    $component->save();

    $this->addOrUpdateProperty($component, 'ComponentNumber', $item['number']);
    $this->addOrUpdateProperty($component, 'CompCount', '1');
    $this->addOrUpdateObjectTermRelation($component, 'component_type', $item['type']);

    return $component;
  }

  /**
   * Add an associative relation (isDerivativeOf / isCopyOf) between two IOs.
   * subject -> type -> object. Idempotent.
   */
  protected function addAssociativeRelation($subject, $object, $typeName)
  {
    $taxId = sfConfig::get('app_drmc_taxonomy_associative_relationship_types_id');
    $typeTerm = QubitFlatfileImport::createOrFetchTerm($taxId, $typeName);

    $criteria = new Criteria;
    $criteria->add(QubitRelation::SUBJECT_ID, $subject->id);
    $criteria->add(QubitRelation::OBJECT_ID, $object->id);
    $criteria->add(QubitRelation::TYPE_ID, $typeTerm->id);
    if (null === QubitRelation::getOne($criteria))
    {
      $relation = new QubitRelation;
      $relation->subjectId = $subject->id;
      $relation->objectId = $object->id;
      $relation->typeId = $typeTerm->id;
      $relation->indexOnSave = false;
      $relation->save();
    }
  }

  /**
   * Seed one AIP. The component is looked up by identifier.
   */
  protected function seedAip($artwork, $item)
  {
    // Find the component by identifier
    $criteria = new Criteria;
    $criteria->add(QubitInformationObject::IDENTIFIER, $item['component']);
    $component = QubitInformationObject::getOne($criteria);
    if (null === $component)
    {
      $this->logSection('binder', 'WARNING: component '.$item['component'].' not found, skipping AIP '.$item['uuid']);
      return;
    }

    // AIP IO under the component
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

  // -------------------------------------------------------------------------
  // Property / term relation helpers (same pattern as arDrmcSeedDemoTask)

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
      $io->addProperty($name, $value);
      $io->indexOnSave = false;
      $io->save();
    }
  }

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
