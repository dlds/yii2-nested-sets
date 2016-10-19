<?php

/**
 * @link https://github.com/dlds/yii2-nested-sets
 * @copyright Copyright (c) 2015 Alexander Kochetov
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace dlds\nestedsets;

use yii\base\Behavior;
use yii\base\NotSupportedException;
use yii\db\ActiveRecord;
use yii\db\Exception;
use yii\db\Expression;
use dlds\nestedsets\NestedSetsNodeInterface;

/**
 * NestedSetsBehavior
 *
 * @property ActiveRecord $owner
 *
 * @author Alexander Kochetov <dlds@gmail.com>
 */
class NestedSetsBehavior extends Behavior
{

    const OPR_MAKE_ROOT = 'makeRoot';
    const OPR_PREPEND_TO = 'prependTo';
    const OPR_APPEND_TO = 'appendTo';
    const OPR_INSERT_BEFORE = 'insertBefore';
    const OPR_INSERT_AFTER = 'insertAfter';
    const OPR_DELETE_WITH_DESCENDANTS = 'deleteWithDescendants';

    /**
     * @var string|false
     */
    public $treeAttribute = false;

    /**
     * @var string
     */
    public $leftAttribute = 'lft';

    /**
     * @var string
     */
    public $rightAttribute = 'rgt';

    /**
     * @var string
     */
    public $depthAttribute = 'depth';

    /**
     * @var string|null
     */
    protected $operation;

    /**
     * @var ActiveRecord|null targeted target
     */
    protected $target;

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeInsert',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeUpdate',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    /**
     * Detects if nested operation will be performed and notify owner about that
     */
    public function beforeValidate()
    {
        if (!$this->operation) {
            return true;
        }

        if ($this->operation != self::OPR_DELETE_WITH_DESCENDANTS) {
            $this->owner->markAttributeDirty($this->treeAttribute);
            $this->owner->markAttributeDirty($this->leftAttribute);
            $this->owner->markAttributeDirty($this->rightAttribute);
            $this->owner->markAttributeDirty($this->depthAttribute);
        }
    }

    /**
     * Validates node operation among structure
     * ---
     * It is called before main validation is processed.
     * ---
     * @param type $operation
     * @param $target
     * @return boolean
     */
    public function validateNodeOperation($operation, $target)
    {
        // all operation are considered as valid in default
        return true;
    }
    
    /**
     * @throws NotSupportedException
     */
    public function beforeInsert()
    {
        $this->owner->validateNodeOperation($this->operation, $this->target);
        
        // refresh target to be sure we have current data
        if ($this->target !== null && !$this->target->getIsNewRecord()) {
            $this->target->refresh();
        }

        switch ($this->operation) {
            case self::OPR_MAKE_ROOT:
                $this->beforeInsertRootNode();
                break;
            case self::OPR_PREPEND_TO:
                $this->beforeInsertNode($this->target->getAttribute($this->leftAttribute) + 1, 1);
                break;
            case self::OPR_APPEND_TO:
                $this->beforeInsertNode($this->target->getAttribute($this->rightAttribute), 1);
                break;
            case self::OPR_INSERT_BEFORE:
                $this->beforeInsertNode($this->target->getAttribute($this->leftAttribute), 0);
                break;
            case self::OPR_INSERT_AFTER:
                $this->beforeInsertNode($this->target->getAttribute($this->rightAttribute) + 1, 0);
                break;
            default:
                throw new NotSupportedException('Method "' . get_class($this->owner) . '::insert" is not supported for inserting new nodes.');
        }
    }

    /**
     * @throws Exception
     */
    protected function beforeInsertRootNode()
    {
        if ($this->treeAttribute === false && $this->owner->find()->isTreeRoot()->exists()) {
            throw new Exception('Can not create more than one root when "treeAttribute" is false.');
        }

        $this->owner->setAttribute($this->leftAttribute, 1);
        $this->owner->setAttribute($this->rightAttribute, 2);
        $this->owner->setAttribute($this->depthAttribute, 0);
    }

    /**
     * @param integer $value
     * @param integer $depth
     * @throws Exception
     */
    protected function beforeInsertNode($value, $depth)
    {
        if ($this->target->getIsNewRecord()) {
            throw new Exception('Can not create a node when the target node is new record.');
        }

        if ($depth === 0 && $this->target->isTreeRoot()) {
            throw new Exception('Can not create a node when the target node is root.');
        }

        $this->owner->setAttribute($this->leftAttribute, $value);
        $this->owner->setAttribute($this->rightAttribute, $value + 1);
        $this->owner->setAttribute($this->depthAttribute, $this->target->getAttribute($this->depthAttribute) + $depth);

        if ($this->treeAttribute !== false) {
            $this->owner->setAttribute($this->treeAttribute, $this->target->getAttribute($this->treeAttribute));
        }

        $this->shiftLeftRightAttribute($value, 2);
    }

    /**
     * @throws Exception
     */
    public function afterInsert()
    {
        if ($this->operation === self::OPR_MAKE_ROOT && $this->treeAttribute !== false) {
            $this->owner->setAttribute($this->treeAttribute, $this->owner->getPrimaryKey());
            $primaryKey = $this->owner->primaryKey();

            if (!isset($primaryKey[0])) {
                throw new Exception('"' . get_class($this->owner) . '" must have a primary key.');
            }

            $this->owner->updateAll(
                [$this->treeAttribute => $this->owner->getAttribute($this->treeAttribute)], [$primaryKey[0] => $this->owner->getAttribute($this->treeAttribute)]
            );
        }

        $this->operation = null;
        $this->target = null;
    }

    /**
     * @throws Exception
     */
    public function beforeUpdate()
    {
        $this->owner->validateNodeOperation($this->operation, $this->target);
        
        if ($this->target !== null && !$this->target->getIsNewRecord()) {
            $this->target->refresh();
        }

        switch ($this->operation) {
            case self::OPR_MAKE_ROOT:
                if ($this->treeAttribute === false) {
                    throw new Exception('Can not move a node as the root when "treeAttribute" is false.');
                }

                if ($this->owner->isTreeRoot()) {
                    throw new Exception('Can not move the root node as the root.');
                }

                break;
            case self::OPR_INSERT_BEFORE:
            case self::OPR_INSERT_AFTER:
                if ($this->target->isTreeRoot()) {
                    throw new Exception('Can not move a node when the target node is root.');
                }
            case self::OPR_PREPEND_TO:
            case self::OPR_APPEND_TO:
                if ($this->target->getIsNewRecord()) {
                    throw new Exception('Can not move a node when the target node is new record.');
                }

                if ($this->owner->equals($this->target)) {
                    throw new Exception('Can not move a node when the target node is same.');
                }

                if ($this->target->isDescendantOf($this->owner)) {
                    throw new Exception('Can not move a node when the target node is child.');
                }
        }
    }

    /**
     * @return void
     */
    public function afterUpdate()
    {
        switch ($this->operation) {
            case self::OPR_MAKE_ROOT:
                $this->moveNodeAsRoot();
                break;
            case self::OPR_PREPEND_TO:
                $this->moveNode($this->target->getAttribute($this->leftAttribute) + 1, 1);
                break;
            case self::OPR_APPEND_TO:
                $this->moveNode($this->target->getAttribute($this->rightAttribute), 1);
                break;
            case self::OPR_INSERT_BEFORE:
                $this->moveNode($this->target->getAttribute($this->leftAttribute), 0);
                break;
            case self::OPR_INSERT_AFTER:
                $this->moveNode($this->target->getAttribute($this->rightAttribute) + 1, 0);
                break;
            default:
                return;
        }

        $this->operation = null;
        $this->target = null;
    }

    /**
     * @throws Exception
     * @throws NotSupportedException
     */
    public function beforeDelete()
    {
        $this->owner->validateNodeOperation($this->operation, $this->target);
        
        if ($this->owner->getIsNewRecord()) {
            throw new Exception('Can not delete a node when it is new record.');
        }

        if ($this->owner->isTreeRoot() && $this->operation !== self::OPR_DELETE_WITH_DESCENDANTS) {
            throw new NotSupportedException('Method "' . get_class($this->owner) . '::delete" is not supported for deleting root nodes.');
        }

        $this->owner->refresh();
    }

    /**
     * @return void
     */
    public function afterDelete()
    {
        $leftValue = $this->owner->getAttribute($this->leftAttribute);
        $rightValue = $this->owner->getAttribute($this->rightAttribute);

        if ($this->owner->isChildless() || $this->operation === self::OPR_DELETE_WITH_DESCENDANTS) {
            $this->shiftLeftRightAttribute($rightValue + 1, $leftValue - $rightValue - 1);
        } else {
            $condition = [
                'and',
                ['>=', $this->leftAttribute, $this->owner->getAttribute($this->leftAttribute)],
                ['<=', $this->rightAttribute, $this->owner->getAttribute($this->rightAttribute)]
            ];

            $this->applyTreeAttributeCondition($condition);
            $db = $this->owner->getDb();

            $this->owner->updateAll(
                [
                $this->leftAttribute => new Expression($db->quoteColumnName($this->leftAttribute) . sprintf('%+d', -1)),
                $this->rightAttribute => new Expression($db->quoteColumnName($this->rightAttribute) . sprintf('%+d', -1)),
                $this->depthAttribute => new Expression($db->quoteColumnName($this->depthAttribute) . sprintf('%+d', -1)),
                ], $condition
            );

            $this->shiftLeftRightAttribute($rightValue + 1, -2);
        }

        $this->operation = null;
        $this->target = null;
    }

    /**
     * Creates the root node if the active record is new or moves it
     * as the root node.
     * @param boolean $runValidation
     * @param array $attributes
     * @return boolean
     */
    public function makeRoot($runValidation = true, $attributes = null)
    {
        $this->operation = self::OPR_MAKE_ROOT;

        return $this->owner->save($runValidation, $attributes);
    }

    /**
     * Creates a node as the first child of the target node if the active
     * record is new or moves it as the first child of the target node.
     * @param ActiveRecord $target
     * @param boolean $runValidation
     * @param array $attributes
     * @return boolean
     */
    public function prependTo($target, $runValidation = true, $attributes = null)
    {
        $this->operation = self::OPR_PREPEND_TO;
        $this->target = $target;

        return $this->owner->save($runValidation, $attributes);
    }

    /**
     * Creates a node as the last child of the target node if the active
     * record is new or moves it as the last child of the target node.
     * @param ActiveRecord $target
     * @param boolean $runValidation
     * @param array $attributes
     * @return boolean
     */
    public function appendTo($target, $runValidation = true, $attributes = null)
    {
        $this->operation = self::OPR_APPEND_TO;
        $this->target = $target;

        return $this->owner->save($runValidation, $attributes);
    }

    /**
     * Creates a node as the prevSiblingious sibling of the target node if the active
     * record is new or moves it as the prevSiblingious sibling of the target node.
     * @param ActiveRecord $target
     * @param boolean $runValidation
     * @param array $attributes
     * @return boolean
     */
    public function insertBefore($target, $runValidation = true, $attributes = null)
    {
        $this->operation = self::OPR_INSERT_BEFORE;
        $this->target = $target;

        return $this->owner->save($runValidation, $attributes);
    }

    /**
     * Creates a node as the nextSibling sibling of the target node if the active
     * record is new or moves it as the nextSibling sibling of the target node.
     * @param ActiveRecord $target
     * @param boolean $runValidation
     * @param array $attributes
     * @return boolean
     */
    public function insertAfter($target, $runValidation = true, $attributes = null)
    {
        $this->operation = self::OPR_INSERT_AFTER;
        $this->target = $target;

        return $this->owner->save($runValidation, $attributes);
    }

    /**
     * Deletes a node and its descendants.
     * @return integer|false the number of rows deleted or false if
     * the deletion is unsuccessful for some reason.
     * @throws \Exception
     */
    public function deleteWithDescendants()
    {
        $this->operation = self::OPR_DELETE_WITH_DESCENDANTS;

        if (!$this->owner->isTransactional(ActiveRecord::OP_DELETE)) {
            return $this->deleteWithDescendantsInternal();
        }

        $transaction = $this->owner->getDb()->beginTransaction();

        try {
            $result = $this->deleteWithDescendantsInternal();

            if ($result === false) {
                $transaction->rollBack();
            } else {
                $transaction->commit();
            }

            return $result;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @return integer|false the number of rows deleted or false if
     * the deletion is unsuccessful for some reason.
     */
    protected function deleteWithDescendantsInternal()
    {
        if (!$this->owner->beforeDelete()) {
            return false;
        }

        $condition = [
            'and',
            ['>=', $this->leftAttribute, $this->owner->getAttribute($this->leftAttribute)],
            ['<=', $this->rightAttribute, $this->owner->getAttribute($this->rightAttribute)]
        ];

        $this->applyTreeAttributeCondition($condition);
        $result = $this->owner->deleteAll($condition);
        $this->owner->setOldAttributes(null);
        $this->owner->afterDelete();

        return $result;
    }

    /**
     * Gets the ancestors of the node.
     * @param integer|null $depth the depth
     * @return \yii\db\ActiveQuery
     */
    public function ancestors($depth = null, $sort = SORT_ASC)
    {
        $condition = [
            'and',
            ['<', $this->leftAttribute, $this->owner->getAttribute($this->leftAttribute)],
            ['>', $this->rightAttribute, $this->owner->getAttribute($this->rightAttribute)],
        ];

        if ($depth !== null) {
            $condition[] = ['>=', $this->depthAttribute, $this->owner->getAttribute($this->depthAttribute) - $depth];
        }

        $this->applyTreeAttributeCondition($condition);

        return $this->owner->find()->andWhere($condition)->addOrderBy([$this->leftAttribute => $sort]);
    }

    /**
     * Gets the descendants of the node.
     * @param integer|null $depth the depth
     * @return \yii\db\ActiveQuery
     */
    public function descendants($depth = null, $sort = SORT_ASC)
    {
        $condition = [
            'and',
            ['>', $this->leftAttribute, $this->owner->getAttribute($this->leftAttribute)],
            ['<', $this->rightAttribute, $this->owner->getAttribute($this->rightAttribute)],
        ];

        if ($depth !== null) {
            $condition[] = ['<=', $this->depthAttribute, $this->owner->getAttribute($this->depthAttribute) + $depth];
        }

        $this->applyTreeAttributeCondition($condition);

        return $this->owner->find()->andWhere($condition)->addOrderBy([$this->leftAttribute => $sort]);
    }

    /**
     * Gets the childlessDescendants of the node.
     * @return \yii\db\ActiveQuery
     */
    public function childlessDescendants()
    {
        $condition = [
            'and',
            ['>', $this->leftAttribute, $this->owner->getAttribute($this->leftAttribute)],
            ['<', $this->rightAttribute, $this->owner->getAttribute($this->rightAttribute)],
            [$this->rightAttribute => new Expression($this->owner->getDb()->quoteColumnName($this->leftAttribute) . '+ 1')],
        ];

        $this->applyTreeAttributeCondition($condition);

        return $this->owner->find()->andWhere($condition)->addOrderBy([$this->leftAttribute => SORT_ASC]);
    }

    /**
     * Gets the prevSiblingious sibling of the node.
     * @return \yii\db\ActiveQuery
     */
    public function prevSibling()
    {
        $condition = [$this->rightAttribute => $this->owner->getAttribute($this->leftAttribute) - 1];
        $this->applyTreeAttributeCondition($condition);

        return $this->owner->find()->andWhere($condition);
    }

    /**
     * Gets the nextSibling sibling of the node.
     * @return \yii\db\ActiveQuery
     */
    public function nextSibling()
    {
        $condition = [$this->leftAttribute => $this->owner->getAttribute($this->rightAttribute) + 1];
        $this->applyTreeAttributeCondition($condition);

        return $this->owner->find()->andWhere($condition);
    }

    /**
     * Determines whether the node is root.
     * @return boolean whether the node is root
     */
    public function isTreeRoot()
    {
        return $this->owner->getAttribute($this->leftAttribute) == 1;
    }

    /**
     * Determines whether the node is child of the parent node.
     * @param ActiveRecord $target the parent node
     * @return boolean whether the node is child of the parent node
     */
    public function isAncestorOf($target)
    {
        $result = $this->owner->getAttribute($this->leftAttribute) < $target->getAttribute($this->leftAttribute) && $this->owner->getAttribute($this->rightAttribute) > $target->getAttribute($this->rightAttribute);

        if ($result && $this->treeAttribute !== false) {
            $result = $this->owner->getAttribute($this->treeAttribute) === $target->getAttribute($this->treeAttribute);
        }

        return $result;
    }

    /**
     * Determines whether the node is child of the parent node.
     * @param ActiveRecord $target the parent node
     * @return boolean whether the node is child of the parent node
     */
    public function isDescendantOf($target)
    {
        $result = $this->owner->getAttribute($this->leftAttribute) > $target->getAttribute($this->leftAttribute) && $this->owner->getAttribute($this->rightAttribute) < $target->getAttribute($this->rightAttribute);

        if ($result && $this->treeAttribute !== false) {
            $result = $this->owner->getAttribute($this->treeAttribute) === $target->getAttribute($this->treeAttribute);
        }

        return $result;
    }

    /**
     * Determines whether the node is sibling to given node.
     * @param ActiveRecord $target the parent node
     * @return boolean whether the node is child of the parent node
     */
    public function isSiblingOf($target)
    {
        return $this->isNextSiblingOf($target) || $this->isPrevSiblingOf($target);
    }

    /**
     * Determines whether the node is next sibling to given node.
     * @param ActiveRecord $target the parent node
     * @return boolean whether the node is child of the parent node
     */
    public function isNextSiblingOf($target)
    {
        $result = $this->owner->getAttribute($this->leftAttribute) > $target->getAttribute($this->leftAttribute) && $this->owner->getAttribute($this->rightAttribute) > $target->getAttribute($this->rightAttribute);

        if ($result && $this->treeAttribute !== false) {
            $result = $this->owner->getAttribute($this->treeAttribute) === $target->getAttribute($this->treeAttribute);
        }

        if ($result) {
            $result = $this->owner->getAttribute($this->depthAttribute) === $target->getAttribute($this->depthAttribute);
        }

        return $result;
    }

    /**
     * Determines whether the node is previous sibling to given node.
     * @param ActiveRecord $target the parent node
     * @return boolean whether the node is child of the parent node
     */
    public function isPrevSiblingOf($target)
    {
        $result = $this->owner->getAttribute($this->leftAttribute) < $target->getAttribute($this->leftAttribute) && $this->owner->getAttribute($this->rightAttribute) < $target->getAttribute($this->rightAttribute);

        if ($result && $this->treeAttribute !== false) {
            $result = $this->owner->getAttribute($this->treeAttribute) === $target->getAttribute($this->treeAttribute);
        }

        if ($result) {
            $result = $this->owner->getAttribute($this->depthAttribute) === $target->getAttribute($this->depthAttribute);
        }

        return $result;
    }

    /**
     * Determines whether the node is leaf.
     * @return boolean whether the node is leaf
     */
    public function isChildless()
    {
        return $this->owner->getAttribute($this->rightAttribute) - $this->owner->getAttribute($this->leftAttribute) === 1;
    }

    /**
     * @return void
     */
    protected function moveNodeAsRoot()
    {
        $db = $this->owner->getDb();
        $leftValue = $this->owner->getAttribute($this->leftAttribute);
        $rightValue = $this->owner->getAttribute($this->rightAttribute);
        $depthValue = $this->owner->getAttribute($this->depthAttribute);
        $treeValue = $this->owner->getAttribute($this->treeAttribute);
        $leftAttribute = $db->quoteColumnName($this->leftAttribute);
        $rightAttribute = $db->quoteColumnName($this->rightAttribute);
        $depthAttribute = $db->quoteColumnName($this->depthAttribute);

        $this->owner->updateAll(
            [
            $this->leftAttribute => new Expression($leftAttribute . sprintf('%+d', 1 - $leftValue)),
            $this->rightAttribute => new Expression($rightAttribute . sprintf('%+d', 1 - $leftValue)),
            $this->depthAttribute => new Expression($depthAttribute . sprintf('%+d', -$depthValue)),
            $this->treeAttribute => $this->owner->getPrimaryKey(),
            ], [
            'and',
            ['>=', $this->leftAttribute, $leftValue],
            ['<=', $this->rightAttribute, $rightValue],
            [$this->treeAttribute => $treeValue]
            ]
        );

        $this->shiftLeftRightAttribute($rightValue + 1, $leftValue - $rightValue - 1);
    }

    /**
     * @param integer $value
     * @param integer $depth
     */
    protected function moveNode($value, $depth)
    {
        $db = $this->owner->getDb();
        $leftValue = $this->owner->getAttribute($this->leftAttribute);
        $rightValue = $this->owner->getAttribute($this->rightAttribute);
        $depthValue = $this->owner->getAttribute($this->depthAttribute);
        $depthAttribute = $db->quoteColumnName($this->depthAttribute);
        $depth = $this->target->getAttribute($this->depthAttribute) - $depthValue + $depth;

        if ($this->treeAttribute === false || $this->owner->getAttribute($this->treeAttribute) === $this->target->getAttribute($this->treeAttribute)) {
            $delta = $rightValue - $leftValue + 1;
            $this->shiftLeftRightAttribute($value, $delta);

            if ($leftValue >= $value) {
                $leftValue += $delta;
                $rightValue += $delta;
            }

            $condition = ['and', ['>=', $this->leftAttribute, $leftValue], ['<=', $this->rightAttribute, $rightValue]];
            $this->applyTreeAttributeCondition($condition);

            $this->owner->updateAll(
                [$this->depthAttribute => new Expression($depthAttribute . sprintf('%+d', $depth))], $condition
            );

            foreach ([$this->leftAttribute, $this->rightAttribute] as $attribute) {
                $condition = ['and', ['>=', $attribute, $leftValue], ['<=', $attribute, $rightValue]];
                $this->applyTreeAttributeCondition($condition);

                $this->owner->updateAll(
                    [$attribute => new Expression($db->quoteColumnName($attribute) . sprintf('%+d', $value - $leftValue))], $condition
                );
            }

            $this->shiftLeftRightAttribute($rightValue + 1, -$delta);
        } else {
            $leftAttribute = $db->quoteColumnName($this->leftAttribute);
            $rightAttribute = $db->quoteColumnName($this->rightAttribute);
            $targetRootValue = $this->target->getAttribute($this->treeAttribute);

            foreach ([$this->leftAttribute, $this->rightAttribute] as $attribute) {
                $this->owner->updateAll(
                    [$attribute => new Expression($db->quoteColumnName($attribute) . sprintf('%+d', $rightValue - $leftValue + 1))], ['and', ['>=', $attribute, $value], [$this->treeAttribute => $targetRootValue]]
                );
            }

            $delta = $value - $leftValue;

            $this->owner->updateAll(
                [
                $this->leftAttribute => new Expression($leftAttribute . sprintf('%+d', $delta)),
                $this->rightAttribute => new Expression($rightAttribute . sprintf('%+d', $delta)),
                $this->depthAttribute => new Expression($depthAttribute . sprintf('%+d', $depth)),
                $this->treeAttribute => $targetRootValue,
                ], [
                'and',
                ['>=', $this->leftAttribute, $leftValue],
                ['<=', $this->rightAttribute, $rightValue],
                [$this->treeAttribute => $this->owner->getAttribute($this->treeAttribute)],
                ]
            );

            $this->shiftLeftRightAttribute($rightValue + 1, $leftValue - $rightValue - 1);
        }
    }

    /**
     * @param integer $value
     * @param integer $delta
     */
    protected function shiftLeftRightAttribute($value, $delta)
    {
        $db = $this->owner->getDb();

        foreach ([$this->leftAttribute, $this->rightAttribute] as $attribute) {
            $condition = ['>=', $attribute, $value];
            $this->applyTreeAttributeCondition($condition);

            $this->owner->updateAll(
                [$attribute => new Expression($db->quoteColumnName($attribute) . sprintf('%+d', $delta))], $condition
            );
        }
    }

    /**
     * @param array $condition
     */
    protected function applyTreeAttributeCondition(&$condition)
    {
        if ($this->treeAttribute !== false) {
            $condition = [
                'and',
                $condition,
                [$this->treeAttribute => $this->owner->getAttribute($this->treeAttribute)]
            ];
        }
    }

}
