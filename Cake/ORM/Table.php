<?php
/**
 * PHP Version 5.4
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         CakePHP(tm) v 3.0.0
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
namespace Cake\ORM;

use Cake\Core\App;
use Cake\Database\Schema\Table as Schema;
use Cake\Database\Type;
use Cake\Event\Event;
use Cake\Event\EventListener;
use Cake\Event\EventManager;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\BelongsToMany;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Association\HasOne;
use Cake\ORM\BehaviorRegistry;
use Cake\ORM\Entity;
use Cake\ORM\Error\MissingEntityException;
use Cake\Utility\Inflector;
use Cake\Validation\Validator;

/**
 * Represents a single database table.
 *
 * Exposes methods for retrieving data out of it, and manages the associations
 * this table has to other tables. Multiple instances of this class can be created
 * for the same database table with different aliases, this allows you to address
 * your database structure in a richer and more expressive way.
 *
 * ### Retrieving data
 *
 * The primary way to retrieve data is using Table::find(). See that method
 * for more information.
 *
 * ### Dynamic finders
 *
 * In addition to the standard find($type) finder methods, CakePHP provides dynamic
 * finder methods. These methods allow you to easily set basic conditions up. For example
 * to filter users by username you would call
 *
 * {{{
 * $query = $users->findByUsername('mark');
 * }}}
 *
 * You can also combine conditions on multiple fields using either `Or` or `And`:
 *
 * {{{
 * $query = $users->findByUsernameOrEmail('mark', 'mark@example.org');
 * }}}
 *
 * ### Bulk updates/deletes
 *
 * You can use Table::updateAll() and Table::deleteAll() to do bulk updates/deletes.
 * You should be aware that events will *not* be fired for bulk updates/deletes.
 *
 * ### Callbacks/events
 *
 * Table objects provide a few callbacks/events you can hook into to augment/replace
 * find operations. Each event uses the standard event subsystem in CakePHP
 *
 * - `beforeFind($event, $query, $options)` - Fired before each find operation. By stopping
 *   the event and supplying a return value you can bypass the find operation entirely. Any
 *   changes done to the $query instance will be retained for the rest of the find.
 * - `beforeValidate($event, $entity, $options, $validator)` - Fired before an entity is validated.
 *   By stopping this event, you can abort the validate + save operations.
 * - `afterValidate($event, $entity, $options, $validator)` - Fired after an entity is validated.
 * - `beforeSave($event, $entity, $options)` - Fired before each entity is saved. Stopping this
 *   event will abort the save operation. When the event is stopped the result of the event will
 *   be returned.
 * - `afterSave($event, $entity, $options)` - Fired after an entity is saved.
 * - `beforeDelete($event, $entity, $options)` - Fired before an entity is deleted.
 *   By stopping this event you will abort the delete operation.
 * - `afterDelete($event, $entity, $options)` - Fired after an entity has been deleted.
 *
 * @see Cake\Event\EventManager for reference on the events system.
 */
class Table implements EventListener {

/**
 * Name of the table as it can be found in the database
 *
 * @var string
 */
	protected $_table;

/**
 * Human name giving to this particular instance. Multiple objects representing
 * the same database table can exist by using different aliases.
 *
 * @var string
 */
	protected $_alias;

/**
 * Connection instance
 *
 * @var \Cake\Database\Connection
 */
	protected $_connection;

/**
 * The schema object containing a description of this table fields
 *
 * @var \Cake\Database\Schema\Table
 */
	protected $_schema;

/**
 * The name of the field that represents the primary key in the table
 *
 * @var array
 */
	protected $_primaryKey;

/**
 * The name of the field that represents a human readable representation of a row
 *
 * @var string
 */
	protected $_displayField;

/**
 * The list of associations for this table. Indexed by association name,
 * values are Association object instances.
 *
 * @var array
 */
	protected $_associations = [];

/**
 * EventManager for this table.
 *
 * All model/behavior callbacks will be dispatched on this manager.
 *
 * @var Cake\Event\EventManager
 */
	protected $_eventManager;

/**
 * BehaviorRegistry for this table
 *
 * @var Cake\ORM\BehaviorRegistry
 */
	protected $_behaviors;

/**
 * The name of the class that represent a single row for this table
 *
 * @var string
 */
	protected $_entityClass;

/**
 * A list of validation objects indexed by name
 *
 * @var array
 */
	protected $_validators = [];

/**
 * Initializes a new instance
 *
 * The $config array understands the following keys:
 *
 * - table: Name of the database table to represent
 * - alias: Alias to be assigned to this table (default to table name)
 * - connection: The connection instance to use
 * - entityClass: The fully namespaced class name of the entity class that will
 *   represent rows in this table.
 * - schema: A \Cake\Database\Schema\Table object or an array that can be
 *   passed to it.
 * - eventManager: An instance of an event manager to use for internal events
 * - behaviors: A BehaviorRegistry. Generally not used outside of tests.
 *
 * @param array config Lsit of options for this table
 * @return void
 */
	public function __construct(array $config = []) {
		if (!empty($config['table'])) {
			$this->table($config['table']);
		}
		if (!empty($config['alias'])) {
			$this->alias($config['alias']);
		}
		if (!empty($config['connection'])) {
			$this->connection($config['connection']);
		}
		if (!empty($config['schema'])) {
			$this->schema($config['schema']);
		}
		if (!empty($config['entityClass'])) {
			$this->entityClass($config['entityClass']);
		}
		$eventManager = $behaviors = null;
		if (!empty($config['eventManager'])) {
			$eventManager = $config['eventManager'];
		}
		if (!empty($config['behaviors'])) {
			$behaviors = $config['behaviors'];
		}
		$this->_eventManager = $eventManager ?: new EventManager();
		$this->_behaviors = $behaviors ?: new BehaviorRegistry($this);
		$this->initialize($config);
		$this->_eventManager->attach($this);
	}

/**
 * Get the default connection name.
 *
 * This method is used to get the fallback connection name if an
 * instance is created through the TableRegistry without a connection.
 *
 * @return string
 * @see Cake\ORM\TableRegistry::get()
 */
	public static function defaultConnectionName() {
		return 'default';
	}

/**
 * Initialize a table instance. Called after the constructor.
 *
 * You can use this method to define associations, attach behaviors
 * define validation and do any other initialization logic you need.
 *
 * {{{
 *	public function initialize(array $config) {
 *		$this->belongsTo('Users');
 *		$this->belongsToMany('Tagging.Tags');
 *		$this->primaryKey('something_else');
 *	}
 * }}}
 *
 * @param array $config Configuration options passed to the constructor
 * @return void
 */
	public function initialize(array $config) {
	}

/**
 * Returns the database table name or sets a new one
 *
 * @param string $table the new table name
 * @return string
 */
	public function table($table = null) {
		if ($table !== null) {
			$this->_table = $table;
		}
		if ($this->_table === null) {
			$table = namespaceSplit(get_class($this));
			$table = substr(end($table), 0, -5);
			if (empty($table)) {
				$table = $this->alias();
			}
			$this->_table = Inflector::underscore($table);
		}
		return $this->_table;
	}

/**
 * Returns the table alias or sets a new one
 *
 * @param string $table the new table alias
 * @return string
 */
	public function alias($alias = null) {
		if ($alias !== null) {
			$this->_alias = $alias;
		}
		if ($this->_alias === null) {
			$alias = namespaceSplit(get_class($this));
			$alias = substr(end($alias), 0, -5) ?: $this->_table;
			$this->_alias = $alias;
		}
		return $this->_alias;
	}

/**
 * Returns the connection instance or sets a new one
 *
 * @param \Cake\Database\Connection $conn the new connection instance
 * @return \Cake\Database\Connection
 */
	public function connection($conn = null) {
		if ($conn === null) {
			return $this->_connection;
		}
		return $this->_connection = $conn;
	}

/**
 * Get the event manager for this Table.
 *
 * @return Cake\Event\EventManager
 */
	public function getEventManager() {
		return $this->_eventManager;
	}

/**
 * Returns the schema table object describing this table's properties.
 *
 * If an \Cake\Database\Schema\Table is passed, it will be used for this table
 * instead of the default one.
 *
 * If an array is passed, a new \Cake\Database\Schema\Table will be constructed
 * out of it and used as the schema for this table.
 *
 * @param array|\Cake\Database\Schema\Table new schema to be used for this table
 * @return \Cake\Database\Schema\Table
 */
	public function schema($schema = null) {
		if ($schema === null) {
			if ($this->_schema === null) {
				$this->_schema = $this->connection()
					->schemaCollection()
					->describe($this->table());
			}
			return $this->_schema;
		}

		if (is_array($schema)) {
			$constraints = [];

			if (isset($schema['_constraints'])) {
				$constraints = $schema['_constraints'];
				unset($schema['_constraints']);
			}

			$schema = new Schema($this->table(), $schema);

			foreach ($constraints as $name => $value) {
				$schema->addConstraint($name, $value);
			}
		}

		return $this->_schema = $schema;
	}

/**
 * Test to see if a Table has a specific field/column.
 *
 * Delegates to the schema object and checks for column presence
 * using the Schema\Table instance.
 *
 * @param string $field The field to check for.
 * @return boolean True if the field exists, false if it does not.
 */
	public function hasField($field) {
		$schema = $this->schema();
		return $schema->column($field) !== null;
	}

/**
 * Returns the primary key field name or sets a new one
 *
 * @param string $key sets a new name to be used as primary key
 * @return string
 */
	public function primaryKey($key = null) {
		if ($key !== null) {
			$this->_primaryKey = $key;
		}
		if ($this->_primaryKey === null) {
			$key = current((array)$this->schema()->primaryKey());
			$this->_primaryKey = $key ?: null;
		}
		return $this->_primaryKey;
	}

/**
 * Returns the display field or sets a new one
 *
 * @param string $key sets a new name to be used as display field
 * @return string
 */
	public function displayField($key = null) {
		if ($key !== null) {
			$this->_displayField = $key;
		}
		if ($this->_displayField === null) {
			$schema = $this->schema();
			$this->_displayField = $this->primaryKey();
			if ($schema->column('title')) {
				$this->_displayField = 'title';
			}
			if ($schema->column('name')) {
				$this->_displayField = 'name';
			}
		}
		return $this->_displayField;
	}

/**
 * Returns the class used to hydrate rows for this table or sets
 * a new one
 *
 * @param string $name the name of the class to use
 * @throws \Cake\ORM\Error\MissingEntityException when the entity class cannot be found
 * @return string
 */
	public function entityClass($name = null) {
		if ($name === null && !$this->_entityClass) {
			$default = '\Cake\ORM\Entity';
			$self = get_called_class();
			$parts = explode('\\', $self);

			if ($self === __CLASS__ || count($parts) < 3) {
				return $this->_entityClass = $default;
			}

			$alias = Inflector::singularize(substr(array_pop($parts), 0, -5));
			$name = implode('\\', array_slice($parts, 0, -1)) . '\Entity\\' . $alias;
			if (!class_exists($name)) {
				return $this->_entityClass = $default;
			}
		}

		if ($name !== null) {
			$class = App::classname($name, 'Model\Entity');
			$this->_entityClass = $class;
		}

		if (!$this->_entityClass) {
			throw new MissingEntityException([$name]);
		}

		return $this->_entityClass;
	}

/**
 * Add a behavior.
 *
 * Adds a behavior to this table's behavior collection. Behaviors
 * provide an easy way to create horizontally re-usable features
 * that can provide trait like functionality, and allow for events
 * to be listened to.
 *
 * Example:
 *
 * Load a behavior, with some settings.
 *
 * {{{
 * $this->addBehavior('Tree', ['parent' => 'parentId']);
 * }}}
 *
 * Behaviors are generally loaded during Table::initialize().
 *
 * @param string $name The name of the behavior. Can be a short class reference.
 * @param array $options The options for the behavior to use.
 * @return void
 * @see Cake\ORM\Behavior
 */
	public function addBehavior($name, $options = []) {
		$this->_behaviors->load($name, $options);
	}

/**
 * Get the list of Behaviors loaded.
 *
 * This method will return the *aliases* of the behaviors attached
 * to this instance.
 *
 * @return array
 */
	public function behaviors() {
		return $this->_behaviors->loaded();
	}

/**
 * Check if a behavior with the given alias has been loaded.
 *
 * @param string $name The behavior alias to check.
 * @return array
 */
	public function hasBehavior($name) {
		return $this->_behaviors->loaded($name);
	}

/**
 * Returns a association objected configured for the specified alias if any
 *
 * @param string $name the alias used for the association
 * @return Cake\ORM\Association
 */
	public function association($name) {
		$name = strtolower($name);
		if (isset($this->_associations[$name])) {
			return $this->_associations[$name];
		}
		return null;
	}

/**
 * Creates a new BelongsTo association between this table and a target
 * table. A "belongs to" association is a N-1 relationship where this table
 * is the N side, and where there is a single associated record in the target
 * table for each one in this table.
 *
 * Target table can be inferred by its name, which is provided in the
 * first argument, or you can either pass the to be instantiated or
 * an instance of it directly.
 *
 * The options array accept the following keys:
 *
 * - className: The class name of the target table object
 * - targetTable: An instance of a table object to be used as the target table
 * - foreignKey: The name of the field to use as foreign key, if false none
 *   will be used
 * - conditions: array with a list of conditions to filter the join with
 * - joinType: The type of join to be used (e.g. INNER)
 *
 * This method will return the association object that was built.
 *
 * @param string $associated the alias for the target table. This is used to
 * uniquely identify the association
 * @param array $options list of options to configure the association definition
 * @return Cake\ORM\Association\BelongsTo
 */
	public function belongsTo($associated, array $options = []) {
		$options += ['sourceTable' => $this];
		$association = new BelongsTo($associated, $options);
		return $this->_associations[strtolower($association->name())] = $association;
	}

/**
 * Creates a new HasOne association between this table and a target
 * table. A "has one" association is a 1-1 relationship.
 *
 * Target table can be inferred by its name, which is provided in the
 * first argument, or you can either pass the class name to be instantiated or
 * an instance of it directly.
 *
 * The options array accept the following keys:
 *
 * - className: The class name of the target table object
 * - targetTable: An instance of a table object to be used as the target table
 * - foreignKey: The name of the field to use as foreign key, if false none
 *   will be used
 * - dependent: Set to true if you want CakePHP to cascade deletes to the
 *   associated table when an entity is removed on this table. Set to false
 *   if you don't want CakePHP to remove associated data, for when you are using
 *   database constraints.
 * - cascadeCallbacks: Set to true if you want CakePHP to fire callbacks on
 *   cascaded deletes. If false the ORM will use deleteAll() to remove data.
 *   When true records will be loaded and then deleted.
 * - conditions: array with a list of conditions to filter the join with
 * - joinType: The type of join to be used (e.g. LEFT)
 *
 * This method will return the association object that was built.
 *
 * @param string $associated the alias for the target table. This is used to
 * uniquely identify the association
 * @param array $options list of options to configure the association definition
 * @return Cake\ORM\Association\HasOne
 */
	public function hasOne($associated, array $options = []) {
		$options += ['sourceTable' => $this];
		$association = new HasOne($associated, $options);
		return $this->_associations[strtolower($association->name())] = $association;
	}

/**
 * Creates a new HasMany association between this table and a target
 * table. A "has many" association is a 1-N relationship.
 *
 * Target table can be inferred by its name, which is provided in the
 * first argument, or you can either pass the class name to be instantiated or
 * an instance of it directly.
 *
 * The options array accept the following keys:
 *
 * - className: The class name of the target table object
 * - targetTable: An instance of a table object to be used as the target table
 * - foreignKey: The name of the field to use as foreign key, if false none
 *   will be used
 * - dependent: Set to true if you want CakePHP to cascade deletes to the
 *   associated table when an entity is removed on this table. Set to false
 *   if you don't want CakePHP to remove associated data, for when you are using
 *   database constraints.
 * - cascadeCallbacks: Set to true if you want CakePHP to fire callbacks on
 *   cascaded deletes. If false the ORM will use deleteAll() to remove data.
 *   When true records will be loaded and then deleted.
 * - conditions: array with a list of conditions to filter the join with
 * - sort: The order in which results for this association should be returned
 * - strategy: The strategy to be used for selecting results Either 'select'
 *   or 'subquery'. If subquery is selected the query used to return results
 *   in the source table will be used as conditions for getting rows in the
 *   target table.
 *
 * This method will return the association object that was built.
 *
 * @param string $associated the alias for the target table. This is used to
 * uniquely identify the association
 * @param array $options list of options to configure the association definition
 * @return Cake\ORM\Association\HasMany
 */
	public function hasMany($associated, array $options = []) {
		$options += ['sourceTable' => $this];
		$association = new HasMany($associated, $options);
		return $this->_associations[strtolower($association->name())] = $association;
	}

/**
 * Creates a new BelongsToMany association between this table and a target
 * table. A "belongs to many" association is a M-N relationship.
 *
 * Target table can be inferred by its name, which is provided in the
 * first argument, or you can either pass the class name to be instantiated or
 * an instance of it directly.
 *
 * The options array accept the following keys:
 *
 * - className: The class name of the target table object
 * - targetTable: An instance of a table object to be used as the target table
 * - foreignKey: The name of the field to use as foreign key
 * - joinTable: The name of the table representing the link between the two
 * - through: If you choose to use an already instantiated link table, set this
 *   key to a configured Table instance containing associations to both the source
 *   and target tables in this association.
 * - cascadeCallbacks: Set to true if you want CakePHP to fire callbacks on
 *   cascaded deletes. If false the ORM will use deleteAll() to remove data.
 *   When true join/junction table records will be loaded and then deleted.
 * - conditions: array with a list of conditions to filter the join with
 * - sort: The order in which results for this association should be returned
 * - strategy: The strategy to be used for selecting results Either 'select'
 *   or 'subquery'. If subquery is selected the query used to return results
 *   in the source table will be used as conditions for getting rows in the
 *   target table.
 *
 * This method will return the association object that was built.
 *
 * @param string $associated the alias for the target table. This is used to
 * uniquely identify the association
 * @param array $options list of options to configure the association definition
 * @return Cake\ORM\Association\BelongsToMany
 */
	public function belongsToMany($associated, array $options = []) {
		$options += ['sourceTable' => $this];
		$association = new BelongsToMany($associated, $options);
		return $this->_associations[strtolower($association->name())] = $association;
	}

/**
 * Creates a new Query for this table and applies some defaults based on the
 * type of search that was selected.
 *
 * ### Model.beforeFind event
 *
 * Each find() will trigger a `Model.beforeFind` event for all attached
 * listeners. Any listener can set a valid result set using $query
 *
 * @param string $type the type of query to perform
 * @param array $options
 * @return \Cake\ORM\Query
 */
	public function find($type, $options = []) {
		$query = $this->_buildQuery();
		$query->select()->applyOptions($options);
		return $this->callFinder($type, $query, $options);
	}

/**
 * Returns the query as passed
 *
 * @param \Cake\ORM\Query $query
 * @param array $options
 * @return \Cake\ORM\Query
 */
	public function findAll(Query $query, array $options = []) {
		return $query;
	}

/**
 * Sets up a query object so results appear as an indexed array, useful for any
 * place where you would want a list such as for populating input select boxes.
 *
 * When calling this finder, the fields passed are used to determine what should
 * be used as the array key, value and optionally what to group the results by.
 * By default the primary key for the model is used for the key, and the display
 * field as value.
 *
 * The results of this finder will be in the following form:
 *
 *	[
 *		1 => 'value for id 1',
 *		2 => 'value for id 2',
 *		4 => 'value for id 4'
 *	]
 *
 * @param \Cake\ORM\Query $query
 * @param array $options
 * @return \Cake\ORM\Query
 */
	public function findList(Query $query, array $options = []) {
		$options += [
			'idField' => $this->primaryKey(),
			'valueField' => $this->displayField(),
			'groupField' => false
		];
		$mapper = function($key, $row, $mapReduce) use ($options) {
			$rowKey = $options['idField'];
			$rowVal = $options['valueField'];
			if (!($options['groupField'])) {
				$mapReduce->emit($row[$rowVal], $row[$rowKey]);
				return;
			}

			$key = $row[$options['groupField']];
			$mapReduce->emitIntermediate($key, [$row[$rowKey] => $row[$rowVal]]);
		};

		$reducer = function($key, $values, $mapReduce) {
			$result = [];
			foreach ($values as $value) {
				$result += $value;
			}
			$mapReduce->emit($result, $key);
		};

		return $query->mapReduce($mapper, $reducer);
	}

/**
 * Results for this finder will be a nested array, and is appropriate if you want
 * to use the parent_id field of your model data to build nested results.
 *
 * Values belonging to a parent row based on their parent_id value will be
 * recursively nested inside the parent row values using the `children` property
 *
 * @param \Cake\ORM\Query $query
 * @param array $options
 * @return \Cake\ORM\Query
 */
	public function findThreaded(Query $query, array $options = []) {
		$parents = [];
		$hydrate = $query->hydrate();
		$mapper = function($key, $row, $mapReduce) use (&$parents) {
			$row['children'] = [];
			$parents[$row['id']] =& $row;
			$mapReduce->emitIntermediate($row['parent_id'], $row['id']);
		};

		$reducer = function($key, $values, $mapReduce) use (&$parents, $hydrate) {
			if (empty($key) || !isset($parents[$key])) {
				foreach ($values as $id) {
					$parents[$id] = $hydrate ? $parents[$id] : new \ArrayObject($parents[$id]);
					$mapReduce->emit($parents[$id]);
				}
				return;
			}

			foreach ($values as $id) {
				$parents[$key]['children'][] =& $parents[$id];
			}
		};

		$query->mapReduce($mapper, $reducer);
		if (!$hydrate) {
			$query->mapReduce(function($key, $row, $mapReduce) {
				$mapReduce->emit($row->getArrayCopy());
			});
		}

		return $query;
	}

/**
 * Creates a new Query instance for this table
 *
 * @return \Cake\ORM\Query
 */
	protected function _buildQuery() {
		return new Query($this->connection(), $this);
	}

/**
 * Update all matching rows.
 *
 * Sets the $fields to the provided values based on $conditions.
 * This method will *not* trigger beforeSave/afterSave events. If you need those
 * first load a collection of records and update them.
 *
 * @param array $fields A hash of field => new value.
 * @param array $conditions An array of conditions, similar to those used with find()
 * @return boolean Success Returns true if one or more rows are effected.
 */
	public function updateAll($fields, $conditions) {
		$query = $this->_buildQuery();
		$query->update($this->table())
			->set($fields)
			->where($conditions);
		$statement = $query->executeStatement();
		$success = $statement->rowCount() > 0;
		$statement->closeCursor();
		return $success;
	}

/**
 * Returns the validation rules tagged with $name. It is possible to have
 * multiple different named validation sets, this is useful when you need
 * to use varying rules when saving from different routines in your system.
 *
 * There are two different ways of creating and naming validation sets: by
 * creating a new method inside your own Table subclass, or by building
 * the validator object yourself and storing it using this method.
 *
 * For example, if you wish to create a validation set called 'forSubscription',
 * you will need to create a method in your Table subclass as follows:
 *
 * {{{
 * public function validationForSubscription($validator) {
 *	return $validator
 *	->add('email', 'valid-email', ['rule' => 'email'])
 *	->add('password', 'valid', ['rule' => 'notEmpty']);
 *	->validatePresence('username')
 * }
 * }}}
 *
 * Otherwise, you can build the object by yourself and store it in the Table object:
 *
 * {{{
 * $validator = new \Cake\Validation\Validator($table);
 * $validator
 *	->add('email', 'valid-email', ['rule' => 'email'])
 *	->add('password', 'valid', ['rule' => 'notEmpty'])
 *	->allowEmpty('bio');
 * $table->validator('forSubscription', $validator);
 * }}}
 *
 * You can implement the method in `validationDefault` in your Table subclass
 * should you wish to have a validation set that applies in cases where no other
 * set is specified.
 *
 * @param string $name the name of the validation set to return
 * @param \Cake\Validation\Validator $validator
 * @return \Cake\Validation\Validator
 */
	public function validator($name = 'default', Validator $instance = null) {
		if ($instance === null && isset($this->_validators[$name])) {
			return $this->_validators[$name];
		}

		if ($instance !== null) {
			return $this->_validators[$name] = $instance;
		}

		$validator = new Validator;
		$validator = $this->{'validation' . ucfirst($name)}($validator);
		return $this->_validators[$name] = $validator;
	}

/**
 * Returns the default validator object. Subclasses can override this function
 * to add a default validation set to the validator object.
 *
 * @param \Cake\Validation\Validator $validator The validator that can be modified to
 * add some rules to it.
 * @return \Cake\Validation\Validator
 */
	public function validationDefault(Validator $validator) {
		return $validator;
	}

/**
 * Delete all matching rows.
 *
 * Deletes all rows matching the provided conditions.
 *
 * This method will *not* trigger beforeDelete/afterDelete events. If you
 * need those first load a collection of records and delete them.
 *
 * This method will *not* execute on associations `cascade` attribute. You should
 * use database foreign keys + ON CASCADE rules if you need cascading deletes combined
 * with this method.
 *
 * @param array $conditions An array of conditions, similar to those used with find()
 * @return boolean Success Returns true if one or more rows are effected.
 * @see Cake\ORM\Table::delete()
 */
	public function deleteAll($conditions) {
		$query = $this->_buildQuery();
		$query->delete($this->table())
			->where($conditions);
		$statement = $query->executeStatement();
		$success = $statement->rowCount() > 0;
		$statement->closeCursor();
		return $success;
	}

/**
 * Returns true if there is any row in this table matching the specified
 * conditions.
 *
 * @param array $conditions list of conditions to pass to the query
 * @return boolean
 */
	public function exists(array $conditions) {
		return (bool)count($this->find('all')
			->select(['existing' => 1])
			->where($conditions)
			->limit(1)
			->hydrate(false)
			->toArray());
	}

/**
 * Persists an entity based on the fields that are marked as dirty and
 * returns the same entity after a successful save or false in case
 * of any error.
 *
 * ### Options
 *
 * The options array can receive the following keys:
 *
 * - atomic: Whether to execute the save and callbacks inside a database
 * transaction (default: true)
 * - validate: Whether or not validate the entity before saving, if validation
 * fails, it will abort the save operation. If this key is set to a string value,
 * the validator object registered in this table under the provided name will be
 * used instead of the default one. (default:true)
 * - associated: If true it will save all associated entities as they are found
 * in the passed `$entity` whenever the property defined for the association
 * is marked as dirty. Associated records are saved recursively unless told
 * otherwise. If an array, it will be interpreted as the list of associations
 * to be saved. It is possible to provide different options for saving on associated
 * table objects using this key by making the custom options the array value.
 * If false no associated records will be saved. (default: true)
 *
 * ### Events
 *
 * When saving, this method will trigger four events:
 *
 * - Model.beforeValidate: Will be triggered right before any validation is done
 * for the passed entity if the validate key in $options is not set to false.
 * Listeners will receive as arguments the entity, the options array and the
 * validation object to be used for validating the entity. If the event is
 * stopped the validation result will be set to the result of the event itself.
 * - Model.afterValidate: Will be triggered right after the `validate()` method is
 * called in the entity. Listeners will receive as arguments the entity, the
 * options array and the validation object to be used for validating the entity.
 * If the event is stopped the validation result will be set to the result of
 * the event itself.
 * - Model.beforeSave: Will be triggered just before the list of fields to be
 * persisted is calculated. It receives both the entity and the options as
 * arguments. The options array is passed as an ArrayObject, so any changes in
 * it will be reflected in every listener and remembered at the end of the event
 * so it can be used for the rest of the save operation. Returning false in any
 * of the listeners will abort the saving process. If the event is stopped
 * using the event API, the event object's `result` property will be returned.
 * This can be useful when having your own saving strategy implemented inside a
 * listener.
 * - Model.afterSave: Will be triggered after a successful insert or save,
 * listeners will receive the entity and the options array as arguments. The type
 * of operation performed (insert or update) can be determined by checking the
 * entity's method `isNew`, true meaning an insert and false an update.
 *
 * This method will determine whether the passed entity needs to be
 * inserted or updated in the database. It does that by checking the `isNew`
 * method on the entity, if no information can be found there, it will go
 * directly to the database to check the entity's status.
 *
 * ### Saving on associated tables
 *
 * This method will by default persist entities belonging to associated tables,
 * whenever a dirty property matching the name of the property name set for an
 * association in this table. It is possible to control what associations will
 * be saved and to pass additional option for saving them.
 *
 * {{{
 * $articles->save($entity, ['associated' => ['Comment']); // Only save comment assoc
 *
 * // Save the company, the employees and related addresses for each of them.
 * // For employees use the 'special' validation group
 * $companies->save($entity, [
 * 'associated' => ['Employee' => ['associated' => ['Address'], 'validate' => 'special']
 * ]);
 *
 * // Save no associations
 * $articles->save($entity, ['associated' => false]);
 * }}}
 *
 * @param \Cake\ORM\Entity the entity to be saved
 * @param array $options
 * @return \Cake\ORM\Entity|boolean
 */
	public function save(Entity $entity, array $options = []) {
		$options = new \ArrayObject($options + [
			'atomic' => true,
			'validate' => true,
			'associated' => true
		]);

		if ($entity->isNew() === false && !$entity->dirty()) {
			return $entity;
		}

		if ($options['atomic']) {
			$connection = $this->connection();
			$success = $connection->transactional(function() use ($entity, $options) {
				return $this->_processSave($entity, $options);
			});
		} else {
			$success = $this->_processSave($entity, $options);
		}

		return $success;
	}

/**
 * Performs the actual saving of an entity based on the passed options.
 *
 * @param \Cake\ORM\Entity the entity to be saved
 * @param array $options
 * @return \Cake\ORM\Entity|boolean
 */
	protected function _processSave($entity, $options) {
		$primary = $entity->extract((array)$this->primaryKey());
		if ($primary && $entity->isNew() === null) {
			$entity->isNew(!$this->exists($primary));
		}

		if ($entity->isNew() === null) {
			$entity->isNew(true);
		}

		if ($options['associated'] === true) {
			$options['associated'] = array_keys($this->_associations);
		}
		$options['associated'] = array_filter((array)$options['associated']);

		if (!$this->_processValidation($entity, $options)) {
			return false;
		}

		$event = new Event('Model.beforeSave', $this, compact('entity', 'options'));
		$this->getEventManager()->dispatch($event);

		if ($event->isStopped()) {
			return $event->result;
		}

		$originalOptions = $options->getArrayCopy();
		list($parents, $children) = $this->_sortAssociationTypes($options['associated']);
		$saved = $this->_saveAssociations($parents, $entity, $originalOptions);

		if (!$saved && $options['atomic']) {
			return false;
		}

		$data = $entity->extract($this->schema()->columns(), true);
		$keys = array_keys($data);
		$isNew = $entity->isNew();

		if ($isNew) {
			$success = $this->_insert($entity, $data);
		} else {
			$success = $this->_update($entity, $data);
		}

		if ($success) {
			$success = $this->_saveAssociations($children, $entity, $originalOptions);
			if ($success || !$options['atomic']) {
				$entity->clean();
				$event = new Event('Model.afterSave', $this, compact('entity', 'options'));
				$this->getEventManager()->dispatch($event);
				$entity->isNew(false);
				$success = $entity;
			}
		}

		if (!$success && $isNew) {
			$entity->unsetProperty($this->primaryKey());
			$entity->isNew(true);
		}

		return $success;
	}

/**
 * Auxiliary method used to sort out which associations are defined in this table
 * as the owning side and which not based on a list of provided aliases.
 *
 * @param array $assocs list of association aliases
 * @throws \InvalidArgumentException if one of the provided aliases is not an
 * actual association defined for this table
 * @return array with two values, first one contain associations which target
 * is the owning side (or parent associations) and second value is an array of
 * associations aliases for which this table is the owning side.
 */
	protected function _sortAssociationTypes($assocs) {
		$parents = $children = [];
		foreach ($assocs as $key => $assoc) {
			if (!is_string($assoc)) {
				$assoc = $key;
			}

			$association = $this->association($assoc);
			if (!$association) {
				$msg = __d('cake_dev', '%s is not associated to %s', $this->alias(), $assoc);
				throw new \InvalidArgumentException($msg);
			}

			if ($association->isOwningSide($this)) {
				$children[] = $assoc;
			} else {
				$parents[] = $assoc;
			}
		}
		return [$parents, $children];
	}

/**
 * Runs through a list of aliases and for each of them calls the `save()` method
 * on the corresponding association objects passing $entity and $options as values.
 * If the alias for one of the associations contains an array as values in $assocs,
 * $options will be merged to this value before passed to the save operation.
 *
 *
 * @param array $assocs list of association aliases.
 * @param \Cake\ORM\Entity $entity the entity belonging to this table and maybe
 * containing associated entities.
 * @param array $options options to be passed to the save operation
 * @return boolean|\Cake\ORM\Entity The saved source entity on success, false
 * otherwise
 */
	protected function _saveAssociations($assocs, $entity, array $options) {
		if (empty($assocs)) {
			return $entity;
		}

		$associated = $options['associated'];
		unset($options['associated']);

		foreach ($assocs as $alias) {
			$association = $this->association($alias);
			$property = $association->property();
			$passOptions = $options;

			if (!$entity->dirty($property)) {
				continue;
			}

			if (isset($associated[$alias])) {
				$passOptions = (array)$associated[$alias] + $options;
			}

			if (!$association->save($entity, $passOptions)) {
				return false;
			}
		}

		return $entity;
	}

/**
 * Auxiliary function to handle the insert of an entity's data in the table
 *
 * @param \Cake\ORM\Entity the subject entity from were $data was extracted
 * @param array $data The actual data that needs to be saved
 * @return \Cake\ORM\Entity|boolean
 */
	protected function _insert($entity, $data) {
		$query = $this->_buildQuery();

		$primary = $this->primaryKey();
		$id = $this->_newId($primary);
		if ($id !== null) {
			$data[$primary] = $id;
		}

		$statement = $query->insert($this->table(), array_keys($data))
			->values($data)
			->executeStatement();

		$success = false;
		if ($statement->rowCount() > 0) {
			if ($primary && !isset($data[$primary])) {
				$id = $statement->lastInsertId($this->table(), $primary);
			}
			if ($primary && $id !== null) {
				$entity->set($primary, $id);
			}
			$success = $entity;
		}
		$statement->closeCursor();
		return $success;
	}

/**
 * Generate a primary key value for a new record.
 *
 * By default, this uses the type system to generate a new primary key
 * value if possible. You can override this method if you have specific requirements
 * for id generation.
 *
 * @param string $primary The primary key column to get a new ID for.
 * @return null|mixed Either null or the new primary key value.
 */
	protected function _newId($primary) {
		if (!$primary) {
			return null;
		}
		$typeName = $this->schema()->columnType($primary);
		$type = Type::build($typeName);
		return $type->newId();
	}

/**
 * Auxiliary function to handle the update of an entity's data in the table
 *
 * @param \Cake\ORM\Entity the subject entity from were $data was extracted
 * @param array $data The actual data that needs to be saved
 * @return \Cake\ORM\Entity|boolean
 * @throws \InvalidArgumentException When primary key data is missing.
 */
	protected function _update($entity, $data) {
		$primaryKey = $entity->extract((array)$this->primaryKey());
		$data = array_diff_key($data, $primaryKey);

		if (empty($data)) {
			return $entity;
		}

		$filtered = array_filter($primaryKey, 'strlen');
		if (count($filtered) < count($primaryKey)) {
			$message = __d('cake_dev', 'A primary key value is needed for updating');
			throw new \InvalidArgumentException($message);
		}

		$query = $this->_buildQuery();
		$statement = $query->update($this->table())
			->set($data)
			->where($primaryKey)
			->executeStatement();

		$success = false;
		if ($statement->rowCount() > 0) {
			$success = $entity;
		}
		$statement->closeCursor();
		return $success;
	}

/**
 * Validates the $entity if the 'validate' key is not set to false in $options
 * If not empty it will construct a default validation object or get one with
 * the name passed in the key
 *
 * @param \Cake\ORM\Entity The entity to validate
 * @param \ArrayObject $options
 * @return boolean true if the entity is valid, false otherwise
 */
	protected function _processValidation($entity, $options) {
		if (empty($options['validate'])) {
			return true;
		}

		$type = is_string($options['validate']) ? $options['validate'] : 'default';
		$validator = $this->validator($type);
		$validator->provider('table', $this);
		$validator->provider('entity', $entity);

		$pass = compact('entity', 'options', 'validator');
		$event = new Event('Model.beforeValidate', $this, $pass);
		$this->getEventManager()->dispatch($event);

		if ($event->isStopped()) {
			return (bool)$event->result;
		}

		if (!count($validator)) {
			return true;
		}

		$success = $entity->validate($validator);

		$event = new Event('Model.afterValidate', $this, $pass);
		$this->getEventManager()->dispatch($event);

		if ($event->isStopped()) {
			$success = (bool)$event->result;
		}

		return $success;
	}

/**
 * Delete a single entity.
 *
 * Deletes an entity and possibly related associations from the database
 * based on the 'dependent' option used when defining the association.
 * For HasMany and HasOne associations records will be removed based on
 * the dependent option. Join table records in BelongsToMany associations
 * will always be removed. You can use the `cascadeCallbacks` option
 * when defining associations to change how associated data is deleted.
 *
 * ## Options
 *
 * - `atomic` Defaults to true. When true the deletion happens within a transaction.
 *
 * ## Events
 *
 * - `beforeDelete` Fired before the delete occurs. If stopped the delete
 *   will be aborted. Receives the event, entity, and options.
 * - `afterDelete` Fired after the delete has been successful. Receives
 *   the event, entity, and options.
 *
 * The options argument will be converted into an \ArrayObject instance
 * for the duration of the callbacks, this allows listeners to modify
 * the options used in the delete operation.
 *
 * @param Entity $entity The entity to remove.
 * @param array $options The options fo the delete.
 * @return boolean success
 */
	public function delete(Entity $entity, array $options = []) {
		$options = new \ArrayObject($options + ['atomic' => true]);

		$process = function() use ($entity, $options) {
			return $this->_processDelete($entity, $options);
		};

		if ($options['atomic']) {
			return $this->connection()->transactional($process);
		}
		return $process();
	}

/**
 * Perform the delete operation.
 *
 * Will delete the entity provided. Will remove rows from any
 * dependent associations, and clear out join tables for BelongsToMany associations.
 *
 * @param Entity $entity The entity to delete.
 * @param ArrayObject $options The options for the delete.
 * @return boolean success
 */
	protected function _processDelete($entity, $options) {
		$eventManager = $this->getEventManager();
		$event = new Event('Model.beforeDelete', $this, [
			'entity' => $entity,
			'options' => $options
		]);
		$eventManager->dispatch($event);
		if ($event->isStopped()) {
			return $event->result;
		}

		if ($entity->isNew()) {
			return false;
		}
		$primaryKey = (array)$this->primaryKey();
		$conditions = (array)$entity->extract($primaryKey);

		$query = $this->_buildQuery();
		$statement = $query->delete($this->table())
			->where($conditions)
			->executeStatement();

		$success = $statement->rowCount() > 0;
		if (!$success) {
			return $success;
		}

		foreach ($this->_associations as $assoc) {
			$assoc->cascadeDelete($entity, $options->getArrayCopy());
		}

		$event = new Event('Model.afterDelete', $this, [
			'entity' => $entity,
			'options' => $options
		]);
		$eventManager->dispatch($event);

		return $success;
	}

/**
 * Calls a finder method directly and applies it to the passed query,
 * if no query is passed a new one will be created and returned
 *
 * @param string $type name of the finder to be called
 * @param \Cake\ORM\Query $query The query object to apply the finder options to
 * @param array $args List of options to pass to the finder
 * @return \Cake\ORM\Query
 * @throws \BadMethodCallException
 */
	public function callFinder($type, Query $query, $options = []) {
		$finder = 'find' . ucfirst($type);
		if (method_exists($this, $finder)) {
			return $this->{$finder}($query, $options);
		}

		if ($this->_behaviors && $this->_behaviors->hasFinder($type)) {
			return $this->_behaviors->callFinder($type, [$query, $options]);
		}

		throw new \BadMethodCallException(
			__d('cake_dev', 'Unknown finder method "%s"', $type)
		);
	}

/**
 * Provides the dynamic findBy and findByAll methods.
 *
 * @param string $method The method name that was fired.
 * @param array $args List of arguments passed to the function.
 * @return mixed.
 * @throws Cake\Error\Exception when there are missing arguments, or when
 *  and & or are combined.
 */
	protected function _dynamicFinder($method, $args) {
		$method = Inflector::underscore($method);
		preg_match('/^find_([\w]+)_by_/', $method, $matches);
		if (empty($matches)) {
			// find_by_ is 8 characters.
			$fields = substr($method, 8);
			$findType = 'all';
		} else {
			$fields = substr($method, strlen($matches[0]));
			$findType = Inflector::variable($matches[1]);
		}
		$conditions = [];
		$hasOr = strpos($fields, '_or_');
		$hasAnd = strpos($fields, '_and_');

		$makeConditions = function($fields, $args) {
			$conditions = [];
			if (count($args) < count($fields)) {
				throw new \Cake\Error\Exception(__d(
					'cake_dev',
					'Not enough arguments to magic finder. Got %s required %s',
					count($args),
					count($fields)
				));
			}
			foreach ($fields as $field) {
				$conditions[$field] = array_shift($args);
			}
			return $conditions;
		};

		if ($hasOr !== false && $hasAnd !== false) {
			throw new \Cake\Error\Exception(__d(
				'cake_dev',
				'Cannot mix "and" & "or" in a magic finder. Use find() instead.'
			));
		}

		if ($hasOr === false && $hasAnd === false) {
			$conditions = $makeConditions([$fields], $args);
		} elseif ($hasOr !== false) {
			$fields = explode('_or_', $fields);
			$conditions = [
				'OR' => $makeConditions($fields, $args)
			];
		} elseif ($hasAnd !== false) {
			$fields = explode('_and_', $fields);
			$conditions = $makeConditions($fields, $args);
		}

		return $this->find($findType, [
			'conditions' => $conditions,
		]);
	}

/**
 * Handles behavior delegation + dynamic finders.
 *
 * If your Table uses any behaviors you can call them as if
 * they were on the table object.
 *
 * @param string $method name of the method to be invoked
 * @param array $args List of arguments passed to the function
 * @return mixed
 * @throws \BadMethodCallException
 */
	public function __call($method, $args) {
		if ($this->_behaviors && $this->_behaviors->hasMethod($method)) {
			return $this->_behaviors->call($method, $args);
		}
		if (preg_match('/^find(?:\w+)?By/', $method) > 0) {
			return $this->_dynamicFinder($method, $args);
		}

		throw new \BadMethodCallException(
			__d('cake_dev', 'Unknown method "%s"', $method)
		);
	}

/**
 * Get the Model callbacks this table is interested in.
 *
 * By implementing the conventional methods a table class is assumed
 * to be interested in the related event.
 *
 * Override this method if you need to add non-conventional event listeners.
 * Or if you want you table to listen to non-standard events.
 *
 * @return array
 */
	public function implementedEvents() {
		$eventMap = [
			'Model.beforeFind' => 'beforeFind',
			'Model.beforeSave' => 'beforeSave',
			'Model.afterSave' => 'afterSave',
			'Model.beforeDelete' => 'beforeDelete',
			'Model.afterDelete' => 'afterDelete',
		];
		$events = [];

		foreach ($eventMap as $event => $method) {
			if (!method_exists($this, $method)) {
				continue;
			}
			$events[$event] = $method;
		}
		return $events;
	}

}