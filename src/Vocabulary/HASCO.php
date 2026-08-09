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
    const PLATFORM_INSTANCE             = HASCO::HASCO . "PlatformInstance";
    const INSTRUMENT_INSTANCE           = HASCO::HASCO . "InstrumentInstance";
    const COMPONENT_INSTANCE            = HASCO::HASCO . "ComponentInstance";
    const COMPONENT_DEPLOYMENT          = HASCO::HASCO . "ComponentDeployment";
    const STUDY                         = HASCO::HASCO . "Study";
    const PROCESS_BASED_STUDY           = HASCO::HASCO . "ProcessBasedStudy";
    const STUDY_OBJECT                  = HASCO::HASCO . "StudyObject";
    const STUDY_OBJECT_COLLECTION       = HASCO::HASCO . "StudyObjectCollection";
    const STUDY_ROLE                    = HASCO::HASCO . "StudyRole";
    const SUBJECT_GROUP                 = HASCO::HASCO . "SubjectGroup";
    const TIME_COLLECTION               = HASCO::HASCO . "TimeCollection";
    const VALUE                         = HASCO::HASCO . "Value";
    const VIRTUAL_COLUMN                = HASCO::HASCO . "VirtualColumn";
    const WKF                           = HASCO::HASCO . "WKF";

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

    /*
     *  ENTRY POINTS
     */

    // CLASS
    const ANNOTATION_STEM_CLASS_ENTRY_POINT           = HASCO::HASCO . "AnnotationStemEntryPoint";
    const ATTRIBUTE_CLASS_ENTRY_POINT                 = HASCO::HASCO . "AttributeEntryPoint";
    const CODEBOOK_CLASS_ENTRY_POINT                  = HASCO::HASCO . "CodebookEntryPoint";
    const COMPONENT_STEM_CLASS_ENTRY_POINT            = HASCO::HASCO . "ComponentStemEntryPoint";
    const COMPONENT_CLASS_ENTRY_POINT                 = HASCO::HASCO . "ComponentEntryPoint";
    const COMPONENT_ATTRIBUTE_CLASS_ENTRY_POINT       = HASCO::HASCO . "ComponentAttributeEntryPoint";
    const ENTITY_CLASS_ENTRY_POINT                    = HASCO::HASCO . "EntityEntryPoint";
    const GROUP_CLASS_ENTRY_POINT                     = HASCO::HASCO . "GroupEntryPoint";
    const INSTRUMENT_CLASS_ENTRY_POINT                = HASCO::HASCO . "InstrumentEntryPoint";
    const ORGANIZATION_CLASS_ENTRY_POINT              = HASCO::HASCO . "OrganizationEntryPoint";
    const PERSON_CLASS_ENTRY_POINT                    = HASCO::HASCO . "PersonEntryPoint";
    const PLACE_CLASS_ENTRY_POINT                     = HASCO::HASCO . "PlaceEntryPoint";
    const PLATFORM_CLASS_ENTRY_POINT                  = HASCO::HASCO . "PlatformEntryPoint";
    const WORKFLOW_STEM_CLASS_ENTRY_POINT             = HASCO::HASCO . "WorkflowEntryPoint";
    // const QUESTIONNAIRE_ENTRY_POINT             = HASCO::HASCO . "QuestionnaireEntryPoint";
    const RESPONSE_OPTION_CLASS_ENTRY_POINT           = HASCO::HASCO . "ResponseOptionEntryPoint";
    const STUDY_CLASS_ENTRY_POINT                     = HASCO::HASCO . "StudyEntryPoint";
    const TASK_CLASS_ENTRY_POINT                      = HASCO::HASCO . "TaskEntryPoint";
    const TASK_TEMPORAL_DEPENDENCY_CLASS_ENTRY_POINT  = HASCO::HASCO . "TaskTemporalDependencyEntryPoint";
    const ANATOMICAL_PART_CLASS_ENTRY_POINT           = HASCO::HASCO . "AnatomicalPartEntryPoint";
    const PROCESS_CLASS_ENTRY_POINT                   = HASCO::HASCO . "ProcessEntryPoint";
    const MEDICAL_DEVICE_CLASS_ENTRY_POINT            = HASCO::HASCO . "MedicalDeviceEntryPoint";

    // INSTANCES
    const INSTRUMENT_INSTANCE_ENTRY_POINT         = HASCO::HASCO . "InstrumentInstanceEntryPoint";
    const COMPONENT_INSTANCE_ENTRY_POINT         = HASCO::HASCO . "ComponentInstanceEntryPoint";
    const PLATFORM_INSTANCE_ENTRY_POINT         = HASCO::HASCO . "PlatformInstanceEntryPoint";
    const UNIT_INSTANCE_ENTRY_POINT             = HASCO::HASCO . "UnitInstanceEntryPoint";

   }
