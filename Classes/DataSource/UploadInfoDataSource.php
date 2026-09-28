<?php

declare(strict_types=1);

namespace Lala\Webcam\DataSource;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Exception as NeosDomainException;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Neos\Service\DataSource\AbstractDataSource;
use Lala\Webcam\Service\WebcamService;

/**
 * Feeds the inspector table that tells editors how to configure the webcam
 */
class UploadInfoDataSource extends AbstractDataSource
{
    protected static $identifier = 'lala-webcam-upload-info';

    #[Flow\Inject]
    protected WebcamService $webcamService;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject]
    protected ContentRepositoryAuthorizationService $authorizationService;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected UserService $userService;

    /**
     * Inspector views expect their data below a "data" key
     *
     * @param array<mixed> $arguments
     * @return array{data: array{rows: list<array{label: string, value: string}>}}
     */
    public function getData(?Node $node = null, array $arguments = []): array
    {
        return ['data' => ['rows' => $node ? $this->getRows($node) : []]];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function getRows(Node $node): array
    {
        if (!$this->mayManageUploads($node)) {
            return [$this->row('status', $this->translate('uploadInfo.notAllowed'))];
        }

        if ($this->webcamService->findWebcam($node->contentRepositoryId, $node->aggregateId) === null) {
            return [$this->row('status', $this->translate('uploadInfo.notPublished'))];
        }

        $token = (string)$node->getProperty('uploadToken');
        if (!preg_match(WebcamService::UPLOAD_TOKEN_PATTERN, $token)) {
            return [
                $this->row('status', $this->translate('uploadInfo.missingToken')),
                $this->row('tokenSuggestion', bin2hex(random_bytes(24))),
            ];
        }

        $uploadUri = $this->getUploadUri($node);
        $snapshot = $this->webcamService->findLatestSnapshot($node->contentRepositoryId, $node->aggregateId);

        return [
            $this->row('uploadUri', $uploadUri),
            $this->row('curl', sprintf(
                'curl -fsS -H %s -F image=@webcam.jpg %s',
                escapeshellarg('X-Webcam-Token: ' . $token),
                escapeshellarg($uploadUri)
            )),
            $this->row('lastUpload', $snapshot?->getCreatedAt()->format('d.m.Y H:i:s') ?? $this->translate('uploadInfo.noUploadYet')),
        ];
    }

    /**
     * The token allows publishing images to the live site without review, so only users who could
     * edit this webcam and publish to live anyway get to see it. The data source resolves any node
     * address the client sends, which is why the workspace of that address is checked as well.
     */
    private function mayManageUploads(Node $node): bool
    {
        $roles = $this->securityContext->getRoles();
        $userId = $this->userService->getCurrentUser()?->getId();

        return $this->authorizationService->getNodePermissions($node, $roles)->edit
            && $this->authorizationService->getWorkspacePermissions($node->contentRepositoryId, $node->workspaceName, $roles, $userId)->write
            && $this->authorizationService->getWorkspacePermissions($node->contentRepositoryId, WorkspaceName::forLive(), $roles, $userId)->write;
    }

    private function getUploadUri(Node $node): string
    {
        $path = $this->controllerContext->getUriBuilder()
            ->reset()
            ->setFormat('json')
            ->uriFor('upload', ['nodeAggregateId' => $node->aggregateId->value], 'Webcam', 'Lala.Webcam');

        $siteNode = $this->contentRepositoryRegistry->subgraphForNode($node)
            ->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE));
        try {
            $domain = $siteNode ? $this->siteRepository->findSiteBySiteNode($siteNode)->getPrimaryDomain() : null;
        } catch (NeosDomainException) {
            $domain = null;
        }
        if ($domain === null) {
            $requestUri = $this->controllerContext->getRequest()->getHttpRequest()->getUri();
            return (string)$requestUri->withPath($path)->withQuery('')->withFragment('');
        }

        $baseUri = (string)$domain;
        return (str_contains($baseUri, '://') ? $baseUri : 'https://' . $baseUri) . $path;
    }

    /**
     * @return array{label: string, value: string}
     */
    private function row(string $label, string $value): array
    {
        return ['label' => $this->translate('uploadInfo.' . $label . '.label'), 'value' => $value];
    }

    private function translate(string $id): string
    {
        return $this->translator->translateById($id, [], null, null, 'Main', 'Lala.Webcam') ?? $id;
    }
}
