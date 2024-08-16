<?php

  namespace Drupal\rep\Vocabulary;

  class VSTOI {

    const VSTOI                           = "http://hadatac.org/ont/vstoi#";

    /*
     *    CLASSES
     */

    const ANNOTATION                      = VSTOI::VSTOI . "Annotation";
    const ANNOTATION_STEM                 = VSTOI::VSTOI . "AnnotationStem";
    const CODEBOOK                        = VSTOI::VSTOI . "Codebook";
    const CONTAINER                       = VSTOI::VSTOI . "Container";
    const CONTAINER_SLOT                  = VSTOI::VSTOI . "ContainerSlot";
    const DEPLOYMENT                      = VSTOI::VSTOI . "Deployment";
    const DETECTOR                        = VSTOI::VSTOI . "Detector";
    const DETECTOR_INSTANCE               = VSTOI::VSTOI . "DetectorInstance";
    const DETECTOR_STEM                   = VSTOI::VSTOI . "DetectorStem";
    const INSTANCE                        = VSTOI::VSTOI . "Instance";
    const INSTRUMENT                      = VSTOI::VSTOI . "Instrument";
    const INSTRUMENT_INSTANCE             = VSTOI::VSTOI . "InstrumentInstance";
    const ITEM                            = VSTOI::VSTOI . "Item";
    const PLATFORM                        = VSTOI::VSTOI . "Platform";
    const PLATFORM_INSTANCE               = VSTOI::VSTOI . "PlatformInstance";
    const PSYCHOMETRIC_QUESTIONNAIRE      = VSTOI::VSTOI . "PsychometricQuestionnaire";
    const QUESTIONNAIRE                   = VSTOI::VSTOI . "Questionnaire";
    const RESPONSE_OPTION                 = VSTOI::VSTOI . "ResponseOption";
    const SUBCONTAINER                    = VSTOI::VSTOI . "Subcontainer";

    /*
     *    PROPERTIES
     */

    const BELONGS_TO                      = VSTOI::VSTOI . "belongsTo";
    const HAS_ANNOTATION_STEM             = VSTOI::VSTOI . "hasAnnotationStem";
    const HAS_PLATFORM                    = VSTOI::VSTOI . "hasPlatform";
    const HAS_SERIAL_NUMBER               = VSTOI::VSTOI . "hasSerialNumber";
    const HAS_WEB_DOCUMENTATION           = VSTOI::VSTOI . "hasWebDocumentation";
    const HAS_CONTENT                     = VSTOI::VSTOI . "hasContent";
    const HAS_CODEBOOK                    = VSTOI::VSTOI . "hasCodebook";
    const HAS_DETECTOR                    = VSTOI::VSTOI . "hasDetector";
    const HAS_DETECTOR_STEM               = VSTOI::VSTOI . "hasDetectorStem";
    const HAS_INSTRUCTION                 = VSTOI::VSTOI . "hasInstruction";
    const HAS_LANGUAGE                    = VSTOI::VSTOI . "hasLanguage";
    const HAS_POSITION                    = VSTOI::VSTOI . "hasPosition";
    const HAS_PRIORITY                    = VSTOI::VSTOI . "hasPriority";
    const HAS_SHORT_NAME                  = VSTOI::VSTOI . "hasShortName";
    const HAS_STYLE                       = VSTOI::VSTOI . "hasStyle";
    const HAS_STATUS                      = VSTOI::VSTOI . "hasStatus";
    const HAS_SIR_MANAGER_EMAIL           = VSTOI::VSTOI . "hasSIRManagerEmail";
    const HAS_VERSION                     = VSTOI::VSTOI . "hasVersion";
    const OF_CODEBOOK                     = VSTOI::VSTOI . "ofCodebook";

    /*
     *    POSITIONS
     */

    const NOT_VISIBLE                     = VSTOI::VSTOI . "NotVisible";
    const TOP_LEFT                        = VSTOI::VSTOI . "TopLeft";
    const TOP_CENTER                      = VSTOI::VSTOI . "TopCenter";
    const TOP_RIGHT                       = VSTOI::VSTOI . "TopRight";
    const LINE_BELOW_TOP                  = VSTOI::VSTOI . "LineBelowTop";
    const LINE_ABOVE_BOTTOM               = VSTOI::VSTOI . "LineAboveBottom";
    const BOTTOM_LEFT                     = VSTOI::VSTOI . "BottomLeft";
    const BOTTOM_CENTER                   = VSTOI::VSTOI . "BottomCenter";
    const BOTTOM_RIGHT                    = VSTOI::VSTOI . "BottomRight";
    const PAGE_TOP_LEFT                   = VSTOI::VSTOI . "PageTopLeft";
    const PAGE_TOP_CENTER                 = VSTOI::VSTOI . "PageTopCenter";
    const PAGE_TOP_RIGHT                  = VSTOI::VSTOI . "PageTopRight";
    const PAGE_LINE_BELOW_TOP             = VSTOI::VSTOI . "PageLineBelowTop";
    const PAGE_LINE_ABOVE_BOTTOM          = VSTOI::VSTOI . "PageLineAboveBottom";
    const PAGE_BOTTOM_LEFT                = VSTOI::VSTOI . "PageBottomLeft";
    const PAGE_BOTTOM_CENTER              = VSTOI::VSTOI . "PageBottomCenter";
    const PAGE_BOTTOM_RIGHT               = VSTOI::VSTOI . "PageBottomRight";


  }
