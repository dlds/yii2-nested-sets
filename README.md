# Yii 2 Nested Sets Behavior

A modern nested sets behavior for the Yii2 framework utilizing the Modified Preorder Tree Traversal algorithm.

## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```bash
$ composer require dlds/yii2-nested-sets
```

or add

```
"dlds/yii2-nested-sets": "~1.3.0"
```

to the `require` section of your `composer.json` file.

## Configuring

Configure model as follows

```php
use dlds\nestedsets\NestedSetsBehavior;

class Menu extends \yii\db\ActiveRecord
{
    public function behaviors() {
        return [
            'tree' => [
                'class' => NestedSetsBehavior::className(),
                // 'treeAttribute' => 'tree',
                // 'leftAttribute' => 'lft',
                // 'rightAttribute' => 'rgt',
                // 'depthAttribute' => 'depth',
            ],
        ];
    }

    public function transactions()
    {
        return [
            self::SCENARIO_DEFAULT => self::OP_ALL,
        ];
    }

    public static function find()
    {
        return new MenuQuery(get_called_class());
    }
}
```

To use multiple tree mode uncomment `treeAttribute` array key inside `behaviors()` method.

Configure query class as follows

```php
use dlds\nestedsets\NestedSetsQueryBehavior;

class MenuQuery extends \yii\db\ActiveQuery
{
    public function behaviors() {
        return [
            NestedSetsQueryBehavior::className(),
        ];
    }
}
```

## Usage

### Making a root node

To make a root node

```php
$countries = new Menu(['name' => 'Countries']);
$countries->makeRoot();
```

The tree will look like this

```
- Countries
```

### Prepending a node as the first child of another node

To prepend a node as the first child of another node

```php
$russia = new Menu(['name' => 'Russia']);
$russia->prependTo($countries);
```

The tree will look like this

```
- Countries
    - Russia
```

### Appending a node as the last child of another node

To append a node as the last child of another node

```php
$australia = new Menu(['name' => 'Australia']);
$australia->appendTo($countries);
```

The tree will look like this

```
- Countries
    - Russia
    - Australia
```

### Inserting a node before another node

To insert a node before another node

```php
$newZeeland = new Menu(['name' => 'New Zeeland']);
$newZeeland->insertBefore($australia);
```

The tree will look like this

```
- Countries
    - Russia
    - New Zeeland
    - Australia
```

### Inserting a node after another node

To insert a node after another node

```php
$unitedStates = new Menu(['name' => 'United States']);
$unitedStates->insertAfter($australia);
```

The tree will look like this
```
- Countries
    - Russia
    - New Zeeland
    - Australia
    - United States
```

### Getting the root nodes

To get all the root nodes

```php
$roots = Menu::find()->isTreeRoot()->all();
```

### Getting the leaves nodes

To get all the leaves nodes

```php
$leaves = Menu::find()->isTreeLeaf()->all();
```

To get all the leaves of a node

```php
$countries = Menu::findOne(['name' => 'Countries']);
$leaves = $countries->isTreeLeaf()->all();
```

### Getting children of a node

To get all the children of a node

```php
$countries = Menu::findOne(['name' => 'Countries']);
$children = $countries->descendants()->all();
```

To get the first level children of a node

```php
$countries = Menu::findOne(['name' => 'Countries']);
$children = $countries->descendants(1)->all();
```

### Getting parents of a node

To get all the parents of a node

```php
$countries = Menu::findOne(['name' => 'Countries']);
$parents = $countries->ancestors()->all();
```

To get the first parent of a node

```php
$countries = Menu::findOne(['name' => 'Countries']);
$parent = $countries->ancestors(1)->one();
```