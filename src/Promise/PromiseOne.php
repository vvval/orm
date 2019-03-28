<?php declare(strict_types=1);
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Cycle\ORM\Promise;

use Cycle\ORM\ORMInterface;

/**
 * Promises one entity and resolves the result via ORM heap or entity repository.
 */
class PromiseOne implements PromiseInterface
{
    /** @var ORMInterface|null @internal */
    private $orm;

    /** @var string|null */
    private $target;

    /** @var array */
    private $scope;

    /** @var mixed */
    private $resolved;

    /**
     * @param ORMInterface $orm
     * @param string       $target
     * @param array        $scope
     */
    public function __construct(ORMInterface $orm, string $target, array $scope)
    {
        $this->orm = $orm;
        $this->target = $target;
        $this->scope = $scope;
    }

    /**
     * @inheritdoc
     */
    public function __loaded(): bool
    {
        return empty($this->orm);
    }

    /**
     * @inheritdoc
     */
    public function __role(): string
    {
        return $this->target;
    }

    /**
     * @inheritdoc
     */
    public function __scope(): array
    {
        return $this->scope;
    }

    /**
     * @inheritdoc
     */
    public function __resolve()
    {
        if (!is_null($this->orm)) {
            if (count($this->scope) !== 1) {
                $this->resolved = $this->orm->getRepository($this->target)->findOne($this->scope);
            } else {
                $key = key($this->scope);
                $value = $this->scope[$key];

                $this->resolved = $this->orm->get($this->target, $key, $value, true);
            }

            $this->orm = null;
        }

        return $this->resolved;
    }
}