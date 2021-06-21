<?php

namespace CloudVPN\Api\Services\Internal;

use CloudVPN\Api\Exceptions\EnvVarNotFound;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use JsonException;
use Psr\Http\Message\ResponseInterface;

abstract class InternalApiService
{
    /** @var string */
    protected const GET_METHOD = 'GET';
    /** @var string */
    protected const POST_METHOD = 'POST';
    /** @var string */
    protected const PUT_METHOD = 'PUT';
    /** @var Client */
    private Client $httpClient;
    /** @var Request */
    protected Request $request;
    /** @var string|null */
    private ?string $hostName;
    /** @var int */
    protected int $defaultApiVersion = 1;

    /**
     * InternalApiService constructor.
     * @param Client $httpClient
     * @param Request $request
     * @throws EnvVarNotFound
     */
    public function __construct(Client $httpClient, Request $request)
    {
        $this->httpClient = $httpClient;
        $this->request = $request;
        $hostEnvVar = strtoupper($this->getServiceEnvVarName());
        $this->hostName = env(strtoupper($this->getServiceEnvVarName()));

        if (!$this->hostName) {
            throw new EnvVarNotFound('Environment variable "'.$hostEnvVar.'" does not present');
        }
    }

    /**
     * @param string $method
     * @param string $path
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    protected function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $path = trim($path, " \t\n\r\0\x0B/");

        return $this->httpClient->request($method, $this->hostName.'/api/v'.$this->defaultApiVersion.'/'.$path, $options);
    }

    /**
     * @param ResponseInterface $response
     * @return array
     * @throws JsonException
     */
    protected function getJson(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param int $version
     * @return $this
     */
    public function setApiVersion(int $version): self
    {
        $this->defaultApiVersion = $version;

        return $this;
    }

    abstract protected function getServiceEnvVarName(): string;
}
