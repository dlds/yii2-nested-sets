<?php

/**
 * @copyright Copyright (c) 2014 Digital Deals s.r.o.
 * @license http://www.digitaldeals.cz/license/
 */

namespace dlds\nestedsets;

use yii\helpers\ArrayHelper;
use yii\helpers\StringHelper;

/**
 * NestedSetsBehavior
 *
 * @property ActiveRecord $owner
 *
 * @author Jiri Svoboda <jiri.svoboda@dlds.cz>
 */
class NestedSetsHelper {

    /**
     * Automatically detects apropriate save function
     * @param \yii\db\ActiveRecord $model
     */
    public static function save(\yii\db\ActiveRecord $model, $attribute)
    {
        if (!self::hasNestedSetsBehavior($model))
        {
            throw new \ErrorException('Autodetecting of save method is not allowed because model has no ' . StringHelper::basename(NestedSetsBehavior::className()) . 'attached.');
        }

        if (!self::areAllNestedAttributesUnsafe($model))
        {
            throw new \ErrorException(sprintf('All nested sets attributes must be unsafe. Try to remove these attrs [%s, %s, %s, %s] from model rules definition.', $model->treeAttribute, $model->leftAttribute, $model->rightAttribute, $model->depthAttribute));
        }

        if (!$model->hasProperty($attribute))
        {
            throw new \ErrorException(sprintf('Model has no attribute "%s".', $attribute));
        }

        $newParent = $model->findOne($model->{$attribute});

        if (!$newParent && !$model->isRoot())
        {
            return $model->makeRoot();
        }

        $parent = $model->parents(1)->one();

        if ($newParent && (!$parent || $newParent->primaryKey != $parent->primaryKey))
        {
            return $model->appendTo($newParent);
        }
        
        return $model->save();
    }

    /**
     * Checks if model has nested sets behavior attached to
     * @param \yii\db\ActiveRecord $model
     * @return boolen TRUE if it has behavior attached, otherwise FALSE
     */
    private static function hasNestedSetsBehavior(\yii\db\ActiveRecord $model)
    {
        $classes = ArrayHelper::getColumn($model->behaviors(), 'class');

        return in_array(NestedSetsBehavior::className(), $classes);
    }

    /**
     * Checks if all nested sets attributes are unsave so they cannot be changed implicitly
     * @param \yii\db\ActiveRecord $model
     * @return boolean
     */
    private static function areAllNestedAttributesUnsafe(\yii\db\ActiveRecord $model)
    {
        if ($model->treeAttribute && $model->isAttributeActive($model->treeAttribute))
        {
            return false;
        }

        return !$model->isAttributeActive($model->leftAttribute) && !$model->isAttributeActive($model->rightAttribute) && !$model->isAttributeActive($model->depthAttribute);
    }

}
