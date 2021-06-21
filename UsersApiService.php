<?php

namespace CloudVPN\Api\Services\Internal;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use JsonException;

class UsersApiService extends InternalApiService
{
    /**
     * @example https://confluence.mts.ru/pages/viewpage.action?pageId=80100176
     *
     * @param array $params
     * @return array
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getOrCreateUser(array $params): array
    {
        $options = [
            RequestOptions::FORM_PARAMS => $params,
        ];

        $response = $this->request(self::POST_METHOD, 'create', $options);

        return $this->getJson($response)['data'];
    }

    /**
     * @example https://confluence.mts.ru/pages/viewpage.action?pageId=80100176
     *
     * @param array $params
     * @return array
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getOrCreateAdmin(array $params): array
    {
        $options = [
            RequestOptions::FORM_PARAMS => $params,
        ];

        $response = $this->request(self::POST_METHOD, 'admin/create', $options);

        return $this->getJson($response)['data'];
    }

    /**
     * @return array
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getCurrentUserInfo(): array
    {
        $response = $this->request(self::GET_METHOD, 'info');

        return $this->getJson($response)['data'];
    }

    /**
     * @return array
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getDemoUserInfo(): array
    {
        $response = $this->request(self::GET_METHOD, 'info/demo');

        return $this->getJson($response)['data'];
    }

    /**
     * @return array
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getCurrentNetworkInfo(): array
    {
        $response = $this->request(self::GET_METHOD, 'network/info');

        return $this->getJson($response)['data'];
    }

    /**
     * @param string $userUuid
     * @return array
     * @throws GuzzleException
     * @throws JsonException
     */
    public function getUserNetworks(string $userUuid): array
    {
        $response = $this->request(self::GET_METHOD, $userUuid.'/networks');

        return $this->getJson($response)['data'];
    }

    /**
     * @return string
     */
    protected function getServiceEnvVarName(): string
    {
        return 'USERS_SERVICE_URL';
    }
}
