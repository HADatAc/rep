<?php

namespace Drupal\rep\Vocabulary;

use Drupal\rep\Vocabulary\SCHEMA;

class ORGANIZATION_SCHEMA {

  const CollegeOrUniversity                     = SCHEMA::SCHEMA . "CollegeOrUniversity";
  const CollegeOrUniversityOrFacultyOrSchool    = SCHEMA::SCHEMA . "EducationalOrganization";
  const GovernmentOrganization                  = SCHEMA::SCHEMA . "GovernmentOrganization";
  const MedicalOrganization                     = SCHEMA::SCHEMA . "MedicalOrganization";
  const ResearchOrganization                    = SCHEMA::SCHEMA . "ResearchOrganization";

  public static function getOptions(): array {
    return [
      self::CollegeOrUniversity                   => 'College or University',
      self::CollegeOrUniversityOrFacultyOrSchool  => 'College or University’s Faculty, School',
      self::GovernmentOrganization                => 'Governament Organization',
      self::MedicalOrganization                   => 'Medical Organization',
      self::ResearchOrganization                  => 'Research Organization',
    ];
  }
}
