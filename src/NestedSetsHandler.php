<?php
/**
 * @copyright Copyright (c) 2014 Digital Deals s.r.o.
 * @license http://www.digitaldeals.cz/license/
 */

namespace dlds\nestedsets;

use yii\helpers\ArrayHelper;
use yii\helpers\StringHelper;

/**
 * NestedSetsHandler
 *
 * @property ActiveRecord $owner
 *
 * @author Jiri Svoboda <jiri.svoboda@dlds.cz>
 */
class NestedSetsHandler {

    /**
     * Automatically detects apropriate save function
     * @param \yii\db\ActiveRecord $model
     */
    public static function save(\yii\db\ActiveRecord $model, $attribute)
    {
        if (!self::hasNestedSetsBehavior($model))
        {
            throw new \ErrorException('Autodetecting of save method is not allowed because model has no '.StringHelper::basename(NestedSetsBehavior::className()).'attached.');
        }

        if (!self::areAllNestedAttributesSafe($model))
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
            if ($model->treeAttribute)
            {
                return $model->makeRoot();
            }

            $root = $model->find()->roots()->one();

            if ($root)
            {
                return $model->appendTo($root);
            }
        }

        $parent = $model->parents(1)->one();

        if ($newParent && $newParent->primaryKey != $model->primaryKey && (!$parent || $newParent->primaryKey != $parent->primaryKey))
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
    private static function areAllNestedAttributesSafe(\yii\db\ActiveRecord $model)
    {
        if ($model->treeAttribute && $model->isAttributeSafe($model->treeAttribute))
        {
            return false;
        }

        return !$model->isAttributeSafe($model->leftAttribute) && !$model->isAttributeSafe($model->rightAttribute) && !$model->isAttributeSafe($model->depthAttribute);
    }
}