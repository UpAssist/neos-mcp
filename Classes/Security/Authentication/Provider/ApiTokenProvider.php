<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Security\Authentication\Provider;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\Token\BearerToken;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Neos\Flow\Security\Policy\PolicyService;

class ApiTokenProvider extends AbstractProvider
{
    /**
     * @Flow\InjectConfiguration(path="apiToken", package="UpAssist.Neos.Mcp")
     * @var string|null
     */
    protected $apiToken;

    /**
     * @Flow\Inject
     * @var PolicyService
     */
    protected $policyService;

    public function getTokenClassNames(): array
    {
        return [BearerToken::class];
    }

    public function authenticate(TokenInterface $authenticationToken): void
    {
        if (!$authenticationToken instanceof BearerToken) {
            throw new UnsupportedAuthenticationTokenException('This provider only supports BearerToken.', 1742767400);
        }

        $bearer = $authenticationToken->getBearer();
        if ($bearer === '' || empty($this->apiToken)) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);
            return;
        }

        if (!hash_equals((string) $this->apiToken, $bearer)) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $account = new Account();
        $account->setAccountIdentifier('mcp-api-client');
        $account->setAuthenticationProviderName($this->name);

        $authenticateRoles = $this->options['authenticateRoles'] ?? ['Neos.Neos:Administrator'];
        $roles = array_map([$this->policyService, 'getRole'], $authenticateRoles);
        $account->setRoles($roles);

        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
    }
}
