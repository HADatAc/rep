<?php

  namespace Drupal\rep;

  use Drupal\rep\Vocabulary\HASCO;
  use Drupal\rep\Vocabulary\VSTOI;

  class EntryPoints {

    // CLASSES
    const CLASS_EP_ANNOTATION_STEM           = HASCO::ANNOTATION_STEM_CLASS_ENTRY_POINT;
    const CLASS_EP_ATTRIBUTE                 = HASCO::ATTRIBUTE_CLASS_ENTRY_POINT;
    const CLASS_EP_CODEBOOK                  = HASCO::CODEBOOK_CLASS_ENTRY_POINT;
    const CLASS_EP_COMPONENT_STEM            = HASCO::COMPONENT_STEM_CLASS_ENTRY_POINT;
    const CLASS_EP_COMPONENT                 = HASCO::COMPONENT_CLASS_ENTRY_POINT;
    const CLASS_EP_COMPONENT_ATTRIBUTE       = HASCO::COMPONENT_ATTRIBUTE_CLASS_ENTRY_POINT;
    const CLASS_EP_ENTITY                    = HASCO::ENTITY_CLASS_ENTRY_POINT;
    const CLASS_EP_GROUP                     = HASCO::GROUP_CLASS_ENTRY_POINT;
    const CLASS_EP_INSTRUMENT                = HASCO::INSTRUMENT_CLASS_ENTRY_POINT;
    const CLASS_EP_ORGANIZATION              = HASCO::ORGANIZATION_CLASS_ENTRY_POINT;
    const CLASS_EP_PERSON                    = HASCO::PERSON_CLASS_ENTRY_POINT;
    const CLASS_EP_PLACE                     = HASCO::PLACE_CLASS_ENTRY_POINT;
    const CLASS_EP_PLATFORM                  = HASCO::PLATFORM_CLASS_ENTRY_POINT;
    const CLASS_EP_WORKFLOW_STEM             = VSTOI::PROCESS_STEM;
    // const EP_QUESTIONNAIRE             = HASCO::QUESTIONNAIRE_ENTRY_POINT;
    const CLASS_EP_RESPONSE_OPTION           = HASCO::RESPONSE_OPTION_CLASS_ENTRY_POINT;
    const CLASS_EP_STUDY                     = HASCO::STUDY_CLASS_ENTRY_POINT;
    const CLASS_EP_TASK                      = HASCO::TASK_CLASS_ENTRY_POINT;
    const CLASS_EP_TASK_TEMPORAL_DEPENDENCY  = HASCO::TASK_TEMPORAL_DEPENDENCY_CLASS_ENTRY_POINT;
    const CLASS_EP_ANATOMICAL_PART           = HASCO::ANATOMICAL_PART_CLASS_ENTRY_POINT;
    const CLASS_EP_PROCESS                   = HASCO::PROCESS_CLASS_ENTRY_POINT;
    const CLASS_EP_MEDICAL_DEVICE            = HASCO::MEDICAL_DEVICE_CLASS_ENTRY_POINT;

    // ONTOLOGY ENTRY POINTS  
    const CLASS_EP_NCIT                      = 'http://purl.obolibrary.org/obo/NCIT_C97325'; // NCIT Manufactured Object (root in PMSR subset)
    const CLASS_EP_UBERON                    = 'http://purl.obolibrary.org/obo/UBERON_0001062'; // UBERON Anatomical Entity
    const CLASS_EP_PMSR                      = 'http://pmsr.net/ont/pmsr#MedicalSimulationProcessStem'; // PMSR Medical Simulation Process Stem

    // INSTANCES
    const INSTANCE_EP_INSTRUMENT             = HASCO::INSTRUMENT_INSTANCE_ENTRY_POINT;
    const INSTANCE_EP_COMPONENT              = HASCO::COMPONENT_INSTANCE_ENTRY_POINT;
    const INSTANCE_EP_PLATFORM               = HASCO::PLATFORM_INSTANCE_ENTRY_POINT;
    const INSTANCE_EP_UNIT                   = HASCO::UNIT_INSTANCE_ENTRY_POINT;

  }
