<?php

  namespace Drupal\rep\Vocabulary;

use PHPUnit\Event\Application\Started;

  class HASCO {

    const HASCO                   = "http://hadatac.org/ont/hasco/";

    // CLASSES

    const DATAFILE                      = HASCO::HASCO . "DataFile";
    const DATA_ACQUISITION              = HASCO::HASCO . "DataAcquisition";
    const DD                            = HASCO::HASCO . "DD";
    const DP2                           = HASCO::HASCO . "DP2";
    const DSG                           = HASCO::HASCO . "DSG";
    const INS                           = HASCO::HASCO . "INS";
    const KGR                           = HASCO::HASCO . "KGR";
    const MANAGED_ONTOLOGY              = HASCO::HASCO . "ManagedOntology";
    const ONTOLOGY                      = HASCO::HASCO . "Ontology";
    const POSSIBLE_VALUE                = HASCO::HASCO . "PossibleValue";
    const SAMPLE_COLLECTION             = HASCO::HASCO . "SampleCollection";
    const SDD                           = HASCO::HASCO . "SDD";
    const SDD_ATTRIBUTE                 = HASCO::HASCO . "SDDAttribute";
    const SDD_OBJECT                    = HASCO::HASCO . "SDDObject";
    const SEMANTIC_DATA_DICTIONARY      = HASCO::HASCO . "SemanticDataDictionary";
    const SEMANTIC_VARIABLE             = HASCO::HASCO . "SemanticVariable";
    const SPACE_COLLECTION              = HASCO::HASCO . "SpaceCollection";
    const STD                           = HASCO::HASCO . "STD";
    const STR                           = HASCO::HASCO . "STR";
    const STREAM                        = HASCO::HASCO . "Stream";
    const STREAMTOPIC                   = HASCO::HASCO . 'StreamTopic';
    const STUDY                         = HASCO::HASCO . "Study";
    const STUDY_OBJECT                  = HASCO::HASCO . "StudyObject";
    const STUDY_OBJECT_COLLECTION       = HASCO::HASCO . "StudyObjectCollection";
    const STUDY_ROLE                    = HASCO::HASCO . "StudyRole";
    const SUBJECT_GROUP                 = HASCO::HASCO . "SubjectGroup";
    const TIME_COLLECTION               = HASCO::HASCO . "TimeCollection";
    const VALUE                         = HASCO::HASCO . "Value";
    const VIRTUAL_COLUMN                = HASCO::HASCO . "VirtualColumn";

    // PROPERTIES

    const IS_MEMBER_OF                  = HASCO::HASCO . "isMemberOf";

    /*
     * STREAM STATUS
     */
    const DRAFT                         = HASCO::HASCO . "Draft";
    const ACTIVE                        = HASCO::HASCO . "Active";
    const CLOSED                        = HASCO::HASCO . "Closed";
    const ALL_STATUSES                  = HASCO::HASCO . "AllStatuses";

    /*
     * STREAM TYPE=MESSAGES RECORDING STATUS
     */
    const INACTIVE                      = HASCO::HASCO . "Inactive";
    const RECORDING                     = HASCO::HASCO . "Recording";
    const INGESTING                     = HASCO::HASCO . "Ingesting";
    const SUSPENDED                     = HASCO::HASCO . "Suspended";

    /*
     * PERMISSION URI
     */

    const PUBLIC                        = HASCO::HASCO . "Public";
    const PRIVATE                       = HASCO::HASCO . "Private";

   }
