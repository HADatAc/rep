<?php

namespace Drupal\rep;

class Constant {

  const DEFAULT_WAS_GENERATED_BY          = "http://hadatac.org/ont/vstoi#Original";
  const DEFAULT_LANGUAGE                  = "en";
  const DEFAULT_INFORMANT                 = "http://hadatac.org/ont/vstoi#Self";

  const PREFIX_ANNOTATION                 = "ANN";
  const PREFIX_ANNOTATION_STEM            = "ASM";
  const PREFIX_CODEBOOK                   = "CBK";
  const PREFIX_DATAFILE                   = "DFL";
  const PREFIX_DA                         = "DA0";
  const PREFIX_DD                         = "DD0";
  const PREFIX_DP2                        = "DP2";
  const PREFIX_DEPLOYMENT                 = "DPL";
  const PREFIX_DETECTOR_STEM              = "DSM";
  const PREFIX_DETECTOR                   = "DTC";
  const PREFIX_DETECTOR_INSTANCE          = "DTI";
  const PREFIX_DSG                        = "DSG";
  const PREFIX_INSTRUMENT                 = "IS0";
  const PREFIX_INSTRUMENT_INSTANCE        = "INI";
  const PREFIX_INS                        = "INS";
  const PREFIX_ORGANIZATION               = "ORG";
  const PREFIX_PERSON                     = "PER";
  const PREFIX_PLACE                      = "PLC";
  const PREFIX_PLATFORM                   = "PF0";
  const PREFIX_PLATFORM_INSTANCE          = "PFI";
  const PREFIX_POSSIBLE_VALUE             = "PSV";
  const PREFIX_POSTAL_ADDRESS             = "PAD";
  const PREFIX_RESPONSE_OPTION            = "ROP";
  const PREFIX_SDD                        = "SDD";
  const PREFIX_SDD_ATTRIBUTE              = "SDDATT";
  const PREFIX_SDD_OBJECT                 = "SDDOBJ";
  const PREFIX_SEMANTIC_DATA_DICTIONARY   = "SDY";
  const PREFIX_SEMANTIC_VARIABLE          = "SVR";
  const PREFIX_STUDY                      = "STD";
  const PREFIX_STUDY_OBJECT_COLLECTION    = "OCL";
  const PREFIX_STUDY_OBJECT               = "OBJ";
  const PREFIX_STUDY_ROLE                 = "RLE";
  const PREFIX_STR                        = "STR";
  const PREFIX_STREAM                     = "STM";
  const PREFIX_SUBCONTAINER               = "SCT";
  const PREFIX_VIRTUAL_COLUMN             = "VCO";

  const FILE_STATUS_UNPROCESSED           = "UNPROCESSED";
  const FILE_STATUS_PROCESSED             = "PROCESSED";
  const FILE_STATUS_PROCESSED_STD         = "PROCESSED_STD";
  const FILE_STATUS_WORKING               = "WORKING";
  const FILE_STATUS_WORKING_STD           = "WORKING_STD";

  const TOT_PER_PAGE                      = 6;
  const TOT_OBJS_PER_PAGE                 = 20;
  const TOT_SOCS_PER_PAGE                 = 40;

}