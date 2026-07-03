# Implementation spec: `binder:seed-demo` (`lib/task/binder/arDrmcSeedDemoTask.class.php`)

Goal: seed 3 demo artworks (with components, one supporting technology record, and fake AIPs) **directly into MySQL via Propel** — no Archivematica, no TMS — then reindex Elasticsearch so the Angular UI (works browse, work view, context browser, AIPs tab) renders real content. Idempotent: safe to run repeatedly.

All patterns below are copied from the real ingest path (`qtPackageExtractorMETSArchivematicaDIP`, `arFetchTmsV1/V2`, `arFetchTmsBase`) with file:line citations.

---

## 0. Preconditions

1. `php symfony binder:bootstrap` has run. It creates the DRMC LOD terms ('Artwork record', 'Description', 'Component', 'Archival master', 'Exhibition format', 'Supporting technology record', 'AIP', 'Digital object', ... — `lib/task/binder/arDrmcBootstrapTask.class.php:75-95`), the taxonomies 'Classifications', 'Departments', 'Component types', 'Supporting technologies relation types', 'Associative relationship types' (`arDrmcBootstrapTask.class.php:126-153`), the note-type terms 'InstallComments'/'PrepComments'/'StorageComments' (`arDrmcBootstrapTask.class.php:157-172`) and the relation-type terms `isPartOf/isFormatOf/isVersionOf/references/requires` under 'Supporting technologies relation types' (`arDrmcBootstrapTask.class.php:201-233`).
2. Base AtoM fixtures are loaded (they always are on an installed system): AIP-relation term **178** and AIP-type taxonomy **71** with terms **179–182** come from `data/fixtures/taxonomyTerms.yml:928-938, 2579-2595` — NOT from binder:bootstrap. Constants: `QubitTerm::AIP_RELATION_ID = 178`, `ARTWORK_COMPONENT_ID = 179`, `ARTWORK_MATERIAL_ID = 180`, `SUPPORTING_DOCUMENTATION_ID = 181`, `SUPPORTING_TECHNOLOGY_ID = 182` (`lib/model/QubitTerm.php:152-158`); `QubitTaxonomy::AIP_TYPE_ID = 71` (`lib/model/QubitTaxonomy.php:73`).
3. Elasticsearch is up (index `atom`, per `plugins/arElasticSearchPlugin/config/search.yml:25`).

**Never hardcode DRMC term ids.** They are auto-increment `term` rows resolved at runtime by name: `arDrmcBootstrapTask::getDrmcConfigArray()` slugifies each English LOD term name into `app_drmc_lod_<slug>_id`, each taxonomy name into `app_drmc_taxonomy_<slug>_id`, and each note-type term into `app_drmc_term_<slug>_id` (`arDrmcBootstrapTask.class.php:315-422`). CLI tasks must load this themselves — exactly as `arUpdateCollectionTmsTask.class.php:51` and `lib/task/search/arPopulateTask.class.php:52` do:

```php
sfConfig::add(arDrmcBootstrapTask::getDrmcConfigArray());
```

Config keys the seeder will use (all produced by `getDrmcConfigArray()`):

| key | resolves term named | used for |
|---|---|---|
| `app_drmc_lod_artwork_record_id` | 'Artwork record' | artwork IO LOD; what makes browse show it (`informationobjectsWorksBrowseAction.class.php:39`) |
| `app_drmc_lod_description_id` | 'Description' | the intermediate "Components" node |
| `app_drmc_lod_component_id`, `app_drmc_lod_archival_master_id`, `app_drmc_lod_exhibition_format_id`, `app_drmc_lod_duplicating_copy_id`, `app_drmc_lod_artist_verified_proof_id`, `app_drmc_lod_documentation_id` | component-status LODs | component IOs (all members of `app_drmc_component_lod_ids`, `arDrmcBootstrapTask.class.php:335-350`) |
| `app_drmc_lod_supporting_technology_record_id` | 'Supporting technology record' | tech record IO |
| `app_drmc_lod_aip_id`, `app_drmc_lod_digital_object_id` | 'AIP', 'Digital object' | AIP intermediate IO + file IOs |
| `app_drmc_taxonomy_classifications_id`, `app_drmc_taxonomy_departments_id`, `app_drmc_taxonomy_component_types_id` | taxonomies | classification/department/component-type terms |
| `app_drmc_taxonomy_supporting_technologies_relation_types_id` | taxonomy | tech-record link relation type |
| `app_drmc_term_installcomments_id`, `app_drmc_term_prepcomments_id`, `app_drmc_term_storagecomments_id` | note types | component notes |

---

## 1. Task boilerplate

File: `lib/task/binder/arDrmcSeedDemoTask.class.php` (auto-loaded; sibling tasks in `lib/task/binder/` need no registration). Copy the shape of `arDrmcBootstrapTask` (`arDrmcBootstrapTask.class.php:26-59`):

```php
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
  }

  public function execute($arguments = array(), $options = array())
  {
    sfContext::createInstance($this->configuration);
    new sfDatabaseManager($this->configuration);

    // REQUIRED: resolve app_drmc_* ids (arUpdateCollectionTmsTask.class.php:51)
    sfConfig::add(arDrmcBootstrapTask::getDrmcConfigArray());

    // ... seed, then reindex (section 8)
  }
}
```

Run with: `php symfony binder:seed-demo`.

---

## 2. Demo dataset (mirrors the 2014 demo screenshots)

| Artwork title | identifier (fake TMS ObjectID) | ObjectNumber (accession) | Artist (`actor_i18n.authorized_form_of_name`) | Artist dates | Dated (display year) | Classification | Department | Medium (`extent_and_medium`) | Dimensions (`physical_characteristics`) |
|---|---|---|---|---|---|---|---|---|---|
| Lovers | `100001` | `1250.2011` | Teiji Furuhashi | Japanese, 1960–1995 | `1994` | Media | Media and Performance Art | Computer controlled five-channel laser disc/sound installation with five projectors and two audio channels | Dimensions variable |
| Grosse Fatigue | `100002` | `232.2014` | Camille Henrot | French, born 1978 | `2013` | Media | Media and Performance Art | Video (color, sound) | 13 min. |
| Manifestos 2 | `100003` | `108.2014` | Charles Atlas | American, born 1949 | `2013` | Media | Media and Performance Art | Five-channel video installation (color, sound) | Dimensions variable |

Components (2–4 each; LOD names must match bootstrap terms exactly):

- **Lovers**: `Archival master` (identifier `200001`, ComponentNumber `1250.2011.x1`), `Exhibition format` (`200002`, `1250.2011.x2`), `Duplicating copy` (`200003`, `1250.2011.x3`).
- **Grosse Fatigue**: `Artist verified proof` (`200004`, `232.2014.x1`), `Exhibition format` (`200005`, `232.2014.x2`).
- **Manifestos 2**: `Archival master` (`200006`, `108.2014.x1`), `Exhibition format` (`200007`, `108.2014.x2`), `Documentation` (`200008`, `108.2014.x3`), `Component` (`200009`, `108.2014.x4`).

Component-type term (taxonomy 'Component types', created on the fly): e.g. `Video`, `Installation`, `Document`.

Supporting technology record (1, linked to Lovers): title `Pioneer LD-V8000 laserdisc player`, relation type `requires`.

AIPs (3, fixed UUIDs so reruns are idempotent):

| UUID | filename | attach to | part_of | size_on_disk | digital_object_count | created_at |
|---|---|---|---|---|---|---|
| `6f198fa9-3fa5-43ab-b0b6-8f8db2f31a52` | `lovers_archival_master` | Lovers / component `1250.2011.x1` | Lovers IO id | `5368709120` | 4 | `2014-05-12 10:31:00` |
| `9559945a-52e6-4c0e-9d54-83e8e548dd1d` | `lovers_exhibition_format` | Lovers / component `1250.2011.x2` | Lovers IO id | `734003200` | 2 | `2014-06-02 15:02:00` |
| `d3a3f8d6-8a5b-4e6c-9c1a-2f4b7c1d9e0f` | `grossefatigue_transfer_1` | Grosse Fatigue / component `232.2014.x1` | Grosse Fatigue IO id | `21474836480` | 6 | `2014-06-20 09:15:00` |

---

## 3. Artwork records — exact Propel pattern

Copied from `qtPackageExtractorMETSArchivematicaDIP::processArtworkRecord()` (`plugins/qtSwordPlugin/lib/qtPackageExtractorMETSArchivematicaDIP.class.php:175-192`). This is the idempotency lookup AND the creation:

```php
// Lookup by identifier + LOD (idempotent)
$criteria = new Criteria;
$criteria->add(QubitInformationObject::IDENTIFIER, $identifier);
$criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_artwork_record_id'));

if (null === $artwork = QubitInformationObject::getOne($criteria))
{
  $artwork = new QubitInformationObject;
  $artwork->identifier = $identifier;                      // fake TMS ObjectID
  $artwork->parentId = QubitInformationObject::ROOT_ID;    // artworks live under root
  $artwork->levelOfDescriptionId = sfConfig::get('app_drmc_lod_artwork_record_id');
  $artwork->setPublicationStatusByName('Published');       // status row, type STATUS_TYPE_PUBLICATION_ID (QubitInformationObject.php:1795-1805)
  $artwork->indexOnSave = false;                           // we reindex explicitly at the end
  $artwork->save();                                        // ORM fills lft/rgt nested set + slug automatically
}

// i18n columns (information_object_i18n) — same columns arFetchTmsV1 writes (arFetchTmsV1.class.php:135-277)
$artwork->title = 'Lovers';                                // -> information_object_i18n.title
$artwork->extentAndMedium = '...medium...';                // -> extent_and_medium (browse "medium", tms "medium")
$artwork->physicalCharacteristics = '...dimensions...';    // -> physical_characteristics (browse "dimensions")
$artwork->indexOnSave = false;
$artwork->save();
```

**Do not write raw SQL for information objects** — `save()` maintains the `object` base row (`class_name` auto-set from `get_class`, `lib/model/om/BaseObject.php:114`), the nested set (`lft`/`rgt`), and the `slug` row. The context-browser tree is pure nested-set SQL (`informationobjectsTreeAction.class.php:31-71`), so broken `lft/rgt` = broken context browser.

### 3.1 TMS-ish properties (tables `property` + `property_i18n`)

The ES `tmsObject.*` block is built ONLY from these properties when LOD = artwork record (`arElasticSearchInformationObjectPdo.class.php:1194-1258`); `/tms` reads the same rows (`informationobjectsTmsAction.class.php:56-101`). Use the idempotent helper pattern from `arFetchTmsBase::addOrUpdateProperty()` (`lib/task/binder/tms/arFetchTmsBase.class.php:78-95`):

```php
protected function addOrUpdateProperty($name, $value, $io)
{
  if (isset($io->id) && null !== $property = QubitProperty::getOneByObjectIdAndName($io->id, $name))
  {
    $property->value = $value;
    $property->indexOnSave = false;
    $property->save();
  }
  else
  {
    $io->addProperty($name, $value);   // creates property + property_i18n rows
  }
}
```

Per artwork, set:

| property.name | value | consumed by |
|---|---|---|
| `ObjectNumber` | accession number, e.g. `1250.2011` | browse `accession_number` (`tmsObject.accessionNumber`, PdoClass:1230-1233 region) and `/tms` `accessionNumber` (`informationobjectsTmsAction.class.php`) |
| `Dated` | `1994` | ES `tmsObject.collectionDate` |
| `AccessionISODate` | `2011-10-05` | ES `tmsObject.dateCollected`/`yearCollected` (AIP overview + aggs use `year_collected`) |
| `Thumbnail` | any web-accessible image URL | browse card thumbnail + `/tms` `thumbnail` (`arElasticSearchInformationObjectPdo.class.php:1213-1221` — properties, NOT the digital_object row) |
| `FullImage` | image URL | work-view full image |
| `childComponents` | `serialize($componentIoIds)` — PHP-serialized array of the component **IO ids** | ES `tmsChildComponents` (`arElasticSearchInformationObjectPdo.class.php:1246-1257`); set AFTER components exist, exactly as `arFetchTmsV1.class.php:130` does: `QubitProperty::addUnique($artwork->id, 'childComponents', serialize($tmsComponentsIoIds), array('indexOnSave' => false));` — for idempotency use `addOrUpdateProperty` instead of `addUnique` |

(Optionally `LastModifiedCheckDate` = today's ISO date; without it `GET /api/informationobjects/:id/status` 500s, but `WorkViewCtrl` only logs that failure.)

### 3.2 Classification & Department (`object_term_relation`)

Copy `arFetchTmsBase::addOrUpdateObjectTermRelation()` verbatim (`arFetchTmsBase.class.php:97-128`) — it is already idempotent (one relation per taxonomy per object):

```php
$taxonomyId = sfConfig::get('app_drmc_taxonomy_'.strtolower($name).'s_id'); // $name = 'classification' | 'department'
$term = QubitFlatfileImport::createOrFetchTerm($taxonomyId, $value);        // idempotent by (taxonomy, name, culture='en') — lib/QubitFlatfileImport.class.php:1247

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
```

Call with `('classification', 'Media', $artwork)` and `('department', 'Media and Performance Art', $artwork)`. ES reads these into `tmsObject.classification`/`department` (`arElasticSearchInformationObjectPdo.class.php:1194-1258`); the browse facets aggregate on `tmsObject.classification.id`/`department.id` (`informationobjectsWorksBrowseAction.class.php:97-201`).

### 3.3 Creation event + artist (gives browse `year`/`artist`, view `names`/`dates`)

ES `dates` come from `event`+`event_i18n.date` rows (`arElasticSearchInformationObjectPdo.class.php:1123-1127`); `creators` from actors of events with `type_id = QubitTerm::CREATION_ID` (=111, `lib/model/QubitTerm.php:37`) (`:1172-1177`). The real path is `arFetchTmsBase::addOrUpdateCreationEvent()` (`arFetchTmsBase.class.php:160-184`) → `qtSwordPlugin::addDataToCreationEvent()` (`plugins/qtSwordPlugin/lib/qtSwordPlugin.class.php:40-116`).

**PITFALL — do not reuse `addDataToCreationEvent` for the artist dates**: line 46 reads `$options['actorDate']` (undefined variable; should be `$data['actorDate']`), so `datesOfExistence` is silently dropped. Also `QubitFlatfileImport::createOrFetchActor()` dereferences `$options['repositoryId']` (`lib/QubitFlatfileImport.class.php:1143-1153`) — pass the key explicitly. Seed directly:

```php
// Actor (idempotent by authorized form of name)
$actor = QubitFlatfileImport::createOrFetchActor('Teiji Furuhashi',
  array('repositoryId' => null, 'datesOfExistence' => 'Japanese, 1960-1995'));

// Creation event (idempotent lookup, arFetchTmsBase.class.php:163-178)
$criteria = new Criteria;
$criteria->add(QubitEvent::INFORMATION_OBJECT_ID, $artwork->id);
$criteria->add(QubitEvent::TYPE_ID, QubitTerm::CREATION_ID);
if (null === $event = QubitEvent::getOne($criteria))
{
  $event = new QubitEvent;
  $event->informationObjectId = $artwork->id;   // artwork must be saved first
  $event->typeId = QubitTerm::CREATION_ID;      // 111
}
$event->actorId = $actor->id;
$event->date = '1994';                          // -> event_i18n.date  => browse "year" = dates[0].i18n.date
$event->startDate = '1994-01-01';               // event.start_date (date column)
$event->endDate = '1994-01-01';
$event->indexOnSave = false;
$event->save();
```

ES also uses the first event's start/end for `tmsObject.dateCreated` (`arElasticSearchInformationObjectPdo.class.php:1194-1258`), which feeds the "date created" facet.

---

## 4. Components

Structure mandated by the tree/API code: artwork → intermediate **"Components"** node (LOD 'Description') → component IOs. Components link to their work purely by nested-set ancestry — no relation row (`arElasticSearchInformationObjectPdo.class.php:989-1001`).

### 4.1 Components parent — copy `arFetchTmsBase::getOrCreateComponentsParent()` (`arFetchTmsBase.class.php:220-238`), already idempotent:

```php
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
```

### 4.2 Component IOs — copy `arFetchTmsV1::getTmsComponentsData()` creation block (`arFetchTmsV1.class.php:113-123`), idempotent lookup by identifier + component LOD:

```php
$criteria = new Criteria;
$criteria->add(QubitInformationObject::IDENTIFIER, $componentIdentifier);
$criteria->addAnd(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_component_lod_ids'), Criteria::IN);
if (null === $component = QubitInformationObject::getOne($criteria))
{
  $component = new QubitInformationObject;
  $component->parentId = $componentsParent->id;
  $component->identifier = $componentIdentifier;               // fake TMS ComponentID
  $component->levelOfDescriptionId = sfConfig::get('app_drmc_lod_archival_master_id'); // per-row LOD from dataset
  $component->setPublicationStatusByName('Published');
  $component->indexOnSave = false;
}
$component->title = 'Archival master';                          // e.g. component status/name
$component->extentAndMedium = 'Digital Betacam';                // optional
$component->save();
$componentIoIds[] = $component->id;
```

Per component add (patterns at `arFetchTmsV1.class.php:380-434`):

- Properties via `addOrUpdateProperty`: `ComponentNumber` = `1250.2011.x1` (the `/tree` action decorates node labels with it and the DIP path matches AIPs by it — `informationobjectsTreeAction.class.php:31-71`, `qtPackageExtractorMETSArchivematicaDIP.class.php:198-210`), `CompCount` = `'1'`.
- Component type: `addOrUpdateObjectTermRelation('component_type', 'Video', $component)` — key resolves to `app_drmc_taxonomy_component_types_id` (`arFetchTmsBase.class.php:99`).
- Notes (optional, fills the component `/tms` panel): copy `addOrUpdateNote()` (`arFetchTmsBase.class.php:130-158`) with `sfConfig::get('app_drmc_term_installcomments_id')` etc. (usage: `arFetchTmsV1.class.php:399-402`).
- `artworkThumbnail` property = artwork's Thumbnail URL (`arFetchTmsV1.class.php:450-454`; ES `tmsComponent.artwork.thumbnail`, `arElasticSearchInformationObjectPdo.class.php:1325-1328`).

### 4.3 After all components of a work exist

```php
$this->addOrUpdateProperty('childComponents', serialize($componentIoIds), $artwork); // arFetchTmsV1.class.php:130
```

---

## 5. Supporting technology record (1, linked to Lovers)

Tech records are plain IOs under root whose LOD is 'Supporting technology record' (`informationobjectsTechnologyRecordsBrowseAction.class.php:39`; created in prod by `informationobjectsCreateAction.class.php:32-41`). Idempotency: lookup by title + LOD (they have no identifier):

```php
$criteria = new Criteria;
$criteria->addJoin(QubitInformationObject::ID, QubitInformationObjectI18n::ID);
$criteria->add(QubitInformationObjectI18n::TITLE, 'Pioneer LD-V8000 laserdisc player');
$criteria->add(QubitInformationObject::LEVEL_OF_DESCRIPTION_ID, sfConfig::get('app_drmc_lod_supporting_technology_record_id'));
if (null === $techRecord = QubitInformationObject::getOne($criteria))
{
  $techRecord = new QubitInformationObject;
  $techRecord->parentId = QubitInformationObject::ROOT_ID;
  $techRecord->levelOfDescriptionId = sfConfig::get('app_drmc_lod_supporting_technology_record_id');
  $techRecord->setPublicationStatusByName('Published');
  $techRecord->title = 'Pioneer LD-V8000 laserdisc player';
  $techRecord->scopeAndContent = 'Industrial laserdisc player required for exhibition of Lovers.';
  $techRecord->indexOnSave = false;
  $techRecord->save();
}
```

Link to the artwork the way `ApiInformationobjectsRelatedTechnologiesCreateAction` does (`plugins/arRestApiPlugin/modules/api/actions/informationobjectsRelatedTechnologiesCreateAction.class.php:34-88`): a `relation` row with **subject = artwork (or component) IO, object = tech record**, `type_id` = a term in 'Supporting technologies relation types' (`requires`):

```php
$typeTerm = QubitFlatfileImport::createOrFetchTerm(
  sfConfig::get('app_drmc_taxonomy_supporting_technologies_relation_types_id'), 'requires');

// idempotency: skip if a relation with this subject/object/type already exists
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
```

This is what makes the context browser show a `supporting_technologies_count` badge on the node — `/tree` counts relations whose type term is in that taxonomy (`informationobjectsTreeAction.class.php:31-71`).

---

## 6. Fake AIPs

Model: table `aip` (`config/schema.yml:40-48`) — `id` (FK `object.id`, inheritance), `type_id` (FK term, nullable), `uuid` varchar(36) **required + unique**, `filename`, `size_on_disk` bigint, `digital_object_count` int, `created_at` timestamp, `part_of` (FK `object.id` = the **artwork** IO). Class `QubitAip` (`lib/model/QubitAip.php:26`); saving auto-creates the `object` row (`class_name='QubitAip'`, `BaseObject.php:114`); no slug needed.

Per AIP, replicate `qtPackageExtractorMETSArchivematicaDIP::addAip()` + `processArtworkRecord()` (`qtPackageExtractorMETSArchivematicaDIP.class.php:212-256, 263-308`):

```php
// Idempotency: fixed UUID lookup (QubitAip.php:77-83)
if (null !== QubitAip::getByUuid($uuid)) { continue; }

// (a) intermediate 'AIP' information object under the component (lines 263-279)
$aipIo = new QubitInformationObject;
$aipIo->parentId = $component->id;                 // or $componentsParent->id if no component
$aipIo->levelOfDescriptionId = sfConfig::get('app_drmc_lod_aip_id');
$aipIo->setPublicationStatusByName('Published');
$aipIo->title = 'AIP';
$aipIo->indexOnSave = false;
$aipIo->save();

// (b) aip row (lines 287-295)
$aip = new QubitAip;
$aip->uuid = $uuid;                                // 36-char v4 UUID, unique
$aip->filename = 'lovers_archival_master';
$aip->digitalObjectCount = 4;
$aip->partOf = $artwork->id;                       // REQUIRED: AIP detail endpoint dereferences partOf.id/title/levelOfDescriptionId unconditionally (QubitApiAip.class.php:57-59)
$aip->sizeOnDisk = 5368709120;
$aip->createdAt = '2014-05-12 10:31:00';
$aip->typeId = QubitTerm::ARTWORK_COMPONENT_ID;    // 179, taxonomy 71; null => bucketed 'unclassified' (QubitApiAction.class.php:356-359)
$aip->indexOnSave = false;
$aip->save();

// (c) properties shown in ES doc (lines 297-303)
QubitProperty::addUnique($aip->id, 'ingestionUser', 'demo', array('indexOnSave' => false));
QubitProperty::addUnique($aip->id, 'attachedTo', $component->getTitle(array('sourceCulture' => true)), array('indexOnSave' => false));

// (d) relations, type 178 — this is what puts the AIP into the artwork's ES aips[] (arElasticSearchInformationObjectPdo.class.php:906-920, 1179-1184)
$relation = new QubitRelation;                     // AIP <-> component (lines 219-224)
$relation->objectId = $component->id;
$relation->subjectId = $aip->id;
$relation->typeId = QubitTerm::AIP_RELATION_ID;    // 178
$relation->indexOnSave = false;
$relation->save();

$relation = new QubitRelation;                     // AIP <-> artwork, always (lines 244-249)
$relation->objectId = $artwork->id;
$relation->subjectId = $aip->id;
$relation->typeId = QubitTerm::AIP_RELATION_ID;
$relation->indexOnSave = false;
$relation->save();
```

Optional (fills the AIP "files" tab, otherwise it's just empty): 1–2 child IOs under `$aipIo` with LOD `app_drmc_lod_digital_object_id`, Published, title = filename, and properties `aipUUID` = the AIP uuid and `original_relative_path_within_aip` = e.g. `objects/lovers_ch1.mov` (`qtPackageExtractorMETSArchivematicaDIP.class.php:320-341, 414-461`). The files tab queries ES type `QubitInformationObject` for `aipUuid = <uuid>` + digital-object LOD (`aipsFilesAction.class.php:34-35`); the ES fields come from the `aipUUID` property (`arElasticSearchInformationObjectPdo.class.php:1338-1369`). Skip PREMIS/`metsData` — format/codec facets will simply be empty (`aipsBrowseAction.class.php:64-83`).

---

## 7. Ordering (per artwork)

1. Artwork IO (save) → verify: row in `information_object` with correct LOD + slug.
2. i18n columns, properties, classification/department, creation event + actor.
3. Components parent → components (save each) → collect `$componentIoIds`.
4. `childComponents` property on artwork.
5. Tech record + relation (Lovers only).
6. AIP IO + `aip` row + relations + properties.
7. After ALL works: reindex (section 8).

---

## 8. Reindexing — nothing renders without this

Works browse, work read (first half), AIP browse/detail/overview are **ES-only** (`informationobjectsWorksBrowseAction.class.php:224`, `aipsBrowseAction.class.php:137`, `QubitApiAction.class.php:318-373`). Only `/tree`, `/tree/associations` and `/tms` read MySQL directly.

**Option A (recommended, simplest):** after seeding, run

```
php symfony search:populate
```

`arPopulateTask` loads the DRMC config itself (`lib/task/search/arPopulateTask.class.php:52`) and the mapping-driven loop indexes both `QubitInformationObject` and `QubitAip` types (`arElasticSearchPlugin.class.php:280-296`; `aip` is a top-level main type at `plugins/arElasticSearchPlugin/config/mapping.yml:293-327`). The seeder should print this command, or invoke it via `$task = new arPopulateTask($this->dispatcher, $this->formatter); $task->run(...)`.

**Option B (surgical, like real ingest):** set `indexOnSave = false` on every object (as all snippets above do) and at the end call, per work, exactly what `processArtworkRecord` does (`qtPackageExtractorMETSArchivematicaDIP.class.php:227, 253-255`):

```php
QubitSearch::getInstance()->update($aip);
QubitSearch::getInstance()->update($aipIo);
QubitSearch::getInstance()->update($component);   // each component
QubitSearch::getInstance()->update($artwork);
QubitSearch::getInstance()->update($techRecord);
```

**PITFALL:** `$aip->save()` alone never indexes — `QubitAip::save()` tests an undefined local `$indexOnSave` instead of `$this->indexOnSave` (`lib/model/QubitAip.php:38-56`, bug at line 42) — so the explicit `update($aip)` (or `search:populate`) is mandatory.

**PITFALL:** order matters within a work — index the artwork LAST, after `childComponents`, relations and `aip` rows exist, because `arElasticSearchInformationObjectPdo::serialize()` reads them all at index time (`arElasticSearchInformationObjectPdo.class.php:906-920, 1123-1184, 1194-1258`).

---

## 9. Pitfalls checklist

1. **Missing `sfConfig::add(arDrmcBootstrapTask::getDrmcConfigArray())`** → every `sfConfig::get('app_drmc_*')` returns null → IOs saved with NULL LOD → invisible everywhere. Load it first (`arUpdateCollectionTmsTask.class.php:51`).
2. **Culture/sourceCulture:** run under `env=cli` where culture is `en`. i18n setters (`$io->title = ...`) write the current culture; the `/tree` SQL joins `information_object_i18n` on `source_culture` (`informationobjectsTreeAction.class.php:31-71`) and bootstrap creates all terms with culture 'en' (`arDrmcBootstrapTask.class.php:117-122, 193-198`). Don't set titles before/after in mixed cultures.
3. **Nested set / slugs:** always go through `QubitInformationObject::save()`; never raw-insert `information_object`. Parent must be saved (have an id) before children reference `parentId`.
4. **Publication status:** call `setPublicationStatusByName('Published')` on every IO (`qtPackageExtractorMETSArchivematicaDIP.class.php:189, 231, 269, 323`) — creates the `status` row (`QubitInformationObject.php:1795-1805`).
5. **`aip.uuid` is required + unique** (`config/schema.yml:43`) — use fixed literal UUIDs for idempotency via `QubitAip::getByUuid()` (`QubitAip.php:77-83`).
6. **`aip.part_of` must point at a real artwork IO** — `QubitApiAip::getResults()` dereferences `partOf` unconditionally (`QubitApiAip.class.php:57-59`).
7. **Relation direction:** AIP relations are `subject = aip`, `object = IO` (`qtPackageExtractorMETSArchivematicaDIP.class.php:244-247`); tech-record relations are `subject = artwork/component`, `object = tech record` (`informationobjectsRelatedTechnologiesCreateAction.class.php:34-88`). Swapping them breaks the ES `aips[]` join (`arElasticSearchInformationObjectPdo.class.php:906-920`) and the tree badge respectively.
8. **`addDataToCreationEvent` actor-date bug** (`qtSwordPlugin.class.php:46` uses `$options['actorDate']`) — set `datesOfExistence` on the actor yourself.
9. **`QubitAip::save()` indexing bug** (`QubitAip.php:42`) — always explicitly reindex AIPs.
10. **Component numbers:** without a `ComponentNumber` property the tree shows bare titles and DIP-style AIP↔component matching can't work (`informationobjectsTreeAction.class.php`, `qtPackageExtractorMETSArchivematicaDIP.class.php:198-210`).
11. **Term ids differ per database** — resolve everything through `sfConfig`; only 111 (CREATION), 178 (AIP relation), 179–182 (AIP types) are safe constants from `QubitTerm.php`.
12. **Browse result `id` vs URL id:** browse results are keyed by information_object.id (the ES `_id`), while each result's `id` *field* is the TMS identifier (`informationobjectsWorksBrowseAction.class.php:233-244`; `works.search.html:46-57`). Both must exist — set `identifier` on every artwork.

---

## 10. Verification checklist

DB (after `php symfony binder:seed-demo`):

```sql
-- 3 artworks with the artwork-record LOD
SELECT io.id, io.identifier, i.title FROM information_object io
  JOIN information_object_i18n i ON io.id = i.id
  WHERE io.level_of_description_id = (SELECT t.id FROM term t JOIN term_i18n ti ON t.id=ti.id
    WHERE ti.name='Artwork record' AND ti.culture='en' AND t.taxonomy_id=34);
-- components nested under each artwork ('Components' node + children), lft/rgt sane
SELECT id, parent_id, lft, rgt, level_of_description_id FROM information_object ORDER BY lft;
-- 3 aips + 6 relations type 178 + 1 tech relation
SELECT uuid, filename, size_on_disk, part_of FROM aip;
SELECT subject_id, object_id, type_id FROM relation WHERE type_id = 178;
-- properties per artwork
SELECT p.name, pi.value FROM property p JOIN property_i18n pi ON p.id=pi.id WHERE p.object_id = <artwork_id>;
```

Then `php symfony search:populate`, then (endpoints require an authenticated session — log into the UI and reuse the cookie with `curl -b`):

| Check | Expect |
|---|---|
| `GET /api/informationobjects/works` | `total: 3`; each result has `title`, `accession_number`, `artist`, `year`, `classification`, `department`, `thumbnail` (`informationobjectsWorksBrowseAction.class.php:233-244`) |
| `GET /api/informationobjects/<artwork_id>` | title/level_of_description_id + `names` (artist) + `dates` (`informationobjectsReadAction.class.php:40-124`) |
| `GET /api/informationobjects/<artwork_id>/tms` | `accessionNumber`, `year`, `artist`, `classification`, `department`, `medium`, `dimensions`, `thumbnail` (`informationobjectsTmsAction.class.php:56-101`) |
| `GET /api/informationobjects/<artwork_id>/tree` | nested tree: artwork → Components → components (+ AIP nodes), `supporting_technologies_count` = 1 on Lovers (`informationobjectsTreeAction.class.php:31-110`) |
| `GET /api/informationobjects/<artwork_id>/tree/associations` | empty array is fine (only component↔component/associative rels appear) |
| `GET /api/informationobjects/<artwork_id>/aips` | totals: 2 AIPs / correct summed size for Lovers (`QubitApiAction.class.php:318-373`) |
| `GET /api/informationobjects/components` | the 9 components (`informationobjectsComponentsBrowseAction.class.php`) |
| `GET /api/aips` | 3 AIPs with filename/size/ingested date (`aipsBrowseAction.class.php:137`) |
| `GET /api/aips/<uuid>` | detail incl. `partOf.title` (`QubitApiAip.class.php:29-59`) |
| `GET /api/informationobjects/technologyrecords` | 1 record |

UI (Angular routes, `plugins/arDrmcPlugin/frontend/app/scripts/routing.js:55-65, 44-53`):

- `/works` browse: 3 cards with thumbnail, artist, year, accession number; classification/department facets populated.
- `/works/<artwork_id>` view: TMS metadata panel filled; components list; AIPs overview; context browser renders artwork → Components → component nodes with the tech-record badge on Lovers.
- `/aips` and `/aips/<uuid>`: 3 AIPs listed, detail loads (files tab empty unless section 6's optional file IOs were seeded).
- Known benign error: `GET /api/informationobjects/<id>/status` 500s without a real TMS unless `LastModifiedCheckDate` was seeded — `WorkViewCtrl` only logs it.
