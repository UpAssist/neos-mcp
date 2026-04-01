<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use UpAssist\Neos\Mcp\Service\EntityCrudService;

class EntityCrudController extends ActionController
{
    protected $defaultViewObjectName = JsonView::class;

    protected $supportedMediaTypes = ['application/json', 'text/html'];

    /**
     * @Flow\InjectConfiguration(path="apiToken", package="UpAssist.Neos.Mcp")
     * @var string|null
     */
    protected $apiToken;

    /**
     * @Flow\Inject
     * @var EntityCrudService
     */
    protected $entityCrudService;

    private function checkAuth(): void
    {
        $authHeader = $this->request->getHttpRequest()->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $authHeader);
        if (empty($this->apiToken) || !hash_equals((string) $this->apiToken, $token)) {
            $this->throwStatus(401, 'Unauthorized', json_encode(['error' => 'Unauthorized']));
        }
    }

    protected function resolveActionMethodName(): string
    {
        $this->checkAuth();
        return parent::resolveActionMethodName();
    }

    /** @Flow\SkipCsrfProtection */
    public function listEntitiesAction(): void
    {
        $this->view->assign('value', $this->entityCrudService->getEntityConfigurations());
    }

    /** @Flow\SkipCsrfProtection */
    public function listAction(string $entity, string $filter = '', string $filterParams = '{}', int $limit = 50, int $offset = 0): void
    {
        try {
            $params = json_decode($filterParams, true) ?: [];
            $result = $this->entityCrudService->listEntities($entity, $filter, $params, $limit, $offset);
            $this->view->assign('value', $result);
        } catch (\InvalidArgumentException $e) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function showAction(string $entity, string $identifier): void
    {
        try {
            $result = $this->entityCrudService->showEntity($entity, $identifier);
            $this->view->assign('value', $result);
        } catch (\InvalidArgumentException $e) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function createAction(string $entity, string $properties = '{}'): void
    {
        try {
            $props = json_decode($properties, true) ?: [];
            $result = $this->entityCrudService->createEntity($entity, $props);
            $this->view->assign('value', $result);
        } catch (\InvalidArgumentException $e) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => $e->getMessage()]));
        } catch (\RuntimeException $e) {
            $this->throwStatus(403, 'Forbidden', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function updateAction(string $entity, string $identifier, string $properties = '{}'): void
    {
        try {
            $props = json_decode($properties, true) ?: [];
            $result = $this->entityCrudService->updateEntity($entity, $identifier, $props);
            $this->view->assign('value', $result);
        } catch (\InvalidArgumentException $e) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => $e->getMessage()]));
        } catch (\RuntimeException $e) {
            $this->throwStatus(403, 'Forbidden', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function deleteAction(string $entity, string $identifier): void
    {
        try {
            $result = $this->entityCrudService->deleteEntity($entity, $identifier);
            $this->view->assign('value', $result);
        } catch (\InvalidArgumentException $e) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => $e->getMessage()]));
        } catch (\RuntimeException $e) {
            $this->throwStatus(403, 'Forbidden', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function executeAction(string $entity, string $action, string $identifier = '', string $params = '{}'): void
    {
        try {
            $parsedParams = json_decode($params, true) ?: [];
            $result = $this->entityCrudService->executeAction($entity, $action, $identifier, $parsedParams);
            $this->view->assign('value', $result);
        } catch (\InvalidArgumentException $e) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => $e->getMessage()]));
        } catch (\RuntimeException $e) {
            $this->throwStatus(403, 'Forbidden', json_encode(['error' => $e->getMessage()]));
        }
    }
}
