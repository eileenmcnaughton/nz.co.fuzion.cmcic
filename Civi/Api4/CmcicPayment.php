<?php

namespace Civi\Api4;

use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Monetico CmcicPayment operations (Reconciliation, status checks).
 *
 * @searchable none
 * @since 2.0.0
 * @package Civi\Api4
 */
class CmcicPayment extends AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return Action\CmcicPayment\Reconcile
   */
  public static function reconcile(bool $checkPermissions = TRUE): Action\CmcicPayment\Reconcile {
    return (new Action\CmcicPayment\Reconcile(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return BasicGetFieldsAction
   */
  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

}
