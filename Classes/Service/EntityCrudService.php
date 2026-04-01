<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Persistence\Repository;
use Neos\Media\Domain\Repository\AssetRepository;

/**
 * Generic CRUD service for Doctrine entities exposed via MCP.
 *
 * Reads entity configuration from UpAssist.Neos.Mcp.entities (merged from all packages)
 * and provides list, show, create, update, delete, and custom action operations.
 *
 * @Flow\Scope("singleton")
 */
class EntityCrudService
{
    /**
     * @Flow\InjectConfiguration(path="entities", package="UpAssist.Neos.Mcp")
     * @var array<string, array<string, mixed>>
     */
    protected array $entitiesConfig = [];

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    // -------------------------------------------------------------------------
    // Schema / Discovery
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getEntityConfigurations(): array
    {
        $result = [];
        foreach ($this->entitiesConfig as $key => $config) {
            $result[$key] = [
                'label' => $config['label'] ?? $key,
                'readOnly' => $config['readOnly'] ?? false,
                'fields' => $config['fields'] ?? [],
                'filters' => $this->describeFilters($config),
                'actions' => $this->describeActions($config),
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // List / Show
    // -------------------------------------------------------------------------

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listEntities(string $entityKey, string $filter = '', array $filterParams = [], int $limit = 50, int $offset = 0): array
    {
        $config = $this->requireConfig($entityKey);
        $repository = $this->resolveRepository($config);

        if ($filter !== '' && isset($config['filters'][$filter])) {
            return $this->executeFilter($config, $repository, $filter, $filterParams, $limit, $offset);
        }

        $query = $repository->createQuery();
        $query->setLimit($limit);
        $query->setOffset($offset);
        $total = $query->count();
        $items = $query->execute()->toArray();

        return [
            'items' => array_map(fn(object $entity) => $this->serializeEntity($entity, $config), $items),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showEntity(string $entityKey, string $identifier): array
    {
        $config = $this->requireConfig($entityKey);
        $entity = $this->findEntityOrFail($config, $identifier);
        return $this->serializeEntity($entity, $config);
    }

    // -------------------------------------------------------------------------
    // Create / Update / Delete
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function createEntity(string $entityKey, array $properties): array
    {
        $config = $this->requireConfig($entityKey);
        $this->assertWritable($config);

        if (isset($config['serviceMethods']['create']) && isset($config['service'])) {
            return $this->delegateToService($config, 'create', null, $properties);
        }

        $className = $config['className'];
        $entity = new $className();
        $this->applyProperties($entity, $properties, $config);

        $repository = $this->resolveRepository($config);
        $repository->add($entity);
        $this->persistenceManager->persistAll();

        return $this->serializeEntity($entity, $config);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateEntity(string $entityKey, string $identifier, array $properties): array
    {
        $config = $this->requireConfig($entityKey);
        $this->assertWritable($config);
        $entity = $this->findEntityOrFail($config, $identifier);

        if (isset($config['serviceMethods']['update']) && isset($config['service'])) {
            return $this->delegateToService($config, 'update', $entity, $properties);
        }

        $this->applyProperties($entity, $properties, $config);

        $repository = $this->resolveRepository($config);
        $repository->update($entity);
        $this->persistenceManager->persistAll();

        return $this->serializeEntity($entity, $config);
    }

    /**
     * @return array{success: bool}
     */
    public function deleteEntity(string $entityKey, string $identifier): array
    {
        $config = $this->requireConfig($entityKey);
        $this->assertWritable($config);
        $entity = $this->findEntityOrFail($config, $identifier);

        if (isset($config['serviceMethods']['delete']) && isset($config['service'])) {
            $service = $this->resolveService($config);
            $method = $config['serviceMethods']['delete'];
            $service->$method($entity);
            $this->persistenceManager->persistAll();
            return ['success' => true];
        }

        $repository = $this->resolveRepository($config);
        $repository->remove($entity);
        $this->persistenceManager->persistAll();

        return ['success' => true];
    }

    // -------------------------------------------------------------------------
    // Named Actions
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function executeAction(string $entityKey, string $action, string $identifier = '', array $params = []): array
    {
        $config = $this->requireConfig($entityKey);
        $actionConfig = $config['actions'][$action] ?? null;
        if ($actionConfig === null) {
            throw new \InvalidArgumentException('Unknown action: ' . $action);
        }

        $method = $actionConfig['method'];
        $isServiceMethod = $actionConfig['serviceMethod'] ?? false;
        $requiresEntity = $actionConfig['requiresEntity'] ?? false;

        $target = $isServiceMethod ? $this->resolveService($config) : $this->resolveRepository($config);

        $args = [];
        if ($requiresEntity) {
            $args[] = $this->findEntityOrFail($config, $identifier);
        } elseif ($identifier !== '') {
            $args[] = $identifier;
        }

        foreach ($params as $value) {
            $args[] = $value;
        }

        $result = $target->$method(...$args);
        $this->persistenceManager->persistAll();

        if (is_object($result) && is_a($result, $config['className'])) {
            return $this->serializeEntity($result, $config);
        }

        if (is_array($result)) {
            return $result;
        }

        return ['success' => true];
    }

    // -------------------------------------------------------------------------
    // Private: Config resolution
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function requireConfig(string $entityKey): array
    {
        $config = $this->entitiesConfig[$entityKey] ?? null;
        if ($config === null) {
            throw new \InvalidArgumentException('Unknown entity: ' . $entityKey);
        }
        return $config;
    }

    private function assertWritable(array $config): void
    {
        if ($config['readOnly'] ?? false) {
            throw new \RuntimeException('Entity is read-only');
        }
    }

    private function resolveRepository(array $config): Repository
    {
        $repo = $this->objectManager->get($config['repository']);
        if (!$repo instanceof Repository) {
            throw new \RuntimeException('Repository class does not extend Repository: ' . $config['repository']);
        }
        return $repo;
    }

    private function resolveService(array $config): object
    {
        if (!isset($config['service'])) {
            throw new \RuntimeException('No service configured for entity');
        }
        return $this->objectManager->get($config['service']);
    }

    private function findEntityOrFail(array $config, string $identifier): object
    {
        $repository = $this->resolveRepository($config);
        $entity = $repository->findByIdentifier($identifier);
        if ($entity === null) {
            throw new \InvalidArgumentException('Entity not found: ' . $identifier);
        }
        return $entity;
    }

    // -------------------------------------------------------------------------
    // Private: Serialization
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function serializeEntity(object $entity, array $config): array
    {
        $data = [
            'identifier' => $this->persistenceManager->getIdentifierByObject($entity),
        ];

        foreach (($config['fields'] ?? []) as $fieldName => $fieldConfig) {
            $getter = 'get' . ucfirst($fieldName);
            if (!method_exists($entity, $getter)) {
                $getter = 'is' . ucfirst($fieldName);
                if (!method_exists($entity, $getter)) {
                    continue;
                }
            }

            $value = $entity->$getter();
            $data[$fieldName] = $this->serializeValue($value, $fieldConfig);
        }

        return $data;
    }

    private function serializeValue(mixed $value, array $fieldConfig): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $fieldConfig['type'] ?? 'string';

        return match ($type) {
            'datetime' => $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : $value,
            'reference' => is_object($value) ? $this->persistenceManager->getIdentifierByObject($value) : $value,
            'asset' => is_object($value) ? [
                '__type' => 'asset',
                'identifier' => $this->persistenceManager->getIdentifierByObject($value),
            ] : $value,
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    // -------------------------------------------------------------------------
    // Private: Deserialization / property application
    // -------------------------------------------------------------------------

    private function applyProperties(object $entity, array $properties, array $config): void
    {
        $fields = $config['fields'] ?? [];

        foreach ($properties as $fieldName => $value) {
            $fieldConfig = $fields[$fieldName] ?? null;
            if ($fieldConfig === null) {
                continue;
            }
            if ($fieldConfig['readOnly'] ?? false) {
                continue;
            }

            $setter = 'set' . ucfirst($fieldName);
            if (!method_exists($entity, $setter)) {
                continue;
            }

            $entity->$setter($this->deserializeValue($value, $fieldConfig));
        }
    }

    private function deserializeValue(mixed $value, array $fieldConfig): mixed
    {
        if ($value === null || $value === '') {
            $nullable = $fieldConfig['nullable'] ?? false;
            return $nullable ? null : $value;
        }

        $type = $fieldConfig['type'] ?? 'string';

        return match ($type) {
            'string', 'text', 'markdown' => is_string($value) ? trim($value) : (string) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'datetime' => $this->parseDateTime($value),
            'reference' => $this->resolveReference($value, $fieldConfig),
            'asset' => $this->resolveAsset($value),
            'enum' => $this->validateEnum($value, $fieldConfig),
            'json' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    private function parseDateTime(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function resolveReference(mixed $value, array $fieldConfig): ?object
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $targetEntity = $fieldConfig['targetEntity'] ?? null;
        if ($targetEntity === null) {
            return null;
        }
        $repo = $this->persistenceManager->getObjectByIdentifier($value, $targetEntity);
        return $repo ?: null;
    }

    private function resolveAsset(mixed $value): ?object
    {
        $id = is_array($value) ? ($value['identifier'] ?? null) : $value;
        if (!is_string($id) || trim($id) === '') {
            return null;
        }
        return $this->assetRepository->findByIdentifier($id);
    }

    private function validateEnum(mixed $value, array $fieldConfig): string
    {
        $allowed = $fieldConfig['enum'] ?? [];
        $stringVal = (string) $value;
        if ($allowed !== [] && !in_array($stringVal, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Invalid enum value "' . $stringVal . '". Allowed: ' . implode(', ', $allowed)
            );
        }
        return $stringVal;
    }

    // -------------------------------------------------------------------------
    // Private: Service delegation
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function delegateToService(array $config, string $operation, ?object $entity, array $properties): array
    {
        $service = $this->resolveService($config);
        $method = $config['serviceMethods'][$operation];
        $fields = $config['fields'] ?? [];

        $args = [];
        if ($entity !== null) {
            $args[] = $entity;
        }

        $reflection = new \ReflectionMethod($service, $method);
        $params = $reflection->getParameters();

        $startIndex = $entity !== null ? 1 : 0;
        for ($i = $startIndex; $i < count($params); $i++) {
            $param = $params[$i];
            $paramName = $param->getName();

            if (array_key_exists($paramName, $properties)) {
                $fieldConfig = $fields[$paramName] ?? ['type' => 'string'];
                $args[] = $this->deserializeValue($properties[$paramName], $fieldConfig);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        $result = $service->$method(...$args);
        $this->persistenceManager->persistAll();

        if (is_array($result) && isset($result['notification'])) {
            $resultEntity = $result['notification'];
            $serialized = $this->serializeEntity($resultEntity, $config);
            $serialized['errors'] = $result['errors'] ?? [];
            return $serialized;
        }

        if (is_object($result) && is_a($result, $config['className'])) {
            return $this->serializeEntity($result, $config);
        }

        if (is_array($result)) {
            return $result;
        }

        return ['success' => true];
    }

    // -------------------------------------------------------------------------
    // Private: Filter execution
    // -------------------------------------------------------------------------

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function executeFilter(array $config, Repository $repository, string $filterName, array $filterParams, int $limit, int $offset): array
    {
        $filterConfig = $config['filters'][$filterName];
        $method = $filterConfig['method'];
        $paramConfigs = $filterConfig['parameters'] ?? [];

        $args = [];
        foreach ($paramConfigs as $paramName => $paramConfig) {
            if (array_key_exists($paramName, $filterParams)) {
                $args[] = $this->castFilterParam($filterParams[$paramName], $paramConfig);
            } elseif (isset($paramConfig['default'])) {
                $args[] = $paramConfig['default'];
            } elseif ($paramConfig['required'] ?? false) {
                throw new \InvalidArgumentException('Missing required filter parameter: ' . $paramName);
            } else {
                $args[] = null;
            }
        }

        if ($paramConfigs === []) {
            $result = $repository->$method();
        } else {
            $result = $repository->$method(...$args);
        }

        $items = is_array($result) ? $result : $result->toArray();
        $total = count($items);

        if ($paramConfigs !== [] && !isset($paramConfigs['limit'])) {
            $items = array_slice($items, $offset, $limit);
        }

        return [
            'items' => array_map(fn(object $entity) => $this->serializeEntity($entity, $config), $items),
            'total' => $total,
        ];
    }

    private function castFilterParam(mixed $value, array $paramConfig): mixed
    {
        $type = $paramConfig['type'] ?? 'string';
        return match ($type) {
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }

    // -------------------------------------------------------------------------
    // Private: Schema description
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function describeFilters(array $config): array
    {
        $filters = [];
        foreach (($config['filters'] ?? []) as $name => $filterConfig) {
            $filters[$name] = [
                'label' => $filterConfig['label'] ?? $name,
                'parameters' => $filterConfig['parameters'] ?? [],
            ];
        }
        return $filters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function describeActions(array $config): array
    {
        $actions = [];
        foreach (($config['actions'] ?? []) as $name => $actionConfig) {
            $actions[$name] = [
                'label' => $actionConfig['label'] ?? $name,
                'requiresEntity' => $actionConfig['requiresEntity'] ?? false,
            ];
        }
        return $actions;
    }
}
