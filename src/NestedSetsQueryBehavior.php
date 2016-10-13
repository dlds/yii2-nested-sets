<?php

/**
 * @link https://github.com/dlds/yii2-nested-sets
 * @copyright Copyright (c) 2015 Alexander Kochetov
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace dlds\nestedsets;

use yii\db\Expression;
use yii\base\Behavior;
use yii\helpers\ArrayHelper;

/**
 * NestedSetsQueryBehavior
 *
 * @property \yii\db\ActiveQuery $owner
 *
 * @author Jiri Svoboda <jiri.svoboda@dlds.cz>
 */
class NestedSetsQueryBehavior extends Behavior
{

    /**
     * Filters only roots nodes
     * @return \yii\db\ActiveQuery
     */
    public function isTreeRoot()
    {
        $model = new $this->owner->modelClass();

        $this->owner->andWhere([$model->leftAttribute => 1]);

        return $this->owner;
    }

    /**
     * Filters nodes which are not roots
     * @return \yii\db\ActiveQuery
     */
    public function notTreeRoot()
    {
        $model = new $this->owner->modelClass();

        $this->owner->andWhere(['<>', $model->leftAttribute, 1]);

        return $this->owner;
    }

    /**
     * Filters only leaves nodes
     * @return \yii\db\ActiveQuery
     */
    public function isTreeLeaf()
    {
        $model = new $this->owner->modelClass();

        $expression = new Expression($db->quoteColumnName($model->leftAttribute) . '+ 1');
        $this->owner->andWhere([$model->rightAttribute => $expression]);

        return $this->owner;
    }

    /**
     * Filters nodes which are not leaves
     * @return \yii\db\ActiveQuery
     */
    public function notTreeLeaf()
    {
        $model = new $this->owner->modelClass();

        $expression = new Expression($db->quoteColumnName($model->leftAttribute) . '+ 1');
        $this->owner->andWhere(['>' . $model->rightAttribute, $expression]);

        return $this->owner;
    }

    /**
     * Filters only nodes who are ancestors to at least one tree node
     * @param $depth given descendant depth
     * @param $lft given descendant lft
     * @param $rgt given descendant rgt
     * @return \yii\db\ActiveQuery
     */
    public function isTreeAncestor($depth, $lft, $rgt)
    {
        $model = new $this->owner->modelClass();

        $this->owner->andWhere(['<', $model->depthAttribute, $depth]);
        $this->owner->andWhere(['<', $model->leftAttribute, $lft]);
        $this->owner->andWhere(['>', $model->rightAttribute, $rgt]);

        return $this->owner;
    }

    /**
     * Filters only nodes who are descendants of given depth, lft, rgt
     * @return \yii\db\ActiveQuery
     */
    public function isTreeDescendant($depth, $lft, $rgt)
    {
        $model = new $this->owner->modelClass();

        $this->owner->andWhere(['>', $model->depthAttribute, $depth]);
        $this->owner->andWhere(['>', $model->leftAttribute, $lft]);
        $this->owner->andWhere(['<', $model->rightAttribute, $rgt]);

        return $this->owner;
    }

    /**
     * Filters only nodes in given tree depth
     * @param int $depth
     * @return \yii\db\ActiveQuery
     */
    public function inTreeDepth($depth)
    {
        return $this->treeDepthInRange($depth, $depth);
    }

    /**
     * Filters only nodes in given tree depth range
     * @param int $min
     * @param int $max
     * @return \yii\db\ActiveQuery
     */
    public function inTreeDepthRange($min, $max)
    {
        $model = new $this->owner->modelClass();

        $this->andWhere(['>=', $model->depthAttribute, $min]);

        return $this->andWhere(['<=', $model->depthAttribute, $max]);
    }

}
