<?php

namespace Drupal\rep;

class Constant {

  const DEFAULT_WAS_GENERATED_BY          = "http://hadatac.org/ont/vstoi#Original";
  const DEFAULT_LANGUAGE                  = "en";
  const DEFAULT_INFORMANT                 = "http://hadatac.org/ont/vstoi#Self";

  const PREFIX_ANNOTATION                 = "AN";
  const PREFIX_ANNOTATION_STEM            = "AS";
  const PREFIX_CODEBOOK                   = "CB";
  const PREFIX_DATAFILE                   = "DF";
  const PREFIX_DA                         = "DA";
  const PREFIX_DSG                        = "DG";
  const PREFIX_DETECTOR_STEM              = "DS";
  const PREFIX_DETECTOR                   = "DT";
  const PREFIX_INSTRUMENT                 = "IN";
  const PREFIX_ORGANIZATION               = "OR";
  const PREFIX_PERSON                     = "PS";
  const PREFIX_PLACE                      = "PL";
  const PREFIX_POSTAL_ADDRESS             = "PA";
  const PREFIX_RESPONSE_OPTION            = "RO";
  const PREFIX_SEMANTIC_VARIABLE          = "SV";
  const PREFIX_SDD                        = "SD";
  const PREFIX_STUDY                      = "ST";
  const PREFIX_STUDY_OBJECT_COLLECTION    = "OC";
  const PREFIX_STUDY_OBJECT               = "OB";
  const PREFIX_STUDY_ROLE                 = "SR";
  const PREFIX_SUBCONTAINER               = "SC";
  const PREFIX_VIRTUAL_COLUMN             = "VC";

  const FILE_STATUS_UNPROCESSED           = "UNPROCESSED";
  const FILE_STATUS_PROCESSED             = "PROCESSED";
  const FILE_STATUS_PROCESSED_STD         = "PROCESSED_STD";
  const FILE_STATUS_WORKING               = "WORKING";
  const FILE_STATUS_WORKING_STD           = "WORKING_STD";

  const TOT_PER_PAGE                      = 6;
  const TOT_OBJS_PER_PAGE                 = 20;
  const TOT_SOCS_PER_PAGE                 = 40;

}