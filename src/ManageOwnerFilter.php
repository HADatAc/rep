<?php

namespace Drupal\rep;

use Drupal\rep\Vocabulary\VSTOI;

class ManageOwnerFilter {

  public static function isAdmin(): bool {
    $account = \Drupal::currentUser();
    if (!$account) {
      return FALSE;
    }

    $roles = $account->getRoles();
    if (in_array('administrator', $roles, TRUE) || in_array('content_editor', $roles, TRUE)) {
      return TRUE;
    }

    return $account->hasPermission('administer site configuration') || $account->hasPermission('administer users');
  }

  public static function normalizeSelectedEmail(?string $email): string {
    $value = trim((string) $email);
    if ($value === '_' || strcasecmp($value, 'all') === 0) {
      return '';
    }
    return $value;
  }

  public static function resolveEffectiveOwner(string $authenticatedEmail, ?string $selectedEmail, ?string $statusFilter): string {
    $authenticated = trim($authenticatedEmail);
    $selected = self::normalizeSelectedEmail($selectedEmail);

    if (!self::isAdmin() || $selected === '') {
      return $authenticated;
    }

    if (!self::allowsOwnerOverrideForStatus($statusFilter)) {
      return $authenticated;
    }

    return $selected;
  }

  public static function allowsOwnerOverrideForStatus(?string $statusFilter): bool {
    if ($statusFilter === NULL || $statusFilter === '') {
      return FALSE;
    }

    if ($statusFilter === '_') {
      return TRUE;
    }

    return in_array((string) $statusFilter, [VSTOI::DRAFT, VSTOI::UNDER_REVIEW], TRUE);
  }

}
