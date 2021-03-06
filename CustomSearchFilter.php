<?php

/*
 * Adaption of the api-platform Search filter to allow for setting the
 * strategy in the request in the form ?fieldname[strategy]=value
 *
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractContextAwareFilter;
use ApiPlatform\Core\Api\IriConverterInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryBuilderHelper;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Filter the collection by given properties.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class CustomSearchFilter extends AbstractContextAwareFilter
{
    /**
     * @var string Exact matching
     */
    const STRATEGY_EXACT = 'exact';

    /**
     * @var string The value must be contained in the field
     */
    const STRATEGY_PARTIAL = 'partial';

    /**
     * @var string Finds fields that are starting with the value
     */
    const STRATEGY_START = 'start';

    /**
     * @var string Finds fields that are ending with the value
     */
    const STRATEGY_END = 'end';

    /**
     * @var string Finds fields that are starting with the word
     */
    const STRATEGY_WORD_START = 'word_start';

    protected $iriConverter;
    protected $propertyAccessor;

    /**
     * @param RequestStack|null $requestStack No prefix to prevent autowiring of this deprecated property
     */
    public function __construct(ManagerRegistry $managerRegistry, IriConverterInterface $iriConverter, $requestStack = null, PropertyAccessorInterface $propertyAccessor = null, LoggerInterface $logger = null, array $properties = null)
    {
        parent::__construct($managerRegistry, $requestStack, $logger, $properties);

        $this->iriConverter = $iriConverter;
        $this->propertyAccessor = $propertyAccessor ?: PropertyAccess::createPropertyAccessor();
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(string $resourceClass): array
    {
        $description = [];

        $properties = $this->properties;
        if (null === $properties) {
            $properties = array_fill_keys($this->getClassMetadata($resourceClass)->getFieldNames(), null);
        }

        foreach ($properties as $property => $strategy) {
            if (!$this->isPropertyMapped($property, $resourceClass, true)) {
                continue;
            }

            if ($this->isPropertyNested($property, $resourceClass)) {
                $propertyParts = $this->splitPropertyParts($property, $resourceClass);
                $field = $propertyParts['field'];
                $metadata = $this->getNestedMetadata($resourceClass, $propertyParts['associations']);
            } else {
                $field = $property;
                $metadata = $this->getClassMetadata($resourceClass);
            }

            if ($metadata->hasField($field)) {
                $typeOfField = $this->getType((string) $metadata->getTypeOfField($field));
                $strategy = $this->properties[$property] ?? self::STRATEGY_EXACT;
                $filterParameterNames = [$property];

                if (self::STRATEGY_EXACT === $strategy) {
                    $filterParameterNames[] = $property.'[]';
                }

                foreach ($filterParameterNames as $filterParameterName) {
                    $description[$filterParameterName] = [
                        'property' => $property,
                        'type' => $typeOfField,
                        'required' => false,
                        'strategy' => $strategy,
                        'is_collection' => '[]' === substr($filterParameterName, -2),
                    ];
                }
            } elseif ($metadata->hasAssociation($field)) {
                $filterParameterNames = [
                    $property,
                    $property.'[]',
                ];

                foreach ($filterParameterNames as $filterParameterName) {
                    $description[$filterParameterName] = [
                        'property' => $property,
                        'type' => 'string',
                        'required' => false,
                        'strategy' => self::STRATEGY_EXACT,
                        'is_collection' => '[]' === substr($filterParameterName, -2),
                    ];
                }
            }
        }

        // $description['building[start]'] = [
        //     'property' => 'building',
        //     'type' => 'string',
        //     'required' => 'false',
        //     'strategy' => self::STRATEGY_START,
        //     'is_collection' => false
        // ];

        return $description;
    }

    /**
     * Converts a Doctrine type in PHP type.
     */
    private function getType(string $doctrineType): string
    {
        switch ($doctrineType) {
            case Type::TARRAY:
                return 'array';
            case Type::BIGINT:
            case Type::INTEGER:
            case Type::SMALLINT:
                return 'int';
            case Type::BOOLEAN:
                return 'bool';
            case Type::DATE:
            case Type::TIME:
            case Type::DATETIME:
            case Type::DATETIMETZ:
                return \DateTimeInterface::class;
            case Type::FLOAT:
                return 'float';
        }

        if (\defined(Type::class.'::DATE_IMMUTABLE')) {
            switch ($doctrineType) {
                case Type::DATE_IMMUTABLE:
                case Type::TIME_IMMUTABLE:
                case Type::DATETIME_IMMUTABLE:
                case Type::DATETIMETZ_IMMUTABLE:
                    return \DateTimeInterface::class;
            }
        }

        return 'string';
    }

    /**
     * {@inheritdoc}
     */
    protected function filterProperty(string $property, $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null)
    {
        if (null === $value ||
            !$this->isPropertyEnabled($property, $resourceClass) ||
            !$this->isPropertyMapped($property, $resourceClass, true)
        ) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $field = $property;

        $this->logger->info('the property in filterProperty: '. $property);
        $this->logger->info('the provided value: '. print_r($value, true));

        if ($this->isPropertyNested($property, $resourceClass)) {
            list($alias, $field, $associations) = $this->addJoinsForNestedProperty($property, $alias, $queryBuilder, $queryNameGenerator, $resourceClass);
            $metadata = $this->getNestedMetadata($resourceClass, $associations);
        } else {
            $metadata = $this->getClassMetadata($resourceClass);
        }

        //inspect the $value array for a provided search strategy.
        $value = $this->testForProvidedSearchStrategy((array) $value, (string) $property);

        $this->logger->info('the updated value: '. print_r($value, true));
        $this->logger->info('the search strategy: '. $this->properties[$property]);

        $this->logger->info('the properties array: '. print_r($this->properties, true));

        $values = $this->normalizeValues((array) $value);

        if (empty($values)) {
            $this->logger->notice('Invalid filter ignored: Empty Values', [
                'exception' => new InvalidArgumentException(sprintf('At least one value is required, multiple values should be in "%1$s[]=firstvalue&%1$s[]=secondvalue" format', $property)),
            ]);

            return;
        }

        $caseSensitive = true;

        $this->logger->info('does metadata have '. $field .': ' . ($metadata->hasField($field) ? 'yes' : 'no'));

        if ($metadata->hasField($field)) {
            if ('id' === $field) {
                $values = array_map([$this, 'getIdFromValue'], $values);
            }

            if (!$this->hasValidValues($values, $this->getDoctrineFieldType($property, $resourceClass))) {
                $this->logger->notice('Invalid filter ignored: Invalid value for the filter', [
                    'exception' => new InvalidArgumentException(sprintf('Values for field "%s" are not valid according to the doctrine type.', $field)),
                ]);

                return;
            }

            $strategy = $this->properties[$property] ?? self::STRATEGY_EXACT;

            $this->logger->info('the current stategy is: '. $strategy);

            // prefixing the strategy with i makes it case insensitive
            if (0 === strpos($strategy, 'i')) {
                $strategy = substr($strategy, 1);
                $caseSensitive = false;
            }

            if (1 === \count($values)) {
                $this->addWhereByStrategy($strategy, $queryBuilder, $queryNameGenerator, $alias, $field, $values[0], $caseSensitive);

                return;
            }

            if (self::STRATEGY_EXACT !== $strategy) {
                $this->logger->notice('Invalid filter ignored: Invalid Strategy', [
                    'exception' => new InvalidArgumentException(sprintf('"%s" strategy selected for "%s" property, but only "%s" strategy supports multiple values', $strategy, $property, self::STRATEGY_EXACT)),
                ]);

                return;
            }

            $wrapCase = $this->createWrapCase($caseSensitive);
            $valueParameter = $queryNameGenerator->generateParameterName($field);

            $queryBuilder
                ->andWhere(sprintf($wrapCase('%s.%s').' IN (:%s)', $alias, $field, $valueParameter))
                ->setParameter($valueParameter, $caseSensitive ? $values : array_map('strtolower', $values));
        }

        // metadata doesn't have the field, nor an association on the field
        if (!$metadata->hasAssociation($field)) {
            return;
        }

        $values = array_map([$this, 'getIdFromValue'], $values);

        $this->logger->info('checking array map of values: '. print_r($values, true));

        if (!$this->hasValidValues($values, $this->getDoctrineFieldType($property, $resourceClass))) {
            $this->logger->notice('Invalid filter ignored: Invalid value for the provided field', [
                'exception' => new InvalidArgumentException(sprintf('Values for field "%s" are not valid according to the doctrine type.', $field)),
            ]);

            return;
        }

        $association = $field;
        $valueParameter = $queryNameGenerator->generateParameterName($association);

        if ($metadata->isCollectionValuedAssociation($association)) {
            $associationAlias = QueryBuilderHelper::addJoinOnce($queryBuilder, $queryNameGenerator, $alias, $association);
            $associationField = 'id';
        } else {
            $associationAlias = $alias;
            $associationField = $field;
        }

        if (1 === \count($values)) {
            $queryBuilder
                ->andWhere(sprintf('%s.%s = :%s', $associationAlias, $associationField, $valueParameter))
                ->setParameter($valueParameter, $values[0]);
        } else {
            $queryBuilder
                ->andWhere(sprintf('%s.%s IN (:%s)', $associationAlias, $associationField, $valueParameter))
                ->setParameter($valueParameter, $values);
        }
    }

    /**
     * Adds where clause according to the strategy.
     *
     * @throws InvalidArgumentException If strategy does not exist
     */
    protected function addWhereByStrategy(string $strategy, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $alias, string $field, $value, bool $caseSensitive)
    {
        $wrapCase = $this->createWrapCase($caseSensitive);
        $valueParameter = $queryNameGenerator->generateParameterName($field);

        switch ($strategy) {
            case null:
            case self::STRATEGY_EXACT:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s').' = '.$wrapCase(':%s'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);
                break;
            case self::STRATEGY_PARTIAL:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s').' LIKE '.$wrapCase('CONCAT(\'%%\', :%s, \'%%\')'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);
                break;
            case self::STRATEGY_START:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s').' LIKE '.$wrapCase('CONCAT(:%s, \'%%\')'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);
                break;
            case self::STRATEGY_END:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s').' LIKE '.$wrapCase('CONCAT(\'%%\', :%s)'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);
                break;
            case self::STRATEGY_WORD_START:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%1$s.%2$s').' LIKE '.$wrapCase('CONCAT(:%3$s, \'%%\')').' OR '.$wrapCase('%1$s.%2$s').' LIKE '.$wrapCase('CONCAT(\'%% \', :%3$s, \'%%\')'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);
                break;
            default:
                throw new InvalidArgumentException(sprintf('strategy %s does not exist.', $strategy));
        }
    }

    /**
     * Creates a function that will wrap a Doctrine expression according to the
     * specified case sensitivity.
     *
     * For example, "o.name" will get wrapped into "LOWER(o.name)" when $caseSensitive
     * is false.
     */
    protected function createWrapCase(bool $caseSensitive): \Closure
    {
        return function (string $expr) use ($caseSensitive): string {
            if ($caseSensitive) {
                return $expr;
            }

            return sprintf('LOWER(%s)', $expr);
        };
    }

    /**
     * Gets the ID from an IRI or a raw ID.
     */
    protected function getIdFromValue(string $value)
    {
        try {
            if ($item = $this->iriConverter->getItemFromIri($value, ['fetch_data' => false])) {
                return $this->propertyAccessor->getValue($item, 'id');
            }
        } catch (InvalidArgumentException $e) {
            // Do nothing, return the raw value
        }

        return $value;
    }

    /**
     * Test the values array for a provided search method
     * It will be the key for the array
     * Use that term to set the search strategy for the property
     * Return the updated values array for use in normalize values
     */
    protected function testForProvidedSearchStrategy(array $value, $property): array
    {
        $this->logger->info('in testForProvidedSearchStrategy');
        $this->logger->info('value: '. print_r($value, true));
        $this->logger->info('property: '. $property);
        
        $valid_strategies = [
            self::STRATEGY_START,
            self::STRATEGY_END,
            self::STRATEGY_EXACT,
            self::STRATEGY_PARTIAL,
            self::STRATEGY_WORD_START
        ];

        $updated_value = [];

        foreach ($value as $k => $v) {
            if (!\is_int($k)) {
                //test to see what we have
                if (\in_array($k, $valid_strategies)) {
                    //Set the strategy
                    $this->properties[$property] = $k;
                }
                //reset the Values array to be a standard number indexed array
                $updated_value = $this->convertArray($v);
            } else {
                $updated_value = $value;
            }
        }
        return $updated_value;
    }

    /**
     * strip the key and return the value as its own numerically indexed array
     */
    protected function convertArray($value): array
    {
        switch (gettype($value)) {
            case ('array'):
                return $value;
                break;
            default:
                return [$value];
        }
    }

    /**
     * Normalize the values array.
     */
    protected function normalizeValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (!\is_int($key) || !\is_string($value)) {
                unset($values[$key]);
            }
        }

        return array_values($values);
    }

    /**
     * When the field should be an integer, check that the given value is a valid one.
     *
     * @param Type|string $type
     */
    protected function hasValidValues(array $values, $type = null): bool
    {
        foreach ($values as $key => $value) {
            if (Type::INTEGER === $type && null !== $value && false === filter_var($value, FILTER_VALIDATE_INT)) {
                return false;
            }
        }

        return true;
    }
}
