<?php

declare(strict_types=1);

namespace Lala\Webcam\Controller;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionFailedException;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Lala\Webcam\Service\InvalidJpegException;
use Lala\Webcam\Service\JpegMetadataStripper;
use Lala\Webcam\Service\WebcamService;
use Psr\Http\Message\UploadedFileInterface;

class WebcamController extends ActionController
{
    /**
     * Highly compressed JPEGs can be small files with huge dimensions that freeze browsers decoding them
     */
    private const MAXIMUM_DIMENSION = 16384;

    /**
     * @var list<string>
     */
    protected $supportedMediaTypes = ['application/json'];

    #[Flow\Inject]
    protected WebcamService $webcamService;

    #[Flow\Inject]
    protected JpegMetadataStripper $jpegMetadataStripper;

    #[Flow\InjectConfiguration(path: 'maximumFileSize')]
    protected int $maximumFileSize;

    #[Flow\InjectConfiguration(path: 'statusMaximumAge')]
    protected int $statusMaximumAge;

    public function showAction(string $nodeAggregateId): string
    {
        $contentRepositoryId = $this->getContentRepositoryId();
        $webcam = $contentRepositoryId && ($id = NodeAggregateId::tryFromString($nodeAggregateId))
            ? $this->webcamService->findWebcam($contentRepositoryId, $id)
            : null;
        $snapshot = $webcam ? $this->webcamService->findLatestSnapshot($webcam->contentRepositoryId, $webcam->nodeAggregateId) : null;

        if ($snapshot === null) {
            return $this->respond(404, ['error' => 'No image uploaded yet'], $this->statusMaximumAge);
        }

        return $this->respond(200, [
            'uri' => $this->webcamService->getSnapshotUri($snapshot),
            'width' => $snapshot->getWidth(),
            'height' => $snapshot->getHeight(),
            'createdAt' => $snapshot->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->statusMaximumAge);
    }

    #[Flow\SkipCsrfProtection]
    public function uploadAction(string $nodeAggregateId): string
    {
        $contentRepositoryId = $this->getContentRepositoryId();
        $webcam = $contentRepositoryId && ($id = NodeAggregateId::tryFromString($nodeAggregateId))
            ? $this->webcamService->findWebcam($contentRepositoryId, $id)
            : null;

        // Unknown webcams and wrong tokens look the same, so the API does not reveal which node ids exist
        if ($webcam === null || !$this->webcamService->isValidUploadToken($webcam, $this->getUploadToken())) {
            return $this->respond(403, ['error' => 'Unknown webcam or invalid token']);
        }

        $file = $this->request->getHttpRequest()->getUploadedFiles()['image'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->respond(400, ['error' => 'Expected a JPEG file in the multipart field "image"']);
        }
        if ($file->getSize() > $this->maximumFileSize) {
            return $this->respond(413, ['error' => sprintf('The image must not be larger than %d bytes', $this->maximumFileSize)]);
        }

        try {
            $jpeg = $this->jpegMetadataStripper->strip((string)$file->getStream());
        } catch (InvalidJpegException $exception) {
            return $this->respond(415, ['error' => $exception->getMessage()]);
        }

        $imageSize = getimagesizefromstring($jpeg);
        if ($imageSize === false || $imageSize[2] !== IMAGETYPE_JPEG) {
            return $this->respond(415, ['error' => 'Not a JPEG file']);
        }
        if ($imageSize[0] > self::MAXIMUM_DIMENSION || $imageSize[1] > self::MAXIMUM_DIMENSION) {
            return $this->respond(413, ['error' => sprintf('The image must not be wider or higher than %d pixels', self::MAXIMUM_DIMENSION)]);
        }

        $snapshot = $this->webcamService->addSnapshot($webcam, $jpeg, $imageSize[0], $imageSize[1]);

        return $this->respond(201, [
            'uri' => $this->webcamService->getSnapshotUri($snapshot),
            'width' => $snapshot->getWidth(),
            'height' => $snapshot->getHeight(),
        ]);
    }

    private function getContentRepositoryId(): ?ContentRepositoryId
    {
        try {
            return SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;
        } catch (SiteDetectionFailedException) {
            return null;
        }
    }

    /**
     * Apache with mod_proxy_fcgi drops the Authorization header unless configured otherwise,
     * which is why the custom header is checked first
     */
    private function getUploadToken(): string
    {
        $httpRequest = $this->request->getHttpRequest();
        $token = $httpRequest->getHeaderLine('X-Webcam-Token');
        if ($token !== '') {
            return $token;
        }
        return preg_match('/^Bearer\s+(\S+)$/i', $httpRequest->getHeaderLine('Authorization'), $matches) ? $matches[1] : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(int $statusCode, array $data, ?int $maximumAge = null): string
    {
        $this->response->setStatusCode($statusCode);
        $this->response->setContentType('application/json');
        $this->response->setHttpHeader('Cache-Control', $maximumAge ? sprintf('public, max-age=%d', $maximumAge) : 'no-store');

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
