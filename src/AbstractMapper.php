<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM;

use Spiral\ORM\Command\CommandInterface;
use Spiral\ORM\Command\ContextualInterface;
use Spiral\ORM\Command\Control\Branch;
use Spiral\ORM\Command\Database\Delete;
use Spiral\ORM\Command\Database\Insert;
use Spiral\ORM\Command\Database\Update;
use Spiral\ORM\Command\Control\Nil;

// todo: events
abstract class AbstractMapper implements MapperInterface
{
    // system column to store entity type
    public const ENTITY_TYPE = '_type';

    protected $orm;

    protected $class;

    protected $table;

    protected $primaryKey;

    protected $children;

    protected $columns;

    public function __construct(ORMInterface $orm, $class)
    {
        $this->orm = $orm;
        $this->class = $class;

        // todo: mass export
        $this->columns = $this->orm->getSchema()->define($class, Schema::COLUMNS);
        $this->table = $this->orm->getSchema()->define($class, Schema::TABLE);
        $this->primaryKey = $this->orm->getSchema()->define($class, Schema::PRIMARY_KEY);
        $this->children = $this->orm->getSchema()->define($class, Schema::CHILDREN) ?? [];
    }

    public function entityClass(array $data): string
    {
        $class = $this->class;
        if (!empty($this->children) && !empty($data[self::ENTITY_TYPE])) {
            $class = $this->children[$data[self::ENTITY_TYPE]] ?? $class;
        }

        return $class;
    }

    public function prepare(array $data): array
    {
        $class = $this->entityClass($data);

        return [new $class, $data];
    }

    // todo: need state as INPUT!!!!
    public function queueStore($entity): ContextualInterface
    {
        /** @var State $state */
        $state = $this->orm->getHeap()->get($entity);

        if ($state == null || $state->getState() == State::NEW) {
            $cmd = $this->queueCreate($entity, $state);
            $state->setActiveCommand($cmd);
            $cmd->onComplete(function () use ($state) {
                $state->setActiveCommand(null);
            });

            return $cmd;
        }

        $lastCommand = $state->getActiveCommand();
        if (empty($lastCommand)) {
            // todo: check multiple update commands working within the split (!)
            return $this->queueUpdate($entity, $state);
        }

        if ($lastCommand instanceof Branch) {
            return $lastCommand;
        }

        $split = new Branch($lastCommand, $this->queueUpdate($entity, $state));
        $state->setActiveCommand($split);

        return $split;
    }

    public function queueDelete($entity): CommandInterface
    {
        $state = $this->orm->getHeap()->get($entity);
        if ($state == null) {
            // todo: this should not happen, todo: need nullable delete
            return new Nil();
        }

        // todo: delete relations as well

        return $this->buildDelete($entity, $state);
    }

    protected function getColumns($entity): array
    {
        return array_intersect_key($this->extract($entity), array_flip($this->columns));
    }

    // todo: state must not be null
    protected function queueCreate($entity, StateInterface &$state = null): ContextualInterface
    {
        $columns = $this->getColumns($entity);

        $class = get_class($entity);
        if ($class != $this->class) {
            // possibly children
            foreach ($this->children as $alias => $childClass) {
                if ($childClass == $class) {
                    $columns[self::ENTITY_TYPE] = $alias;
                }
            }

            // todo: what is that?

            // todo: exception
        }

        if (is_null($state)) {
            // todo: do we need to track PK?
            $state = new State(State::NEW, $columns);
            $this->orm->getHeap()->attach($entity, $state);
        } else {
            // todo: do i need it here? do it in complete? OR NOT???
            $state->setData($columns);
        }

        $state->setState(State::SCHEDULED_INSERT);

        // todo: this is questionable (what if ID not autogenerated)
        unset($columns[$this->primaryKey]);

        $insert = new Insert($this->orm->getDatabase($entity), $this->table, $columns);

        // we are managed at this moment

        $insert->onExecute(function (Insert $command) use ($entity, $state) {
            $state->setData([$this->primaryKey => $command->getInsertID()] + $command->getContext());
        });

        $insert->onComplete(function (Insert $command) use ($entity, $state) {
            $state->setState(State::LOADED);
            $this->hydrate($entity, $state->getData());
        });

        $insert->onRollBack(function (Insert $command) use ($entity, $state) {
            // detach or change the state ?
            // todo: need test for that (!)
            $this->orm->getHeap()->detach($entity);

            // todo: reset state and data
            $state->setState(State::NEW);
        });

        return $insert;
    }

    protected function queueUpdate($entity, StateInterface $state): ContextualInterface
    {
        $eData = $this->getColumns($entity);
        $oData = $state->getData();
        $cData = array_diff($eData, $oData);

        // todo: pack changes (???) depends on mode (USE ALL FOR NOW)

        $update = new Update(
            $this->orm->getDatabase($entity),
            $this->table,
            $cData,
            [$this->primaryKey => $oData[$this->primaryKey] ?? $eData[$this->primaryKey] ?? null]
        );

        $current = $state->getState();
        $state->setState(State::SCHEDULED_UPDATE);
        $state->setData($cData);

        $state->onChange(function (State $state) use ($update) {
            $update->setScope($this->primaryKey, $state->getData()[$this->primaryKey]);
        });

        $update->onExecute(function (Update $command) use ($entity, $state) {
            $state->setData($command->getContext());
        });

        $update->onComplete(function (Update $command) use ($entity, $state) {
            $state->setState(State::LOADED);
            $this->hydrate($entity, $state->getData());
        });

        $update->onRollBack(function () use ($state, $current) {
            //todo: rollback data
            $state->setState($current);
        });

        return $update;
    }

    protected function buildDelete($entity, StateInterface $state): CommandInterface
    {
        // todo: better primary key fetch

        $delete = new Delete(
            $this->orm->getDatabase($entity),
            $this->table,
            // todo: uuid?
            [$this->primaryKey => $state->getData()[$this->primaryKey] ?? $this->extract($entity)[$this->primaryKey] ?? null]
        );

        $current = $state->getState();

        $state->setState(State::SCHEDULED_DELETE);

        $state->onChange(function (State $state) use ($delete) {
            $delete->setScope($this->primaryKey, $state->getData()[$this->primaryKey]);
        });

        $delete->onComplete(function (Delete $command) use ($entity) {
            $this->orm->getHeap()->detach($entity);
        });

        $delete->onRollBack(function () use ($state, $current) {
            $state->setState($current);
        });

        return $delete;
    }
}